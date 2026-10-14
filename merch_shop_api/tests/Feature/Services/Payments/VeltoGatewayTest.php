<?php

namespace Tests\Feature\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\Payments\VeltoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VeltoGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.velto.secret_key', 'sk_sandbox_test');
        config()->set('services.velto.base_url', 'https://velto-gate.test/v1');
    }

    public function test_it_sends_the_customer_email_to_velto_when_present(): void
    {
        Http::fake([
            'https://velto-gate.test/v1/payments' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tnx_mail',
                    'checkout_url' => 'https://checkout.velto.test/pay',
                ],
            ], 200),
        ]);

        $order = $this->pendingOrder();
        $order->update(['customer_email' => 'client@tdev.tg']);
        $payment = $order->payments()->firstOrFail();

        app(VeltoGateway::class)->initiate($payment, 'https://boutique.exemple.test/retour');

        Http::assertSent(function ($request): bool {
            $customer = $request->data()['customer'] ?? [];

            return ($customer['email'] ?? null) === 'client@tdev.tg'
                && ($customer['phone_number']['country'] ?? null) === 'bj'
                && ! array_key_exists('number', $customer['phone_number'] ?? []);
        });
    }

    public function test_it_rejects_http_callback_url_in_live_mode(): void
    {
        config()->set('services.velto.secret_key', 'pk_live_test_key');

        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->expectException(ApiException::class);

        app(VeltoGateway::class)->initiate(
            $payment,
            'http://localhost:3000/checkout/payment-return',
        );
    }

    public function test_it_opens_a_velto_checkout_with_our_payment_reference(): void
    {
        Http::fake([
            'https://velto-gate.test/v1/payments' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tnx_123',
                    'checkout_url' => 'https://checkout.velto.test/pay',
                ],
            ], 200),
        ]);

        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $intent = app(VeltoGateway::class)->initiate(
            $payment,
            'https://boutique.exemple.test/retour',
        );

        $this->assertSame('tnx_123', $intent->transactionId);
        $this->assertSame('https://checkout.velto.test/pay', $intent->checkoutUrl);

        Http::assertSent(function ($request) use ($payment): bool {
            $body = $request->data();
            $email = $body['customer']['email'] ?? null;

            return $request->url() === 'https://velto-gate.test/v1/payments'
                && ($body['external_reference'] ?? null) === $payment->uuid
                && ($body['callback_url'] ?? null) === 'https://boutique.exemple.test/retour'
                && is_string($email)
                && $email !== ''
                && ! array_key_exists('phone', $body['customer'] ?? [])
                && ($body['customer']['phone_number']['country'] ?? null) === 'bj';
        });
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
