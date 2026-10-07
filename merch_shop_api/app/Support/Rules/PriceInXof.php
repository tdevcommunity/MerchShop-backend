<?php

namespace App\Support\Rules;

use App\Support\Api\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Un prix du catalogue, en francs CFA.
 *
 * La regle refuse un montant non entier, mais le message dit pourquoi : le franc
 * CFA n'a pas de subdivision, donc « 2500,50 » n'est pas un prix aberrant qu'il
 * faudrait corriger, c'est un montant qui n'existe pas. Un message d'erreur qui
 * ne dit que « le format est invalide » enverrait l'administrateur chercher un
 * erreur de saisie la ou il y a une regle de devise.
 */
final class PriceInXof implements ValidationRule
{
    /**
     * Determine si la regle est validee.
     *
     * La conversion passe par `Money`, qui porte la regle de devise : la regle de
     * validation et le calcul au moment du paiement ne peuvent donc pas diverger
     * sur ce qu'est un montant payable. Elle accepte au passage les formes
     * entieres equivalents, « 2500.00 » et « 2500,00 », qu'un operateur de
     * paiement ou une saisie au clavier produisent aussi.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /*
         * Un JSON porte le montant comme un nombre ou comme une chaine, et les
         * deux doivent passer : un operateur de paiement envoie l'un, un
         * back-office l'autre. Cette distinction appartient a `Money`, qui lit
         * les deux, et non a cette regle.
         */
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('Le champ :attribute doit etre un montant en francs CFA.');

            return;
        }

        try {
            app(Money::class)->toAmount($value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
