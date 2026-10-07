<?php

namespace App\Support\Api;

/**
 * Une action tracee dans le journal d'audit.
 *
 * Les valeurs sont des verbes au passe simple, parce que c'est la seule forme qui
 * se lise sans traduction a la ligne du tableau : « role_change » dit ce qui s'est
 * passe, « role » ne dit rien.
 *
 * Elles sont stockees, donc stables : en renommer une perdrait le journal, et le
 * journal est la seule reponse a « quand et par qui ce produit a-t-il change ».
 *
 * L'enumeration existe pour que le vocabulaire soit verifie a l'ecriture. Elle
 * n'interdit pas d'en ajouter — un nouveau chantier ajoute une action, et il le
 * doit — mais elle oblige a la nommer ici plutot que de la glisser dans une
 * chaine libre ou personne ne la trouvera.
 */
enum AuditAction: string
{
    /** Un produit ou un rayon a ete cree. */
    case PRODUCT_CREATED = 'product_created';

    /** Un produit ou un rayon a ete modifie. */
    case PRODUCT_UPDATED = 'product_updated';

    /** Un produit ou un rayon a ete supprime. */
    case PRODUCT_DELETED = 'product_deleted';

    /** Un produit a change d'etat de publication. */
    case PRODUCT_STATUS_CHANGED = 'product_status_changed';

    /** Une declinaison a ete creee ou modifiee dans un produit existant. */
    case VARIANT_SAVED = 'variant_saved';

    /** Le catalogue a ete copie : la source reste intacte. */
    case PRODUCT_DUPLICATED = 'product_duplicated';

    /** Le stock d'une declinaison a ete corrige a la main. */
    case STOCK_ADJUSTED = 'stock_adjusted';

    /** L'etat d'une commande a ete change par le guichet. */
    case ORDER_STATUS_CHANGED = 'order_status_changed';

    /** Une commande a ete annulee. */
    case ORDER_CANCELLED = 'order_cancelled';

    /** Un remboursement a ete demande a l'operateur. */
    case ORDER_REFUND_REQUESTED = 'order_refund_requested';

    /** Un retrait a ete valide au stand. */
    case ORDER_PICKUP_VALIDATED = 'order_pickup_validated';

    /** Un compte de guichet a ete cree. */
    case USER_CREATED = 'user_created';

    /** Le role d'un compte a ete change. */
    case USER_ROLE_CHANGED = 'user_role_changed';

    /** Un compte a ete active ou desactive. */
    case USER_STATUS_CHANGED = 'user_status_changed';

    /** Un mot de passe de guichet a ete reinitialise. */
    case USER_PASSWORD_RESET = 'user_password_reset';
}