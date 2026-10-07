<?php

namespace Tests\Feature\Models;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_a_uuid_on_creation(): void
    {
        $this->assertTrue(Str::isUuid(Invoice::factory()->create()->uuid));
    }

    public function test_it_belongs_to_a_single_order(): void
    {
        $order = Order::factory()->create();
        $invoice = Invoice::factory()->forOrder($order)->create();

        $this->assertTrue($order->is($invoice->order));
        $this->assertTrue($order->invoice->is($invoice));
    }

    public function test_it_casts_amounts_to_whole_francs(): void
    {
        $order = Order::factory()->create([
            'sub_total' => 12000,
            'discount' => 1000,
            'total' => 11000,
        ]);
        $invoice = Invoice::factory()->forOrder($order)->create();

        // La facture recopie des entiers : le franc CFA n'a pas de subdivision.
        $this->assertSame(12000, $invoice->sub_total);
        $this->assertSame(1000, $invoice->discount);
        $this->assertSame(11000, $invoice->total);
    }

    public function test_it_casts_issued_at_to_datetime(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertInstanceOf(Carbon::class, $invoice->issued_at);
    }

    public function test_it_forbids_a_second_invoice_for_the_same_order(): void
    {
        // Cardinalite 1-1 portee par la base : sans cette contrainte, une
        // double facturation passerait la validation applicative.
        $order = Order::factory()->create();
        Invoice::factory()->forOrder($order)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::factory()->state(['order_id' => $order->id])->create();
    }

    public function test_it_rejects_a_duplicate_invoice_number(): void
    {
        $order = Order::factory()->create();
        Invoice::factory()->forOrder($order)->create(['invoice_number' => 'FACT-20260929-AB12CD34']);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::factory()
            ->forOrder(Order::factory()->create())
            ->create(['invoice_number' => 'FACT-20260929-AB12CD34']);
    }
}
