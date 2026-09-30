<?php

namespace App\Enums;

/**
 * Moyen de paiement utilise par l'acheteur.
 *
 * Distinct de PaymentProvider : la methode est ce que l'acheteur a choisi
 * (mobile money, carte), le fournisseur est l'infrastructure qui a traite
 * l'appel (FedaPay, KKiaPay...). Un seul aggregateur peut encaisser les deux
 * methodes, d'ou la separation.
 */
enum PaymentMethod: string
{
    /** Mobile Money togoise (Flooz, T-Money) via un aggregateur. */
    case MOBILE_MONEY = 'mobile_money';

    /** Carte bancaire via un agregateur. */
    case CARD = 'card';
}
