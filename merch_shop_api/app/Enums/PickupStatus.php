<?php

namespace App\Enums;

/**
 * Etat du retrait physique d'une commande au stand merch.
 *
 * Regle de modelisation du plan de tracking : distinguer le droit et l'usage.
 * Une commande payee en attente de retrait a le droit d'etre servie (c'est
 * l'etat de la commande) mais ne l'a pas encore utilise : ces deux notions ne
 * doivent jamais fusionner dans un seul booleen, sinon le comptage des goodies
 * distribuees devient impossible.
 */
enum PickupStatus: string
{
    /** Commande eligible au retrait, pas encore servie. */
    case PENDING = 'pending';

    /** Commande remise au participant. */
    case PICKED_UP = 'picked_up';

    /** Retrait annule (commande non recuperee au festival). */
    case CANCELLED = 'cancelled';
}
