<?php

namespace App\Enums;

/**
 * Statut d'un compte utilisateur.
 *
 * Distinct de UserRole : le role dit ce que l'utilisateur a le droit de faire,
 * le statut dit si son compte est utilisable. Un compte peut etre inactif tout
 * en gardant son role pour etre reactive sans reattribuer de droits.
 */
enum UserStatus: int
{
    /** Compte desactive : authentification refusee, donnees conservees. */
    case INACTIVE = 0;

    /** Compte actif. */
    case ACTIVE = 1;

    /**
     * Le compte est-il utilisable ?
     *
     * Distinct de la seule comparaison `=== self::ACTIVE` dispersee dans les
     * policies et les services : le nom dit ce qu'on veut dire, et ajouter un
     * statut intermédiaire (SUSPENDED, par exemple) ne demandera qu'a etre
     * arbitre ici.
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
