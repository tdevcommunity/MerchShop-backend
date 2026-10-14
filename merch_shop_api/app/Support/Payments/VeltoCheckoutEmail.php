<?php

namespace App\Support\Payments;

use App\Models\Order;

/**
 * Velto exige un email client (string).
 */
final class VeltoCheckoutEmail
{
    public static function forOrder(Order $order): string
    {
        $contact = is_string($order->customer_email) ? trim($order->customer_email) : '';
        if ($contact !== '' && filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            if (self::shouldUsePlusTaggedEmail()) {
                return self::plusTaggedEmail($contact, $order);
            }

            return $contact;
        }

        /*
         * Sans email au checkout : adresse technique par commande pour satisfaire
         * l'API Velto sans inventer une boite personnelle.
         */
        $domain = (string) config(
            'orders.velto_guest_email_domain',
            'checkout.guest.ourtdev.com',
        );
        $local = strtolower((string) preg_replace('/[^a-z0-9]+/i', '.', (string) $order->order_number));
        $local = trim($local, '.');

        if ($local === '') {
            $local = 'order';
        }

        return $local.'@'.$domain;
    }

    /**
     * Evite qu'un meme email reutilise un profil FedaPay (ex. +228 memorise).
     * Le reçu part toujours vers la boite reelle si le fournisseur accepte les +tags.
     */
    private static function shouldUsePlusTaggedEmail(): bool
    {
        if (! config('services.velto.sandbox_unique_customer_email', true)) {
            return false;
        }

        return VeltoEnvironment::isSandbox();
    }

    private static function plusTaggedEmail(string $email, Order $order): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }

        [$local, $domain] = $parts;
        $tag = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) $order->order_number));
        if ($tag === '') {
            $tag = 'order';
        }

        return $local.'+'.$tag.'@'.$domain;
    }
}
