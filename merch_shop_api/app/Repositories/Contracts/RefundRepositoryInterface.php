<?php

namespace App\Repositories\Contracts;

use App\Models\Order;
use App\Models\Refund;

/**
 * Acces aux demandes de restitution.
 *
 * @extends RepositoryInterface<Refund>
 */
interface RefundRepositoryInterface extends RepositoryInterface
{
    /**
     * Un refund par son uuid, avec la commande et le paiement charges.
     */
    public function findWithRelations(int|string $id): ?Refund;

    /**
     * La demande encore en attente pour cette commande, s'il y en a une.
     *
     * C'est la barriere d'idempotence du remboursement : un guichetier qui
     * reclique deux fois, ou deux guichetiers qui traitent la meme commande, ne
     * doivent pas envoyer l'argent deux fois. Une demande close n'est pas
     * retournee — une nouvelle demande apres un echec est un nouvel essai
     * deliberé, pas une repetition.
     */
    public function openForOrder(Order $order): ?Refund;

    /**
     * Un refund par la reference du depot chez l'operateur.
     *
     * C'est par cette reference que la notification de sortie d'argent nous
     * parle : nous n'avons choisi aucun identifiant pour elle, qui n'existe
     * qu'apres l'appel. La colonne est unique en base, donc la methode renvoie
     * au plus une ligne.
     */
    public function findByPayoutReference(string $reference): ?Refund;
}
