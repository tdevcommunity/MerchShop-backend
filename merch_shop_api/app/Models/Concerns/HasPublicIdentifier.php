<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Identifiant public stable d'une ressource, distinct de sa cle primaire.
 *
 * Le diagramme du shop et le plan de tracking designent chaque entite par un
 * `uuid`, alors que dans  NAMING_CONVENTIONS.md la section 6 impose `id` en cle primaire
 * et `<table>_id` en cle etrangere. Les deux sont satisfaits : `id` reste la PK
 * interne : courte, donc rapide a indexer, et `uuid` est l'identifiant expose
 * sur l'API et recopie dans les systemes partenaires (app de scan, back-office).
 *
 * Pourquoi pas HasUuids de Laravel : ce trait transforme la cle primaire en
 * uuid. On veut ici l'inverse : une PK entiere et un uuid a cote.
 *
 * Ce trait ne porte que la generation. L'adressage par uuid est declare cote
 * modele avec l'attribut #[RouteKey('uuid')], que Laravel applique deja au
 * route model binding.
 */
trait HasPublicIdentifier
{
    /**
     * Boot du modele : garantit un uuid sur chaque nouvel enregistrement.
     *
     * Rempli seulement s'il est absent, ce qui laisse un uuid fourni par un
     * import ou une reprise de donnees passer tel quel.
     */
    protected static function bootHasPublicIdentifier(): void
    {
        // Pas d'appel parent:: ici : le modele parent n'a pas cette
        // methode, et l'appel se resoudrait par __callStatic, qui
        // reentrerait dans le boot en cours.
        static::creating(function ($model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
