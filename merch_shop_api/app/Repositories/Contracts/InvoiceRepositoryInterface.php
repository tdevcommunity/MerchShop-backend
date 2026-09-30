<?php

namespace App\Repositories\Contracts;

use App\Models\Invoice;
use App\Models\Order;

/**
 * Emission et lecture des factures.
 *
 * @extends RepositoryInterface<Invoice>
 */
interface InvoiceRepositoryInterface extends RepositoryInterface
{
    /**
     * La facture d'une commande, si elle existe.
     *
     * La cardinalite 1-1 est portee par un index unique sur `order_id` : cette
     * lecture est donc la seule maniere fiable de savoir si une commande a deja
     * ete facturee, ce qui rend le service d'emission idempotent.
     */
    public function findForOrder(Order $order): ?Invoice;

    /**
     * Numero de facture deja attribue.
     *
     * Interroge y compris les lignes supprimees : une facture ne se reclasse
     * pas, et son numero apparait sur les pieces comptables.
     */
    public function invoiceNumberExists(string $invoiceNumber): bool;
}
