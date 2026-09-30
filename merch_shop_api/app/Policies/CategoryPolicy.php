<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ManagesCatalog;

/**
 * Droits d'ecriture sur les categories du catalogue.
 *
 * L'ecriture du catalogue est reservee a l'administrateur : ces routes
 * modifient ce que voit l'ensemble des visiteurs, et un compte staff (guichet,
 * scan) n'a pas a pouvoir changer le contenu de la boutique. La lecture, elle,
 * n'est pas contrainte : elle est ouverte a tous, y compris sans session.
 *
 * Les verbes de lecture (`viewAny`, `view`) n'ont pas de methode ici : la
 * lecture du catalogue est publique, elle n'a donc pas a etre autorisee. Laravel
 * considere l'absence de methode comme une autorisation accordee, ce qui est
 * exact dans ce cas et évite d'écrire `return true` quatre fois.
 */
final class CategoryPolicy
{
    use ManagesCatalog;

    public function create(User $user): bool
    {
        return $this->managesCatalog($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->managesCatalog($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->managesCatalog($user);
    }
}
