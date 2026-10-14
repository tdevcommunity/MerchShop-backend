<?php

namespace App\Support\Payments;

use App\Models\Order;

/**
 * Payload client envoye a Velto / FedaPay pour le checkout heberge.
 */
final class VeltoGatewayCustomer
{
    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order): array
    {
        $customer = [
            'name' => (string) $order->customer_name,
            'email' => VeltoCheckoutEmail::forOrder($order),
        ];

        $checkoutCountry = self::checkoutPhoneCountry();
        if ($checkoutCountry !== null) {
            /*
             * Pays par defaut sur le portail MM (sandbox : Benin pour Momo Test).
             * On n'envoie jamais le numero de contact togolais : il servirait au
             * suivi commande, pas au MM, et pre-remplirait +228 chez FedaPay.
             */
            $customer['phone_number'] = [
                'country' => $checkoutCountry,
            ];
        }

        return $customer;
    }

    private static function checkoutPhoneCountry(): ?string
    {
        if (! self::shouldApplyCheckoutPhoneCountry()) {
            return null;
        }

        $configured = config('services.velto.checkout_phone_country');
        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return strtolower(trim($configured));
    }

    private static function shouldApplyCheckoutPhoneCountry(): bool
    {
        if (! config('services.velto.checkout_phone_country_in_sandbox_only', true)) {
            return true;
        }

        return VeltoEnvironment::isSandbox();
    }
}
