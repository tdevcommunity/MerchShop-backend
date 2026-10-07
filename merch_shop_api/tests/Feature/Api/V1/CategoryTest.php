<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CRUD des categories.
 *
 * Le point de controle principal est l'ecriture, reservee au role
 * administrateur : c'est la seule frontiere de securite de cet ensemble de
 * routes, et elle se verifie par le role refuse comme par le role accepte.
 */
class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_is_public(): void
    {
        Category::factory()->count(2)->create();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['uuid', 'name', 'description', 'slug', 'status', 'productsCount']],
                // Les cles de pagination sont normalisees en camelCase comme
                // le reste de la charge utile : current_page devient
                // currentPage. C'est le contrat du fil, pas une exception.
                'meta' => ['currentPage', 'perPage', 'total'],
            ]);
    }

    public function test_list_hides_inactive_categories(): void
    {
        Category::factory()->inactive()->create(['name' => 'Archive 2025']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_is_paginated(): void
    {
        Category::factory()->count(5)->create();

        $this->getJson('/api/v1/categories?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.perPage', 2);
    }

    public function test_per_page_is_capped(): void
    {
        // Sans plafond, per_page=100000 materialise toute la table en memoire.
        config(['api.pagination.max_per_page' => 10]);

        $this->getJson('/api/v1/categories?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.perPage', 10);
    }

    public function test_show_is_public(): void
    {
        $category = Category::factory()->create(['name' => 'T-shirts']);

        $this->getJson('/api/v1/categories/'.$category->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $category->uuid)
            ->assertJsonPath('data.name', 'T-shirts');
    }

    public function test_show_returns_404_for_an_unknown_category(): void
    {
        $this->getJson('/api/v1/categories/00000000-0000-4000-8000-000000000000')
            ->assertNotFound()
            // Code specifique a la ressource plutot que le NOT_FOUND
            // generique : un front qui affiche « categorie introuvable » ne
            // doit pas avoir a deviner de quel type de ressource il s'agit.
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    }

    public function test_admin_can_create_a_category(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-shirts'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'T-shirts');

        $this->assertDatabaseHas('categories', ['name' => 'T-shirts']);
    }

    public function test_creation_derives_the_slug_from_the_name(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-Shirts'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 't-shirts');
    }

    public function test_creation_accepts_an_explicit_slug(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-shirts', 'slug' => 'tees'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'tees');
    }

    public function test_creation_suffixes_a_duplicated_derived_slug(): void
    {
        $admin = User::factory()->admin()->create();

        // Deux categories au meme nom ne doivent pas se concurrencer : le slug
        // derive est suffixe plutot que de refuser la creation.
        $this->actingAs($admin)->postJson('/api/v1/categories', ['name' => 'Casquettes'])
            ->assertCreated()->assertJsonPath('data.slug', 'casquettes');

        $this->actingAs($admin)->postJson('/api/v1/categories', ['name' => 'Casquettes'])
            ->assertCreated()->assertJsonPath('data.slug', 'casquettes-2');
    }

    public function test_creation_refuses_an_explicitly_requested_taken_slug(): void
    {
        Category::factory()->create(['slug' => 'tees']);

        // Un slug choisi explicitement l'a ete pour une raison metier : le
        // suffixer en silence detruirait cette raison.
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/categories', ['name' => 'Autre', 'slug' => 'tees'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLUG_ALREADY_EXISTS');
    }

    public function test_creation_rejects_a_missing_name(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/categories', [])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['name']]]]);
    }

    public function test_creation_rejects_an_unknown_status(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-shirts', 'status' => 99])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
    }

    public function test_creation_requires_a_session(): void
    {
        $this->postJson('/api/v1/categories', ['name' => 'T-shirts'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_a_customer_cannot_create_a_category(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-shirts'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseMissing('categories', ['name' => 'T-shirts']);
    }

    public function test_staff_cannot_create_a_category(): void
    {
        // Le role staff sert au guichet et au scan. Lui donner le catalogue
        // reviendrait a lui laisser modifier ce que voient tous les visiteurs.
        $this->actingAs(User::factory()->staff()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-shirts'])
            ->assertForbidden();

        $this->assertDatabaseMissing('categories', ['name' => 'T-shirts']);
    }

    public function test_admin_can_update_a_category(): void
    {
        $category = Category::factory()->create(['name' => 'Ancien nom']);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/categories/'.$category->uuid, ['name' => 'Nouveau nom'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau nom');
    }

    public function test_update_keeps_the_slug_when_only_the_name_is_not_given(): void
    {
        $category = Category::factory()->create(['name' => 'T-shirts', 'slug' => 'tees']);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/categories/'.$category->uuid, ['description' => 'Description'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'tees');
    }

    public function test_update_allows_hiding_a_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/categories/'.$category->uuid, ['status' => CatalogStatus::INACTIVE->value])
            ->assertOk()
            ->assertJsonPath('data.status', CatalogStatus::INACTIVE->value);
    }

    public function test_a_customer_cannot_update_a_category(): void
    {
        $category = Category::factory()->create(['name' => 'T-shirts']);

        $this->actingAs(User::factory()->create())
            ->putJson('/api/v1/categories/'.$category->uuid, ['name' => 'Piraté'])
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['uuid' => $category->uuid, 'name' => 'T-shirts']);
    }

    public function test_admin_can_delete_an_empty_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/categories/'.$category->uuid)
            ->assertOk();

        $this->assertSoftDeleted('categories', ['uuid' => $category->uuid]);
    }

    public function test_deletion_is_soft(): void
    {
        // Le produit historique d'une categorie doit rester consultable : la
        // suppression logique preserve les commandes passees.
        $category = Category::factory()->create();
        $categoryId = $category->id;

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/categories/'.$category->uuid)
            ->assertOk();

        $this->assertNotNull(
            $this->app['db']->table('categories')->where('id', $categoryId)->first(),
            'La ligne doit rester en base, seule deleted_at doit etre renseigne.',
        );
    }

    public function test_a_masked_category_is_hidden_from_the_public_reads(): void
    {
        $category = Category::factory()->inactive()->create();

        // La liste ne la montre pas ; son URL doit repondre 404 egalement.
        $this->getJson('/api/v1/categories/'.$category->uuid)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'CATEGORY_NOT_FOUND');
    }

    public function test_a_masked_category_can_still_be_republished(): void
    {
        $category = Category::factory()->inactive()->create();

        // Une categorie masquee doit rester atteignable depuis le back-office,
        // sinon la republieration serait impossible.
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/categories/'.$category->uuid, ['status' => CatalogStatus::ACTIVE->value])
            ->assertOk()
            ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);

        $this->getJson('/api/v1/categories/'.$category->uuid)->assertOk();
    }

    public function test_deletion_is_refused_while_active_products_remain(): void
    {
        $category = Category::factory()->create();
        Product::factory()->count(2)->create(['category_id' => $category->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/categories/'.$category->uuid)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CATEGORY_NOT_EMPTY')
            ->assertJsonPath('error.details.activeProductsCount', 2);

        $this->assertDatabaseHas('categories', ['uuid' => $category->uuid, 'deleted_at' => null]);
    }

    public function test_deletion_is_allowed_once_products_are_archived(): void
    {
        $category = Category::factory()->create();
        Product::factory()->inactive()->create(['category_id' => $category->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson('/api/v1/categories/'.$category->uuid)
            ->assertOk();
    }

    public function test_a_customer_cannot_delete_a_category(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create())
            ->deleteJson('/api/v1/categories/'.$category->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['uuid' => $category->uuid, 'deleted_at' => null]);
    }

    public function test_an_inactive_admin_cannot_write(): void
    {
        // Un administrateur desactive ne doit pas conserver son pouvoir
        // d'ecriture tant que sa session est ouverte.
        $this->actingAs(User::factory()->admin()->inactive()->create())
            ->postJson('/api/v1/categories', ['name' => 'T-shirts'])
            ->assertForbidden();
    }

    public function test_counters_are_loaded_without_an_extra_query_per_row(): void
    {
        Category::factory()->count(3)->create();
        $category = Category::factory()->create();
        Product::factory()->count(2)->create(['category_id' => $category->id]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/v1/categories')->assertOk();

        // Le compte par categorie est resolu par un withCount pose sur la
        // requete. Sans lui, la ressource emettrait une requete par ligne et
        // la page deviendrait quadratique.
        $this->assertLessThanOrEqual(2, $queries, 'Trop de requetes : le compteur de produits n\'est pas agrege.');
    }
}
