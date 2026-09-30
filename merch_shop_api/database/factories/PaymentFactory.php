<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'amount' => fake()->randomFloat(2, 5000, 50000),
            'method' => PaymentMethod::MOBILE_MONEY,
            'provider' => fake()->randomElement([
                PaymentProvider::FEDAPAY,
                PaymentProvider::KKIAPAY,
                PaymentProvider::PAYGATE,
                PaymentProvider::FLOOZ,
                PaymentProvider::TMONEY,
            ]),
            // Pas de transaction_id avant l'appel a l'operateur : la colonne
            // est nullable precisement pour couvrir cette fenetre.
            'transaction_id' => null,
            'status' => PaymentStatus::PENDING,
            'paid_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * Paiement confirme par l'operateur.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::SUCCESS,
            'transaction_id' => 'TXN-'.strtoupper(fake()->unique()->bothify('????????##')),
            'paid_at' => now(),
        ]);
    }

    /**
     * Paiement refuse, motif conserve pour le support.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::FAILED,
            'transaction_id' => 'TXN-'.strtoupper(fake()->unique()->bothify('????????##')),
            'paid_at' => null,
            'failure_reason' => fake()->randomElement([
                'Solde insuffisant',
                'Demande expiree',
                'Refus de l emetteur',
            ]),
        ]);
    }

    /**
     * Paiement d'un montant donne, pour une commande donnee.
     */
    public function forOrder(Order $order, float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->getKey(),
            'amount' => $amount,
        ]);
    }
}
