<?php

namespace App\Support\Payments;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * Verification de l'origine d'une notification Velto.
 *
 * Velto signe le corps brut avec HMAC SHA-256 et expose le resultat dans
 * l'en-tete X-Velto-Signature.
 */
final class VeltoSignatureVerifier
{
    public const HEADER = 'X-Velto-Signature';

    public function verify(Request $request): void
    {
        $secret = (string) config('orders.webhooks.velto.secret');

        if ($secret === '') {
            throw new ApiException(
                'Aucun secret configure pour cet agregateur de paiement.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => 'velto'],
            );
        }

        $provided = $request->header(self::HEADER);

        if (! is_string($provided) || trim($provided) === '') {
            throw new ApiException(
                'Signature de notification invalide.',
                401,
                'INVALID_WEBHOOK_SIGNATURE',
            );
        }

        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals(strtolower($expected), strtolower(trim($provided)))) {
            throw new ApiException(
                'Signature de notification invalide.',
                401,
                'INVALID_WEBHOOK_SIGNATURE',
            );
        }
    }
}
