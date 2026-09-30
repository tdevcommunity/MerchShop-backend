<?php

namespace Tests\Feature\Models;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $category = Category::factory()->create();

        $this->assertTrue(Str::isUuid($category->uuid));
    }

    public function test_it_routes_by_uuid(): void
    {
        $category = Category::factory()->create();

        $this->assertSame('uuid', $category->getRouteKeyName());
        $this->assertTrue($category->is($category->fresh()->resolveRouteBinding($category->uuid)));
    }

    public function test_it_casts_status_to_enum(): void
    {
        $category = Category::factory()->create(['status' => CatalogStatus::INACTIVE]);

        $this->assertInstanceOf(CatalogStatus::class, $category->status);
        $this->assertSame(CatalogStatus::INACTIVE, $category->status);
    }

    public function test_it_has_many_products(): void
    {
        $category = Category::factory()->create();
        $products = Product::factory()->count(3)->create(['category_id' => $category->id]);

        $this->assertCount(3, $category->products);
        $this->assertEqualsCanonicalizing(
            $products->pluck('id')->all(),
            $category->products->pluck('id')->all()
        );
    }

    public function test_it_soft_deletes_without_losing_products(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $category->delete();

        $this->assertSoftDeleted($category);
        $this->assertNull(Category::find($category->id));
        // La relation passe par le produit, pas par la categorie : le produit
        // reste adressable et la commande historique reste lisible.
        $this->assertDatabaseHas('products', ['category_id' => $category->id]);
    }

    public function test_it_rejects_a_duplicate_slug(): void
    {
        $slug = 'textile';
        Category::factory()->create(['slug' => $slug]);

        $this->expectException(UniqueConstraintViolationException::class);

        Category::factory()->create(['slug' => $slug]);
    }

    public function test_it_reserves_a_slug_even_for_a_soft_deleted_category(): void
    {
        // Une URL de catalogue ne doit pas pouvoir designer deux elements a la
        // fois, meme si l'un est archive.
        Category::factory()->create(['slug' => 'textile'])->delete();

        $this->expectException(UniqueConstraintViolationException::class);

        Category::factory()->create(['slug' => 'textile']);
    }

    public function test_it_filters_active_categories(): void
    {
        $active = Category::factory()->create();
        Category::factory()->inactive()->create();

        $this->assertEqualsCanonicalizing(
            [$active->id],
            Category::active()->pluck('id')->all()
        );
    }
}
