<?php

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
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
            'name' => $name,
            'description' => fake()->sentence(),
            // Le slug est unique en base : on le derive du nom pour qu'il reste
            // lisible, et unique() evite les collisions entre creations.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => CatalogStatus::ACTIVE,
        ];
    }

    /**
     * Categorie retiree du catalogue.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogStatus::INACTIVE,
        ]);
    }
}
