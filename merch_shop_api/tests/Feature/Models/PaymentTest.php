<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(Payment::factory()->create()->uuid));
    }

    public function test_it_belongs_to_an_order(): void
    {
        $order = Order::factory()->create();
        $payment = Payment::factory()->forOrder($order, 12000)->create();

        $this->assertTrue($order->is($payment->order));
    }

    public function test_it_casts_amount_and_enums(): void
    {
        $payment = Payment::factory()->create([
            'amount' => 7500,
            'method' => PaymentMethod::CARD,
            'provider' => PaymentProvider::PAYGATE,
        ]);

        $this->assertSame(7500, $payment->amount);
        $this->assertSame(PaymentMethod::CARD, $payment->method);
        $this->assertSame(PaymentProvider::PAYGATE, $payment->provider);
    }

    public function test_a_confirmed_payment_is_marked_paid(): void
    {
        $payment = Payment::factory()->successful()->create();

        $this->assertSame(PaymentStatus::SUCCESS, $payment->status);
        $this->assertInstanceOf(Carbon::class, $payment->paid_at);
        $this->assertTrue($payment->isPaid());
    }

    public function test_a_pending_payment_is_not_paid(): void
    {
        $payment = Payment::factory()->create();

        $this->assertSame(PaymentStatus::PENDING, $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertFalse($payment->isPaid());
    }

    public function test_a_failed_payment_keeps_its_reason(): void
    {
        // Le plan de tracking exige de conserver les echecs, pas seulement les
        // paiements reussis.
        $payment = Payment::factory()->failed()->create();

        $this->assertSame(PaymentStatus::FAILED, $payment->status);
        $this->assertNotNull($payment->failure_reason);
        $this->assertNull($payment->paid_at);
    }

    public function test_it_allows_several_payments_for_one_order(): void
    {
        // Cas du reglement partiel : la commande reste ouverte tant que le
        // total n'est pas couvert.
        $order = Order::factory()->create(['total' => 20000]);
        Payment::factory()->forOrder($order, 10000)->successful()->create();
        Payment::factory()->forOrder($order, 10000)->create();

        $this->assertCount(2, $order->payments);
        $this->assertSame(1, $order->payments()->successful()->count());
    }

    public function test_it_rejects_a_duplicate_transaction_id(): void
    {
        // Reference operateur unique : c'est ce qui rend le webhook idempotent.
        // Rejouer le meme evenement ne doit pas creer une seconde ligne.
        $transactionId = 'TXN-ABCDEFGH12';
        Payment::factory()->successful()->create(['transaction_id' => $transactionId]);

        $this->expectException(UniqueConstraintViolationException::class);

        Payment::factory()->successful()->create(['transaction_id' => $transactionId]);
    }

    public function test_it_accepts_several_payments_without_a_transaction_id_yet(): void
    {
        // Plusieurs paiements en attente n'ont pas encore de reference
        // operateur : la base doit tolerer plusieurs valeurs nulles.
        $order = Order::factory()->create();
        Payment::factory()->forOrder($order, 5000)->create();
        Payment::factory()->forOrder($order, 5000)->create();

        $this->assertNull(Payment::whereNull('transaction_id')->first()->transaction_id);
        $this->assertCount(2, Payment::whereNull('transaction_id')->get());
    }

    public function test_it_filters_successful_payments(): void
    {
        $success = Payment::factory()->successful()->create();
        Payment::factory()->create();
        Payment::factory()->failed()->create();

        $this->assertEqualsCanonicalizing(
            [$success->id],
            Payment::successful()->pluck('id')->all()
        );
    }
}
