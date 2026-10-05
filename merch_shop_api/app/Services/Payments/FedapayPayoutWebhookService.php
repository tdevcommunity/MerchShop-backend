<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Refund;
use App\Repositories\Contracts\RefundRepositoryInterface;
use App\Support\Payments\FedapayPayoutEvent;
use App\Support\Payments\FedapaySignatureVerifier;
use Illuminate\Http\Request;

/**
 * Notifications de sortie d'argent FedaPay.
 *
 * Distinct de `FedapayWebhookService`, qui traite les encaissements, pour une
 * raison qui n'est pas de commodite : les deux evenements disent des choses
 * opposees sur la meme commande. Confondre « l'argent est parti » avec « le
 * client a paye » ferait passer une commande en payee au moment ou son
 * remboursement echoue, et l'inversement bloquerait un remboursement confirme.
 *
 * La signature est verifiee avant toute lecture du corps, exactement comme pour
 * l'encaissement : une notification doit etre authentique avant d'etre
 * interpretee, sinon ses messages d'erreur renseigneraient celui qui l'a
 * forgee.
 */
final class FedapayPayoutWebhookService
{
    public function __construct(
        private readonly FedapaySignatureVerifier $verifier,
        private readonly RefundRepositoryInterface $refunds,
        private readonly RefundService $refundsService,
    ) {}

    /**
     * Verifie puis applique une notification de depot.
     *
     * Rend la demande concernee, ou `null` lorsque la notification ne vise rien
     * de connu — un depot initiated sur un autre compte FedaPay, par exemple.
     * Ce cas est acquitte sans erreur : c'est la seule reponse qui arrete les
     * reprises de l'operateur, et refuser un evenement attendu le ferait
     * rejouer indefiniment.
     *
     * @throws ApiException 401 sur signature invalide, 422 sur evenement illisible
     *                      ou statut inconnu.
     */
    public function handle(Request $request): ?Refund
    {
        $this->verifier->verify($request);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $event = FedapayPayoutEvent::fromPayload($decoded);

        $refund = $this->refunds->findByPayoutReference($event->payoutReference);

        if ($refund === null) {
            return null;
        }

        /*
         * Un statut inconnu est refuse plutot que classe dans l'une des deux
         * issues. C'est le seul point ou il faut trancher, et le trancher dans
         * le silence serait le pire choix : un vocabulaire d'operateur qui
         * evoluerait se traduirait par une commande bloquee en attente, sans
         * qu'aucune erreur ne soit jamais signalee.
         */
        if (! $event->isKnown()) {
            throw new ApiException(
                'Statut de dépôt FedaPay inconnu.',
                422,
                'UNKNOWN_FEDAPAY_PAYOUT_STATUS',
                ['status' => $event->status],
            );
        }

        if ($event->hasSettled()) {
            return $this->refundsService->settle($refund);
        }

        if ($event->hasFailed()) {
            return $this->refundsService->fail($refund, $event->failureReason($decoded));
        }

        /*
         * Le depot est en cours : la demande reste en attente et rien n'est
         * ecrit. C'est le seul cas ou acquitter sans rien faire est la bonne
         * reponse, et la commande reste bloquee en `refund_pending` — ce qui
         * dit au guichet que de l'argent circule encore.
         */
        return $refund;
    }
}
