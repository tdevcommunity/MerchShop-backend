<?php

namespace Tests\Doubles;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentIntent;
use Throwable;

/**
 * Prestataire de paiement de test.
 *
 * Il remplace FedaPay sans appel reseau et compte ses appels, ce qui est la seule
 * facon de verifier l'idempotence du checkout : une URL de paiement rendue deux
 * fois n'a de sens que si l'operateur n'a ete sollicite qu'une fois.
 *
 * Il enregistre aussi ce qu'il a recu, pour que les tests puissent affirmer que
 * le montant envoye est celui de la commande — le montant ne se deduit pas du
 * client, et c'est la seule maniere de le prouver.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** Nombre d'appels a `initiate`. */
    public int $calls = 0;

    /** Un appel par `initiate`, dans l'ordre. */
    public array $initiations = [];

    /** Erreur a lever au prochain appel, pour tester l'echec du prestataire. */
    public ?Throwable $failure = null;

    public function provider(): PaymentProvider
    {
        return PaymentProvider::FEDAPAY;
    }

    public function initiate(Payment $payment, string $callbackUrl): PaymentIntent
    {
        $this->calls++;

        $this->initiations[] = [
            'payment' => $payment->uuid,
            'amount' => $payment->amount,
            'callback_url' => $callbackUrl,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new PaymentIntent(
            reference: $payment->uuid,
            transactionId: 'TXN-'.$this->calls,
            checkoutUrl: 'https://paiement.exemple.test/'.$this->calls,
            token: 'jeton-'.$this->calls,
        );
    }
}
