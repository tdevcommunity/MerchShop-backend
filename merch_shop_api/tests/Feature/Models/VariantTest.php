<?php

namespace Tests\Feature\Models;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_product_variants_table(): void
    {
        // Le plan de tracking attend product_variant_id : la table doit porter
        // ce nom-la, meme si la classe s'appelle Variant.
        $this->assertSame('product_variants', (new Variant)->getTable());
    }

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(Variant::factory()->create()->uuid));
    }

    public function test_it_belongs_to_a_product(): void
    {
        $product = Product::factory()->create();
        $variant = Variant::factory()->create(['product_id' => $product->id]);

        $this->assertTrue($product->is($variant->product));
    }

    public function test_it_returns_price_as_a_whole_number_of_francs(): void
    {
        $variant = Variant::factory()->create(['price' => 1999]);

        /*
         * Un entier, jamais une chaine ni un flottant. Le franc CFA n'a pas de
         * subdivision : la colonne ne peut pas representer de centimes, et le
         * retour n'a donc rien a normaliser.
         */
        $this->assertSame(1999, $variant->price);
        $this->assertIsInt($variant->price);
    }

    public function test_it_stores_a_price_exactly_as_given(): void
    {
        // Un grand prix reste exact : c'etait le risque du flottant, que
        // l'entier supprime.
        $variant = Variant::factory()->create(['price' => 9_999_999_999]);

        $this->assertSame(9_999_999_999, $variant->fresh()->price);
    }

    public function test_it_accepts_a_price_written_with_trailing_decimals(): void
    {
        // Un back-office saisit « 1999.00 » : la meme somme, donc le meme prix.
        $variant = Variant::factory()->create(['price' => '1999.00']);

        $this->assertSame(1999, $variant->fresh()->price);
    }

    public function test_it_casts_stock_and_status(): void
    {
        $variant = Variant::factory()->create(['stock' => 12, 'status' => CatalogStatus::ACTIVE]);

        $this->assertSame(12, $variant->stock);
        $this->assertSame(CatalogStatus::ACTIVE, $variant->status);
    }

    public function test_it_reports_availability(): void
    {
        $available = Variant::factory()->withStock(5)->create();
        $empty = Variant::factory()->outOfStock()->create();
        $withdrawn = Variant::factory()->inactive()->withStock(5)->create();

        $this->assertTrue($available->is_available);
        // Un article publie mais epuise n'est pas vendable...
        $this->assertFalse($empty->is_available);
        // ...ni un article retire de la vente, meme en stock.
        $this->assertFalse($withdrawn->is_available);
    }

    public function test_the_stock_column_defaults_to_zero(): void
    {
        // Insertion directe pour verifier la valeur par defaut du schema, que
        // la factory court-circuite toujours en fournissant un stock. Un
        // article cree sans stock ne doit pas s'afficher comme disponible.
        $product = Product::factory()->create();

        $id = DB::table('product_variants')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku' => 'ACC-000001',
            'name' => 'Sticker TDEV',
            'price' => 500,
            'status' => CatalogStatus::ACTIVE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Variant::find($id)->stock);
        $this->assertFalse(Variant::find($id)->is_available);
    }

    public function test_it_rejects_a_duplicate_sku(): void
    {
        Variant::factory()->create(['sku' => 'TSH-M-NOIR']);

        $this->expectException(UniqueConstraintViolationException::class);

        Variant::factory()->create(['sku' => 'TSH-M-NOIR']);
    }

    public function test_it_requires_a_product(): void
    {
        $this->expectException(QueryException::class);

        Variant::factory()->create(['product_id' => null]);
    }

    public function test_it_refuses_a_negative_stock(): void
    {
        // Garde-fou base : meme si la decrementation en transaction se
        // contredit, la base refuse d'acter une survente silencieuse.
        //
        // La colonne est unsigned, ce que PostgreSQL et MySQL appliquent mais
        // SQLite ignore : le test ne tourne donc que sur un SGBD qui controle
        // reellement la contrainte. Sous SQLite, la garantie reste assuree par
        // le service de commande (transaction + lockForUpdate), a construire au
        // chantier suivant.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite n applique pas les colonnes unsigned.');
        }

        $this->expectException(QueryException::class);

        Variant::factory()->create(['stock' => -1]);
    }

    public function test_it_filters_active_and_in_stock_variants(): void
    {
        $sellable = Variant::factory()->withStock(3)->create();
        $empty = Variant::factory()->outOfStock()->create();
        Variant::factory()->inactive()->withStock(9)->create();

        // Le scope catalogue ne filtre que la publication.
        $this->assertEqualsCanonicalizing(
            [$sellable->id, $empty->id],
            Variant::active()->pluck('id')->all()
        );
        // Le scope de vente ajoute la disponibilite reelle.
        $this->assertSame([$sellable->id], Variant::active()->inStock()->pluck('id')->all());
    }
}
