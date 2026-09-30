<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Regle unique d'ecriture du catalogue, partagee par les policies produit et
 * categorie.
 *
 * Elle existe pour que la decision « qui peut modifier la vitrine » soit
 * ecrite une seule fois. Dupliquee dans chaque policy, elle divergerait des
 * la premiere evolution : le cas le plus dangereux n'est pas une policy
 * fausse, mais deux policies vraies pour des raisons differentes.
 */
trait ManagesCatalog
{
    /**
     * L'utilisateur a-t-il le droit d'ecrire dans le catalogue ?
     *
     * Reserve a l'administrateur. Le role staff sert au guichet et au scan :
     * il doit pouvoir servir une commande, pas modifier ce que voient tous les
     * visiteurs du shop. Un compte client n'a evidemment aucun droit ici.
     *
     * Le statut du compte est verifie en plus du role : un administrateur
     * desactive ne doit pas conserver son pouvoir d'ecriture, meme si sa
     * session est encore ouverte.
     */
    protected function managesCatalog(User $user): bool
    {
        return $user->role === UserRole::ADMIN && $user->status->isActive();
    }
}
