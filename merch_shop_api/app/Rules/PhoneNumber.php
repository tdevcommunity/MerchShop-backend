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
 * Seul le Togo est accepte : le festival n'installe de stands que sur son
 * territoire, et un code pays etranger passe par ici finirait dans une colonne
 * a deux lettres qui ne serait pas la sienne.
 *
 * Le meme pays est declare ailleurs dans l'API — `RegisterRequest` valide le
 * compte sur `^\\+?228[0-9]{8}$` et le schema OpenAPI annonce la meme forme. Ces
 * trois lectures etaient tombees sur la Cote d'Ivoire, seule a regner ici : un
 * acheteur togolais pouvait creer son compte puis se faire refuser sa commande,
 * sur un numero que l'API venait d'accepter.
 */
final class PhoneNumber implements ValidationRule
{
    /** Indicatif du Togo, tel qu'il se colle au numero national. */
    private const COUNTRY = '228';

    /** Code pays attendu par l'operateur, dans la colonne voisine. */
    private const COUNTRY_CODE = 'tg';

    /**
     * Longueur d'un numero togolais : huit chiffres, fixe comme mobile.
     *
     * Une seule longueur, donc, et non les deux que le pays voisin autorisait.
     * Accepter dix chiffres laisserait passer un numero qui n'a pas d'abonne :
     * la commande serait « payee » vers une destination qui n'existe pas, et le
     * remboursement echouerait toujours.
     */
    private const NATIONAL_LENGTH = 8;

    /**
     * Prefixe d'appel international, ecrit `00228...`.
     *
     * Ecriture valide et frequente, et une des trois avec lesquelles un meme
     * numero se designe. Elle est retiree avant l'indicatif, car elle se trouve
     * devant lui.
     */
    private const INTERNATIONAL_PREFIX = '00';

    /**
     * Verifie la normalisation sans l'appliquer.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->normalise($value) === null) {
            $fail('Le numéro de téléphone n’est pas un numéro togolais valide.');
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
         * `00` devant l'indicatif n'est pas le debut du numero : c'est la
         * maniere d'ecrire un appel depuis l'international, et il se retire
         * avant toute autre lecture. La borne sur la longueur qui suit eviterait
         * de le confondre avec un numero national — huit chiffres ne peuvent pas
         * commencer par `00` — mais le retirer ici evite de dependre de cette
         * coincidence.
         */
        if (str_starts_with($digits, self::INTERNATIONAL_PREFIX)) {
            $digits = substr($digits, strlen(self::INTERNATIONAL_PREFIX));
        }

        /*
         * L'indicatif est retire une seule fois, et seulement d'un numero plus
         * long que le national. Sans cette borne, un numero national valide
         * commencerait par `228` — `22890123` est un Togois valide — et perdrait
         * ses trois premiers chiffres : un retrait vers un numero faux, a partir
         * d'une saisie valide. C'est le meme piege que celui que la regle
         * bicountryenne du pays voisin rendait permanent.
         */
        if (strlen($digits) > self::NATIONAL_LENGTH && str_starts_with($digits, self::COUNTRY)) {
            $digits = substr($digits, strlen(self::COUNTRY));
        }

        if (strlen($digits) !== self::NATIONAL_LENGTH) {
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
