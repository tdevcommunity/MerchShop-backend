<?php

namespace App\Enums;

/**
 * Cycle de vie d'une commande.
 *
 * Le plan de tracking impose de ne jamais ecraser l'historique : ces valeurs
 * decrivent l'etat courant, la trace des transitions relevera d'une table
 * d'evenements dediee (chantier tracking, hors perimetre de ce lot).
 *
 * Chaque valeur n'est atteignable que depuis un etat precis, ce que le service
 * de commande validera lors de l'implementation des cas d'usage.
 */
enum OrderStatus: int
{
    /** Commande creee, en attente de paiement. */
    case PENDING_PAYMENT = 1;

    /** Paiement confirme par le webhook de l'operateur. */
    case PAID = 2;

    /** Commande payee et prete a etre retiree au stand merch. */
    case READY_FOR_PICKUP = 3;

    /** Commande remise au participant (scan MERCH_PICKUP valide). */
    case PICKED_UP = 4;

    /**
     * Commande annulee avant reglement.
     *
     * Une commande deja payee ne s'annule pas : elle se rembourse, ce qui
     * laisse une trace comptable de l'encaissement puis de sa restitution.
     */
    case CANCELLED = 5;

    /** Commande remboursee apres paiement. */
    case REFUNDED = 6;

    /**
     * La commande a-t-elle ete reglee au sens du plan de tracking ?
     *
     * Les commandes non reglees sont celles que le support doit relancer ; une
     * commande terminee par annulation ou remboursement est close sans l'etre.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::PAID, self::READY_FOR_PICKUP, self::PICKED_UP => true,
            self::PENDING_PAYMENT, self::CANCELLED, self::REFUNDED => false,
        };
    }
}
