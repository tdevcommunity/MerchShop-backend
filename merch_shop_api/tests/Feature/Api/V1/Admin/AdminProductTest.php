<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le catalogue vu de la gestion.
 *
 * Ces tests fixent la raison d'etre de l'ecran de gestion : un produit qu'on
 * vient de creer doit rester visible, meme s'il n'est pas encore publie. La
 * lecture publique (`/api/v1/products`) ne rend que les actifs — c'est correct
 * pour la vitrine et inutilisable pour le back-office — donc l'acces de gestion
 * doit rendre tous les statuts.
 *
 * Le test le plus important est le premier : il compare les deux lectures sur
 * le meme produit, parce que la regression a corriger etait precisement un
 * ecran de gestion branche sur la lecture publique.
 */
final class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_admin_list_shows_products_the_public_catalog_hides(): void
    {
        Product::factory()->create(['name' => 'A Actif']);
        Product::factory()->inactive()->create(['name' => 'Z Brouillon']);

        // La vitrine ne montre que ce qui est en vente.
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonFragment(['name' => 'A Actif'])
            ->assertJsonMissing(['name' => 'Z Brouillon']);

        // La gestion montre le brouillon en plus, sans retirer l'actif.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products')
            ->assertOk()
            ->assertJsonFragment(['name' => 'A Actif'])
            ->assertJsonFragment(['name' => 'Z Brouillon']);
    }

    public function test_the_list_is_paginated(): void
    {
        Product::factory()->count(7)->create();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.perPage', 3)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.lastPage', 3);
    }

    public function test_it_searches_by_variant_sku(): void
    {
        $product = Product::factory()->create(['name' => 'Stylo bille']);
        Variant::factory()->create(['product_id' => $product->id, 'sku' => 'STYLO-BLEU-01']);
        Product::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products?q=STYLO-BLEU-01')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Stylo bille'], $names);
    }

    public function test_it_filters_by_status(): void
    {
        Product::factory()->create(['name' => 'En ligne']);
        Product::factory()->inactive()->create(['name' => 'Retire']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products?status=0')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Retire'], $names);
    }

    public function test_it_filters_by_category_slug(): void
    {
        $category = Category::factory()->create(['slug' => 'pens']);
        Product::factory()->create(['name' => 'Stylo', 'category_id' => $category->id]);
        Product::factory()->create(['name' => 'Mug']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products?category=pens')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Stylo'], $names);
    }

    public function test_an_unknown_status_filter_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products?status=9')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_FILTER');
    }

    public function test_the_show_opens_an_inactive_product_that_the_public_route_hides(): void
    {
        $product = Product::factory()->inactive()->create();

        // Le shop renvoie 404 sur un produit masque : c'est le retrait.
        $this->getJson('/api/v1/products/'.$product->uuid)->assertNotFound();

        // Le back-office doit pouvoir rouvrir sa fiche pour le republier.
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $product->uuid)
            ->assertJsonPath('data.status', 0);
    }
}
