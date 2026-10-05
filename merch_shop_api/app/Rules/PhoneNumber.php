<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un numero de telephone mobile money normalisable.
 *
 * Cette regle existe parce que le numero est la seule donnee d'identite qu'on
 * demande a un festivalier dont on ne connait pas le compte, et parce que la
 * forme dans laquelle il est saisi ne se laisse pas deviner : `07 07 07 07 07`,
 * `+2250707070707`, `2250707070707` et `0707070707` designent le meme
 * abonnement. Valider la saisie telle quelle refuserait des numeros corrects,
 * et accepter la saisie telle quelle enverrait de l'argent a un numero
 * mal forme.
 *
 * Elle normalise plutot qu'elle ne refuse : le numero retenu est rendu en
 * format national sans indicatif, avec le pays separe dans la colonne voisine.
 * C'est exactement ce que l'operateur attend, et cela evite de deviner son
 * operateur a partir d'un prefixe — un prefixe qui ne dit pas l'operateur, et
 * qui change.
 *
 * Seule la Cote d'Ivoire est acceptee : le festival n'installe de stands
 * que sur son territoire, et un code pays etranger passe par ici finirait dans
 * une colonne a deux lettres qui ne serait pas la sienne.
 */
final class PhoneNumber implements ValidationRule
{
    /**
     * Indicatif du pays, et longueur des numeros nationaux.
     *
     * Les numeros ivoiriens mobiles font huit chiffres et commencent par 01 ou 05
     * ; fixes et numeros des autres operateurs en font dix. Les deux formes sont
     * acceptees : un festivalier sans compte peut avoir une ligne fixe.
     */
    private const COUNTRY = '225';

    private const COUNTRY_CODE = 'ci';

    private const NATIONAL_LENGTHS = [8, 10];

    /**
     * Verifie la normalisation sans l'appliquer.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->normalise($value) === null) {
            $fail('Le numéro de téléphone n’est pas un numéro mobile money ivoirien valide.');
        }
    }

    /**
     * Le numero au format national, sans indicatif.
     *
     * Retourne `null` si la saisie n'est pas un numero de ce pays : la regle de
     * validation et la normalisation doivent tomber d'accord, sinon une valeur
     * validee par une reponse 200 pourrait etre rejetee par l'appel a
     * l'operateur.
     */
    public static function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        /*
         * Tout ce qui n'est pas chiffre disparait : espaces, points, tirets et
         * parenthesees sont des facons d'ecrire le meme numero, pas des
         * caracteres qui le distinguent.
         */
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        /*
         * L'indicatif est retire une seule fois. Sans cette borne, un numero
         * national valide comme `0707070707` commencerait par `225` par
         * coincidence et perdrait ses trois premiers chiffres — un retrait vers
         * un numero faux, a partir d'une saisie valide.
         */
        if (str_starts_with($digits, self::COUNTRY) && strlen($digits) > 10) {
            $digits = substr($digits, 3);
        }

        $length = strlen($digits);

        if (! in_array($length, self::NATIONAL_LENGTHS, true)) {
            return null;
        }

        /*
         * Un numero dont tous les chiffres se repeten n'est pas un numero : il
         * ne peut designer aucun abonne. Le laisser passer produirait une
         * commande « remboursable » vers une destination qui n'existe pas, donc
         * un remboursement qui echoue toujours.
         */
        if (count(array_unique(str_split($digits))) === 1) {
            return null;
        }

        return $digits;
    }

    /**
     * Le code pays attendu par l'operateur.
     *
     * Expose ici plutot que repete dans les appelants : la valeur doit rester
     * identique entre la validation, l'enregistrement et l'appel, et trois
     * occurences d'un code pays seraient trois occasions de diverger.
     */
    public static function countryCode(): string
    {
        return self::COUNTRY_CODE;
    }
}
