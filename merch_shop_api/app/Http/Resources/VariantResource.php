<?php

namespace App\Http\Resources;

use App\Models\Variant;
use Illuminate\Http\Request;

/**
 * Representation publique d'une declinaison de produit.
 *
 * `is_available` vient de l'acceseur du modele, qui combine statut et stock.
 * Le front n'a ainsi pas a refaire le calcul, et surtout les deux peuvent pas
 * diverger : l'affichage et l'ajout au panier portent la meme regle.
 *
 * @mixin Variant
 */
final class VariantResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Variant $variant */
        $variant = $this->resource;

        return [
            'uuid' => $variant->uuid,
            'sku' => $variant->sku,
            'name' => $variant->name,

            /*
             * Taille et couleur, en plus du libelle `name`.
             *
             * `name` reste le texte affiche au client et au guichet. Ces deux
             * colonnes en sont la decomposition analytique : la spec Data du
             * festival (section 13) exige de pouvoir agreger les ventes par
             * taille et par couleur, ce qu'un libelle libre ne permet pas, ni
             * pour un regroupement ni pour un rapprochement avec une table de
             * reference des tailles vendues.
             *
             * Nullable par nature : un accessoire n'a ni taille ni couleur, et
             * un textile peut n'avoir qu'une declinaison « Taille unique ».
             */
            'size' => $variant->size,
            'color' => $variant->color,

            // Prix en francs CFA, entier : le franc CFA n'a pas de subdivision,
            // et un entier en JSON ne peut pas perdre de precision a l'aller-
            // retour comme le ferait un flottant.
            'price' => $variant->price,
            'stock' => $variant->stock,
            'status' => $variant->status->value,
            'is_available' => $variant->is_available,
        ];
    }
}
