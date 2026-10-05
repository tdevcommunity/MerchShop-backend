<?php

namespace App\Enums;

/**
 * Etat d'une demande de restitution d'argent.
 *
 * Cette enum existe pour une raison unique : separer « demande » de « faite ».
 * Un remboursement declenche un depot chez un operateur mobile money, et ce
 * depot ne se termine pas au moment ou l'API le demande — il se confirme plus
 * tard, par une notification. Tant que la confirmation n'est pas arrivee,
 * l'argent est encore chez nous, et l'ecrire comme fait serait faux.
 */
enum RefundStatus: int
{
    /** Depot demande, sortie d'argent pas encore confirmee par l'operateur. */
    case PENDING = 1;

    /** Sortie d'argent confirmee par l'operateur. */
    case SETTLED = 2;

    /** Depot refuse par l'operateur : l'argent n'est jamais sorti. */
    case FAILED = 3;

    /**
     * La demande est-elle encore susceptible d'aboutir ?
     *
     * Seule une demande en attente se trouve dans cette situation : elle peut
     * encore etre confirmee, ou son retrait etre tente de nouveau apres un
     * echec passager. Une demande close ne l'est plus.
     */
    public function isOpen(): bool
    {
        return $this === self::PENDING;
    }
}
