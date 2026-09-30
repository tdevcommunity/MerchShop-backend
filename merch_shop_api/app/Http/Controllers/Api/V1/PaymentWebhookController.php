<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Order\PaymentNotificationRequest;
use App\Services\PaymentService;
use App\Support\Payments\WebhookSignatureVerifier;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Notifications des aggregateurs de paiement.
 *
 * Cette route est la seule de l'API qui ne soit pas protegee par la session :
 * l'operateur n'a pas de compte chez nous, et ne peut pas en avoir un. Sa seule
 * defense est la signature du corps, verifiee avant meme la validation.
 *
 * L'ordre des operations est delibere : signature, puis validation, puis
 * ecriture. Valider d'abord laisserait un attaquant lire les messages
 * d'erreur d'un corps qu'il a forge, et l'`ApiException` renverrait 503 a
 * quiconque tickerait la route sans secret.
 */
final class PaymentWebhookController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly WebhookSignatureVerifier $signatures,
    ) {}

    /**
     * Notification de paiement d'un operateur.
     *
     * La reponse est un 200 tant que la notification a ete comprise et
     * rapprochee, y compris pour un echec de paiement annonce par l'operateur :
     * une notification d'echec est une information attendue, pas une erreur de
     * l'API. Seul un rapprochement impossible renvoie autre chose qu'un 200,
     * ce qui pousse l'operateur a reessayer.
     */
    #[OpenApiResponse(status: 200, description: 'Notification rapprochee, paiement accepte ou refuse.')]
    #[OpenApiResponse(status: 401, description: 'Signature absente ou invalide : la notification n’est pas traitee.')]
    #[OpenApiResponse(status: 422, description: 'Notification signee mais mal formee.')]
    public function handle(PaymentNotificationRequest $request, PaymentProvider $provider): JsonResponse
    {
        $this->signatures->verify($request, $provider);

        $order = $this->payments->handleNotification([
            ...$request->body(),
            'status' => PaymentStatus::fromOperator($request->string('status')->toString()),
        ]);

        /*
         * La commande et son statut sont renvoyes pour que l'operateur puisse
         * confirmer cote Integration que la notification a bien ete comprise.
         * Aucune donnee de paiement ni de client n'est renvoyee : la reponse
         * d'un webhook est lue par l'operateur, et n'a pas a devenir un canal
         * de fuite vers lui.
         */
        return response()->json([
            'data' => [
                'orderUuid' => $order->uuid,
                'orderNumber' => $order->order_number,
                'status' => $order->status->value,
            ],
        ]);
    }
}
