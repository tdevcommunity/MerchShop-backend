<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base des controleurs de l'API.
 *
 * Un controleur here est mince par construction : il resout le service, lui
 * passe les donnees deja validees par un ApiRequest, et retourne une ressource.
 * Ni requete Eloquent, ni regle metier.
 */
abstract class ApiController extends Controller
{
    /**
     * Nombre d'elements par page demande par le client, borne par la
     * configuration de l'API.
     *
     * Le plafond protege la base : sans lui, un client peut demander
     * `per_page=100000` et forcer le serveur a materialiser la table entiere
     * en memoire.
     */
    protected function perPage(Request $request): int
    {
        $requested = $request->integer('per_page', (int) config('api.pagination.default_per_page'));

        return max(1, min($requested, (int) config('api.pagination.max_per_page')));
    }

    /**
     * Serialise une ressource en reponse JSON en forcant le statut HTTP.
     *
     * Une JsonResource choisit son propre statut (200, ou 201 quand le modele
     * vient d'etre cree) ; cette methode permet de le surcharger, par exemple
     * pour un 503 de readiness, sans passer par un response()->json() manuel
     * qui perdrait la normalisation camelCase de ApiResource.
     */
    protected function jsonResource(JsonResource $resource, int $status = Response::HTTP_OK): JsonResponse
    {
        return $resource->response()->setStatusCode($status);
    }
}
