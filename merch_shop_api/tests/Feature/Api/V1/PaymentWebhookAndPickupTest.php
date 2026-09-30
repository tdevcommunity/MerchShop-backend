<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PickupQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifications d'operateur et service au guichet, de l'exterieur.
 *
 * Ces routes sont les seules de l'API accessibles sans session, et les seules
 * dont la reponse n'appartient pas au client. Les tests font donc deux choses :
 * verifier qu'une requete non signee ne fait rien, et verifier que la session du
 * guichetier ne peut pas etre contournee.
 */
class PaymentWebhookAndPickupTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-operateur-de-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('orders.webhooks.fedapay.secret', self::SECRET);
    }

    public function test_it_accepts_a_correctly_signed_notification(): void
    {
        $order = $this->pendingOrder();
        $payload = $this->notification($order, 'success');

        $this->postJson('/api/v1/payments/webhooks/fedapay', $payload, $this->signatureHeaders($payload))
            ->assertOk()
            ->assertJsonPath('data.orderUuid', $order->uuid)
            ->assertJsonPath('data.status', OrderStatus::PAID->value);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_carrying_an_impossible_amount(): void
    {
        /*
         * 2500,50 FCFA n'existe pas : le franc CFA n'a pas de subdivision. Un
         * operateur qui annoncait ce montant ne parlait pas de la meme somme que
         * la commande, et l'accepter ici reviendrait a facturer un montant que
         * personne n'a confirme.
         */
        $order = $this->pendingOrder();

        $payload = $this->notification($order, 'success', ['amount' => '2500.50']);

        $this->postJson('/api/v1/payments/webhooks/fedapay', $payload, $this->signatureHeaders($payload))
            ->assertUnprocessable();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_accepts_a_notification_writing_the_amount_with_decimals(): void
    {
        /*
         * « 2500.00 » et « 2500,00 » designent la meme somme qu'« 2500 » : un
         * operateur n'est pas tenu d'ecrire la forme de l'API, et refuser une
         * notification legitime ferait perdre un paiement.
         */
        $order = $this->pendingOrder();

        $payload = $this->notification($order, 'success', ['amount' => '2500.00']);

        $this->postJson('/api/v1/payments/webhooks/fedapay', $payload, $this->signatureHeaders($payload))
            ->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_ignores_a_notification_without_signature(): void
    {
        $order = $this->pendingOrder();

        $this->postJson('/api/v1/payments/webhooks/fedapay', $this->notification($order, 'success'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status, 'Aucun effet sans signature.');
    }

    public function test_it_ignores_a_notification_signed_with_a_wrong_secret(): void
    {
        $order = $this->pendingOrder();
        $payload = $this->notification($order, 'success');

        $this->postJson('/api/v1/payments/webhooks/fedapay', $payload, [
            'X-Payment-Signature' => hash_hmac('sha256', json_encode($payload), 'mauvais-secret'),
        ])->assertUnauthorized();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_answers_a_signed_but_malformed_notification_with_a_422(): void
    {
        $payload = ['reference' => 'pas-un-uuid', 'status' => 'succes', 'amount' => '2500.00'];

        $this->postJson('/api/v1/payments/webhooks/fedapay', $payload, $this->signatureHeaders($payload))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_it_has_no_route_at_all_for_an_unknown_provider(): void
    {
        /*
         * Les routes sont generees depuis l'enumeration des operateurs : il n'y
         * a pas de segment joker, donc un operateur inconnu n'est pas traite
         * par l'action, il n'existe pas. C'est plus fort qu'un refus applicatif,
         * qui laisserait la porte ouverte pour un operateur ajoute plus tard.
         */
        $this->postJson('/api/v1/payments/webhooks/inconnu', ['reference' => 'x'], [
            'X-Payment-Signature' => 'abc',
        ])->assertNotFound();
    }

    public function test_it_accepts_a_notification_of_failure_without_paying_the_order(): void
    {
        $order = $this->pendingOrder();
        $payload = $this->notification($order, 'failed', ['failure_reason' => 'Solde insuffisant']);

        $this->postJson('/api/v1/payments/webhooks/fedapay', $payload, $this->signatureHeaders($payload))
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::PENDING_PAYMENT->value);

        $this->assertSame(PaymentStatus::FAILED, $order->payments()->firstOrFail()->refresh()->status);
    }

    public function test_it_shows_the_pickup_queue_to_a_member_of_the_counter(): void
    {
        $staff = $this->counter();
        $this->orderFor(User::factory()->create());

        $this->actingAs($staff)->getJson('/api/v1/pickup/orders')
            ->assertOk()
            ->assertJsonStructure(['data' => [['uuid', 'orderNumber', 'status', 'pickupStatus']]]);
    }

    public function test_it_hides_the_pickup_queue_from_a_customer(): void
    {
        $this->orderFor(User::factory()->create());

        $this->actingAs(User::factory()->create())->getJson('/api/v1/pickup/orders')->assertForbidden();
    }

    public function test_it_refuses_pickup_actions_to_a_customer(): void
    {
        $order = $this->orderFor(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/orders/{$order->uuid}/ready")
            ->assertForbidden();
    }

    public function test_it_refuses_a_deactivated_member_of_the_counter(): void
    {
        $order = $this->orderFor(User::factory()->create());
        $staff = $this->counter(UserStatus::INACTIVE);

        $this->actingAs($staff)->getJson('/api/v1/pickup/orders')->assertForbidden();
    }

    public function test_it_walks_a_paid_order_to_the_counter_and_then_refuses_the_same_scan(): void
    {
        $staff = $this->counter();
        $order = $this->paidOrder();

        $this->actingAs($staff)->postJson("/api/v1/orders/{$order->uuid}/ready")->assertOk();

        $qr = json_decode(
            $this->actingAs($staff)->getJson("/api/v1/orders/{$order->uuid}")->json('data.pickupQrPayload'),
            true,
        );

        $this->assertIsArray($qr);
        $this->assertSame($order->uuid, $qr['order_uuid']);

        $this->actingAs($staff)->postJson('/api/v1/pickup/scan', ['payload' => json_encode($qr)])
            ->assertOk()
            ->assertJsonPath('data.pickupStatus', 'picked_up');

        /*
         * Le meme QR, une seconde fois : c'est le double comptage le plus
         * probable au guichet, quand un guichetier reclique ou que la
         * connexion retombe apres la validation.
         */
        $this->actingAs($staff)->postJson('/api/v1/pickup/scan', ['payload' => json_encode($qr)])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PICKUP_TOKEN_INVALID');
    }

    public function test_it_refuses_a_qr_of_an_order_that_is_not_ready_yet(): void
    {
        $staff = $this->counter();
        $order = $this->paidOrder();
        $qr = $this->qrOf($order);

        $this->actingAs($staff)->postJson('/api/v1/pickup/scan', ['payload' => json_encode($qr)])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_NOT_PICKABLE');
    }

    public function test_it_refuses_a_hand_written_qr(): void
    {
        $staff = $this->counter();
        $order = $this->paidOrder();

        $this->actingAs($staff)->postJson('/api/v1/pickup/scan', [
            'payload' => json_encode(['order_uuid' => $order->uuid, 'token' => str_repeat('a', 64)]),
        ])->assertNotFound();
    }

    public function test_it_refuses_a_qr_that_is_not_json(): void
    {
        $this->actingAs($this->counter())
            ->postJson('/api/v1/pickup/scan', ['payload' => 'https://exemple.com/commande/1'])
            ->assertNotFound();
    }

    public function test_it_serves_a_paid_order_without_a_scan_when_the_phone_is_broken(): void
    {
        $staff = $this->counter();
        $order = $this->paidOrder();

        $this->actingAs($staff)->postJson("/api/v1/orders/{$order->uuid}/ready")->assertOk();

        $this->actingAs($staff)->postJson("/api/v1/orders/{$order->uuid}/picked-up")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::PICKED_UP->value);
    }

    public function test_it_lets_the_owner_download_the_qr_but_not_a_stranger(): void
    {
        $owner = User::factory()->create();
        $order = $this->paidOrder($owner);

        // Le refus ne depend pas du rendu : il est verifie meme sans GD.
        $this->actingAs(User::factory()->create())->getJson("/api/v1/orders/{$order->uuid}/qr")->assertForbidden();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped("L'extension GD est requise pour rendre le QR en PNG.");
        }

        $this->actingAs($owner)->getJson("/api/v1/orders/{$order->uuid}/qr")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_it_refuses_the_qr_of_an_unpaid_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->orderFor($owner);

        $this->actingAs($owner)->getJson("/api/v1/orders/{$order->uuid}/qr")->assertStatus(409);
    }

    /**
     * En-tetes de signature calcules sur le corps reellement envoye.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function signatureHeaders(array $payload): array
    {
        return [
            'X-Payment-Signature' => hash_hmac('sha256', json_encode($payload), self::SECRET),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function notification(Order $order, string $status, array $overrides = []): array
    {
        return [
            'reference' => $order->payments()->firstOrFail()->uuid,
            'transaction_id' => 'FED-'.$order->uuid,
            'status' => $status,
            'amount' => $order->total,
            // Les surcharges passent en dernier : sans cela, un test qui veut
            // envoyer un autre montant verrait le sien ecrase sans le dire.
            ...$overrides,
        ];
    }

    private function counter(UserStatus $status = UserStatus::ACTIVE): User
    {
        return User::factory()->create(['role' => UserRole::STAFF, 'status' => $status]);
    }

    private function orderFor(?User $owner): Order
    {
        $variant = Variant::factory()->withStock(20)->create(['price' => '2500.00']);

        return app(OrderService::class)->create([
            'user' => $owner,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'shipping_address' => null,
            'payment_method' => 'mobile_money',
            'participant_id' => null,
        ]);
    }

    private function paidOrder(?User $owner = null): Order
    {
        $order = $this->orderFor($owner);
        $payload = $this->notification($order, 'success');

        return app(PaymentService::class)->handleNotification([
            ...$payload,
            'status' => PaymentStatus::SUCCESS,
        ]);
    }

    private function pendingOrder(): Order
    {
        return $this->orderFor(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function qrOf(Order $order): array
    {
        return json_decode(app(PickupQrCodeService::class)->encodePayload($order), true);
    }
}
