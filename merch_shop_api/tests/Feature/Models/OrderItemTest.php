<?php

namespace Tests\Feature\Models;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(OrderItem::factory()->create()->uuid));
    }

    public function test_it_belongs_to_an_order_a_product_and_a_variant(): void
    {
        $product = Product::factory()->create();
        $variant = Variant::factory()->create(['product_id' => $product->id]);
        $item = OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
        ]);

        $this->assertTrue($item->order->is($item->order));
        $this->assertTrue($product->is($item->product));
        $this->assertTrue($variant->is($item->variant));
    }

    public function test_it_joins_orders_to_products_through_many_to_many(): void
    {
        // Relation plusieurs-a-plusieurs : la commande ne liste pas ses
        // produits directement, elle passe par ses lignes.
        $order = Order::factory()->create();
        $product = Product::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);

        $relation = $order->products();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertEqualsCanonicalizing(
            [$product->id],
            $order->products()->pluck('products.id')->all()
        );
    }

    public function test_it_accepts_a_null_variant(): void
    {
        // Un article sans declinaison (sticker, agenda) n'a pas de variante.
        $item = OrderItem::factory()->withoutVariant()->create();

        $this->assertNull($item->product_variant_id);
        $this->assertNull($item->variant);
    }

    public function test_it_casts_amounts_to_whole_francs(): void
    {
        $item = OrderItem::factory()->forQuantity(3, '1999')->create();

        $this->assertSame(3, $item->quantity);
        $this->assertSame(1999, $item->fresh()->unit_price);
        $this->assertSame(5997, $item->fresh()->total_price);
    }

    public function test_it_keeps_the_unit_price_frozen_against_a_catalog_price_change(): void
    {
        $variant = Variant::factory()->create(['price' => 5000]);
        $item = OrderItem::factory()->create([
            'product_variant_id' => $variant->id,
            'unit_price' => 5000,
        ]);

        $variant->update(['price' => 9000]);

        // Le prix de la ligne ne bouge pas : une promotion ulterieure ne doit
        // pas reecrire un passe deja emis.
        $this->assertSame(5000, $item->fresh()->unit_price);
    }

    public function test_it_keeps_the_product_name_snapshot(): void
    {
        $product = Product::factory()->create(['name' => 'T-shirt TDEV']);
        $item = OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_name' => 'T-shirt TDEV',
        ]);

        $product->update(['name' => 'T-shirt TDEV 2027']);

        // La facture doit rester fidele a ce qui a ete vendu, meme apres
        // renommage du produit.
        $this->assertSame('T-shirt TDEV', $item->fresh()->product_name);
    }

    public function test_it_rejects_a_null_order(): void
    {
        $this->expectException(QueryException::class);

        OrderItem::factory()->create(['order_id' => null]);
    }
}
