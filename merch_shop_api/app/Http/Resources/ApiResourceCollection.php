<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\NormalizesResponseKeys;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Collection de ressources de l'API.
 *
 * Laravel construit par defaut une `AnonymousResourceCollection` pour
 * `Resource::collection()`, qui n'herite pas de `ApiResource` et echapperait
 * donc a la normalisation camelCase. Cette classe la remplace afin que la
 * forme de la reponse soit identique pour une ressource unique et pour une
 * liste.
 */
class ApiResourceCollection extends AnonymousResourceCollection
{
    use NormalizesResponseKeys;
}
