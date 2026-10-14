<?php

namespace Tests\Feature\Services\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Doubles\FakePaymentGateway;
use Tests\TestCase;

class CheckoutReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payments.return_url', 'https://boutique.exemple.test/retour');
        config()->set('payments.provider', PaymentProvider::VELTO->value);
        config()->set('services.velto.secret_key', 'sk_sandbox_test');
        config()->set('services.velto.base_url', 'https://velto-gate.test/v1');

        $this->gateway = new FakePaymentGateway;

        $this->app->instance(PaymentGatewayRegistry::class, new PaymentGatewayRegistry([
            PaymentProvider::VELTO->value => FakePaymentGateway::class,
        ]));
        $this->app->instance(FakePaymentGateway::class, $this->gateway);
    }

    private FakePaymentGateway $gateway;

    public function test_it_opens_a_new_checkout_when_velto_cancelled_the_previous_session(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();
        $payment->update([
            'provider' => PaymentProvider::VELTO,
            'transaction_id' => 'tnx_old',
            'checkout_url' => 'https://checkout.velto.test/dead',
            'status' => PaymentStatus::PENDING,
        ]);

        Http::fake([
            'https://velto-gate.test/v1/payments/tnx_old' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tnx_old',
                    'status' => 'CANCELLED',
                    'external_reference' => $payment->uuid,
                ],
            ], 200),
        ]);

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment")
            ->assertSuccessful()
            ->assertJsonPath('data.checkoutUrl', 'https://paiement.exemple.test/1');

        $this->assertSame(1, $this->gateway->calls);
        $this->assertSame(PaymentStatus::FAILED, $payment->refresh()->status);
    }

    private function pendingOrder(): Order
    {
        $variant = Variant::factory()->withStock(20)->create(['price' => '2500']);

        return app(OrderService::class)->create([
            'user' => User::factory()->create(),
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'shipping_address' => null,
            'payment_method' => 'mobile_money',
            'customer_name' => 'Jean Dupont',
            'customer_phone_number' => '90123456',
            'participant_id' => null,
        ]);
    }
}
