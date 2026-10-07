<?php

namespace App\Enums;

/**
 * Mode de retrait d'une commande.
 *
 * Le tunnel d'achat propose ces deux choix au moment du checkout ; ils ne sont
 * pas equivalents pour le suivi : seule une commande en retrait genere un QR
 * Code a scanner au stand merch.
 */
enum FulfillmentMethod: string
{
    /** Retrait sur place le jour J au stand merch, via QR Code. */
    case PICKUP = 'pickup';

    /** Expedition par un transporteur. */
    case DELIVERY = 'delivery';

    /**
     * Ce mode genere-t-il un QR Code de retrait ?
     *
     * Le QR est le seul support du controle d'acces au stand : une commande
     * livree n'en recoit pas, et creer un QR pour elle n'aurait aucun sens.
     */
    public function requiresPickupQrCode(): bool
    {
        return $this === self::PICKUP;
    }
}
