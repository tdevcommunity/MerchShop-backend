<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\NormalizesResponseKeys;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base de toutes les ressources de l'API.
 *
 * Deux garanties sont offertes ici, volontairement implementees au niveau de la
 * couche presentation plutot que sur les modeles :
 *
 *  1. les cles de la charge utile sont reecrites en camelCase, comme l'exige
 *     docs/NAMING_CONVENTIONS.md section 5. La conversion se fait sur la
 *     reponse finale (`data`, `links`, `meta`), donc une ressource imbriquee
 *     est normalisee elle aussi, sans avoir a le repeter ;
 *  2. la reponse reste enveloppee sous la cle `data` par defaut, les
 *     ressources paginees exposant en plus `links` et `meta`.
 *
 * La base de donnees reste en snake_case : la conversion ne touche ni les
 * modeles, ni les files d'attente, ni le cache.
 */
abstract class ApiResource extends JsonResource
{
    use NormalizesResponseKeys;

    /**
     * Force `Resource::collection()` a produire une `ApiResourceCollection`.
     *
     * Sans cela, la reponse d'une liste passerait par la classe anonyme de
     * Laravel, qui n'herite pas de cette base et serait donc renvoyee en
     * snake_case.
     */
    protected static function newCollection($resource)
    {
        return new ApiResourceCollection($resource, static::class);
    }
}
