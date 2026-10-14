<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\Payments\VeltoWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VeltoWebhookController extends ApiController
{
    public function __construct(
        private readonly VeltoWebhookService $webhook,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $order = $this->webhook->handle($request);

        return response()->json([
            'data' => $order === null ? null : [
                'orderUuid' => $order->uuid,
                'orderNumber' => $order->order_number,
                'status' => $order->status->value,
            ],
        ]);
    }
}
