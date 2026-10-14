<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\PaymentService;
use App\Support\Payments\VeltoPaymentStatus;
use App\Support\Payments\VeltoSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class VeltoWebhookService
{
    public function __construct(
        private readonly VeltoSignatureVerifier $signatures,
        private readonly PaymentService $payments,
    ) {}

    public function handle(Request $request): ?Order
    {
        $this->signatures->verify($request);

        $event = VeltoEvent::fromPayload($request->getContent());

        if (! $event->concernsPayment()) {
            Log::info('Evenement Velto hors paiement, acquitte sans effet.', [
                'event' => $event->event,
            ]);

            return null;
        }

        $status = $this->statusFor($event);

        if ($status === null) {
            return null;
        }

        return $this->payments->handleNotification([
            'reference' => $event->paymentReference,
            'transaction_id' => $event->transactionId,
            'status' => $status,
            'amount' => $event->amount === null ? null : (string) $event->amount,
            'failure_reason' => $status === PaymentStatus::FAILED
                ? 'Paiement refuse ou annule chez Velto.'
                : null,
        ]);
    }

    private function statusFor(VeltoEvent $event): ?PaymentStatus
    {
        $status = VeltoPaymentStatus::fromOperator($event->status);

        if ($status !== null) {
            return $status;
        }

        if (in_array($event->status, ['PENDING', 'PROCESSING'], true)) {
            return null;
        }

        Log::warning('Evenement Velto avec statut inconnu, acquitte sans effet.', [
            'event' => $event->event,
            'status' => $event->status,
            'transaction_id' => $event->transactionId,
        ]);

        return null;
    }
}
