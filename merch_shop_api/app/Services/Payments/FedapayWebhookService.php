<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\PaymentService;
use App\Support\Payments\FedapaySignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Traitement des notifications FedaPay.
 *
 * Le service tient la chaine complete, dans un ordre qui n'est pas negociable :
 * la signature d'abord, la lecture ensuite, l'ecriture enfin. Valider avant
 * d'authentiquer laisserait un attaquant provoquer des erreurs en amont de la
 * seule verification qui protege la route, et lui apprendre au passage ce que
 * notre code attend.
 *
 * FedaPay emet un evenement pour chaque changement d'etat d'une transaction,
 * dont beaucoup ne concernent pas le reglement : la creation d'une transaction,
 * sa mise a jour, un remboursement. Ces evenements sont acquittes — un 200 est
 * la seule reponse qui arrete les reprises de l'operateur — mais n'ecrivent
 * rien, faute de quoi ils seraient rejetes en boucle.
 */
final class FedapayWebhookService
{
    /**
     * Etats FedaPay qui valent reglement.
     *
     * `transferred` est traite comme `approved` parce que le SDK lui-meme compte
     * les deux parmi les etats payes : le transfert n'est que l'aboutissement
     * bancaire de ce que l'acheteur a deja approuve. Les traiter differemment
     * ferait dependre l'ouverture du droit de retrait du moment ou l'argent
     * arrive sur le compte FedaPay, et non de la decision du client.
     *
     * @var array<string, PaymentStatus>
     */
    private const SETTLED = [
        'approved' => PaymentStatus::SUCCESS,
        'transferred' => PaymentStatus::SUCCESS,
    ];

    /**
     * Etats FedaPay qui signifient que l'argent ne sera pas encaisse.
     *
     * `expired` y figure avec `declined` et `canceled`, et c'est ce que la
     * documentation permet de conclure : une collecte que le client n'a pas
     * finalisee expire seule au bout de vingt-quatre heures. La traiter comme
     * inconnue — ce qu'elle etait — renvoyait un 422 que FedaPay rejouait en
     * boucle, pour une transaction dont le sort etait deja tranche : l'argent
     * n'etait pas encaisse, et le bruit reconduisait sans fin sur une erreur
     * qui n'en etait pas une.
     *
     * @var array<string, PaymentStatus>
     */
    private const UNSETTLED = [
        'declined' => PaymentStatus::FAILED,
        'canceled' => PaymentStatus::FAILED,
        'expired' => PaymentStatus::FAILED,
    ];

    /**
     * Etats FedaPay qui disent que le paiement n'est pas encore tranche.
     *
     * @var array<int, string>
     */
    private const NEUTRAL = ['pending'];

    /**
     * Etats FedaPay de remboursement.
     *
     * Ils ne sont pas traites ici : le remboursement d'une commande est decide
     * par le guichet via `OrderService::refund()`, qui rend le stock et revoque
     * le droit de retrait, deux consequences qu'un evenement ne peut pas porter
     * seul. Les acquitter sans rien ecrire evite que FedaPay les rejoue, et le
     * journal signale que l'evenement est passe par une voie non traitee.
     *
     * @var array<int, string>
     */
    private const REFUNDED = [
        'refunded',
        'approved_partially_refunded',
        'transferred_partially_refunded',
    ];

    public function __construct(
        private readonly FedapaySignatureVerifier $signatures,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Verifie puis applique une notification FedaPay.
     *
     * Renvoie la commande concernee, ou null lorsque la notification est
     * acquittee sans effet : repondre 200 dans ce cas est ce qui arrete
     * l'operateur, qui ne retente que les notifications en erreur.
     */
    public function handle(Request $request): ?Order
    {
        $this->signatures->verify($request);

        $event = FedapayEvent::fromPayload($request->getContent());

        if (! $event->concernsTransaction()) {
            Log::info('Evenement FedaPay hors transaction, acquitte sans effet.', [
                'event' => $event->name,
            ]);

            return null;
        }

        $status = $this->statusFor($event);

        if ($status === null) {
            return null;
        }

        return $this->payments->handleNotification([
            /*
             * Notre reference voyage dans les metadonnees de la transaction et
             * constitue le rapprochement principal : c'est un identifiant interne
             * que nous avons choisi, donc invérifiable par l'acheteur et non
             * reutilisable sur une autre commande. Elle peut ne pas revenir si
             * l'operateur cesse de la transmettre ; la reference qu'il attribue
             * a la transaction, elle, est stockee au moment du checkout et sert
             * alors de repli.
             */
            'reference' => $event->paymentReference,
            'transaction_id' => $event->transactionReference,
            'status' => $status,
            'amount' => $event->amount === null ? null : (string) $event->amount,
            'failure_reason' => $status === PaymentStatus::FAILED ? $this->reason($event) : null,
        ]);
    }

    /**
     * L'etat interne qu'un etat FedaPay represente, ou null s'il n'en represente
     * aucun.
     *
     * La lecture se fait sur le statut de la transaction et non sur le nom de
     * l'evenement : l'evenement dit ce qui s'est passe, le statut dit dans quel
     * etat la transaction est, et c'est le second qui fait foi. `transaction.updated`
     * peut ainsi etre traite comme le succes ou l'echec qu'il annonce, sans table
     * de correspondance a maintenir pour chaque nom d'evenement.
     */
    private function statusFor(FedapayEvent $event): ?PaymentStatus
    {
        $status = strtolower($event->status);

        if (isset(self::SETTLED[$status])) {
            return self::SETTLED[$status];
        }

        if (isset(self::UNSETTLED[$status])) {
            return self::UNSETTLED[$status];
        }

        /*
         * Une transaction encore en attente est une information, pas un evenement
         * : elle ne change rien, et la laisser passer par le service de paiement
         * la ferait rejeter comme un etat inattendu, donc rejouer sans fin par
         * l'operateur.
         */
        if (in_array($status, self::NEUTRAL, true)) {
            return null;
        }

        if (in_array($status, self::REFUNDED, true)) {
            Log::warning('Evenement de remboursement FedaPay non traite automatiquement.', [
                'event' => $event->name,
                'status' => $event->status,
                'transaction_reference' => $event->transactionReference,
                'payment_reference' => $event->paymentReference,
            ]);

            return null;
        }

        /*
         * FedaPay peut ajouter des etats. Les traiter comme un refus ferait
         * passer une transaction reussie pour un echec ; les ignorer laisserait
         * une commande payee en attente. Aucun des deux n'est acceptable : la
         * notification est donc refusee, ce qui la rend visible et laisse a
         * l'operateur le temps de la rejouer une fois le vocabulaire etendu.
         */
        throw new ApiException(
            'Etat de transaction FedaPay inconnu : '.$event->status,
            422,
            'UNKNOWN_FEDAPAY_STATUS',
            ['event' => $event->name, 'status' => $event->status],
        );
    }

    /**
     * Motif d'echec conserve.
     *
     * Le plan de tracking exige de garder la raison d'un refus, et FedaPay
     * n'en fournit pas dans l'evenement : c'est donc son propre vocabulaire qui
     * est conserve, plutot qu'un motif vide que l'analyse ne pourrait pas
     * distinguer d'un abandon.
     *
     * Trois motifs et non deux, parce que `expired` partage avec `canceled` un
     * etat interne sans partager sa cause : rien ne distingue l'acheteur qui a
     * change d'avis de celui qui a laisse le lien expirer sans jamais l'ouvrir.
     * Les confondre attribuerait au second une annulation qu'il n'a pas
     * demandee, et qui apparaitrait dans le suivi des abandons comme un refus de
     * sa part.
     */
    private function reason(FedapayEvent $event): string
    {
        return match (strtolower($event->status)) {
            'declined' => 'Refus du prestataire de paiement.',
            'expired' => 'Paiement non finalise : la collecte a expire chez le prestataire de paiement.',
            default => 'Paiement annule chez le prestataire de paiement.',
        };
    }
}
