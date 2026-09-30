<?php

namespace App\Repositories\Contracts;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

/**
 * Acces aux tentatives de paiement d'une commande.
 *
 * @extends RepositoryInterface<Payment>
 */
interface PaymentRepositoryInterface extends RepositoryInterface
{
    /**
     * Un paiement par son uuid, avec la commande chargee.
     *
     * C'est par cet uuid que l'operateur fait reference au paiement lorsqu'il
     * rappelle : la reference que nous lui avons transmise est donc notre
     * identifiant interne, et non la reference de l'operateur, qui n'existe
     * qu'apres l'appel chez lui.
     */
    public function findWithOrder(int|string $id): ?Payment;

    /**
     * Un paiement par la reference operateur, webhook compris.
     *
     * La colonne porte un index unique, donc la methode renvoie au plus une
     * ligne. C'est la seconde barriere d'idempotence apres la comparaison
     * d'etat : si deux webhooks concurrents portant la meme transaction
     * arrivent, l'unicite de la base interdit le second enregistrement.
     */
    public function findByTransactionId(string $transactionId): ?Payment;

    /**
     * Paiements d'une commande, du plus recent au plus ancien.
     *
     * @return Collection<int, Payment>
     */
    public function forOrder(Order $order);

    /**
     * Enregistre la reference operateur d'un paiement.
     *
     * Refuse une transaction deja portee par une autre ligne : cela signifierait
     * que l'operateur a rattache le meme reglement a deux commandes, ce qui
     * n'a pas de lecture rationnelle et ne doit pas etre enregistre
     * silencieusement.
     */
    public function attachTransactionId(Payment $payment, string $transactionId): Payment;

    /**
     * Le reglement a-t-il ete confirme par l'operateur ?
     *
     * Interroge la colonne `paid_at` plutot que le statut : la date de
     * reglement est la seule preuve bancaire, un statut pouvant avoir ete ecrit
     * sans confirmation reelle. Elle permet aussi de totaliser le montant reellement
     * encaisse sur une commande, tous moyens confondus.
     */
    public function settledAmount(Order $order): string;
}
