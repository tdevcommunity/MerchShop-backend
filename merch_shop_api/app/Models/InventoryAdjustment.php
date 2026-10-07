<?php

namespace App\Models;

use App\Enums\InventoryReason;
use Database\Factories\InventoryAdjustmentFactory;
use App\Models\Concerns\HasPublicIdentifier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un mouvement de stock saisi a la main.
 *
 * Le stock d'une declinaison est un compteur, donc il ne dit rien de ce qui
 * l'a fait bouger. Cette ligne est ce qui le dit, et elle s'ecrit en meme temps
 * que l'ajustement, dans la meme transaction : un mouvement de stock sans trace
 * est un stock que le plan de tracking ne peut pas reconcilier.
 *
 * Elle ne se modifie pas. Corriger une erreur de saisie passe par l'ajustement
 * inverse, ce qui laisse les deux mouvements visibles — la seule facon de savoir
 * combien d'articles ont reellement circule.
 *
 * @property-read InventoryReason $reason
 */
#[Fillable([
    'product_variant_id',
    'product_id',
    'sku',
    'product_name',
    'previous_stock',
    'delta',
    'next_stock',
    'reason',
    'note',
    'user_id',
    'user_email',
])]
#[RouteKey('uuid')]
class InventoryAdjustment extends Model
{
    /** @use HasFactory<InventoryAdjustmentFactory> */
    use HasFactory, HasPublicIdentifier;

    /**
     * Nom de la table.
     *
     * La classe s'appelle `InventoryAdjustment` et non `InventoryLog` : un
     * journal se lit, un ajustement s'exerce. Le mot « log » est reserve aux
     * traces qu'on ne peut pas produire soi-meme, comme `audit_logs`.
     */
    protected $table = 'inventory_adjustments';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => InventoryReason::class,
            'previous_stock' => 'integer',
            'delta' => 'integer',
            'next_stock' => 'integer',
        ];
    }

    /**
     * La declinaison dont le stock a bouge.
     *
     * Nullable parce que la variante peut disparaitre du catalogue apres coup,
     * et que son stock doit rester reconciliable : la ligne du journal garde le
     * nom et la reference figes, qui sont alors sa seule description.
     *
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'product_variant_id');
    }

    /**
     * Le produit auquel appartenait la declinaison.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Le membre du guichet qui a saisi l'ajustement.
     *
     * Nullable pour la meme raison que les deux precedents : la trace doit
     * survivre a la suppression du compte qui l'a produite.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le mouvement augmente-t-il le stock ?
     *
     * Expose parce que le signe du delta se lit mal dans un tableau : deux
     * nombres qui ne se distinguent que par leur signe disent moins bien « on a
     * ajoute cinq pieces » que « on en a ajoute cinq ».
     */
    public function isAddition(): bool
    {
        return $this->delta > 0;
    }
}