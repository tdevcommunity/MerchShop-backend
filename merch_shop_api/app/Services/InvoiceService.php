<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Order;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Database\QueryException;

/**
 * Emission des factures.
 *
 * Une facture est un document comptable : elle n'est ni modifiable, ni
 * supprimable, et il ne peut en exister qu'une par commande. Ces trois regles
 * sont portees par la base (index unique sur `order_id`), et cette classe se
 * contente de les traduire en erreurs lisibles.
 *
 * Elle est separee du service de commande parce que l'emission obeit a un rythme
 * different : une facture est une ecriture comptable, un changement de statut de
 * commande ne l'est pas.
 */
final class InvoiceService
{
    /** Nombre de tentatives d'attribution d'un numero de facture. */
    private const NUMBER_ATTEMPTS = 5;

    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
    ) {}

    /**
     * Emet la facture d'une commande, ou renvoie celle qui existe deja.
     *
     * Idempotente par construction : c'est la propriete qui compte quand
     * l'emission est declenchee par un webhook, que l'operateur rejoue aussi
     * souvent qu'il veut. Une commande facturee deux fois serait un incident
     * comptable, pas un doublon sans consequence.
     *
     * Les montants sont repris de la commande et non recalcules : la facture
     * doit dire ce qui a ete facture, et la commande est l'etat arrete de la
     * vente. Les recalculer ici ouvrirait la porte a un ecart entre les deux
     * documents si la commande venait a etre corrigee apres coup.
     */
    public function issueFor(Order $order): Invoice
    {
        $existing = $this->invoices->findForOrder($order);

        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
            $invoiceNumber = $this->generateInvoiceNumber($order);

            if ($this->invoices->invoiceNumberExists($invoiceNumber)) {
                continue;
            }

            try {
                /** @var Invoice $invoice */
                $invoice = $this->invoices->create([
                    'invoice_number' => $invoiceNumber,
                    'order_id' => $order->id,
                    'sub_total' => $order->sub_total,
                    'discount' => $order->discount,
                    'total' => $order->total,
                    /*
                     * La date d'emission est celle du reglement, pas celle de la
                     * commande : la facture n'existe qu'a partir du paiement, et
                     * c'est cette date qui sert de piece comptable.
                     */
                    'issued_at' => now(),
                ]);

                return $invoice;
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'invoice_number') && ! str_contains($exception->getMessage(), 'invoices_order_id_unique')) {
                    throw $exception;
                }
            }
        }

        throw new ApiException(
            'Impossible d\'emettre la facture. Reessayez dans un instant.',
            503,
            'INVOICE_NUMBER_UNAVAILABLE',
        );
    }

    /**
     * Numero de facture, derivé du numero de commande.
     *
     * Le numero de commande portant deja l'unicite, la derivation rend le numero
     * de facture unique lui aussi, et rend la correspondance lisible sans avoir a
     * etablir de table : un guichetier qui lit `FAC-TDEV-20260930-A1B2C3` sait
     * immediatement quelle commande il facture.
     */
    private function generateInvoiceNumber(Order $order): string
    {
        return 'FAC-'.$order->order_number;
    }
}
