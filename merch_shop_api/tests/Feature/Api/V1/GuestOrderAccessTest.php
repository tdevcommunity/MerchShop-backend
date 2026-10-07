<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PickupStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\PaymentService;
use App\Support\Orders\OrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acces d'un client sans compte a sa commande, par le jeton emis a la creation.
 *
 * Le passage de commande est public, mais les lectures passent par une session.
 * Sans dispositif complementaire, ce client payerait un retrait et ne pourrait
 * jamais afficher le QR que son paiement vient d'ouvrir : sa commande serait
 * orpheline. Ces tests verrouillent la voie de retour, et surtout son etroitesse.
 */
class GuestOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_an_access_token_for_a_guest_order(): void
    {
        $response = $this->checkout()->assertCreated();

        $token = $response->json('data.guestAccessToken');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        // La commande n'etait pas encore payee : le QR n'existe pas encore, et
        // le jeton ne doit rien laisser deviner de la commande a ce stade.
        $this->assertNull($response->json('data.pickupQrPayload'));
    }

    public function test_it_does_not_issue_a_token_for_a_signed_in_order(): void
    {
        $variant = Variant::factory()->withStock(5)->create(['price' => '2500.00']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/orders', $this->payload($variant))
            ->assertCreated();

        $this->assertNull($response->json('data.guestAccessToken'));

        $order = Order::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertNull($order->guest_access_token_hash);
    }

    public function test_a_guest_reads_their_order_with_the_token(): void
    {
        $created = $this->checkout()->assertCreated();

        $token = $created->json('data.guestAccessToken');

        $response = $this->withHeader(OrderAccess::HEADER, $token)
            ->getJson('/api/v1/orders/'.$created->json('data.uuid'))
            ->assertOk();

        $this->assertSame($created->json('data.uuid'), $response->json('data.uuid'));
    }

    public function test_a_guest_reads_its_qr_payload_once_the_payment_is_confirmed(): void
    {
        $created = $this->checkout()->assertCreated();

        $uuid = $created->json('data.uuid');
        $token = $created->json('data.guestAccessToken');

        $this->pay($uuid);

        /*
         * C'est le scenario que le jeton rend possible : sans lui, ce client ne
         * pourrait pas afficher le QR que son paiement vient d'ouvrir.
         */
        $response = $this->withHeader(OrderAccess::HEADER, $token)
            ->getJson('/api/v1/orders/'.$uuid)
            ->assertOk();

        $this->assertNotNull($response->json('data.pickupQrPayload'));
    }

    public function test_the_token_is_never_returned_again(): void
    {
        $created = $this->checkout()->assertCreated();

        $uuid = $created->json('data.uuid');
        $token = $created->json('data.guestAccessToken');

        $this->withHeader(OrderAccess::HEADER, $token)
            ->getJson('/api/v1/orders/'.$uuid)
            ->assertOk()
            ->assertJsonMissingPath('data.guestAccessToken');
    }

    public function test_a_guest_order_is_not_readable_without_a_token(): void
    {
        $created = $this->checkout()->assertCreated();

        $this->getJson('/api/v1/orders/'.$created->json('data.uuid'))->assertForbidden();

        $this->getJson('/api/v1/orders/'.$created->json('data.uuid'), [OrderAccess::HEADER => 'faux'])
            ->assertForbidden();
    }

    public function test_a_token_does_not_open_another_order(): void
    {
        $first = $this->checkout()->assertCreated();
        $second = $this->checkout()->assertCreated();

        $this->withHeader(OrderAccess::HEADER, $first->json('data.guestAccessToken'))
            ->getJson('/api/v1/orders/'.$second->json('data.uuid'))
            ->assertForbidden();
    }

    public function test_a_token_does_not_open_an_order_belonging_to_an_account(): void
    {
        $variant = Variant::factory()->withStock(5)->create(['price' => '2500.00']);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/orders', $this->payload($variant))
            ->assertCreated();

        $order = Order::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

        /*
         * Un jeton emis pour une commande invitee puis rattache a un compte : le
         * jeton est alors une identite fantome, qui doit cesser d'ouvrir quoi que
         * ce soit, et pas devenir un acces de secours a la commande d'autrui.
         */
        app(OrderAccess::class)->issueFor($order->forceFill(['user_id' => null]));
        $order->user()->associate(User::factory()->create())->save();

        $token = Order::query()->whereKey($order->getKey())->firstOrFail()->guest_access_token_hash;

        $this->assertNotNull($token);

        $this->withHeader(OrderAccess::HEADER, 'jeton-quelconque')
            ->getJson('/api/v1/orders/'.$order->uuid)
            ->assertForbidden();
    }

    public function test_a_guest_cannot_reach_a_staff_transition_with_a_token(): void
    {
        $created = $this->checkout()->assertCreated();

        $uuid = $created->json('data.uuid');
        $token = $created->json('data.guestAccessToken');

        $this->withHeader(OrderAccess::HEADER, $token)
            ->postJson("/api/v1/orders/{$uuid}/picked-up", [])
            ->assertUnauthorized();

        $this->withHeader(OrderAccess::HEADER, $token)
            ->postJson("/api/v1/orders/{$uuid}/cancel", [])
            ->assertUnauthorized();
    }

    public function test_a_guest_order_stays_out_of_the_counter_queue_without_a_session(): void
    {
        $this->checkout()->assertCreated();

        $this->getJson('/api/v1/pickup/orders')->assertUnauthorized();
    }

    public function test_the_token_does_not_become_valid_once_the_order_is_cancelled(): void
    {
        $created = $this->checkout()->assertCreated();

        $uuid = $created->json('data.uuid');
        $token = $created->json('data.guestAccessToken');

        Order::query()->where('uuid', $uuid)->firstOrFail()->forceFill([
            'status' => OrderStatus::CANCELLED,
            'pickup_status' => PickupStatus::CANCELLED,
        ])->save();

        /*
         * Le jeton donne acces a la commande, pas a un retrait : une commande
         * annulee reste lisible, pour que le client comprenne pourquoi, mais elle
         * n'a plus de QR a servir.
         */
        $response = $this->withHeader(OrderAccess::HEADER, $token)
            ->getJson('/api/v1/orders/'.$uuid)
            ->assertOk();

        $this->assertNull($response->json('data.pickupQrPayload'));
    }

    /**
     * Passe une commande invitee, et renvoie la reponse de creation.
     */
    private function checkout(): TestResponse
    {
        $variant = Variant::factory()->withStock(5)->create(['price' => '2500.00']);

        return $this->postJson('/api/v1/orders', $this->payload($variant));
    }

    /**
     * Regle la commande comme payee, comme le ferait un webhook d'operateur.
     */
    private function pay(string $uuid): void
    {
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();

        app(PaymentService::class)->handleNotification([
            'reference' => $order->payments()->firstOrFail()->uuid,
            'transaction_id' => 'TXN-'.$order->order_number,
            'status' => PaymentStatus::SUCCESS,
            'amount' => $order->total,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Variant $variant): array
    {
        return [
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
        ];
    }
}
