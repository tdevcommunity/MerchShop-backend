<?php

namespace App\Support\Api;

use InvalidArgumentException;

/**
 * Montants du festival, exprimes en francs CFA.
 *
 * Le festival ne vend qu'en francs CFA (XOF), et le franc CFA n'a pas de
 * subdivision : un montant payable est toujours un nombre entier de francs. Cette
 * classe porte cette regle plutot que de la laisser implicite dans les
 * migrations, parce qu'elle a une consequence peu evident : un arrondi ne peut
 * pas etre tolere en silence. Un prix de 2500,50 FCFA ne serait ni encaisse par
 * un mobile money, ni affiche sur un ticket ; on prefere le refuser.
 *
 * La devise n'est pas un parametre de la classe. Il n'y a qu'une devise, et une
 * colonne `currency` dans la base serait une reponse a une question que ce
 * projet ne pose pas.
 *
 * Le service est sans etat, donc partageable et testable seule.
 */
final class Money
{
    /**
     * Code de la devise, expose pour la documentation et les messages d'erreur.
     */
    public const CURRENCY = 'XOF';

    /**
     * Garde-fou de conversion, distinct de toute borne metier.
     *
     * Une regle de prix appartient a la validation, pas a l'arithmetique : un
     * total de commande est legitement superieur au prix d'un article, et un
     * garde-fou pose ici le refuserait. Ce que la conversion doit garantir, c'est
     * seulement qu'elle ne tronque pas silencieusement un entier qui deborde,
     * ce que la comparaison detecte.
     */
    private const OVERFLOW_GUARD = PHP_INT_MAX;

    /**
     * Convertit une valeur recue en montant entier de francs CFA.
     *
     * Les formes entieres sont acceptees telles quelles : « 2500 », « 2500.00 »,
     * « 2 500 » et « 2500,00 » designent la meme somme, et un operateur de
     * paiement n'est pas tenu d'ecrire la meme forme que l'API. La virgule est
     * donc lue, puis la normalisation se fait avant la verification, sinon le
     * separateur serait rejete comme un caractere invalide.
     *
     * Un montant non entier, lui, est refuse plutot que arrondi. La raison est
     * qu'un arrondi ici serait invisible : le catalogue afficherait un prix que
     * personne n'a choisi, et l'ecart se decouvrirait au moment du paiement.
     * C'est le service qui doit rendre visible un calcul non entier.
     */
    public function toAmount(string|int|float $amount): int
    {
        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('Un montant doit etre fini.');
            }

            /*
             * Un flottant dont la valeur entiere n'est pas exactement
             * representable — 2499,9999999999995 — ne doit pas etre lu comme un
             * non entier. La comparaison se fait sur la valeur formatee, qui est
             * ce que l'appelant a voulu ecrire.
             */
            $amount = sprintf('%.2F', $amount);
        }

        $value = str_replace([' ', "\u{00a0}"], '', (string) $amount);
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Un montant doit etre numerique.');
        }

        if (str_starts_with($value, '-')) {
            throw new InvalidArgumentException('Un montant ne peut pas etre negatif.');
        }

        [$units, $decimals] = array_pad(explode('.', $value, 2), 2, '');

        if ($decimals !== '' && rtrim($decimals, '0') !== '') {
            throw new InvalidArgumentException(
                'Un montant en '.self::CURRENCY.' est un nombre entier de francs : '
                .'le franc CFA n\'a pas de subdivision.',
            );
        }

        if (! preg_match('/^\d+$/', $units)) {
            throw new InvalidArgumentException('Un montant doit etre numerique.');
        }

        $integer = (int) $units;

        if ($integer > self::OVERFLOW_GUARD) {
            throw new InvalidArgumentException('Un montant est trop grand pour etre represente.');
        }

        return $integer;
    }

    /**
     * Somme des francs d'une liste de lignes.
     *
     * Les montants sont deja des entiers, ici la somme est donc entiere : c'est
     * tout l'interet d'avoir abandonne les centimes, une remise ou un port
     * s'y additionnent sans qu'un centime apparaisse ou disparaisse.
     *
     * @param  array<int, int>  $amounts
     */
    public function sum(array $amounts): int
    {
        return array_sum($amounts);
    }

    /**
     * Deux montants designent-ils la meme somme ?
     *
     * La comparaison se fait sur la valeur convertie et non sur l'ecriture : un
     * montant relu en base et un montant calcule ici n'ont pas forcement la meme
     * forme, alors qu'ils valent la meme chose.
     */
    public function equals(string|int|float $left, string|int|float $right): bool
    {
        return $this->toAmount($left) === $this->toAmount($right);
    }

    /**
     * Arrondit un montant calcule vers le franc entier le plus proche.
     *
     * Reserve au calcul, jamais a la saisie : une remise en pourcentage produit
     * un resultat non entier, et il faut bien choisir ce qu'on fait de la
     * subdivision. L'arrondi est commercial — au plus proche, la demi-unite
     * upwards — et non bancaire, parce que c'est ce que le client compare au
     * prix annonce, et ce que le guichetier additionne de tete.
     *
     * La valeur calculee est donc fixee ici, une fois, explicitement : c'est le
     * seul endroit du projet ou un montant peut devenir non entier, donc le seul
     * endroit ou un arrondi doit se trouver.
     */
    public function roundToPayable(float $amount): int
    {
        if (! is_finite($amount) || $amount < 0) {
            throw new InvalidArgumentException('Un montant doit etre fini et positif.');
        }

        return (int) floor($amount + 0.5);
    }
}
