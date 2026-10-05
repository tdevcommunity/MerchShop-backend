<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Payments\FedapayWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications FedaPay.
 *
 * Ce controleur a son propre chemin et non celui, generique, des autres
 * agregateurs, parce que FedaPay ne parle pas comme eux : sa signature porte sur
 * l'horodatage suivi du corps, et sa notification est un evenement enveloppe
 * autour d'une transaction, non un corps plat decrivant un paiement. Le faire
 * passer dans le chemin generique le ferait echouer a la premiere verification —
 * ce qui, sur un webhook, ne se remarque qu'a l'absence de commandes payees.
 *
 * Il ne fait que deux choses : appeler le service, qui verifie puis applique, et
 * repondre. Toute la logique — signature, tolerance de rejeu, traduction de
 * l'evenement, idempotence — est dans le service, afin qu'elle soit verifiable
 * sans passer par HTTP.
 */
final class FedapayWebhookController extends ApiController
{
    public function __construct(
        private readonly FedapayWebhookService $webhook,
    ) {}

    /**
     * Notification de FedaPay.
     *
     * La reponse est un 200 des que la notification est comprise, y compris
     * lorsqu'elle ne concerne qu'un evenement sans effet sur une commande : un
     * 200 est la seule reponse qui arrete les reprises de FedaPay, et refus
     * sous pretexte qu'un evenement ne nous interesse pas le ferait rejouer
     * indefinement un evenement parfaitement attendu.
     */
    public function handle(Request $request): JsonResponse
    {
        $order = $this->webhook->handle($request);

        /*
         * Aucun detail de paiement n'est renvoye : la reponse d'un webhook est
         * lue par l'operateur, et n'a pas a devenir un canal de fuite vers lui.
         */
        return response()->json([
            'data' => $order === null ? null : [
                'orderUuid' => $order->uuid,
                'orderNumber' => $order->order_number,
                'status' => $order->status->value,
            ],
        ]);
    }
}
