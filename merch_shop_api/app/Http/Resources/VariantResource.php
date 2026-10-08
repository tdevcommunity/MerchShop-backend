<?php

namespace App\Http\Resources;

use App\Enums\CatalogStatus;
use App\Models\Variant;
use Illuminate\Http\Request;

/**
 * Une declinaison de produit.
 *
 * Trois champs meritent leur place ici, parce qu'ils evitent trois calculs
 * repetes dans des contextes differents.
 *
 * `lowStockThreshold` est le seuil propre a la declinaison, et il est renvoye
 * plutot que deduit : il existe une colonne, donc il doit venir de cette colonne
 * et non d'une constante recopiee dans le front, qui divergerait a la premiere
 * correction faite au stand.
 *
 * `stockLevel` est la comparaison de `stock` a ce seuil, et elle est faite ici
 * une fois pour toutes. Le tunnel affiche « en rupture », la liste de stock affiche
 * « bas », et le back-office trie sur « bas » : trois lectures de la meme
 * comparaison, donc trois endroits ou elle pouvait diverger. Elle se lit au
 * catalogue, dans la ressource, sans que personne n'ait a la refaire.
 *
 * Elle tient compte du statut, et pas seulement du nombre : une declinaison
 * desactivee n'est pas « disponible » meme avec du stock, et l'afficher comme
 * telle ferait croire au stand qu'il peut la vendre.
 *
 * Le libelle de niveau est expose en plus de sa valeur, parce que c'est lui qui
 * s'affiche et que le front n'a pas a porter le vocabulaire : ajouter un niveau
 * ici change le texte sans qu'une liste de libelles a synchroniser elsewhere
 * oublie de le faire.
 *
 * @mixin Variant
 */
final class VariantResource extends ApiResource
{
    /**
     * Niveaux de stock, du plus tranquille au plus urgent.
     *
     * Ordonnés par gravitye et non par ordre alphabetique, parce que c'est cet
     * ordre que le back-office utilise pour classer : un tableau de stock se lit
     * de haut en bas, et « rupture » doit arriver avant « disponible ».
     *
     * @return array<string, string>
     */
    private const LEVEL_LABELS = [
        'available' => 'Disponible',
        'low' => 'Stock bas',
        'out' => 'En rupture',
        'disabled' => 'Désactivée',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Variant $variant */
        $variant = $this->resource;

        $level = $this->stockLevel($variant);

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
            'imageUrl' => $variant->image_url,
            'colorHex' => $variant->color_hex,

            // Prix en francs CFA, entier : le franc CFA n'a pas de subdivision,
            // et un entier en JSON ne peut pas perdre de precision a l'aller-
            // retour comme le ferait un flottant.
            'price' => $variant->price,

            'stock' => $variant->stock,
            'lowStockThreshold' => $variant->low_stock_threshold,

            'status' => $variant->status->value,

            /*
             * `isAvailable` reste l'acceseur du modele, qui combine statut et
             * stock pour repondre a « peut-on l'ajouter au panier ? ». C'est une
             * question distincte de `stockLevel`, qui repond a « doit-on
             * commander ? » : une declinaison desactivee avec du stock n'est ni
             * commandable ni disponible, mais elle n'est pas non plus en rupture.
             * Les deux reponses sont exposees parce qu'elles ne se deduisent pas
             * l'une de l'autre.
             */
            'isAvailable' => $variant->is_available,

            'stockLevel' => $level,
            'stockLevelLabel' => self::LEVEL_LABELS[$level],
        ];
    }

    /**
     * Le niveau de stock d'une declinaison.
     *
     * Le statut passe avant le nombre : une declinaison desactivee est
     * « desactivee » quoi qu'il reste en rayon, parce que c'est l'etat qui
     * empeche de la vendre, et c'est celui qu'il faut corriger en priorité.
     *
     * Le seuil est compare au stock, et non l'inverse : le seuil est une
     * limite posee par le guichet et le stock bouge tout seul, donc c'est le
     * seuil qui dicte le niveau. Un article dont le stock vient de descendre
     * sous le seuil doit apparaitre « bas » immediatement, sans qu'aucune
     * action ne soit necessaire.
     */
    private function stockLevel(Variant $variant): string
    {
        if ($variant->status === CatalogStatus::INACTIVE) {
            return 'disabled';
        }

        if ($variant->stock <= 0) {
            return 'out';
        }

        return $variant->stock <= $variant->low_stock_threshold ? 'low' : 'available';
    }
}