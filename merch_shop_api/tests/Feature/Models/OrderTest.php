<?php

namespace Tests\Feature\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PickupStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(Order::factory()->create()->uuid));
    }

    public function test_it_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->is($order->user));
    }

    public function test_it_accepts_a_guest_order(): void
    {
        // Commande invitee : la cle etrangere est nullable, la relation rend
        // simplement null au lieu d'echouer.
        $order = Order::factory()->guest()->create();

        $this->assertNull($order->user_id);
        $this->assertNull($order->user);
    }

    public function test_it_casts_amounts_to_whole_francs(): void
    {
        $order = Order::factory()->create([
            'sub_total' => 12_500,
            'discount' => 500,
            'total' => 12_000,
        ]);

        // Des entiers : le franc CFA n'a pas de subdivision, donc un montant ne
        // peut pas se relire avec deux decimales qui n'existent pas.
        $this->assertSame(12_500, $order->sub_total);
        $this->assertSame(500, $order->discount);
        $this->assertSame(12_000, $order->total);
    }

    public function test_it_casts_status_and_fulfillment_to_enums(): void
    {
        $order = Order::factory()->forDelivery()->create();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame(FulfillmentMethod::DELIVERY, $order->fulfillment_method);
    }

    public function test_it_casts_pickup_time_to_datetime(): void
    {
        $order = Order::factory()->pickedUp()->create();

        $this->assertInstanceOf(Carbon::class, $order->pickup_time);
        $this->assertSame(PickupStatus::PICKED_UP, $order->pickup_status);
    }

    public function test_it_distinguishes_the_pickup_right_from_the_pickup_use(): void
    {
        // Regle du plan de tracking : une commande payee a le droit d'etre
        // servie, mais ne l'a pas encore utilise. Les deux faits ne doivent
        // jamais tenir dans le meme champ.
        $paid = Order::factory()->paid()->create();
        $pickedUp = Order::factory()->pickedUp()->create();

        $this->assertSame(PickupStatus::PENDING, $paid->pickup_status);
        $this->assertFalse($paid->is_picked_up);

        $this->assertSame(PickupStatus::PICKED_UP, $pickedUp->pickup_status);
        $this->assertTrue($pickedUp->is_picked_up);
    }

    public function test_it_has_many_items_payments_and_one_invoice(): void
    {
        $order = Order::factory()->create();
        OrderItem::factory()->count(3)->create(['order_id' => $order->id]);
        $order->payments()->saveMany(Payment::factory()->count(2)->make());

        $this->assertCount(3, $order->items);
        // Plusieurs paiements : reglement partiel puis solde, ou relance apres
        // un echec.
        $this->assertCount(2, $order->payments);
    }

    public function test_it_rejects_a_duplicate_order_number(): void
    {
        Order::factory()->create(['order_number' => 'TDEV-20260929-AB12CD34']);

        $this->expectException(UniqueConstraintViolationException::class);

        Order::factory()->create(['order_number' => 'TDEV-20260929-AB12CD34']);
    }

    public function test_it_keeps_a_participant_id_for_ticket_shop_join(): void
    {
        // Le plan de tracking exige de pouvoir relier une commande a son pass
        // sans passer par un compte utilisateur.
        $order = Order::factory()->guest()->create(['participant_id' => 'PART-000042']);

        $this->assertSame('PART-000042', $order->fresh()->participant_id);
    }

    public function test_it_filters_orders_awaiting_payment_and_for_pickup(): void
    {
        $pending = Order::factory()->pendingPayment()->create();
        // Commande livree et deja payee : elle ne doit ressortir ni comme
        // attendant un paiement, ni comme commande de retrait.
        Order::factory()->paid()->forDelivery()->create();

        $this->assertEqualsCanonicalizing(
            [$pending->id],
            Order::awaitingPayment()->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$pending->id],
            Order::forPickup()->pluck('id')->all()
        );
    }
}
