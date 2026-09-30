<?php

namespace App\Enums;

/**
 * Agregateur ayant traite la transaction.
 *
 * Les valeurs correspondent aux integrations prevues par le plan de tracking
 * du festival. Ajouter un fournisseur est une evolution de schema : la colonne
 * `provider` est une chaine et non un entier, pour ne pas contraindre la base
 * a une liste figee que le metier n'a pas encore arretee.
 */
enum PaymentProvider: string
{
    /** FedaPay. */
    case FEDAPAY = 'fedapay';

    /** KKiaPay. */
    case KKIAPAY = 'kkiapay';

    /** PayGate. */
    case PAYGATE = 'paygate';

    /** Flooz (MTN Mobile Money). */
    case FLOOZ = 'flooz';

    /** T-Money (Moov Money). */
    case TMONEY = 'tmoney';
}
