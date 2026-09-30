<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['product_id', 'sku', 'name', 'price', 'stock', 'status'])]
#[RouteKey('uuid')]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory, HasPublicIdentifier, SoftDeletes;

    /**
     * Nom de la table : la classe s'appelle Variant, le plan de tracking
     * attend product_variant_id comme identifiant de reference.
     */
    protected $table = 'product_variants';

    /**
     * Produit auquel appartient la variante.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Lignes de commande portant cette variante.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Restreint la requete aux variantes commercialisables.
     *
     * @param  Builder<Variant>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', CatalogStatus::ACTIVE);
    }

    /**
     * Restreint la requete aux variantes effectivement en stock.
     *
     * Le filtre catalogue sert a l'affichage, celui-ci a la vente : une
     * variante publiee mais epuisee ne doit jamais etre ajoutable au panier.
     *
     * @param  Builder<Variant>  $query
     */
    #[Scope]
    protected function inStock(Builder $query): void
    {
        $query->where('stock', '>', 0);
    }

    /**
     * La variante peut-elle encore etre commandee ?
     *
     * Volontairement consultatif : le stock est decremente en transaction avec
     * verrou lors du checkout, donc seule cette verification arrete la
     * majorite des commandes. Elle evite au catalogue d'afficher un article
     * qui ne l'est plus.
     */
    protected function isAvailable(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->status === CatalogStatus::ACTIVE && $this->stock > 0
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'status' => CatalogStatus::class,
        ];
    }
}
