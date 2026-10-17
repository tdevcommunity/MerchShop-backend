<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Payments\VeltoClient;
use App\Support\Payments\VeltoPaymentStatus;
use Illuminate\Support\Facades\Log;

/**
 * Rattrapage au retour navigateur quand le webhook Velto n'atteint pas l'API
 * (ex. localhost). La source de verite reste l'API Velto, pas l'URL de retour.
 */
final class VeltoReturnSyncService
{
    public function __construct(
        private readonly VeltoClient $client,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Verifie l'etat Velto d'un checkout deja ouvert (annule, expire, deja paye).
     */
    public function reconcileCheckoutSession(Payment $payment, Order $order): Order
    {
        if ($payment->provider !== PaymentProvider::VELTO) {
            return $order;
        }

        $transactionId = $payment->transaction_id;
        if (! is_string($transactionId) || $transactionId === '') {
            return $order;
        }

        try {
            $data = $this->client->getPayment($transactionId);
        } catch (ApiException $exception) {
            $this->handleReconcileClientFailure($payment, $transactionId, $exception);

            return $order->fresh(['payments', 'items', 'invoice']) ?? $order;
        }

        return $this->applyRemotePaymentState($payment, $order, $transactionId, $data);
    }

    public function syncFromReturn(Order $order, ?string $transactionId): Order
    {
        $resolved = is_string($transactionId) ? trim($transactionId) : '';

        if ($resolved === '') {
            $resolved = $this->latestVeltoTransactionId($order) ?? '';
        }

        if ($resolved === '') {
            return $order->fresh(['payments', 'items', 'invoice']) ?? $order;
        }

        return $this->sync($order, $resolved);
    }

    public function sync(Order $order, string $transactionId): Order
    {
        $payment = $this->matchingPayment($order, $transactionId);

        $data = $this->client->getPayment($transactionId);

        $remoteReference = is_string($data['external_reference'] ?? null)
            ? $data['external_reference']
            : null;

        if ($remoteReference !== null && $remoteReference !== $payment->uuid) {
            throw new ApiException(
                'La transaction ne correspond pas a cette commande.',
                409,
                'PAYMENT_MISMATCH',
            );
        }

        return $this->applyRemotePaymentState($payment, $order, $transactionId, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyRemotePaymentState(
        Payment $payment,
        Order $order,
        string $transactionId,
        array $data,
    ): Order {
        $remoteReference = is_string($data['external_reference'] ?? null)
            ? $data['external_reference']
            : null;

        if ($remoteReference !== null && $remoteReference !== $payment->uuid) {
            return $order;
        }

        $remoteStatus = is_string($data['status'] ?? null) ? $data['status'] : '';
        $status = VeltoPaymentStatus::fromOperator($remoteStatus);

        if ($status === null) {
            return $order->fresh(['payments', 'items', 'invoice']) ?? $order;
        }

        Log::info('Etat Velto rapproche pour une tentative de paiement.', [
            'payment_reference' => $payment->uuid,
            'order_number' => $order->order_number,
            'transaction_id' => $transactionId,
            'remote_status' => $remoteStatus,
            'mapped_status' => $status->value,
        ]);

        return $this->payments->handleNotification([
            'reference' => $payment->uuid,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => isset($data['amount']) ? (string) $data['amount'] : null,
            'failure_reason' => $status === PaymentStatus::FAILED
                ? 'Paiement refuse ou annule chez Velto.'
                : null,
        ]);
    }

    private function handleReconcileClientFailure(
        Payment $payment,
        string $transactionId,
        ApiException $exception,
    ): void {
        Log::warning('Reconciliation Velto impossible.', [
            'payment_reference' => $payment->uuid,
            'transaction_id' => $transactionId,
            'status' => $exception->status(),
            'error_code' => $exception->errorCode(),
        ]);

        if (in_array($exception->status(), [404, 410], true)) {
            $payment->update([
                'checkout_url' => null,
                'transaction_id' => null,
            ]);
        }
    }

    private function matchingPayment(Order $order, string $transactionId): Payment
    {
        $order->loadMissing('payments');

        /** @var Payment|null $payment */
        $payment = $order->payments
            ->first(fn (Payment $candidate): bool => $candidate->transaction_id === $transactionId);

        if ($payment === null) {
            throw new ApiException(
                'Aucun paiement en cours ne correspond a cette transaction.',
                404,
                'PAYMENT_NOT_FOUND',
                ['transaction_id' => $transactionId],
            );
        }

        if ($payment->provider !== PaymentProvider::VELTO) {
            throw new ApiException(
                'Cette commande n utilise pas Velto.',
                409,
                'PAYMENT_PROVIDER_MISMATCH',
            );
        }

        return $payment;
    }

    private function latestVeltoTransactionId(Order $order): ?string
    {
        $order->loadMissing('payments');

        /** @var Payment|null $payment */
        $payment = $order->payments
            ->filter(function (Payment $candidate): bool {
                return $candidate->provider === PaymentProvider::VELTO
                    && is_string($candidate->transaction_id)
                    && $candidate->transaction_id !== '';
            })
            ->sortByDesc(fn (Payment $candidate) => $candidate->created_at?->getTimestamp() ?? 0)
            ->first();

        return $payment?->transaction_id;
    }
}
