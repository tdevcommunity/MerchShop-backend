<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;

/**
 * Representation publique d'une categorie.
 *
 * `products_count` n'est pas une colonne de la table : c'est un compte calcule
 * a la lecture, par un `withCount` pose systematiquement par le repository (les
 * deux chemins de lecture, liste et detail, passent par des methodes qui le
 * chargent). Le menu du front a besoin de savoir s'il doit afficher une
 * categorie vide, et le calculer en cascade depuis le front coûterait une
 * requete par categorie affichee.
 *
 * L'attribut vaut null s'il n'a pas ete charge : la ressource ne le deduit
 * pas a la volee, ce qui transformerait une liste paginee en une temporelle
 * quadratique.
 *
 * @mixin Category
 */
final class CategoryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        return [
            'uuid' => $category->uuid,
            'name' => $category->name,
            'description' => $category->description,
            'slug' => $category->slug,
            'status' => $category->status->value,
            'products_count' => $category->products_count,
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }
}
