<?php

namespace App\Enums;

/**
 * Cause d'un ajustement de stock.
 *
 * La valeur est un mot unique et stable : elle est stockee, elle est affichee au
 * stand, et elle est lue dans un rapprochement comptable. La renommer prendrait
 * l'historique avec elle, donc les libelles lisibles vivent dans
 * `label()` plutot que dans la valeur.
 *
 * Ces motifs decrivent une intention humaine. Une vente n'en fait pas partie : le
 * stock d'une vente est decremente par la commande, dans la transaction qui la
 * cree, et il est deja trace par sa ligne. Un ajustement de vente compterait la
 * meme sortie deux fois.
 */
enum InventoryReason: string
{
    /** Articles recus et mis en rayon, en debut ou en cours de festival. */
    case RECEPTION = 'reception';

    /** Le stock saisi etait faux, et on corrige ce que la saisie avait dit. */
    case CORRECTION = 'correction';

    /** Articles abimes sur le stand : ils ne sont plus revendables. */
    case DAMAGED = 'damaged';

    /** Articles perdus, comptees et jamais retrouvees. */
    case LOSS = 'loss';

    /** Articles rendus par un acheteur et reintegres au stock. */
    case RETURN = 'return';

    /** Recomptage general : le stock physique ne correspondait pas au stock saisi. */
    case INVENTORY = 'inventory';

    /**
     * Motifs qu'un ajustement manuel peut porter.
     *
     * `sale` est ici pour un seul usage : soldes de lancement et autres
     * gratuités, ou l'on sort du stock sans commande. Une vente qui suit une
     * commande ne passe pas par ici — son retrait de stock appartient a la
     * commande, et c'est elle qui doit pouvoir le justifier.
     *
     * Expose pour que la validation refuse le motif plutot que de le laisser
     * passer : un back-office qui proposerait « vente » sur un ajustement
     * ecraserait une sortie deja tracee ailleurs.
     *
     * @return array<int, string>
     */
    public static function adjustable(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Le libelle lu au stand.
     *
     * Distinct de la valeur pour deux raisons : la valeur reste stable en base
     * quand le libelle est corrige, et l'affichage peut etre retraCite sans que
     * l'historique cesse d'etre relisible.
     */
    public function label(): string
    {
        return match ($this) {
            self::RECEPTION => 'Réception',
            self::CORRECTION => 'Correction',
            self::DAMAGED => 'Abîmé',
            self::LOSS => 'Perte',
            self::RETURN => 'Retour',
            self::INVENTORY => 'Inventaire',
            self::SALE => 'Cession',
        };
    }
}