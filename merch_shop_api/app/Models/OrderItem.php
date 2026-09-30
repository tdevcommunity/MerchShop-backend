<?php

namespace App\Models;

use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_id',
    'product_id',
    'product_variant_id',
    'quantity',
    'unit_price',
    'total_price',
    'product_name',
    'variant_name',
])]
#[RouteKey('uuid')]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory, HasPublicIdentifier, SoftDeletes;

    /**
     * Ligne d'une commande.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Produit vendu, pour le back-office et l'analyse.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Variante vendue, null pour un article sans declinaison.
     *
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'product_variant_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            // Prix figes a l'achat : decimal exact, jamais relu depuis le
            // catalogue, pour qu'une promotion ulterieure ne reecrive pas
            // l'historique de la commande.
        ];
    }
}
