<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Support\Api\Money;
use App\Support\Payments\VeltoClient;
use Illuminate\Support\Facades\Log;

final class VeltoPayoutGateway implements PayoutGateway
{
    public function __construct(
        private readonly Money $money,
        private readonly VeltoClient $client,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::VELTO;
    }

    public function payOut(Order $order): PayoutResult
    {
        $paid = $order->payments()
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->first();

        if ($paid === null) {
            throw new ApiException(
                'Cette commande n’a aucun paiement confirmé à rembourser.',
                422,
                'PAYOUT_WITHOUT_PAYMENT',
                ['order' => $order->uuid],
            );
        }

        $amount = $this->money->toAmount($paid->amount);
        $phone = $this->phoneNumber($order);

        if ($phone === '') {
            throw new ApiException(
                'Numero de telephone client manquant pour le remboursement.',
                422,
                'PAYOUT_PHONE_MISSING',
                ['order' => $order->uuid],
            );
        }

        Log::info('Demande de retrait Velto envoyee.', [
            'order_number' => $order->order_number,
            'payment_reference' => $paid->uuid,
            'provider' => $this->provider()->value,
            'amount' => $amount,
        ]);

        $data = $this->client->createWithdrawal([
            'amount' => $amount,
            'phone_number' => $phone,
            'beneficiary_name' => $order->customer_name,
            'operator' => $this->operatorFor($order),
            'external_reference' => 'refund_'.$order->uuid,
        ]);

        $reference = is_string($data['id'] ?? null) ? $data['id'] : '';
        $status = is_string($data['status'] ?? null) ? strtolower($data['status']) : 'pending';

        return new PayoutResult(
            reference: $reference,
            status: $status,
        );
    }

    private function phoneNumber(Order $order): string
    {
        $digits = preg_replace('/\D+/', '', (string) $order->customer_phone_number) ?? '';

        if ($digits === '') {
            return '';
        }

        $country = strtolower((string) ($order->customer_phone_country ?? 'bj'));

        return match ($country) {
            'bj' => str_starts_with($digits, '229') ? $digits : '229'.$digits,
            'ci' => str_starts_with($digits, '225') ? $digits : '225'.$digits,
            'tg' => str_starts_with($digits, '228') ? $digits : '228'.$digits,
            default => $digits,
        };
    }

    private function operatorFor(Order $order): string
    {
        $configured = (string) config('services.velto.default_withdrawal_operator');

        if ($configured !== '') {
            return $configured;
        }

        return match (strtolower((string) ($order->customer_phone_country ?? 'bj'))) {
            'ci' => 'mtn_open_ci',
            'tg' => 'moov_tg',
            default => 'mtn_open',
        };
    }
}
