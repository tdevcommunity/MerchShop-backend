<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Droits d'acces a une commande.
 *
 * Trois usages, trois questions differentes, et la separation est le point
 * important : la commande est la seule ressource de l'API dont la lecture est
 * cloisonnee par client, parce que c'est la seule qui contienne une adresse de
 * livraison et un historique de paiement.
 *
 *   - `view` : le client qui a commande, ou le guichet ;
 *   - `operate` : le guichet uniquement, pour servir, marquer pret ou
 *     rembourser. Un client ne fait jamais ces transitions lui-meme, meme sur
 *     sa propre commande : les faire par l'API client reviendrait a laisser
 *     n'importe qui s'attribuer une commande payee.
 *   - `cancel` : le client, mais seulement avant reglement, ce que le service
 *     verifie de nouveau.
 */
final class OrderPolicy
{
    /**
     * Lecture de la commande.
     *
     * Le role est verifie avec le statut du compte : un membre du guichet
     * desactive perd son acces immediatement, meme avec une session ouverte.
     */
    public function view(User $user, Order $order): bool
    {
        return $this->operatesAtCounter($user) || $order->isOwnedBy($user);
    }

    /**
     * Action de guichet sur une commande : servir, marquer pret.
     */
    public function operate(User $user, Order $order): bool
    {
        return $this->operatesAtCounter($user);
    }

    /**
     * Acces a la file de retrait et au scan.
     *
     * Ces deux operations ne portent pas sur une commande : le guichetier
     * consulte la file avant de savoir laquelle il va servir, et le scan
     * decouvre la commande par le QR. La regle est donc ecrite sans second
     * argument, plutot que de recevoir une commande nulle pour la seule raison
     * que la requete n'en designe pas encore.
     */
    public function useCounter(User $user): bool
    {
        return $this->operatesAtCounter($user);
    }

    /**
     * Annulation a l'initiative du client.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $this->operatesAtCounter($user) || $order->isOwnedBy($user);
    }

    /**
     * Annulation et remboursement par un membre du guichet.
     *
     * Distinct de `cancel` : rembourser n'est pas annuler, et le droit de
     * rembourser n'implique pas qu'il puisse annuler une commande
     * d'autrui, ce qui laisserait un guichetier effacer une vente d'un simple
     * clic.
     */
    public function refund(User $user, Order $order): bool
    {
        return $this->operatesAtCounter($user);
    }

    /**
     * Le compte est-il membre du guichet et actif ?
     */
    private function operatesAtCounter(User $user): bool
    {
        return $user->role->canOperateMerch() && $user->status->isActive();
    }
}
