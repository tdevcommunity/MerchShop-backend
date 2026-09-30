<?php

namespace App\Http\Requests\Api\V1\Category;

use App\Enums\CatalogStatus;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Payload de creation d'une categorie.
 *
 * Le `slug` est facultatif : absent, il est derive du nom par le service,
 * qui garantit l'unicite. Il reste acceptable pour un import ou une URL
 * imposee, auquel cas le service refuse toute collision.
 */
final class StoreCategoryRequest extends ApiRequest
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
            'name' => ['required', 'string', 'min:2', 'max:150'],

            'description' => ['nullable', 'string', 'max:5000'],

            /**
             * `alpha_dash` et non une regexp ouverte : le slug finit dans une
             * URL et dans un nom de fichier, donc il ne doit contenir ni
             * espace ni accent. Le service applique Str::slug() de toute
             * façon, cette règle évite seulement d'accepter puis de
             * transformer silencieusement une valeur déjà invalide.
             */
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:150'],

            /**
             * Enum fermé, sans liste de chaînes en dur : CatalogStatus est la
             * seule source des valeurs acceptables, donc ajouter un statut doit
             * passer par une migration du modèle et non par une chaîne acceptée
             * en base.
             */
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
