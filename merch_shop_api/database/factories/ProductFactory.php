<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'description' => fake()->sentence(),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    /**
     * Produit retire du catalogue.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogStatus::INACTIVE,
        ]);
    }
}
