<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use App\Models\Order;
use App\Repositories\Contracts\InvoiceRepositoryInterface;

/**
 * Acces Eloquent aux factures.
 *
 * @extends EloquentRepository<Invoice>
 */
final class EloquentInvoiceRepository extends EloquentRepository implements InvoiceRepositoryInterface
{
    /**
     * @return class-string<Invoice>
     */
    protected function modelClass(): string
    {
        return Invoice::class;
    }

    public function findForOrder(Order $order): ?Invoice
    {
        return $this->query()->where('order_id', $order->id)->first();
    }

    public function invoiceNumberExists(string $invoiceNumber): bool
    {
        return $this->query()
            ->withTrashed()
            ->where('invoice_number', $invoiceNumber)
            ->exists();
    }
}
