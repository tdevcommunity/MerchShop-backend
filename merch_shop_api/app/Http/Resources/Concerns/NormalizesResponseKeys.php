<?php

namespace App\Http\Resources\Concerns;

use App\Support\Api\CamelCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Normalise les cles de la reponse JSON en camelCase.
 *
 * Partage entre ApiResource (ressource unique) et ApiResourceCollection
 * (liste, paginee ou non) : une seule implementation, donc pas de divergence
 * entre les deux formes de reponse.
 *
 * L'operation porte sur la charge utile finale (`data`, `links`, `meta`), ce
 * qui normalise aussi les ressources imbriquees sans travail supplementaire.
 */
trait NormalizesResponseKeys
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setData(CamelCase::keys($response->getData(true)));
    }
}
