<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Http\Requests\ApiRequest;

/**
 * Lecture du QR au guichet.
 *
 * Le scan n'envoie que le contenu du QR : l'application de controle n'a pas a
 * connaitre la commande a l'avance, et l'identifiant voyage donc dans le
 * contenu encode plutot que dans l'URL. C'est ce qui evite qu'un lien de scan
 * puisse servir a ouvrir une commande par simple navigation.
 */
final class PickupScanRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.required' => 'Aucun QR n’a ete lu.',
            'payload.max' => 'Le contenu du QR est trop long pour etre un QR de retrait.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payload' => 'contenu du QR',
        ];
    }
}
