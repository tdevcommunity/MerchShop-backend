<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PickupStatus;
use App\Enums\FulfillmentMethod;
use App\Enums\PaymentMethod;
use App\Enums\CatalogStatus;
use App\Exceptions\ApiException;
use App\Models\AnalyticsEvent;
use App\Models\VisitorAcquisition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Service pour la collecte et la gestion des donnees analytiques.
 *
 * Cette couche decouple l'application du stockage et assure les consolidations
 * requises par la spec Data du festival (sections 2, 3, 13, 14, 15, 16, 17,
 * 18, 20).
 */
final class AnalyticsService
{
    /**
     * Enregistre des evenements analytiques bruts.
     *
     * Chaque evenement est valide par la validation du payload, et insere en
     * transaction : soit la totalite du batch est inseree, soit rien.
     *
     * La table est en ajout seul, pas de mises a jour : les versions anterieures
     * d'un evenement sont donc conservables pour une reproduction fidele.
     */
    public function storeEvents(array $payload): void
    {
        DB::transaction(function () use ($payload): void {
            foreach ($payload['events'] as $item) {
                $event = $this->createEvent($item);
                $this->updateAcquisition($event->session_id, $item);
            }
        });
    }

    /**
     * Trouve un evenement par son UUID, ou leve une erreur 404.
     */
    public function findEventOrFail(string $eventId): AnalyticsEvent
    {
        $event = AnalyticsEvent::where('event_id', $eventId)->first();

        if ($event === null) {
            throw new ApiException('Evenement analytique introuvable.', 404, 'ANALYTICS_EVENT_NOT_FOUND');
        }

        return $event;
    }

    /**
     * Supprime un evenement de l'historique.
     */
    public function deleteEvent(string $eventId): void
    {
        AnalyticsEvent::where('event_id', $eventId)->delete();
    }

    /**
     * Cree un evenement a partir du payload valide.
     */
    private function createEvent(array $event): AnalyticsEvent
    {
        $mapped = [
            'event_id' => (string) Str::uuid(),
            'event_name' => $event['event_name'],
            'event_time' => $event['event_time'],
            'received_at' => now(),
            'session_id' => $event['session_id'],
            'participant_id' => $event['participant_id'] ?? null,
            'page' => $event['page'] ?? null,
            'product_id' => $event['product_id'] ?? null,
            'device_type' => $event['device_type'] ?? null,
            'browser' => $event['browser'] ?? null,
            'os' => $event['os'] ?? null,
            'source' => $event['source'] ?? null,
            'campaign' => $event['campaign'] ?? null,
            'properties' => $event['properties'] ?? null,
        ];

        // Supprimer les cles nulles pour garder la table propre.
        $mapped = array_filter($mapped, fn ($v): bool => $v !== null);

        return AnalyticsEvent::create($mapped);
    }

    /**
     * Met a jour ou cree un enregistrement d'acquisition pour une session.
     */
    private function updateAcquisition(string $sessionId, array $event): void
    {
        $acq = VisitorAcquisition::where('session_id', $sessionId)->first();
        $now = CarbonImmutable::now();

        $data = [
            'session_id' => $sessionId,
            'source' => $event['source'] ?? null,
            'medium' => $event['medium'] ?? null,
            'campaign' => $event['campaign'] ?? null,
            'content' => $event['content'] ?? null,
            'term' => $event['term'] ?? null,
            'referrer' => $event['referrer'] ?? null,
            'landing_page' => $event['page'] ?? null,
            'first_visit_at' => $acq?->first_visit_at ?? $now,
            'last_visit_at' => $now,
        ];

        if ($acq !== null) {
            $acq->update($data);
        } else {
            VisitorAcquisition::create($data);
        }
    }
}