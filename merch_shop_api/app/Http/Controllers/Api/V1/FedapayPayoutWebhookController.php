<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Payments\FedapayPayoutWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications de sortie d'argent FedaPay.
 *
 * Ce chemin est separe de celui des encaissements et de celui des autres
 * agregateurs. La signature impose de le faire, comme pour les transactions ;
 * ce qui s'y ajoute est qu'un depot et un paiement n'ont pas la meme
 * consequence, et que les traiter dans le meme controleur donnerait a l'un
 * d'ecrire l'etat que l'autre represente.
 *
 * Comme pour l'encaissement, il ne fait que appeler le service et repondre :
 * verification, traduction et idempotence restent dans le service, testables
 * sans passer par HTTP.
 */
final class FedapayPayoutWebhookController extends ApiController
{
    public function __construct(
        private readonly FedapayPayoutWebhookService $webhook,
    ) {}

    /**
     * Notification de depot FedaPay.
     *
     * La reponse ne dit rien du contenu : c'est un canal vers l'operateur, pas
     * vers un tiers, et un montant ou un numero de telephone qui y figureraient feraient
     * transiter des donnees d'acheteur par une porte qui n'a pas a les
     * transporter.
     */
    public function handle(Request $request): JsonResponse
    {
        $refund = $this->webhook->handle($request);

        return response()->json([
            'data' => $refund === null ? null : [
                'refundUuid' => $refund->uuid,
                'status' => $refund->status->value,
            ],
        ]);
    }
}
