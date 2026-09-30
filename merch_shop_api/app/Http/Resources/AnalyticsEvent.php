<?php

namespace App\Http\Resources;

use App\Models\AnalyticsEvent;
use Illuminate\Http\Request;

final class AnalyticsEvent extends ApiResource
{
    public function toArray(Request $request): array
    {
        /** @var AnalyticsEvent $event */
        $event = $this->resource;

        return [
            'eventId' => $event->event_id,
            'eventName' => $event->event_name,
            'eventTime' => $event->event_time?->toIso8601String(),
            'sessionId' => $event->session_id,
            'participantId' => $event->participant_id,
            'page' => $event->page,
            'productId' => $event->product_id,
            'deviceType' => $event->device_type,
            'browser' => $event->browser,
            'os' => $event->os,
            'source' => $event->source,
            'campaign' => $event->campaign,
            'properties' => $event->properties,
            'receivedAt' => $event->received_at?->toIso8601String(),
        ];
    }
}