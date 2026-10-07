<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // La variante est attachee au produit de la ligne : sans cela, la
        // ligne pointerait vers un produit A et une variante d'un produit B, ce
        // qui produirait des donnees de test absurdes.
        $product = Product::factory();

        $unitPrice = fake()->randomFloat(2, 2000, 15000);
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'product_id' => $product,
            'product_variant_id' => Variant::factory()->for($product, 'product'),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            // Coherent avec le prix unitaire : c'est le service de commande
            // qui recalculera, mais la factory ne doit pas lier le depart.
            'total_price' => $unitPrice * $quantity,
            'product_name' => fake()->catchPhrase(),
            'variant_name' => 'Taille M',
        ];
    }

    /**
     * Ligne portant une quantite et un prix unitaire donnes.
     */
    public function forQuantity(int $quantity, float $unitPrice): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $quantity * $unitPrice,
        ]);
    }

    /**
     * Ligne sans variante, pour un article sans declinaison (sticker, agenda).
     */
    public function withoutVariant(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_variant_id' => null,
            'variant_name' => null,
        ]);
    }
}
