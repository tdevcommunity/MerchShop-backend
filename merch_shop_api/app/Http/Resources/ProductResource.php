<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Http\Request;

/**
 * Representation publique d'un produit.
 *
 * Les variantes sont imbriquees et exposees via `whenLoaded` : la ressource ne
 * les charge pas elle-meme. Le chargement est fait en un aller-retour par le
 * repository, et une ressource qui declencherait un chargement par produit
 * transformerait la page de catalogue en un nombre de requetes proportionnel
 * au nombre de produits affiches.
 *
 * `price_from` est le prix de la declinaison la moins chere effectivement
 * vendable. Un prix « a partir de » calcule sur toutes les variantes, y compris
 * une declinaison epuisee, afficherait un montant que le visiteur ne peut pas
 * payer, alors que c'est precisement l'information qu'il cherche.
 *
 * @mixin Product
 */
final class ProductResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        // Le prix de depart et la disponibilite se derivent des variantes, donc
        // ils ne sont calcules que si le repository les a chargees. Dans le cas
        // contraire on renvoie `null` plutot que de declencher un chargement
        // ici : une ressource qui interroge la base est un piege de requete
        // supplementaire par element liste.
        $sellable = $product->relationLoaded('variants')
            ? $product->variants->filter(fn (Variant $variant): bool => $variant->is_available)
            : null;

        return [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'description' => $product->description,

            /*
             * Photo du produit, en URL absolue.
             *
             * Elle est nullable : un produit publie sans photo doit rester
             * publiable, et la boutique sait afficher un cadre vide a la place.
             * Bloquer la publication sur l'absence d'image reviendrait a faire de
             * l'illustration une condition de vente, alors qu'un t-shirt vendu au
             * stand se vend d'abord par son prix et sa taille.
             */
            'image_url' => $product->image_url,

            'slug' => $product->slug,
            'status' => $product->status->value,

            /*
             * La categorie est exposee en identifiant, nom et slug plutot qu'en
             * ressource imbriquee complete : un menu de navigation n'a besoin de
             * rien d'autre, et une ressource imbriquee alourdirait chaque ligne
             * du catalogue pour rien.
             */
            'category' => $product->relationLoaded('category') && $product->category !== null
                ? [
                    'uuid' => $product->category->uuid,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ]
                : null,

            'variants' => VariantResource::collection($this->whenLoaded('variants')),

            'variants_count' => $product->relationLoaded('variants') ? $product->variants->count() : null,

            'price_from' => $sellable?->min(fn (Variant $variant): float => (float) $variant->price),

            'is_available' => $sellable !== null && $sellable->isNotEmpty(),

            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
