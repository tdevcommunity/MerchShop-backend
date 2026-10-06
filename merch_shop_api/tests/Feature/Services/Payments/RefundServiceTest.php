<?php

namespace Tests\Feature\Services\Payments;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\Payments\PayoutGatewayRegistry;
use App\Services\Payments\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Doubles\FakePayoutGateway;
use Tests\TestCase;

/**
 * La demande de remboursement.
 *
 * Ces tests portent sur la propriete la plus importante de la restitution :
 * elle est demandee a un tiers, et la reponse de ce tiers n'est pas une
 * confirmation. Tout ce qui suit en decoule — l'etat intermediaire, le stock
 * qui ne bouge qu'a la confirmation, l'idempotence de la demande.
 *
 * La passerelle est un double, volontairement. Un test qui appelerait le vrai
 * operateur enverrait de l'argent a chaque execution : c'est le seul endroit ou
 * un oubli serait irreversible, et il est donc impossible ici.
 */
final class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = app(OrderService::class);

        /*
         * Seul l'agregateur compte : le registre de depot est remplace par un
         * double dans chaque test, donc aucun appel FedaPay ne part d'ici.
         */
        config()->set('payments.provider', PaymentProvider::FEDAPAY->value);
    }

    /*
     * La demande elle-meme.
     */

    public function test_it_puts_the_order_in_a_waiting_state_and_keeps_the_money_in_place(): void
    {
        $order = $this->paidOrder();
        $gateway = $this->gateway(status: 'pending');

        $refund = app(RefundService::class)->request($order);

        /*
         * L'etat `refund_pending` est tout l'objet de ce test : ecrire
         * `refunded` ici affirmerait au guichet que l'argent est sorti quand il
         * est encore chez nous.
         */
        $this->assertSame(OrderStatus::REFUND_PENDING, $order->refresh()->status);
        $this->assertSame(RefundStatus::PENDING, $refund->status);
        $this->assertSame('pending', $gateway->lastStatus);
    }

    public function test_it_does_not_return_the_stock_before_the_money_has_left(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->paidOrder($variant);

        $this->gateway(status: 'pending');
        app(RefundService::class)->request($order);

        /*
         * La commande reste bloquee et la marchandise reste sortie du stock. Rendre
         * le stock a la demande autoriserait a servir la piece une seconde fois
         * alors que l'acheteur la possede peut-etre encore.
         */
        $this->assertSame(6, $variant->refresh()->stock);
    }

    public function test_it_settles_immediately_when_the_operator_has_already_sent_the_money(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->paidOrder($variant);

        /*
         * Certains operateurs traitent un depot immediatement. Le service ne doit
         * pas attendre une notification qui ne viendra pas, sous peine de laisser
         * une commande en attente alors que l'argent est parti.
         */
        $this->gateway(status: 'processed');

        $refund = app(RefundService::class)->request($order);

        $this->assertSame(RefundStatus::SETTLED, $refund->refresh()->status);
        $this->assertSame(OrderStatus::REFUNDED, $order->refresh()->status);
        $this->assertSame(10, $variant->refresh()->stock);
    }

    public function test_it_closes_the_request_when_the_operator_refuses_the_payout(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->paidOrder($variant);

        $this->gateway(status: 'rejected');

        $refund = app(RefundService::class)->request($order);

        /*
         * Un depot refuse doit rendre la commande a son etat payee : l'argent n'est
         * jamais sorti, et la commande doit pouvoir etre servie normalement.
         */
        $this->assertSame(RefundStatus::FAILED, $refund->refresh()->status);
        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(6, $variant->refresh()->stock, 'Un depot refuse ne rend pas le stock.');
    }

    /*
     * Idempotence.
     */

    public function test_it_does_not_send_the_money_twice_for_one_request(): void
    {
        $order = $this->paidOrder();
        $gateway = $this->gateway(status: 'pending');

        $service = app(RefundService::class);
        $first = $service->request($order);
        $second = $service->request($order->refresh());

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame(1, $gateway->calls, 'Une seconde demande ne doit pas redéposer.');
    }

    public function test_it_allows_a_new_attempt_after_a_refused_payout(): void
    {
        $order = $this->paidOrder();

        $this->gateway(status: 'rejected');
        app(RefundService::class)->request($order);

        $this->gateway(status: 'pending');
        $second = app(RefundService::class)->request($order->refresh());

        /*
         * Une demande close n'est pas un obstacle : c'est un nouvel essai, que le
         * guichet doit pouvoir faire apres avoir corrige la cause de l'echec.
         */
        $this->assertSame(RefundStatus::PENDING, $second->status);
    }

    /*
     * La destination de l'argent.
     */

    public function test_it_sends_the_money_to_the_number_recorded_on_the_order(): void
    {
        $order = $this->paidOrder();
        $gateway = $this->gateway(status: 'pending');

        app(RefundService::class)->request($order);

        $this->assertSame('90123456', $gateway->sentPhone);
        $this->assertSame('tg', $gateway->sentCountry);
    }

    public function test_it_refuses_an_order_that_was_never_paid(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->pendingOrder($variant);

        $this->gateway(status: 'pending');

        $this->expectException(ApiException::class);

        app(RefundService::class)->request($order);
    }

    /*
     * La confirmation, qui vient d'une notification.
     */

    public function test_it_marks_the_order_refunded_when_the_notification_arrives(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->paidOrder($variant);

        $this->gateway(status: 'pending');
        $refund = app(RefundService::class)->request($order);

        app(RefundService::class)->settle($refund->refresh());

        $this->assertSame(OrderStatus::REFUNDED, $order->refresh()->status);
        $this->assertSame(10, $variant->refresh()->stock);
        $this->assertSame(
            PaymentStatus::REFUNDED,
            $refund->payment()->firstOrFail()->refresh()->status,
        );
    }

    public function test_it_does_not_restore_the_stock_twice_when_the_notification_is_replayed(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->paidOrder($variant);

        $this->gateway(status: 'pending');
        $refund = app(RefundService::class)->request($order);

        $service = app(RefundService::class);
        $service->settle($refund->refresh());
        $service->settle($refund->refresh());
        $service->settle($refund->refresh());

        /*
         * FedaPay notifie une fois, mais un rejeu, une relance ou deux guichetiers
         * simultanes ne doivent pas rendre la marchandise trois fois en rayon.
         */
        $this->assertSame(10, $variant->refresh()->stock, 'Un rejeu ne doit pas surrendre le stock.');
    }

    public function test_it_closes_the_request_when_the_notification_reports_a_failure(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->paidOrder($variant);

        $this->gateway(status: 'pending');
        $refund = app(RefundService::class)->request($order);

        app(RefundService::class)->fail($refund->refresh(), 'Solde insuffisant');

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(6, $variant->refresh()->stock);
        $this->assertSame(
            'Solde insuffisant',
            $refund->refresh()->failure_reason,
            'Le motif de l’operateur doit etre conserve tel quel.',
        );
    }

    /*
     * Helpers.
     */

    private function gateway(string $status): FakePayoutGateway
    {
        $gateway = new FakePayoutGateway($status);

        /*
         * Le registre resout des noms de classe. On lui donne la classe du
         * double, et le double lui-meme est deja resolu dans le conteneur :
         * sans cela, `app()` construirait une passerelle neuve a chaque appel
         * et le statut simule serait perdu entre la demande et la verification.
         */
        $this->app->instance(FakePayoutGateway::class, $gateway);
        $this->app->instance(
            PayoutGatewayRegistry::class,
            new PayoutGatewayRegistry([
                PaymentProvider::FEDAPAY->value => FakePayoutGateway::class,
            ]),
        );

        return $gateway;
    }

    private function paidOrder(?Variant $variant = null): Order
    {
        $order = $this->pendingOrder($variant);

        $order->payments()->firstOrFail()->update([
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
            'provider' => PaymentProvider::FEDAPAY,
            'transaction_id' => 'FEDAPAY-1',
        ]);

        $order->update(['status' => OrderStatus::PAID]);

        return $order->refresh();
    }

    /**
     * Une commande creee par le vrai service, avec son stock reserve.
     *
     * Passer par `OrderService::create` plutot que par des insertions a la main
     * evite que le test decrive une commande que la production ne sait pas
     * produire : la reservation de stock passe par le meme chemin que le vrai.
     */
    private function pendingOrder(?Variant $variant = null): Order
    {
        $variant ??= Variant::factory()->withStock(10)->create();

        return $this->orders->create([
            'user' => null,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 4]],
            'fulfillment_method' => FulfillmentMethod::PICKUP,
            'shipping_address' => null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
            'participant_id' => null,
        ]);
    }
}
