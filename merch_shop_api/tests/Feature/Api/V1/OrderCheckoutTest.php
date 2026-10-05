<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CatalogStatus;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PickupStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Support\Api\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le tunnel d'achat, verifie sur la base et non sur la reponse HTTP.
 *
 * Ces tests passent par le service pour observer ce qui est reellement ecrit,
 * notamment le stock, que la reponse JSON ne montre pas. Les regles d'acces et
 * les codes HTTP sont eux verifies dans OrderTest.
 */
class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    private Money $money;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = app(OrderService::class);
        $this->money = app(Money::class);
    }

    public function test_it_creates_an_order_and_reserves_the_stock(): void
    {
        $variant = Variant::factory()->withStock(10)->create(['price' => 2500]);

        $order = $this->checkout($variant, quantity: 3);

        $this->assertSame(7500, $order->sub_total);
        $this->assertSame(7500, $order->total);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame(PickupStatus::PENDING, $order->pickup_status);

        $this->assertSame(7, $variant->refresh()->stock, 'Le stock doit etre decremente des la creation.');
    }

    public function test_it_opens_a_pending_payment_for_the_order_total(): void
    {
        $variant = Variant::factory()->withStock(10)->create(['price' => 2500]);

        $order = $this->checkout($variant, quantity: 3);

        $payment = $order->payments()->first();

        $this->assertNotNull($payment, 'Une tentative de paiement doit exister des la creation.');
        $this->assertSame(PaymentStatus::PENDING, $payment->status);
        $this->assertSame(PaymentMethod::MOBILE_MONEY, $payment->method);
        $this->assertSame(7500, $payment->amount);
    }

    public function test_it_prices_from_the_catalog_and_ignores_any_price_sent_by_the_client(): void
    {
        /*
         * Le payload ne porte que l'identifiant et la quantite : il n'existe
         * aucun champ prix a falsifier. Ce test verifie le resultat, c'est-a-dire
         * que le montant facturé est bien celui de la base, enonces ici par le
         * prix du catalogue.
         */
        $variant = Variant::factory()->withStock(10)->create(['price' => 3000]);

        $order = $this->checkout($variant, quantity: 2);

        $this->assertSame(6000, $order->total);
    }

    public function test_it_freezes_the_product_and_variant_names_on_the_order_item(): void
    {
        $variant = Variant::factory()->withStock(5)->create([
            'name' => 'Taille M',
            'price' => 2500,
        ]);

        $order = $this->checkout($variant, quantity: 1);
        $item = $order->items->first();

        $this->assertSame('Taille M', $item->variant_name);

        /*
         * La facture doit rester fidele a ce qui a ete vendu. Un renommage du
         * catalogue ne doit pas reecrire l'historique.
         */
        $variant->update(['name' => 'Taille XL']);
        $variant->product->update(['name' => 'Pull renomme']);

        $this->assertSame('Taille M', $item->refresh()->variant_name);
        $this->assertNotSame('Pull renomme', $item->refresh()->product_name);
    }

    public function test_a_delivered_order_carries_no_shipping_fee(): void
    {
        /*
         * La livraison est gratuite : une commande livree coute exactement son
         * sous-total. Cette commande n'a pas de QR, donc elle ne peut pas etre
         * retiree au stand — ce qui est le seul effet de la livraison ici.
         */
        $variant = Variant::factory()->withStock(5)->create(['price' => 2500]);

        $order = $this->orders->create([
            'user' => null,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => FulfillmentMethod::DELIVERY,
            'shipping_address' => 'Rue des Palmiers, Lome',
            'payment_method' => PaymentMethod::CARD,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => null,
        ]);

        $this->assertSame(2500, $order->sub_total);
        $this->assertSame(2500, $order->total);
        $this->assertSame('Rue des Palmiers, Lome', $order->shipping_address);
        $this->assertNull($order->pickup_status, 'Une commande livree n\'a pas de droit de retrait a exercer.');
    }

    public function test_it_refuses_an_order_when_the_stock_is_insufficient(): void
    {
        $variant = Variant::factory()->withStock(2)->create(['price' => 2500]);

        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Stock insuffisant');

        $this->checkout($variant, quantity: 3);
    }

    public function test_it_leaves_the_stock_untouched_when_the_order_is_refused(): void
    {
        $variant = Variant::factory()->withStock(2)->create(['price' => 2500]);

        try {
            $this->checkout($variant, quantity: 3);
        } catch (ApiException) {
            // L'echec est attendu ; ce qui compte est l'etat du stock ensuite.
        }

        $this->assertSame(2, $variant->refresh()->stock, 'Un checkout refuse ne doit pas consommer de stock.');
    }

    public function test_it_refuses_a_variant_that_is_no_longer_sold(): void
    {
        $variant = Variant::factory()->inactive()->withStock(10)->create();

        $this->expectExceptionCode(422);

        $this->checkout($variant, quantity: 1);
    }

    public function test_it_refuses_a_variant_of_a_hidden_product(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $variant->product->update(['status' => CatalogStatus::INACTIVE]);

        $this->expectExceptionCode(422);

        $this->checkout($variant, quantity: 1);
    }

    public function test_it_refuses_an_unknown_variant(): void
    {
        $this->expectExceptionCode(422);

        $this->orders->create([
            'user' => null,
            'items' => [['uuid' => '00000000-0000-4000-8000-000000000000', 'quantity' => 1]],
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'shipping_address' => null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => null,
        ]);
    }

    public function test_it_merges_two_lines_referring_to_the_same_variant(): void
    {
        $variant = Variant::factory()->withStock(10)->create(['price' => 1000]);

        $order = $this->orders->create([
            'user' => null,
            'items' => [
                ['uuid' => $variant->uuid, 'quantity' => 2],
                ['uuid' => $variant->uuid, 'quantity' => 3],
            ],
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'shipping_address' => null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => null,
        ]);

        $this->assertCount(1, $order->items, 'Deux lignes du meme article doivent en former une seule.');
        $this->assertSame(5, $order->items->first()->quantity);
        $this->assertSame(5000, $order->total);
        $this->assertSame(5, $variant->refresh()->stock);
    }

    public function test_it_never_produces_a_total_that_does_not_balance(): void
    {
        /*
         * Trois articles a 9 999 999 999 : le cas ou un calcul en flottant
         * perdrait une unite. Le total doit etre exactement le sous-total, pas
         * une valeur approchee — et le rester meme apres passage en base.
         */
        $variant = Variant::factory()->withStock(10)->create(['price' => 9_999_999_999]);

        $order = $this->checkout($variant, quantity: 3);

        $this->assertSame(29_999_999_997, $order->fresh()->sub_total);
        $this->assertTrue($this->money->equals($order->total, $order->sub_total));
    }

    public function test_it_gives_a_guest_order_no_owner_but_a_public_reference(): void
    {
        $variant = Variant::factory()->withStock(5)->create();

        $order = $this->checkout($variant, quantity: 1);

        $this->assertNull($order->user_id, 'Une commande invitee n\'a pas de compte.');
        $this->assertNotNull($order->uuid);
        $this->assertStringStartsWith('TDEV-', $order->order_number);
    }

    public function test_it_gives_two_orders_two_different_numbers(): void
    {
        $variant = Variant::factory()->withStock(20)->create();

        $first = $this->checkout($variant, quantity: 1);
        $second = $this->checkout($variant, quantity: 1);

        $this->assertNotSame($first->order_number, $second->order_number);
    }

    public function test_it_attaches_the_order_to_the_authenticated_customer(): void
    {
        $customer = User::factory()->create();
        $variant = Variant::factory()->withStock(5)->create();

        $order = $this->orders->create([
            'user' => $customer,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'shipping_address' => null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => null,
        ]);

        $this->assertSame($customer->id, $order->user_id);
    }

    public function test_it_keeps_the_participant_reference_of_a_pickup_order(): void
    {
        $variant = Variant::factory()->withStock(5)->create();

        $order = $this->orders->create([
            'user' => null,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'shipping_address' => null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => 'PART-2026-0042',
        ]);

        $this->assertSame('PART-2026-0042', $order->participant_id);
    }

    /**
     * Passe une commande de retrait sans compte, avec les valeurs par defaut.
     */
    private function checkout(Variant $variant, int $quantity): Order
    {
        return $this->orders->create([
            'user' => null,
            'items' => [['uuid' => $variant->uuid, 'quantity' => $quantity]],
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'shipping_address' => null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => null,
        ]);
    }
}
