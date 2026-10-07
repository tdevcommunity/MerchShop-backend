<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * Representation d'une facture.
 *
 * Les montants sont entiers : le franc CFA n'a pas de subdivision, donc une
 * facture n'a pas de centimes a restituer, et le JSON n'a aucun flottant a
 * perdre en precision.
 *
 * @mixin Invoice
 */
final class InvoiceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        return [
            'uuid' => $invoice->uuid,
            'invoiceNumber' => $invoice->invoice_number,
            'subTotal' => $invoice->sub_total,
            'discount' => $invoice->discount,
            'total' => $invoice->total,
            'issuedAt' => $invoice->issued_at?->toIso8601String(),
        ];
    }
}
