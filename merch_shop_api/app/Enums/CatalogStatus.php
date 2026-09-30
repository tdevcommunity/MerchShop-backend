<?php

namespace App\Enums;

/**
 * Statut de publication d'un element du catalogue.
 *
 * Partage par les categories, produits et variantes : ces trois entites
 * obeissent au meme cycle de vie (on publie un produit, on ne le supprime pas,
 * on le retire de la vente). Un enum par entite serait dupliquation.
 *
 * Les valeurs sont stockees en base, donc consideres comme figees : les
 * applications partenaires (app de scan, back-office) s'y branchent.
 */
enum CatalogStatus: int
{
    /** Element masque du catalogue, conserve pour l'historique des commandes. */
    case INACTIVE = 0;

    /** Element visible et achetable. */
    case ACTIVE = 1;
}
