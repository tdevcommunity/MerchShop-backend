<?php

namespace Tests\Doubles;

use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Services\Payments\PayoutGateway;
use App\Services\Payments\PayoutResult;

/**
 * Une passerelle de depot qui n'appelle personne.
 *
 * Le double existe pour une raison qui n'admet pas d'exception : un test qui
 * appellerait le vrai operateur sortirait de l'argent a chaque execution, et
 * l'oubli serait irreversible. Aucun test de ce depot ne doit pouvoir
 * reellement payer quelqu'un.
 *
 * Il memorise ce qui lui a ete demande, pour que les tests puissent affirmer
 * que l'argent part vers le numero de la commande et vers aucun autre.
 */
final class FakePayoutGateway implements PayoutGateway
{
    /** Reference globale du prochain depot simule. */
    private static int $nextReference = 9001;

    /** Nombre d'appels recus. */
    public int $calls = 0;

    /** Numero de destination demande. */
    public ?string $sentPhone = null;

    /** Code pays demande. */
    public ?string $sentCountry = null;

    /** Dernier statut renvoye par l'operateur simule. */
    public ?string $lastStatus = null;

    public function __construct(
        private readonly string $status = 'pending',
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::FEDAPAY;
    }

    public function payOut(Order $order): PayoutResult
    {
        $this->calls++;

        $this->sentPhone = $order->customer_phone_number;
        $this->sentCountry = $order->customer_phone_country;
        $this->lastStatus = $this->status;

        return new PayoutResult(
            reference: 'PAYOUT-'.self::$nextReference++,
            status: $this->status,
        );
    }
}
