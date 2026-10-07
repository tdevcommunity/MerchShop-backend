<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;

/**
 * Representation d'une ligne de commande.
 *
 * Le nom du produit et celui de la variante sont exposes depuis la ligne, et
 * non lus sur le produit courant : la ligne fige ce qui a ete vendu au moment
 * de la commande. Un renommage ulterieur du catalogue ne doit pas modifier
 * l'historique d'une facture deja emise.
 *
 * @mixin OrderItem
 */
final class OrderItemResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderItem $item */
        $item = $this->resource;

        return [
            'uuid' => $item->uuid,

            // Identifiants du catalogue, pour le lien « voir le produit » du
            // back-office. Ils ne sont pas suffisants pour reconstruire le
            // prix : c'est `unitPrice` qui fait foi.
            'productUuid' => $item->product?->uuid,
            'variantUuid' => $item->variant?->uuid,

            'productName' => $item->product_name,

            /*
             * Categorie, taille et couleur sont lues sur des colonnes figees de
             * la ligne, et non sur le catalogue courant : meme traitement que
             * `productName`, et pour la meme raison. Un produit deplace de
             * categorie, ou une variante dont la couleur a change, ne doit pas
             * reecrire l'historique d'une facture deja emise.
             *
             * La spec Data (section 13) en fait des donnees exigees par ligne,
             * ce qui n'est tenable que si elles sont conservees au moment de la
             * vente et pas recalculees plus tard.
             */
            'productCategory' => $item->product_category,
            'size' => $item->size,
            'color' => $item->color,

            'variantName' => $item->variant_name,

            'quantity' => $item->quantity,
            'unitPrice' => $item->unit_price,
            'totalPrice' => $item->total_price,
        ];
    }
}
