<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PickupStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PickupQrCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cycle de vie d'une commande, du paiement au retrait.
 *
 * Les verifications portent sur la base : l'etat de la commande, celui du
 * paiement, la presence de la facture et le stock. Ce sont ces etats que le
 * guichet et le rapprochement comptable lisent, et eux seuls qu'un test de
 * reponse HTTP ne verrait pas.
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    private PaymentService $payments;

    private PickupQrCodeService $pickupQrCodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = app(OrderService::class);
        $this->payments = app(PaymentService::class);
        $this->pickupQrCodes = app(PickupQrCodeService::class);
    }

    public function test_it_marks_the_order_paid_and_issues_the_invoice_on_confirmation(): void
    {
        $order = $this->paidOrder();

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->invoice, 'Le reglement doit s\'accompagner d\'une facture.');
        $this->assertSame($order->total, $order->invoice->total);
    }

    public function test_it_opens_the_pickup_right_only_after_payment(): void
    {
        $order = $this->pendingOrder();

        $this->assertNull($order->pickup_token_hash, 'Aucune commande impayee ne peut avoir de QR.');

        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $this->assertNotNull($paid->pickup_token_hash);
    }

    public function test_it_never_issues_a_pickup_qr_for_a_delivered_order(): void
    {
        $order = $this->pendingOrder(fulfillmentMethod: FulfillmentMethod::DELIVERY);

        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $this->assertNull($paid->pickup_token_hash, 'Une commande livree n\'a pas de QR de retrait.');
    }

    public function test_it_replays_the_same_webhook_without_any_second_effect(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->first();

        $first = $this->confirmPayment($order, PaymentStatus::SUCCESS);
        $invoiceNumber = $first->invoice->invoice_number;

        $second = $this->payments->handleNotification([
            'reference' => $payment->uuid,
            'transaction_id' => 'FED-TRANSACTION-1',
            'status' => PaymentStatus::SUCCESS,
            'amount' => $order->total,
        ]);

        $this->assertSame(OrderStatus::PAID, $second->status);
        $this->assertSame($invoiceNumber, $second->invoice->invoice_number, 'Le rejeu ne doit pas reemettre de facture.');
        $this->assertCount(1, $second->payments, 'Le rejeu ne doit pas creer de tentative supplementaire.');
    }

    public function test_it_keeps_the_first_transaction_when_a_second_one_arrives(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->first();

        $this->confirmPayment($order, PaymentStatus::SUCCESS, 'FED-TRANSACTION-1');

        $this->payments->handleNotification([
            'reference' => $payment->uuid,
            'transaction_id' => 'FED-TRANSACTION-2',
            'status' => PaymentStatus::SUCCESS,
            'amount' => $order->total,
        ]);

        $this->assertSame(
            'FED-TRANSACTION-1',
            $payment->refresh()->transaction_id,
            'La reference du premier reglement fait foi.',
        );
    }

    public function test_it_refuses_a_confirmation_whose_amount_does_not_match(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->first();

        $this->expectExceptionCode(409);

        $this->payments->handleNotification([
            'reference' => $payment->uuid,
            'transaction_id' => 'FED-TRANSACTION-1',
            'status' => PaymentStatus::SUCCESS,
            'amount' => '1.00',
        ]);
    }

    public function test_it_records_a_refused_payment_and_keeps_the_order_open(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->first();

        $result = $this->confirmPayment($order, PaymentStatus::FAILED, reason: 'Solde insuffisant');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $result->status, 'Un echec de paiement ne clot pas la commande.');
        $this->assertSame(PaymentStatus::FAILED, $payment->refresh()->status);
        $this->assertSame('Solde insuffisant', $payment->refresh()->failure_reason);
        $this->assertNull($result->invoice, 'Une commande non payee n\'a pas de facture.');
    }

    public function test_it_ignores_a_confirmation_reaching_a_cancelled_order(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->first();
        $variant = $order->items->first()->variant;

        $this->orders->cancel($order);

        /*
         * L'operateur confirme en retard, apres l'annulation. Le paiement ne peut
         * pas rouvrir une commande fermee : le service de commande refuse la
         * transition, et la transaction entiere est annulee, donc meme la
         * reference operateur n'est pas ecrite.
         */
        try {
            $this->payments->handleNotification([
                'reference' => $payment->uuid,
                'transaction_id' => 'FED-LATE',
                'status' => PaymentStatus::SUCCESS,
                'amount' => $order->total,
            ]);
        } catch (ApiException) {
            // Refus attendu, verifie ci-dessous.
        }

        $this->assertSame(OrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertNull($payment->refresh()->transaction_id);
    }

    public function test_it_returns_the_stock_on_cancellation(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->orderFor($variant, quantity: 4);

        $this->assertSame(6, $variant->refresh()->stock);

        $this->orders->cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertSame(10, $variant->refresh()->stock, 'Le stock doit revenir.');
    }

    public function test_it_returns_the_stock_on_refund(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->orderFor($variant, quantity: 4);
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $this->assertSame(6, $variant->refresh()->stock);

        $refunded = $this->orders->refund($paid);

        $this->assertSame(OrderStatus::REFUNDED, $refunded->status);
        $this->assertSame(10, $variant->refresh()->stock, 'Un remboursement rend les articles au stock.');
        $this->assertSame(
            PaymentStatus::REFUNDED,
            $paid->payments()->first()->refresh()->status,
        );
    }

    public function test_it_keeps_a_served_article_out_of_the_stock_after_a_refund(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->orderFor($variant, quantity: 4);
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);
        $ready = $this->orders->markReadyForPickup($paid);
        $served = $this->orders->markPickedUp($ready);

        $refunded = $this->orders->refund($served);

        $this->assertSame(OrderStatus::REFUNDED, $refunded->status);
        $this->assertSame(
            6,
            $variant->refresh()->stock,
            'Un article deja remis ne doit pas revenir en stock.',
        );
    }

    public function test_it_refuses_a_cancellation_of_a_paid_order(): void
    {
        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $this->expectExceptionCode(409);

        $this->orders->cancel($paid);
    }

    public function test_it_does_not_credit_the_stock_twice_on_a_double_cancellation(): void
    {
        $variant = Variant::factory()->withStock(10)->create();
        $order = $this->orderFor($variant, quantity: 4);

        $this->orders->cancel($order);

        try {
            $this->orders->cancel($order->refresh());
        } catch (ApiException) {
            // Refus attendu.
        }

        $this->assertSame(10, $variant->refresh()->stock, 'Une double annulation ne doit pas doubler le stock.');
    }

    public function test_it_walks_a_paid_order_to_the_pickup_counter(): void
    {
        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $ready = $this->orders->markReadyForPickup($paid);
        $this->assertSame(OrderStatus::READY_FOR_PICKUP, $ready->status);
        $this->assertSame(PickupStatus::PENDING, $ready->pickup_status, 'Le droit est ouvert, l\'usage non constate.');

        $pickedUp = $this->orders->markPickedUp($ready);
        $this->assertSame(OrderStatus::PICKED_UP, $pickedUp->status);
        $this->assertSame(PickupStatus::PICKED_UP, $pickedUp->pickup_status);
        $this->assertNotNull($pickedUp->pickup_time);
    }

    public function test_it_does_not_serve_an_order_that_was_never_paid(): void
    {
        $order = $this->pendingOrder();

        $this->expectExceptionCode(409);

        $this->orders->markReadyForPickup($order);
    }

    public function test_it_cancels_the_pickup_right_after_a_cancellation(): void
    {
        $order = $this->pendingOrder();

        $cancelled = $this->orders->cancel($order);

        $this->assertSame(PickupStatus::CANCELLED, $cancelled->pickup_status);
    }

    public function test_it_refuses_to_mark_a_delivered_order_as_picked_up(): void
    {
        $order = $this->pendingOrder(fulfillmentMethod: FulfillmentMethod::DELIVERY);
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $this->expectExceptionCode(409);

        $this->orders->markPickedUp($paid);
    }

    public function test_it_renders_a_readable_png_for_a_paid_pickup_order(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped("L'extension GD est requise pour rendre le QR en PNG.");
        }

        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $png = $this->pickupQrCodes->renderPng($paid);

        $this->assertStringStartsWith("\x89PNG", $png, 'Le contenu rendu doit etre une image PNG.');
        $this->assertGreaterThan(1000, strlen($png));
    }

    public function test_the_qr_payload_carries_the_order_and_a_signature(): void
    {
        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $payload = json_decode($this->pickupQrCodes->encodePayload($paid), true);

        $this->assertSame($paid->uuid, $payload['order_uuid']);
        $this->assertSame($paid->order_number, $payload['order_number']);
        $this->assertNotEmpty($payload['token']);
    }

    public function test_it_resolves_the_order_behind_a_scanned_qr(): void
    {
        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);
        $this->orders->markReadyForPickup($paid);

        $token = json_decode($this->pickupQrCodes->encodePayload($paid->refresh()), true)['token'];

        $resolved = $this->pickupQrCodes->resolveOrder($token);

        $this->assertSame($paid->uuid, $resolved->uuid);
    }

    public function test_it_refuses_a_qr_that_does_not_match_any_order(): void
    {
        $this->expectExceptionCode(404);

        $this->pickupQrCodes->resolveOrder(str_repeat('a', 64));
    }

    public function test_it_invalidates_the_qr_once_the_order_has_been_served(): void
    {
        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);
        $this->orders->markReadyForPickup($paid);

        $token = json_decode($this->pickupQrCodes->encodePayload($paid->refresh()), true)['token'];

        $this->orders->markPickedUp($paid->refresh());

        $this->expectExceptionCode(404);

        $this->pickupQrCodes->resolveOrder($token);
    }

    public function test_it_refuses_to_render_a_qr_for_an_unpaid_order(): void
    {
        $order = $this->pendingOrder();

        $this->expectExceptionCode(409);

        $this->pickupQrCodes->renderPng($order);
    }

    public function test_the_qr_token_does_not_leak_the_pickup_secret(): void
    {
        $order = $this->pendingOrder();
        $paid = $this->confirmPayment($order, PaymentStatus::SUCCESS);

        $token = json_decode($this->pickupQrCodes->encodePayload($paid), true)['token'];

        $this->assertStringNotContainsString((string) config('orders.pickup.secret'), $token);
    }

    /**
     * Commande de retrait creee, non payee.
     */
    private function pendingOrder(
        FulfillmentMethod $fulfillmentMethod = FulfillmentMethod::PICKUP,
    ): Order {
        $variant = Variant::factory()->withStock(10)->create(['price' => '2500.00']);

        return $this->orderFor($variant, quantity: 1, fulfillmentMethod: $fulfillmentMethod);
    }

    /**
     * Commande de retrait deja confirmee comme reglee.
     */
    private function paidOrder(): Order
    {
        return $this->confirmPayment($this->pendingOrder(), PaymentStatus::SUCCESS);
    }

    private function orderFor(
        Variant $variant,
        int $quantity,
        FulfillmentMethod $fulfillmentMethod = FulfillmentMethod::PICKUP,
    ): Order {
        return $this->orders->create([
            'user' => null,
            'items' => [['uuid' => $variant->uuid, 'quantity' => $quantity]],
            'fulfillment_method' => $fulfillmentMethod,
            'shipping_address' => $fulfillmentMethod === FulfillmentMethod::DELIVERY ? 'Lome' : null,
            'payment_method' => PaymentMethod::MOBILE_MONEY,
            'participant_id' => null,
        ]);
    }

    /**
     * Simule l'arrivee de la notification de l'operateur.
     */
    private function confirmPayment(
        Order $order,
        PaymentStatus $status,
        string $transactionId = 'FED-TRANSACTION-1',
        ?string $reason = null,
    ): Order {
        /** @var Payment $payment */
        $payment = $order->payments()->first();

        return $this->payments->handleNotification([
            'reference' => $payment->uuid,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => $order->total,
            'failure_reason' => $reason,
        ]);
    }
}
