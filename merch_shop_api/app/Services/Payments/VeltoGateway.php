<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Support\Api\Money;
use App\Support\Payments\VeltoClient;
use App\Support\Payments\VeltoGatewayCustomer;
use App\Support\Payments\VeltoLiveRules;

/**
 * Velto : ouverture d'un paiement Mobile Money / carte via checkout heberge.
 */
final class VeltoGateway implements PaymentGateway
{
    public function __construct(
        private readonly Money $money,
        private readonly VeltoClient $client,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::VELTO;
    }

    public function initiate(Payment $payment, string $callbackUrl): PaymentIntent
    {
        VeltoLiveRules::assertCallbackUrl($callbackUrl);

        $order = $payment->order;
        $amount = $this->money->toAmount($payment->amount);

        /*
         * Pas de telephone ici : celui du checkout sert au contact admin / livraison.
         * Le numero Mobile Money est saisi sur la page hebergee Velto / FedaPay
         * (ex. sandbox Momo Test avec indicatif Benin).
         */
        $data = $this->client->createPayment([
            'amount' => $amount,
            'currency' => $order->currency,
            'description' => 'Commande '.$order->order_number,
            'callback_url' => $callbackUrl,
            'external_reference' => $payment->uuid,
            'customer' => VeltoGatewayCustomer::forOrder($order),
            'metadata' => [
                'payment_uuid' => $payment->uuid,
                'order_uuid' => $order->uuid,
                'order_number' => $order->order_number,
                'payment_method' => $payment->method?->value,
            ],
        ]);

        $checkoutUrl = is_string($data['checkout_url'] ?? null) ? $data['checkout_url'] : null;
        $transactionId = is_string($data['id'] ?? null) ? $data['id'] : null;

        if ($checkoutUrl === null || $checkoutUrl === '' || $transactionId === null || $transactionId === '') {
            throw new ApiException(
                'Le prestataire de paiement a renvoye un lien de paiement invalide.',
                502,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::VELTO->value],
            );
        }

        return new PaymentIntent(
            reference: $payment->uuid,
            transactionId: $transactionId,
            checkoutUrl: $checkoutUrl,
            token: $transactionId,
        );
    }

}
