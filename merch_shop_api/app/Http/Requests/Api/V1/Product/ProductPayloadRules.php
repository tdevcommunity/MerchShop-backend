<?php

namespace App\Http\Requests\Api\V1\Product;

use App\Enums\CatalogStatus;
use App\Support\Rules\PriceInXof;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Règles communes au payload produit.
 *
 * Isolées dans un trait parce que création et mise à jour partagent tout, sauf
 * le caractère obligatoire des champs. Les règles sont écrites une seule fois :
 * deux listes divergentes (une contrainte de stock différente entre POST et
 * PUT) sont le genre d'écart qui n'apparaît qu'en production.
 *
 * Aucune règle d'unicité n'est posée sur `sku` ici. Elle ne peut pas l'être de
 * façon fiable : l'unicité est globale en base et non par produit, et une
 * variante en cours de modification conserve son propre SKU. Le contrôle est
 * donc fait par le service, qui a la liste des variantes du produit sous les
 * yeux, et qui renvoie une erreur 409 nommant le SKU en cause.
 */
trait ProductPayloadRules
{
    /**
     * Règles des attributs du produit.
     *
     * @param  bool  $partial  true pour une mise à jour, où chaque champ peut
     *                         être omis
     * @return array<string, array<int, mixed>>
     */
    protected function productAttributes(bool $partial): array
    {
        $required = $partial
            ? static fn (string $rule): array => ['sometimes', 'required', $rule]
            : static fn (string $rule): array => ['required', $rule];

        return [
            'name' => [...$required('string'), 'min:2', 'max:200'],

            'description' => ['nullable', 'string', 'max:10000'],

            /**
             * `whereNull('deleted_at')` : une catégorie supprimée logiquement
             * n'est pas une destination valide, alors que la ligne existe
             * encore en base. Sans cette condition, la validation passerait et
             * le produit deviendrait invisible du catalogue public.
             */
            'category_id' => [...$required('integer'), Rule::exists('categories', 'id')->whereNull('deleted_at')],

            'slug' => ['nullable', 'string', 'alpha_dash', 'max:200'],

            'status' => ['sometimes', 'required', Rule::enum(CatalogStatus::class)],
        ];
    }

    /**
     * Règles du tableau `variants` et de ses entrées.
     *
     * Le tableau est facultatif à la création : un produit peut être préparé
     * avant que ses déclinaisons soient saisies. Il ne l'est pas davantage en
     * mise à jour, où son absence signifie « je ne touche pas aux variantes »
     * et sa présence, même vide, signifie « le produit n'a plus de variante ».
     *
     * @return array<string, array<int, mixed>>
     */
    protected function variantAttributes(): array
    {
        return [
            'variants' => ['sometimes', 'array'],

            'variants.*' => ['array'],

            /**
             * L'uuid identifie une variante existante de CE produit. Il est
             * facultatif : son absence ne signifie pas « créé » de façon
             * certaine, car le service reconnaît aussi une variante à partir de
             * son SKU. C'est pourquoi un uuid inconnu est refusé par le service
             * (422) et non ignoré par la validation.
             */
            'variants.*.uuid' => ['nullable', 'string', 'uuid'],

            /**
             * La référence vendue au guichet : 100 caractères suffisent
             * largement, la borne évite un payload arbitrairement long en base.
             */
            'variants.*.sku' => ['required', 'string', 'max:100'],

            'variants.*.name' => ['required', 'string', 'min:1', 'max:150'],

            /**
             * Prix en francs CFA, donc un entier : le franc CFA n'a pas de
             * subdivision, et « 2500,50 » n'est ni encaisseable par un mobile
             * money ni affichable sur un ticket. Un prix non entier est refusé
             * plutôt qu'arrondi, parce qu'un arrondi silencieux laisserait croire à
             * un montant que personne n'a choisi.
             *
             * Le format est tolérant : « 2500 », « 2500.00 » et « 2500,00 »
             * désignent la même somme, parce qu'un back-office saisit au clavier
             * n'écrit pas forcément la forme de l'API.
             */
            'variants.*.price' => ['required', new PriceInXof],

            /**
             * Le stock recoupe la contrainte CHECK (stock >= 0) de la base, qui
             * ne s'applique qu'à PostgreSQL et MySQL. La valider ici rend le
             * comportement identique sur SQLite, où la contrainte n'existe pas,
             * et évite un 500 sur une base qui, elle, l'applique.
             */
            'variants.*.stock' => ['required', 'integer', 'min:0', 'max:4294967295'],

            'variants.*.status' => ['sometimes', 'required', Rule::enum(CatalogStatus::class)],
        ];
    }

    /**
     * Règles de la variante par défaut.
     *
     * Un produit sans déclinaison n'a de prix ni de stock, donc il n'a rien de
     * vendable : la boutique ne peut pas l'afficher et le client ne peut pas
     * l'acheter. Plutôt que d'accepter un produit muet, l'API exige le prix et
     * le stock, et le service crée une variante unique qui les porte.
     *
     * Le SKU et le nom restent facultatifs parce qu'ils se déduisent du produit
     * quand ils manquent : la référence est posée au stand à partir du produit,
     * l'administrateur n'a pas à l'inventer.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function defaultVariantAttributes(): array
    {
        return [
            'default_variant' => ['nullable', 'array'],

            'default_variant.sku' => ['nullable', 'string', 'max:100'],

            'default_variant.name' => ['nullable', 'string', 'min:1', 'max:150'],

            'default_variant.price' => ['required_with:default_variant', new PriceInXof],

            'default_variant.stock' => ['required_with:default_variant', 'integer', 'min:0', 'max:4294967295'],

            'default_variant.status' => ['sometimes', 'required', Rule::enum(CatalogStatus::class)],
        ];
    }

    /**
     * Le payload tient-il compte des déclinaisons ?
     *
     * Deux fautes de saisie, toutes deux signalées ici plutôt que dans le
     * service, pour que le client puisse les corriger sans passer par une
     * erreur 500 :
     *
     *   - un produit sans variante et sans `default_variant` n'a pas de prix ;
     *   - un produit à la fois découpé en variantes et doté d'une variante par
     *     défaut porte deux prix concurrents, et le service devrait en choisir
     *     un silencieusement.
     */
    protected function withVariantConsistency(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasVariants = (bool) $this->input('variants');
            $hasDefault = $this->input('default_variant') !== null;

            if ($hasVariants && $hasDefault) {
                $validator->errors()->add(
                    'default_variant',
                    'Un produit ne peut pas avoir à la fois des variantes et une variante par défaut.',
                );

                return;
            }

            if (! $hasVariants && ! $hasDefault) {
                $validator->errors()->add(
                    'default_variant',
                    'Un produit sans variante doit indiquer le prix et le stock de sa variante par défaut.',
                );
            }
        });
    }

    /**
     * Libellés lisibles pour les messages d'erreur des champs imbriqués.
     *
     * @return array<string, string>
     */
    protected function productAttributesMessages(): array
    {
        return [
            'name' => 'nom',
            'description' => 'description',
            'category_id' => 'catégorie',
            'slug' => 'slug',
            'status' => 'statut',
            'variants' => 'variantes',
            'variants.*.sku' => 'référence (SKU) de la variante',
            'variants.*.name' => 'nom de la variante',
            'variants.*.price' => 'prix de la variante',
            'variants.*.stock' => 'stock de la variante',
            'variants.*.status' => 'statut de la variante',
            'default_variant' => 'variante par défaut',
            'default_variant.sku' => 'référence (SKU) de la variante par défaut',
            'default_variant.name' => 'nom de la variante par défaut',
            'default_variant.price' => 'prix de la variante par défaut',
            'default_variant.stock' => 'stock de la variante par défaut',
        ];
    }
}
