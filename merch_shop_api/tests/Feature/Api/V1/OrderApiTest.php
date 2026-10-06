<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le tunnel d'achat vu par le client, de l'API elle-meme.
 *
 * Ces tests complementent OrderCheckoutTest : ils verifient la couche HTTP —
 * codes de reponse, enveloppe `data`, cloisonnement entre comptes — et non
 * l'etat ecrit en base, que le service couvre deja.
 */
class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_order_without_any_account(): void
    {
        $variant = Variant::factory()->withStock(5)->create(['price' => 2500]);

        $response = $this->postJson('/api/v1/orders', $this->payload($variant, quantity: 2));

        $response->assertCreated()
            ->assertJsonPath('data.orderNumber', fn (string $number): bool => str_starts_with($number, 'TDEV-'))
            ->assertJsonPath('data.status', OrderStatus::PENDING_PAYMENT->value)
            ->assertJsonPath('data.fulfillmentMethod', 'pickup')
            ->assertJsonPath('data.total', 5000)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unitPrice', 2500);

        $this->assertSame(3, $variant->refresh()->stock);
    }

    public function test_it_attaches_the_order_to_the_connected_account(): void
    {
        $customer = User::factory()->create();
        $variant = Variant::factory()->withStock(5)->create();

        $response = $this->actingAs($customer)->postJson('/api/v1/orders', $this->payload($variant));

        $uuid = $response->json('data.uuid');

        $this->assertSame($customer->id, Order::where('uuid', $uuid)->firstOrFail()->user_id);
    }

    public function test_it_refuses_an_empty_cart(): void
    {
        $this->postJson('/api/v1/orders', ['items' => [], 'fulfillment_method' => 'pickup', 'payment_method' => 'mobile_money'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_it_refuses_a_quantity_of_zero(): void
    {
        $variant = Variant::factory()->withStock(5)->create();

        $this->postJson('/api/v1/orders', $this->payload($variant, quantity: 0))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
    }

    public function test_it_refuses_the_same_variant_twice_in_one_order(): void
    {
        $variant = Variant::factory()->withStock(5)->create();

        $this->postJson('/api/v1/orders', [
            'items' => [
                ['uuid' => $variant->uuid, 'quantity' => 1],
                ['uuid' => $variant->uuid, 'quantity' => 2],
            ],
            'fulfillment_method' => 'pickup',
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
        ])->assertStatus(422);
    }

    public function test_it_refuses_a_delivery_without_address(): void
    {
        $variant = Variant::factory()->withStock(5)->create();

        $this->postJson('/api/v1/orders', [
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'delivery',
            'payment_method' => 'card',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['shipping_address']]]]);
    }

    public function test_it_refuses_a_quantity_larger_than_the_stock_with_a_stable_code(): void
    {
        $variant = Variant::factory()->withStock(1)->create();

        $this->postJson('/api/v1/orders', $this->payload($variant, quantity: 9))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
    }

    public function test_it_lists_only_the_orders_of_the_connected_account(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $variant = Variant::factory()->withStock(20)->create();

        $this->actingAs($mine)->postJson('/api/v1/orders', $this->payload($variant))->assertCreated();
        $this->actingAs($other)->postJson('/api/v1/orders', $this->payload($variant))->assertCreated();

        $response = $this->actingAs($mine)->getJson('/api/v1/orders')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(
            Order::where('user_id', $mine->id)->firstOrFail()->uuid,
            $response->json('data.0.uuid'),
        );
    }

    public function test_it_never_shows_an_order_to_another_account(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $order = $this->orderFor($owner);

        $this->actingAs($stranger)->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();
    }

    public function test_it_hides_a_guest_order_from_every_account(): void
    {
        /*
         * Une commande invitee n'appartient a personne. La laisser visible par
         * un administrateur via cette route reviendrait a ouvrir l'historique de
         * tous les visiteurs anonymes a quiconque a un compte.
         */
        $customer = User::factory()->create();
        $order = $this->orderFor(null);

        $this->actingAs($customer)->getJson("/api/v1/orders/{$order->uuid}")->assertForbidden();
    }

    public function test_it_requires_a_session_to_read_the_order_history(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_it_refuses_an_unknown_order_status_filter(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->getJson('/api/v1/orders?status=99')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_FILTER');
    }

    public function test_it_shows_a_paid_order_with_its_pickup_qr(): void
    {
        $customer = User::factory()->create();
        $order = $this->orderFor($customer);

        $this->markPaid($order);

        $this->actingAs($customer)->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::PAID->value)
            ->assertJsonStructure(['data' => ['pickupQrPayload', 'allowedActions']]);
    }

    public function test_it_never_exposes_a_qr_before_payment(): void
    {
        /*
         * Le champ est present mais vide, et non absent : sa forme ne change donc
         * pas au moment du paiement, et un front peut lire `pickupQrPayload`
         * sans tester sa presence avant d'afficher un bouton de retrait.
         */
        $customer = User::factory()->create();
        $order = $this->orderFor($customer);

        $this->actingAs($customer)->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.pickupQrPayload', null);
    }

    public function test_it_lets_the_customer_cancel_an_unpaid_order(): void
    {
        $customer = User::factory()->create();
        $order = $this->orderFor($customer);

        $this->actingAs($customer)->postJson("/api/v1/orders/{$order->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED->value);
    }

    public function test_it_refuses_to_cancel_somebody_elses_order(): void
    {
        $order = $this->orderFor(User::factory()->create());

        $this->actingAs(User::factory()->create())->postJson("/api/v1/orders/{$order->uuid}/cancel")
            ->assertForbidden();
    }

    public function test_it_refuses_to_cancel_a_paid_order(): void
    {
        $customer = User::factory()->create();
        $order = $this->orderFor($customer);
        $this->markPaid($order);

        $this->actingAs($customer)->postJson("/api/v1/orders/{$order->uuid}/cancel")
            ->assertStatus(409);
    }

    public function test_it_keeps_a_guest_order_creatable_without_csrf_issues(): void
    {
        /*
         * Le checkout etant public, il reste soumis au CSRF comme toute ecriture
         * de l'API. Ce test verifie que le montage session/CSRF n'empeche pas un
         * visiteur sans compte de commander une fois le jeton fourni.
         */
        $variant = Variant::factory()->withStock(5)->create();

        $this->withSession([])->postJson('/api/v1/orders', $this->payload($variant))->assertCreated();
    }

    /**
     * Charge utile minimale d'une commande de retrait.
     *
     * @return array<string, mixed>
     */
    private function payload(Variant $variant, int $quantity = 1): array
    {
        return [
            'items' => [['uuid' => $variant->uuid, 'quantity' => $quantity]],
            'fulfillment_method' => 'pickup',
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
        ];
    }

    private function orderFor(?User $owner): Order
    {
        $variant = Variant::factory()->withStock(20)->create(['price' => 2500]);

        return app(OrderService::class)->create([
            'user' => $owner,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'shipping_address' => null,
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
            'participant_id' => null,
        ]);
    }

    /**
     * Regle la commande comme payee, par le chemin reel de l'operateur.
     */
    private function markPaid(Order $order): Order
    {
        return app(PaymentService::class)->handleNotification([
            'reference' => $order->payments()->firstOrFail()->uuid,
            'transaction_id' => 'FED-TEST-1',
            'status' => PaymentStatus::SUCCESS,
            'amount' => $order->total,
        ]);
    }
}
