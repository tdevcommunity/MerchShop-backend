<?php

namespace Tests\Feature\Models;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(Product::factory()->create()->uuid));
    }

    public function test_it_belongs_to_a_category(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->assertTrue($category->is($product->category));
    }

    public function test_it_has_many_variants(): void
    {
        $product = Product::factory()->create();
        $variants = Variant::factory()->count(2)->create(['product_id' => $product->id]);

        $this->assertCount(2, $product->variants);
        $this->assertEqualsCanonicalizing(
            $variants->pluck('id')->all(),
            $product->variants->pluck('id')->all()
        );
    }

    public function test_it_casts_status_to_enum(): void
    {
        $product = Product::factory()->create(['status' => CatalogStatus::INACTIVE]);

        $this->assertSame(CatalogStatus::INACTIVE, $product->status);
    }

    public function test_it_requires_a_category(): void
    {
        $this->expectException(QueryException::class);

        Product::factory()->create(['category_id' => null]);
    }

    public function test_it_blocks_deletion_of_a_category_still_holding_products(): void
    {
        // restrictOnDelete : sans cela, supprimer une categorie effacerait ses
        // produits et les lignes de commande qui les referencent.
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $this->expectException(QueryException::class);

        $category->forceDelete();
    }

    public function test_it_rejects_a_duplicate_slug(): void
    {
        Product::factory()->create(['slug' => 'tshirt-tdev']);

        $this->expectException(UniqueConstraintViolationException::class);

        Product::factory()->create(['slug' => 'tshirt-tdev']);
    }

    public function test_it_soft_deletes_a_product_with_its_variants(): void
    {
        $product = Product::factory()->create();
        $variant = Variant::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertNull(Product::find($product->id));
        // La variante reste : une commande ancienne doit pouvoir etre servie
        // et auditee meme apres la sortie du produit du catalogue.
        $this->assertNotNull(Variant::find($variant->id));
    }
}
