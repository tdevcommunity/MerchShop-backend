<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPaymentReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.velto.secret_key', 'sk_sandbox_test');
        config()->set('services.velto.base_url', 'https://velto-gate.test/v1');
    }

    public function test_it_marks_the_order_paid_when_velto_reports_approved(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();
        $payment->update([
            'provider' => PaymentProvider::VELTO,
            'transaction_id' => 'tnx_sync_ok',
        ]);

        Http::fake([
            'https://velto-gate.test/v1/payments/tnx_sync_ok' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tnx_sync_ok',
                    'external_reference' => $payment->uuid,
                    'status' => 'APPROVED',
                    'amount' => 2500,
                ],
            ], 200),
        ]);

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment/sync-return", [
                'transaction_id' => 'tnx_sync_ok',
            ])
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', PaymentStatus::SUCCESS->value)
            ->assertJsonPath('data.status', OrderStatus::PAID->value);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_refuses_a_transaction_that_does_not_match_the_order(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();
        $payment->update([
            'provider' => PaymentProvider::VELTO,
            'transaction_id' => 'tnx_sync_ok',
        ]);

        Http::fake([
            'https://velto-gate.test/v1/payments/tnx_other' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 'tnx_other',
                    'external_reference' => $payment->uuid,
                    'status' => 'APPROVED',
                    'amount' => 2500,
                ],
            ], 200),
        ]);

        $this->actingAs($order->user)
            ->postJson("/api/v1/orders/{$order->uuid}/payment/sync-return", [
                'transaction_id' => 'tnx_other',
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'PAYMENT_NOT_FOUND');
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
