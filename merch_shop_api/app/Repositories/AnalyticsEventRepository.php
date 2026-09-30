<?php

namespace App\Repositories;

use App\Models\AnalyticsEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Repositorie pour les evenements analytiques.
 *
 * Cette table est un journal en ajout seul, pas de logique metier complexe.
 * Les seules operations sont l'insertion et la lecture par ID.
 */
final class AnalyticsEventRepository
{
    public function __construct(private AnalyticsEvent $model) {}

    public function create(array $data): AnalyticsEvent
    {
        return $this->model->create($data);
    }

    public function findById(string $eventId): ?AnalyticsEvent
    {
        return $this->model->where('event_id', $eventId)->first();
    }

    public function delete(string $eventId): void
    {
        $this->model->where('event_id', $eventId)->delete();
    }
}