<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            // Le SKU est la reference vente au guichet : unique en base, donc
            // unique() sur le generateur pour eviter les collisions en lot.
            'sku' => strtoupper(Str()->random(3)).'-'.fake()->unique()->numerify('######'),
            'name' => fake()->randomElement([
                'Taille S',
                'Taille M',
                'Taille L',
                'Taille XL',
                'Couleur Noir',
                'Couleur Blanc',
            ]),
            // Bornes credibles pour un article de festival.
            // Prix en francs CFA, donc entier : voir le type de la colonne.
            'price' => fake()->numberBetween(2000, 15000),
            'stock' => fake()->numberBetween(0, 200),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    /**
     * Variante retiree de la vente.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogStatus::INACTIVE,
        ]);
    }

    /**
     * Variante publiee mais epuisee.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => 0,
        ]);
    }

    /**
     * Variante disposant d'un stock donne.
     */
    public function withStock(int $stock): static
    {
        return $this->state(fn (array $attributes) => [
            'stock' => $stock,
        ]);
    }
}
