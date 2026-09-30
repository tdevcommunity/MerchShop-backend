<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Representation d'une tentative de paiement.
 *
 * `transactionId` est expose parce que le guichet en a besoin pour rapprocher
 * un versement Mobile Money d'une commande. Il n'est renvoye qu'une fois le
 * paiement confirme : avant, la reference existe dans la base mais n'a encore
 * ete vue par aucun operateur, et l'afficher n'apporterait rien au client.
 *
 * @mixin Payment
 */
final class PaymentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;

        return [
            'uuid' => $payment->uuid,
            'amount' => $payment->amount,
            'method' => $payment->method->value,
            'provider' => $payment->provider?->value,
            'status' => $payment->status->value,
            'transactionId' => $payment->transaction_id,
            'failureReason' => $payment->failure_reason,
            'paidAt' => $payment->paid_at?->toIso8601String(),
        ];
    }
}
