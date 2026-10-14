<?php

namespace App\Support\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;

/**
 * Contraintes Velto en mode live (cles pk_live_* / sk_live_*).
 */
final class VeltoLiveRules
{
    public static function assertCallbackUrl(string $callbackUrl): void
    {
        if (VeltoEnvironment::isSandbox() || app()->environment('local')) {
            return;
        }

        if (str_starts_with(strtolower(ltrim($callbackUrl)), 'https://')) {
            return;
        }

        throw new ApiException(
            'En mode live Velto, PAYMENT_RETURN_URL doit etre une URL HTTPS publique (ex. https://shop.ourtdev.com/checkout/payment-return). http://localhost est refuse.',
            503,
            'VELTO_LIVE_HTTPS_CALLBACK_REQUIRED',
            [
                'provider' => PaymentProvider::VELTO->value,
                'callback_url' => $callbackUrl,
            ],
        );
    }
}
