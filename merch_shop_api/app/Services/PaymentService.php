<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Support\Api\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Traitement des confirmations de paiement.
 *
 * Ce service est appele par le webhook de l'agregateur, donc dans des conditions
 * qui ne repondent a aucune des proprietes habituelles d'une ecriture d'API :
 *
 *  - il n'y a pas de session, l'appel vient de l'operateur et non d'un client ;
 *  - la meme notification peut arriver plusieurs fois, en general parce que
 *    l'operateur n'a pas recu notre reponse a temps. Elle doit donc pouvoir etre
 *    rejouee sans rien changer d'autre ;
 *  - l'appel peut echouer au milieu et etre rejoue. Une transaction garantit
 *    qu'un etat partiel ne subsiste pas.
 *
 * La regle qui en decoule : la confirmation est ecriture une seule fois, et tout
 * ce qui ne change rien est renvoye sans erreur. Renvoyer une erreur sur un
 * doublon provoquerait une boucle de rejets chez l'operateur, et un paiement
 * resterait suspendu alors que l'argent est deja encaisse.
 */
final class PaymentService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly PaymentRepositoryInterface $payments,
        private readonly OrderService $orderService,
        private readonly InvoiceService $invoices,
        private readonly PickupQrCodeService $pickupQrCodes,
        private readonly Money $money,
    ) {}

    /**
     * Applique la notification d'un operateur.
     *
     * `reference` est la notre : l'uuid du paiement, transmis a l'operateur au
     * moment du checkout. Elle peut etre absente chez un operateur dont les
     * metadonnees ne sont pas restituees dans ses notifications : le
     * rapprochement se fait alors sur `transaction_id`, que nous avons stockee en
     * ecrivant le checkout et qui est, elle, toujours la nôtre.
     *
     * @param  array{reference?: string|null, transaction_id: string, status: PaymentStatus, amount?: string|null, failure_reason?: string|null}  $notification
     */
    public function handleNotification(array $notification): Order
    {
        return DB::transaction(function () use ($notification): Order {
            $payment = $this->locate($notification);

            if ($payment === null) {
                throw new ApiException(
                    'Paiement inconnu.',
                    404,
                    'PAYMENT_NOT_FOUND',
                    [
                        'reference' => $notification['reference'] ?? null,
                        'transaction_id' => $notification['transaction_id'],
                    ],
                );
            }

            /*
             * Le verrou du paiement protege une tentative contre son propre
             * rejeu. Celui de la commande protege aussi deux tentatives
             * differentes qui voudraient simultanement consommer le meme
             * solde, emettre la meme facture ou ouvrir le meme droit de retrait.
             */
            $order = $payment->order()->lockForUpdate()->firstOrFail();
            $payment->setRelation('order', $order);

            $this->attachTransaction($payment, $notification);

            /*
             * Rejouer une notification deja appliquee ne doit rien ecrire : ni
             * statut, ni date, ni facture.
             *
             * Ce controle precede volontairement la verification du montant. Une
             * premiere notification rejouee apres que la commande a ete entierement
             * reglee annonce le montant de son propre reglement, qui n'est plus le
             * solde attendu ; la comparer d'abord renverrait une erreur sur une
             * notification parfaitement legitime, et l'operateur la rejouerait
             * indefiniment.
             */
            if ($payment->status->isFinal()) {
                return $order;
            }

            /*
             * Le montant annonce par l'operateur est compare au solde restant a
             * encaisser. Sans cette verification, un paiement partiel serait
             * accepte comme un solde : la commande passerait « payee » alors
             * qu'une partie de l'argent n'a jamais ete encaissee.
             *
             * L'ordre des etapes garantit que le paiement en cours de traitement
             * n'est pas encore compte dans l'encaissement : le solde compare est
             * bien celui qui precede cette notification.
             */
            $outstanding = $this->outstandingAmount($order);

            if ($notification['amount'] !== null
                && ! $this->money->equals((string) $notification['amount'], $outstanding)) {
                throw new ApiException(
                    'Le montant annonce par l\'operateur ne correspond pas au solde de la commande.',
                    409,
                    'PAYMENT_AMOUNT_MISMATCH',
                    ['expected' => $outstanding, 'received' => (string) $notification['amount']],
                );
            }

            match ($notification['status']) {
                PaymentStatus::SUCCESS => $this->confirm($payment),
                PaymentStatus::FAILED => $this->reject($payment, $notification['failure_reason'] ?? null),
                default => throw new ApiException(
                    'Cet etat de paiement n\'est pas attendu d\'un webhook.',
                    422,
                    'UNEXPECTED_PAYMENT_STATUS',
                    ['status' => $notification['status']->value],
                ),
            };

            return $order->refresh()->load(['items.variant', 'user', 'payments', 'invoice']);
        });
    }

    /**
     * Retrouve le paiement vise par une notification.
     *
     * Deux voies, dans cet ordre. La reference interne d'abord, parce qu'elle
     * est la seule que nous choisissons et qui ne peut donc pas designer par
     * accident une autre commande. La reference operateur ensuite, ce qui n'est
     * possible que parce que le checkout l'a ecrite : sans cette ecriture, un
     * webhook sans metadonnees serait sans aucun moyen de retour.
     *
     * La reference operateur est chargee avec sa commande comme la premiere, la
     * comparaison du montant qui suit ayant besoin des deux.
     *
     * @param  array{reference?: string|null, transaction_id: string}  $notification
     */
    private function locate(array $notification): ?Payment
    {
        $reference = $notification['reference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            return $this->payments->findWithOrder($reference);
        }

        return $this->payments->findByTransactionId($notification['transaction_id']);
    }

    /**
     * Confirme le reglement d'un paiement.
     *
     * La commande n'est close que lorsque son solde est couvert : une commande
     * payee en deux fois ne devient « payee » qu'a la seconde confirmation. Sans
     * ce controle, la premiere suffirait a ouvrir le droit de retrait.
     */
    private function confirm(Payment $payment): void
    {
        $payment->update([
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
            'failure_reason' => null,
        ]);

        $order = $payment->order;

        if ($this->money->toAmount($this->payments->settledAmount($order)) < $this->money->toAmount($order->total)) {
            return;
        }

        /*
         * Le passage a l'etat paye passe par la table de transitions du service
         * de commande, qui refuse toute transition illegale. C'est le point ou
         * une commande annulee entre-temps resisterait a une confirmation
         * tardive de l'operateur : sans cette verification, le paiement et
         * l'annulation cohabiteraient sur la meme commande.
         */
        $this->orderService->markPaid($order);

        $this->invoices->issueFor($order);

        /*
         * Le droit de retrait n'est ouvert qu'ici, une fois l'argent encaisse.
         * L'octroi est idempotent, donc un rejeu de la notification le reaffecte
         * sans changer la valeur : le client retrouve le meme QR.
         */
        $this->pickupQrCodes->grant($order);
    }

    /**
     * Enregistre un echec de reglement.
     *
     * L'echec est conserve plutot que la ligne supprimee : le plan de tracking
     * exige de garder les refus, seul un succes n'etant jamais seul a dire ce
     * qu'une campagne a rapporte.
     *
     * La date d'echec est ecrite ici et non laissee nulle : sans elle, un
     * abandon de paiement et une tentative jamais presentee a l'operateur
     * produisent la meme ligne, et le taux d'echec par moyen de paiement — que
     * le plan de tracking demande — devient impossible a calculer.
     */
    private function reject(Payment $payment, ?string $reason): void
    {
        $payment->update([
            'status' => PaymentStatus::FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Rattache la reference operateur, en gerant la course entre deux rappels.
     */
    private function attachTransaction(Payment $payment, array $notification): void
    {
        if ($payment->transaction_id === $notification['transaction_id']) {
            return;
        }

        /*
         * Un paiement deja confirme par une autre transaction n'est pas
         * reassigne. L'operateur a parle deux fois, et le second reglement
         * doit etre traite comme ce qu'il est : un remboursement, que le
         * back-office depuis. Ecraser la premiere reference ferait disparaitre
         * la trace du reglement d'origine.
         */
        if ($payment->status->isFinal()) {
            Log::warning('Notification de paiement sur un paiement deja regle.', [
                'payment_reference' => $payment->uuid,
                'transaction_id' => $notification['transaction_id'],
                'stored_transaction_id' => $payment->transaction_id,
            ]);

            return;
        }

        $this->payments->attachTransactionId($payment, $notification['transaction_id']);
    }

    /**
     * Solde restant a encaisser sur une commande.
     *
     * Le montant de la tentative en cours est retire du deja encaisse, sans quoi
     * l'operateur qui confirme un solde annulerait le controle de montant : la
     * commande ne serait plus entierement reglee et le webhook echouerait.
     */
    private function outstandingAmount(Order $order): string
    {
        $settled = $this->money->toAmount($this->payments->settledAmount($order));

        return max(0, $this->money->toAmount($order->total) - $settled);
    }
}
