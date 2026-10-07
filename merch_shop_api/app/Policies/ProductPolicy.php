<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\ManagesCatalog;

/**
 * Droits d'ecriture sur les produits du catalogue.
 *
 * Memes regles que CategoryPolicy, appliquees aux produits et a leurs
 * variantes : la facade et la regle sont partagees, seule la classe change.
 */
final class ProductPolicy
{
    use ManagesCatalog;

    public function create(User $user): bool
    {
        return $this->managesCatalog($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->managesCatalog($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->managesCatalog($user);
    }
}
