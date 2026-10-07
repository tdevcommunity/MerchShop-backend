<?php

namespace App\Http\Requests\Api\V1\Analytics;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Payload d'envoi d'evenements d'analyse.
 *
 * La spec Data du festival (sections 2, 15, 16, 17, 18) exige une collecte
 * en continu des events comportementaux et d'acquisition. Ce payload les
 * porte par lots pour reduire le nombre de requetes, et chaque element est
 * valide independamment des autres : un event malforme n'empêche pas les
 * autres d'être enregistrés.
 */
final class StoreEventsRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.event_name' => ['required', 'string', 'max:100'],
            'events.*.event_time' => ['required', 'date'],
            'events.*.session_id' => ['required', 'string', 'max:64'],
            'events.*.participant_id' => ['nullable', 'string', 'max:100'],
            'events.*.page' => ['nullable', 'string', 'max:500'],
            'events.*.product_id' => ['nullable', 'string', 'max:64'],
            'events.*.device_type' => ['nullable', 'string', 'max:50'],
            'events.*.browser' => ['nullable', 'string', 'max:100'],
            'events.*.os' => ['nullable', 'string', 'max:100'],
            'events.*.source' => ['nullable', 'string', 'max:100'],
            'events.*.campaign' => ['nullable', 'string', 'max:100'],
            'events.*.properties' => ['nullable', 'array'],
        ];
    }
}
