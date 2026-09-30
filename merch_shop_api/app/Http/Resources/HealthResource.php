<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Representation publique de l'etat de l'API.
 *
 * Aucun detail technique n'est expose : ni version de SGBD, ni identifiants
 * de connexion, ni message d'erreur original.
 *
 * C'est la seule ressource de l'API a ne pas envelopper un modele : la sonde
 * assemble son rapport dans `SystemHealthService` a partir de plusieurs
 * sources, dont aucune n'est une ligne de table. La forme de la reponse est
 * donc decrite explicitement sur la methode `toArray`, la resolution
 * automatique d'un modele n'ayant rien a deduire ici.
 */
final class HealthResource extends ApiResource
{
    /**
     * @param  array{status: string, checks: array<string, string>}  $resource
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'api_version' => config('api.version'),
            'checks' => $this->resource['checks'],
        ];
    }
}
