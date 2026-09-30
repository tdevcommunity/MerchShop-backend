<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Payload d'inscription.
 *
 * Ni le role ni le statut ne sont acceptes ici, meme pas en facultatif : leur
 * presence ouvrirait une escalade de privileges par simple ajout de cle dans
 * le corps de la requete. Le service AuthService les force a CUSTOMER/ACTIVE.
 */
final class RegisterRequest extends ApiRequest
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
            'firstname' => ['required', 'string', 'min:2', 'max:100'],
            'lastname' => ['required', 'string', 'min:2', 'max:100'],

            /**
             * Numéro togolais : 8 chiffres commençant par 2, avec ou sans `+`
             * international. Le service support a besoin d'un format saisissable,
             * pas d'un identifiant de base valide partout — un validateur dédié
             * (`Rule`) remplacerait cette regexp dès qu'un autre pays ouvre la
             * boutique.
             *
             * La règle d'unicité inclut les lignes supprimées logiquement, ce
             * qui est exactement ce qu'il faut ici : l'index unique porte sur la
             * colonne seule, donc un compte supprimé occupe toujours son adresse.
             * Appeler `withoutTrashed()` laisserait passer la validation puis
             * ferait échouer l'insertion sur une violation de contrainte (erreur
             * 500).
             */
            'phone' => [
                'required',
                'string',
                'regex:/^\+?228[0-9]{8}$/',
                Rule::unique('users', 'phone'),
            ],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email'),
            ],

            /**
             * Le mot de passe n'est jamais validé par une simple longueur : la
             * longueur seule pousse les comptes vers des phrases prévisibles.
             * Le constructeur `Password` exprime la règle métier d'un coup et
             * reste la seule forme qui accepte `mixedCase` (les règles en
             * chaîne n'exposent pas les contraintes de composition).
             *
             * `max:72` : bcrypt ignore ce qui suit 72 octets, donc un mot de
             * passe plus long est silencieusement tronqué et deux valeurs
             * différentes deviennent le même compte.
             */
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->max(72)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Le numéro doit être un numéro togolais valide (ex: +228 90 12 34 56).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'firstname' => 'prénom',
            'lastname' => 'nom',
            'phone' => 'numéro de téléphone',
            'email' => 'e-mail',
            'password' => 'mot de passe',
            'password_confirmation' => 'confirmation du mot de passe',
        ];
    }
}
