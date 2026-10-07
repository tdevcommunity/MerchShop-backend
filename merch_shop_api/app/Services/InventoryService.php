<?php

namespace App\Services;

use App\Enums\InventoryReason;
use App\Exceptions\ApiException;
use App\Models\InventoryAdjustment;
use App\Models\User;
use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\Api\AuditAction;
use Illuminate\Support\Facades\DB;

/**
 * Corrections de stock saisies a la main.
 *
 * Une commande retire du stock toute seule, dans sa transaction, et laisse une
 * ligne de commande qui dit pourquoi. Ce service est le pendant de cette
 * operation pour ce qui n'a pas de commande : une reception au stand, une piece
 * abimee, un ecart de recomptage. Sans trace, ces mouvements Sortiraient du
 * stock sans que le plan de tracking puisse les expliquer, et le stock annonce
 * divergerait du stock reel sans qu'on sache lequel des deux a tort.
 *
 * Trois garanties tiennent l'ensemble :
 *
 *  1. l'ecriture du stock et celle du journal sont dans la meme transaction. Un
 *     stock corrige sans trace serait le cas que le journal existe pour
 *     empecher ; l'inverse — une trace sans correction — mentirait sur un stock
 *     qui n'a pas bouge ;
 *
 *  2. la ligne de declinaison est verrouillee avant d'etre lue. Deux ajustements
 *     simultanes sans verrou liraient le meme « avant » et le meme « apres », et
 *     le journal decrirait un etat qui n'a pas existe ;
 *
 *  3. un ajustement a zero n'est pas un ajustement. Corriger le stock de rien
 *     produirait une ligne qui ne dit rien et occupe une place dans le journal ;
 *     l'ignorer silencieusement ferait croire a un enregistrement.
 *
 * Le stock ne peut pas devenir negatif. Rendre un article qu'on n'a pas en rayon
 * produirait un panier que le tunnel peut utiliser pour commander, et l'article
 * partirait sans avoir ete paye : le refus est la seule reponse honnete.
 */
final class InventoryService
{
    /**
     * Bornes d'un ajustement.
     *
     * La borne haute n'est pas une defense contre l'abus : elle empeche qu'une
     * faute de frappe — un point en trop, ou un `9` a la place d'un `3` — vide le
     * stock d'une declinaison en une seule ligne. La contrepartie est la meme
     * qu'a une commande : l'ecart se voit dans le journal, avec son motif et son
     * auteur, donc il est rattrapable.
     */
    private const MAX_DELTA = 10000;

    public function __construct(
        private readonly VariantRepositoryInterface $variants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Corrige le stock d'une declinaison.
     *
     * @param  InventoryReason|string  $reason
     */
    public function adjust(Variant $variant, int $delta, InventoryReason|string $reason, User $actor, string $note = ''): InventoryAdjustment
    {
        $motif = $reason instanceof InventoryReason ? $reason : InventoryReason::tryFrom($reason);

        if ($motif === null) {
            throw new ApiException(
                'Ce motif d\'ajustement n\'existe pas.',
                422,
                'INVALID_INVENTORY_REASON',
                [
                    'expected' => array_map(
                        static fn (InventoryReason $case): string => $case->value,
                        InventoryReason::cases(),
                    ),
                ],
            );
        }

        if ($delta === 0) {
            throw new ApiException(
                'Un ajustement de stock doit modifier le stock.',
                422,
                'EMPTY_STOCK_ADJUSTMENT',
            );
        }

        if (abs($delta) > self::MAX_DELTA) {
            throw new ApiException(
                'Un ajustement de stock est plafonné à '.self::MAX_DELTA.' pièces à la fois.',
                422,
                'STOCK_ADJUSTMENT_TOO_LARGE',
                ['max' => self::MAX_DELTA, 'requested' => $delta],
            );
        }

        return DB::transaction(function () use ($variant, $delta, $motif, $actor, $note): InventoryAdjustment {
            /*
             * La ligne est reverrouillee plutot que relue : `findOrFail` a deja
             * rendu une instance dont le stock peut dater d'avant une commande
             * passee entre-temps. Lire cette instance-la reviendrait a ecrire un
             * ajustement sur un etat perime, et le « apres » announce serait
             * faux des la ligne suivante.
             */
            $locked = $this->lockVariant($variant);

            $previous = $locked->stock;
            $next = $previous + $delta;

            if ($next < 0) {
                throw new ApiException(
                    'Ce retrait dépasserait le stock disponible ('.$previous.' pièce(s)).',
                    409,
                    'INSUFFICIENT_STOCK',
                    ['available' => $previous, 'requested' => $delta],
                );
            }

            $this->variants->restock($locked, $delta);

            $adjustment = InventoryAdjustment::query()->create([
                'product_variant_id' => $locked->id,
                'product_id' => $locked->product_id,

                /*
                 * Nom et SKU copies maintenant, pas lus a l'affichage.
                 *
                 * Une declinaison peut disparaitre du catalogue, et son stock doit
                 * rester reconciliable apres : la ligne du journal ne doit donc
                 * pas dependre d'une lecture du catalogue qui n'existera plus.
                 */
                'sku' => $locked->sku,
                'product_name' => $locked->product?->name ?? 'Produit retiré du catalogue',

                'previous_stock' => $previous,
                'delta' => $delta,
                'next_stock' => $next,
                'reason' => $motif,
                'note' => $note,
                'user_id' => $actor->id,
                'user_email' => $actor->email,
            ]);

            /*
             * Le journal d'audit ne se substitue pas au journal de stock : il dit
             * que quelqu'un a corrige le stock, l'autre dit de combien. Les deux
             * sont demandes au guichet, et l'un sans l'autre laisse un trou —
             * l'ajustement sans sa cause, ou la cause sans ses chiffres.
             */
            $this->audit->record(
                AuditAction::STOCK_ADJUSTED,
                $locked,
                $actor,
                ['stock' => $previous],
                ['stock' => $next],
            );

            return $adjustment;
        });
    }

    /**
     * La declinaison, verrouillee pour ecriture.
     */
    private function lockVariant(Variant $variant): Variant
    {
        /** @var Variant|null $locked */
        $locked = Variant::query()
            ->whereKey($variant->getKey())
            ->lockForUpdate()
            ->with('product')
            ->first();

        if ($locked === null) {
            throw new ApiException('Cette déclinaison n\'existe plus.', 404, 'VARIANT_NOT_FOUND');
        }

        return $locked;
    }
}