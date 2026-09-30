<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Base de toutes les requetes de validation de l'API.
 *
 * Chaque endpoint doit passer par une classe fille qui declare `rules()` et
 * `authorize()`. Un controleur qui lit l'entree brute (`$request->all()`,
 * `$request->input()`) sans passer par un ApiRequest viole les standards de
 * securite de l'equipe : le client n'est jamais une source de verite.
 */
abstract class ApiRequest extends FormRequest
{
    /**
     * Le client n'est jamais redirige : une erreur de validation sur une route
     * /api/* doit produire un 422 JSON, pas une redirection 302. Le rendu par
     * defaut de FormRequest tente de construire une URL de retour, ce qui
     * suppose une session et un referer — absents sur une API.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw (new ValidationException($validator))->errorBag($this->errorBag);
    }

    /**
     * Payload valide, type comme un tableau de paires cle/valeur.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        /** @var array<string, mixed> */
        return $this->validated();
    }
}
