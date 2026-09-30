<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\ApiRequest;

/**
 * Payload de connexion.
 *
 * La validation se limite a la forme des champs. Le couple email / mot de
 * passe est verifie par AuthService, qui renvoie un message unique pour tout
 * echec : distinguer ici « email mal forme » de « identifiants invalides »
 * n'apporterait rien au visiteur et confirmerait a un attaquant ce qu'il
 * cherche.
 */
final class LoginRequest extends ApiRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:72'],

            /**
             * « Se souvenir de moi » n'est pas un simple booléen de confort : il
             * fait poser un cookie de longue durée par le navigateur. On
             * vérifie qu'il s'agit bien d'un booléen, sinon un tableau ou une
             * chaîne arbitraire passerait dans la session sans être contrôlée.
             */
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'e-mail',
            'password' => 'mot de passe',
        ];
    }
}
