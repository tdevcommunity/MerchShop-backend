<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
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
            'invoice_number' => 'FACT-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('??##??##')),
            // Montants a zero par defaut : ils n'ont de sens que rapportes a
            // une commande. Utiliser forOrder() pour des montants coherents.
            'sub_total' => 0,
            'discount' => 0,
            'total' => 0,
            'issued_at' => now(),
        ];
    }

    /**
     * Facture d'une commande donnee, montants alignes sur elle.
     *
     * Une facture qui ne recapitule pas sa commande est le piege classique de
     * ces tests, et la base ne peut pas le detecter : le rapprochement est
     * donc fait ici.
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->getKey(),
            'sub_total' => $order->sub_total,
            'discount' => $order->discount,
            'total' => $order->total,
            'issued_at' => $order->created_at ?? now(),
        ]);
    }
}
