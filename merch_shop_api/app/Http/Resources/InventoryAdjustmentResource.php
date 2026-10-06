<?php

namespace App\Http\Resources;

use App\Models\InventoryAdjustment;
use Illuminate\Http\Request;

/**
 * Un mouvement de stock saisi a la main.
 *
 * Les trois etats sont exposes, pas seulement la variation. Un tableau qui ne
 * montre que « +5 » ne dit pas si le stock est passe de douze a dix-sept ou de
 * zero a cinq, et ces deux lignes ne se rattrapent pas de la meme facon : la
 * seconde est une entree en rayon, la premiere une sortie. C'est l'ecart entre
 * le seuil d'alerte et l'etat constate qui declenche une action au stand, donc
 * il doit etre lisible.
 *
 * Le produit et la declinaison sont nommes par leurs identifiants figes plutot
 * que par une relation : la ligne doit rester lisible apres la suppression du
 * produit, et une ligne de journal qui ne peut plus nommer ce qu'elle compte ne
 * sert a rien. Les relations restent donc chargees pour les seules lignes dont
 * l'article existe encore.
 *
 * @mixin InventoryAdjustment
 */
final class InventoryAdjustmentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryAdjustment $adjustment */
        $adjustment = $this->resource;

        return [
            'uuid' => $adjustment->uuid,

            /*
             * Ce qui compte.
             *
             * Les noms figes sont recopies a l'ecriture du mouvement, donc ils
             * disent ce que le comptage concernait au moment ou il a ete fait —
             * et non le nom actuel, qui pourrait avoir change depuis. C'est la
             * seule facon de relire un rapprochement apres un renommage.
             */
            'sku' => $adjustment->sku,
            'productName' => $adjustment->product_name,

            /*
             * Rattachement, absent quand l'article n'existe plus.
             *
             * Distinguer ces deux cas est deliberé : la ligne reste listee et
             * reste lisible, et le champ dit simplement qu'elle n'a plus d'article
             * auquel se raccrocher. Une ligne disparue de l'historique parce que
             * son produit a ete supprime serait la pire des deux lectures — on
             * perdrait le mouvement sans qu'on sache qu'il a jamais existe.
             */
            'productId' => $adjustment->product_id !== null
                ? $adjustment->product?->uuid
                : null,
            'variantId' => $adjustment->product_variant_id !== null
                ? $adjustment->variant?->uuid
                : null,

            'previousStock' => $adjustment->previous_stock,
            'delta' => $adjustment->delta,
            'nextStock' => $adjustment->next_stock,

            /*
             * Le signe, deduit.
             *
             * Expose parce que le signe d'un nombre ne se lit pas dans un
             * tableau : deux nombres qui ne se distinguent que par leur signe
             * disent moins bien « on a ajoute cinq pieces » que « on en a ajoute
             * cinq ». Et le deduire ici plutot que dans le front evite que deux
             * écrans n'en fassent deux traductions.
             */
            'direction' => $adjustment->isAddition() ? 'in' : 'out',

            'reason' => $adjustment->reason->value,
            'reasonLabel' => $adjustment->reason->label(),
            'note' => $adjustment->note,

            /*
             * L'auteur, recopie comme le produit.
             *
             * Meme raison : le journal doit rester lisible si le compte est
             * supprime ou desactive apres le festival.
             */
            'userId' => $adjustment->user_id !== null
                ? $adjustment->user?->uuid
                : null,
            'userEmail' => $adjustment->user_email,

            'createdAt' => $adjustment->created_at?->toIso8601String(),
        ];
    }
}