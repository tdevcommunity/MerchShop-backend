<?php

namespace App\Http\Requests\Api\V1\Category;

use App\Enums\CatalogStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Payload de mise a jour d'une categorie.
 *
 * Toutes les regles sont facultatives : une mise a jour partielle (le
 * back-office renomme une categorie sans y toucher) ne doit pas avoir a
 * renvoyer le descriptif complet. Le service separe alors les cles presentes
 * des cles absentes.
 */
final class UpdateCategoryRequest extends ApiRequest
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
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'slug' => ['sometimes', 'nullable', 'string', 'alpha_dash', 'max:150'],
            'status' => ['sometimes', 'required', Rule::enum(CatalogStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nom',
            'description' => 'description',
            'slug' => 'slug',
            'status' => 'statut',
        ];
    }
}
