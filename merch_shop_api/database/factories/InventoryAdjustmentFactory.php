<?php

namespace Database\Factories;

use App\Enums\InventoryReason;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAdjustment>
 */
class InventoryAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previous = fake()->numberBetween(0, 40);

        return [
            'product_id' => Product::factory(),
            'product_variant_id' => Variant::factory(),
            'product_name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->bothify('??##-????')),
            'previous_stock' => $previous,

            /*
             * Le delta et l'etat suivant sont lies, jamais tires au hasard.
             *
             * Les trois valeurs sont la coherence que le journal garantit — et
             * qu'un test doit pouvoir verifier. Les produire independamment
             * donnerait des lignes ou `previous_stock + delta != next_stock`,
             * c'est-a-dire un journal qui ment sur la seule chose qu'il est
             * cense dire.
             */
            'delta' => $delta = fake()->numberBetween(-5, 20),
            'next_stock' => $previous + $delta,

            'reason' => fake()->randomElement(InventoryReason::cases()),
            'note' => fake()->optional(0.6)->sentence(),

            'user_id' => User::factory(),
            'user_email' => fake()->safeEmail(),
        ];
    }

    /**
     * Reception de merchandise : le stock passe de vide a plein.
     */
    public function reception(int $quantity = 10): static
    {
        return $this->state(fn (array $attributes): array => [
            'previous_stock' => 0,
            'delta' => $quantity,
            'next_stock' => $quantity,
            'reason' => InventoryReason::RECEPTION,
        ]);
    }

    /**
     * Piece abimee : le stock baisse d'une unite.
     *
     * Le stock de depart est celui deja tire par `definition()`, lu dans les
     * attributs plutot que regenere : le_retirer deux fois donnerait deux
     * nombres differents, et un ajustement dont le « avant » ne correspond pas a
     * sa propre ligne.
     */
    public function damaged(): static
    {
        return $this->state(fn (array $attributes): array => [
            'previous_stock' => $previous = max(1, $attributes['previous_stock'] ?? 1),
            'delta' => -1,
            'next_stock' => $previous - 1,
            'reason' => InventoryReason::DAMAGED,
        ]);
    }
}