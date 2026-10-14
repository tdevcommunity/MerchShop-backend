<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VeltoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-webhook-velto-de-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('orders.webhooks.velto.secret', self::SECRET);
    }

    public function test_it_pays_the_order_when_velto_approves_the_payment(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $body = json_encode([
            'event' => 'payment.success',
            'transaction_id' => 'tnx_approved',
            'external_reference' => $payment->uuid,
            'amount' => 2500,
            'currency' => 'XOF',
            'status' => 'APPROVED',
        ], JSON_THROW_ON_ERROR);

        $this->send($body)->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentStatus::SUCCESS, $payment->refresh()->status);
    }

    public function test_it_acquits_an_unknown_velto_status_without_changing_the_order(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $body = json_encode([
            'event' => 'payment.updated',
            'transaction_id' => 'tnx_unknown',
            'external_reference' => $payment->uuid,
            'amount' => 2500,
            'currency' => 'XOF',
            'status' => 'MYSTERY',
        ], JSON_THROW_ON_ERROR);

        $this->send($body)->assertOk();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_without_valid_signature(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $body = json_encode([
            'event' => 'payment.success',
            'transaction_id' => 'tnx_bad_sig',
            'external_reference' => $payment->uuid,
            'amount' => 2500,
            'currency' => 'XOF',
            'status' => 'APPROVED',
        ], JSON_THROW_ON_ERROR);

        $this->postJson('/api/v1/payments/webhooks/velto', json_decode($body, true), [
            'X-Velto-Signature' => 'invalid',
        ])->assertUnauthorized();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    private function send(string $body): TestResponse
    {
        $signature = hash_hmac('sha256', $body, self::SECRET);

        return $this->call(
            'POST',
            '/api/v1/payments/webhooks/velto',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Velto-Signature' => $signature,
            ],
            $body,
        );
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
