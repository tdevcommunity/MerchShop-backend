<?php

namespace Database\Factories;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PickupStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subTotal = fake()->randomFloat(2, 5000, 50000);

        return [
            // Reference lisible au guichet et unique en base.
            'order_number' => 'TDEV-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('??##??##')),
            'user_id' => User::factory(),

            /*
             * Identite de l'acheteur, obligatoire sur toute commande.
             *
             * La factory en produit une par defaut plutot que de la laisser
             * nulle : une commande sans nom ni numero ne serait pas valide en
             * production, et une factory qui produit des etats impossibles cache
             * les tests qui comptaient dessus.
             */
            'customer_name' => fake()->lastName().' '.fake()->firstName(),
            'customer_phone_number' => '0'.fake()->numerify('########'),
            'customer_phone_country' => 'ci',
            'fedapay_customer_id' => null,
            'sub_total' => $subTotal,
            'shipping_address' => null,
            'discount' => 0,
            // Total coherent avec le sous-total : la factory ne doit pas
            // produire de commande dont les comptes ne tiennent pas.
            'total' => $subTotal,
            'status' => OrderStatus::PENDING_PAYMENT,
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'pickup_status' => PickupStatus::PENDING,
            'pickup_time' => null,
            'participant_id' => null,
        ];
    }

    /**
     * Commande invitee, sans compte rattache.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    /**
     * Commande en attente de paiement.
     */
    public function pendingPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PENDING_PAYMENT,
        ]);
    }

    /**
     * Commande payee, prete a etre servie au stand.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::READY_FOR_PICKUP,
            'pickup_status' => PickupStatus::PENDING,
        ]);
    }

    /**
     * Commande effectivement retiree.
     */
    public function pickedUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::PICKED_UP,
            'pickup_status' => PickupStatus::PICKED_UP,
            'pickup_time' => now(),
        ]);
    }

    /**
     * Commande annulee.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::CANCELLED,
        ]);
    }

    /**
     * Commande livree a une adresse, sans QR de retrait.
     */
    public function forDelivery(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'fulfillment_method' => FulfillmentMethod::DELIVERY,
                'shipping_address' => fake()->address(),
                'pickup_status' => null,
            ];
        });
    }
}
