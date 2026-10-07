<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Analytics\StoreEventsRequest;
use App\Http\Resources\AnalyticsEvent as AnalyticsEventResource;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evenements d'analyse et d'acquisition.
 *
 * Cette route est publique (pas d'authentification) mais l'event_time et le
 * session_id sont requis : les bots n'en envoient jamais un. Un serveur peut
 * ainsi distinguer le trafic humain d'un botnet sans credential.
 */
final class AnalyticsController extends ApiController
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * Enregistre un batch d'evenements analytiques.
     */
    public function store(StoreEventsRequest $request): JsonResponse
    {
        $this->analytics->storeEvents($request->body());

        return $this->jsonResource(collect(), 204);
    }

    /**
     * Récupère un événement analytique par son ID.
     */
    public function show(string $eventId, Request $request): AnalyticsEventResource
    {
        return AnalyticsEventResource::make($this->analytics->findEventOrFail($eventId));
    }

    /**
     * Supprime un événement analytique.
     */
    public function destroy(string $eventId): JsonResponse
    {
        $this->analytics->deleteEvent($eventId);

        return $this->jsonResource(collect(), 204);
    }
}
