<?php

namespace App\Enums;

/**
 * Role d'un utilisateur, du moins privilegie au plus privilegie.
 *
 * Enumeration fermee et non extensible a la volee : ajouter un role doit passer
 * par une migration, sinon un compte porte un droit que le code ne connait pas.
 */
enum UserRole: string
{
    /** Participant acheteur : catalogue, panier, ses propres commandes. */
    case CUSTOMER = 'customer';

    /** Personnel du festival : scan des retraits, suivi des commandes sur place. */
    case STAFF = 'staff';

    /** Administration complete du shop. */
    case ADMIN = 'admin';

    /**
     * Le role dispose-t-il de privileges d'exploitation sur le merch ?
     *
     * Utilise par le back-office pour le guichet : un STAFF peut servir une
     * commande, un CUSTOMER ne peut rien modifier.
     */
    public function canOperateMerch(): bool
    {
        return $this === self::STAFF || $this === self::ADMIN;
    }
}
