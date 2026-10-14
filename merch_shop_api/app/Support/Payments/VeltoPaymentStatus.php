<?php

namespace App\Support\Payments;

use App\Enums\PaymentStatus;

final class VeltoPaymentStatus
{
    /** @var array<string, PaymentStatus> */
    private const SETTLED = [
        'APPROVED' => PaymentStatus::SUCCESS,
    ];

    /** @var array<string, PaymentStatus> */
    private const UNSETTLED = [
        'DECLINED' => PaymentStatus::FAILED,
        'CANCELLED' => PaymentStatus::FAILED,
        'CANCELED' => PaymentStatus::FAILED,
        'EXPIRED' => PaymentStatus::FAILED,
        'FAILED' => PaymentStatus::FAILED,
        'REJECTED' => PaymentStatus::FAILED,
    ];

    public static function fromOperator(string $status): ?PaymentStatus
    {
        $normalized = strtoupper(trim($status));

        if (isset(self::SETTLED[$normalized])) {
            return self::SETTLED[$normalized];
        }

        if (isset(self::UNSETTLED[$normalized])) {
            return self::UNSETTLED[$normalized];
        }

        if (in_array($normalized, ['PENDING', 'PROCESSING'], true)) {
            return null;
        }

        return null;
    }
}
