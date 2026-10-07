<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\Payments\PaymentGatewayRegistry;
use App\Support\Orders\OrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

/**
 * Ouverture du paiement d'une commande.
 *
 * La route ne fait qu'un pas de plus que la creation de commande — ouvrir une
 * transaction chez un tiers et rendre l'adresse a laquelle l'acheteur paie — mais
 * c'est ce pas qui engage de l'argent, donc deux proprietes sont verifiees ici :
 * que l'appel a l'operateur est unique, et qu'il ne part que pour une commande
 * que l'appelant a le droit de voir.
 */
class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.return_url', 'https://boutique.exemple.test/retour');

        $this->gateway = new FakePaymentGateway;

        $this->app->instance(PaymentGatewayRegistry::class, new PaymentGatewayRegistry([
            PaymentProvider::FEDAPAY->value => FakePaymentGateway::class,
        ]));

        $this->app->instance(FakePaymentGateway::class, $this->gateway);
    }

    public function test_it_returns_the_payment_link_of_a_new_payment(): void
    {
        $order = $this->pendingOrder($this->customer());

        $this->actingAs($order->user)->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertOk()
            ->assertJsonPath('data.provider', 'fedapay')
            ->assertJsonPath('data.checkoutUrl', 'https://paiement.exemple.test/1')
            ->assertJsonPath('data.transactionId', 'TXN-1')
            ->assertJsonPath('data.status', PaymentStatus::PENDING->value);

        $this->assertSame(1, $this->gateway->calls);
    }

    public function test_it_sends_the_amount_of_the_order_and_not_something_else(): void
    {
        $order = $this->pendingOrder($this->customer());

        $this->actingAs($order->user)->postJson("/api/v1/orders/{$order->uuid}/payment")->assertOk();

        $this->assertCount(1, $this->gateway->initiations);
        $this->assertSame((string) $order->total, (string) $this->gateway->initiations[0]['amount']);
    }

    public function test_it_lets_a_guest_pay_with_the_token_it_received_at_creation(): void
    {
        /*
         * Un invite vient de commander sans compte : exiger une session pour payer
         * le rendrait incapable de finir ce qu'il a commence, alors qu'il detient
         * exactement le jeton qui fait foi sur sa commande.
         */
        $order = $this->pendingOrder(null);
        $token = app(OrderAccess::class)->issueFor($order);

        $this->withHeader(OrderAccess::HEADER, $token)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertOk();
    }

    public function test_it_calls_the_provider_once_and_returns_the_same_link_when_repeated(): void
    {
        /*
         * Un client qui a ferme son onglet doit pouvoir reprendre son paiement. Deux
         * transactions chez le prestataire pour une seule commande laisseraient
         * deux lignes en attente, dont une seule serait rapprochable — et
         * l'acheteur pourrait payer deux fois.
         */
        $order = $this->pendingOrder($this->customer());

        $first = $this->actingAs($order->user)->postJson("/api/v1/orders/{$order->uuid}/payment")->assertOk();
        $second = $this->actingAs($order->user)->postJson("/api/v1/orders/{$order->uuid}/payment")->assertOk();

        $this->assertSame(1, $this->gateway->calls);
        $this->assertSame($first->json('data.checkoutUrl'), $second->json('data.checkoutUrl'));
        $this->assertSame($first->json('data.transactionId'), $second->json('data.transactionId'));
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_it_refuses_a_stranger(): void
    {
        $order = $this->pendingOrder($this->customer());

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertForbidden();

        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_it_refuses_an_anonymous_caller(): void
    {
        $order = $this->pendingOrder($this->customer());

        $this->postJson("/api/v1/orders/{$order->uuid}/payment")->assertForbidden();

        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_it_refuses_an_order_that_is_already_paid(): void
    {
        $order = $this->paidOrder($this->customer());

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_ALREADY_PAID');

        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_it_refuses_an_order_that_has_been_cancelled(): void
    {
        $order = $this->pendingOrder($this->customer());

        app(OrderService::class)->cancel($order);

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_NOT_PAYABLE');

        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_it_refuses_an_order_that_has_been_refunded(): void
    {
        /*
         * Le remboursement laisse la date de reglement en place, pour que la
         * trace du paiement reste lisible. L'absence de solde ne doit donc pas
         * etre ce qui empeche de re-payer : c'est le statut qui le dit. Et le
         * client ne doit pas lire « deja payee » pour une commande qui a ete
         * remboursee — c'est une autre histoire, et il doit pouvoir la
         * raconter.
         */
        $order = $this->paidOrder($this->customer());

        app(OrderService::class)->markRefunded($order);

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_NOT_PAYABLE');

        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_it_reports_a_provider_failure_without_writing_a_transaction_reference(): void
    {
        $order = $this->pendingOrder($this->customer());
        $this->gateway->failure = new \RuntimeException('FedaPay injoignable');

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNAVAILABLE');

        /*
         * Aucune reference operateur n'est ecrite : le client n'a rien paye, donc
         * la commande doit rester aussi ouverte qu'avant l'appel.
         */
        $payment = $order->payments()->firstOrFail()->refresh();
        $this->assertNull($payment->transaction_id);
        $this->assertNull($payment->checkout_url);
        $this->assertSame(PaymentStatus::PENDING, $payment->status);
    }

    public function test_it_refuses_to_open_a_payment_without_a_return_address(): void
    {
        config()->set('payments.return_url', null);

        $order = $this->pendingOrder($this->customer());

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PAYMENT_PROVIDER_NOT_CONFIGURED');

        $this->assertSame(0, $this->gateway->calls);
    }

    public function test_it_never_pays_an_order_on_its_own(): void
    {
        /*
         * Ouvrir un paiement n'encaisse rien. Seul le webhook peut dire que
         * l'argent est arrive, donc une commande dont le client a simplement ouvert
         * une page de paiement doit rester en attente.
         */
        $order = $this->pendingOrder($this->customer());

        $this->actingAs($order->user)->postJson("/api/v1/orders/{$order->uuid}/payment")->assertOk();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
        $this->assertNull($order->payments()->firstOrFail()->refresh()->paid_at);
    }

    public function test_it_lets_the_counter_open_a_payment_for_a_customer_who_cannot_pay_itself(): void
    {
        /*
         * Un client bloque sur un paiement au guichet ne peut pas toujours repayer
         * lui-meme. Le personnel qui peut lire la commande peut donc l'ouvrir :
         * c'est la meme porte que les autres actions de guichet.
         */
        $order = $this->pendingOrder($this->customer());

        $this->actingAs(User::factory()->create(['role' => UserRole::STAFF]))
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertOk();

        $this->assertSame(1, $this->gateway->calls);
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function pendingOrder(?User $owner): Order
    {
        $variant = Variant::factory()->withStock(20)->create(['price' => '2500']);

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

    private function paidOrder(?User $owner = null): Order
    {
        $order = $this->pendingOrder($owner);

        $payment = $order->payments()->firstOrFail();

        $payment->update([
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
            'transaction_id' => 'TXN-EXISTANT',
        ]);

        $order->update(['status' => OrderStatus::PAID]);

        return $order->refresh();
    }
}
