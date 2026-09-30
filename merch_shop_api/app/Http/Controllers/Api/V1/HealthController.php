<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\HealthResource;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sonde de disponibilité de l'API (endpoint de readiness).
 *
 * Elle sert aussi de référence de câblage : c'est la chaîne complète
 * contrôleur -> service -> repository -> ressource. Toute nouvelle couche doit
 * être ajoutée de la même manière.
 */
final class HealthController extends ApiController
{
    /**
     * La forme de la réponse est déclarée ici plutôt que laissée à
     * l'inférence : la sonde n'enveloppe aucun modèle, son rapport étant
     * assemblé depuis plusieurs sources, il n'y a donc rien à déduire d'une
     * classe. Sans ce type, la spécification décrirait la réponse comme une
     * simple chaîne — ce qui est faux, et induirait le front en erreur.
     *
     * Deux détails sont normés ici à la main, parce que la résolution
     * automatique ne les voit pas : la réponse reste enveloppée sous `data`,
     * comme toute autre ressource, et ses clés sont en camelCase, la conversion
     * étant appliquée sur la charge utile finale par `NormalizesResponseKeys`.
     */
    public function __invoke(SystemHealthService $health): JsonResponse
    {
        $report = $health->readiness();

        return $this->jsonResource(
            HealthResource::make($report),
            $report['status'] === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
