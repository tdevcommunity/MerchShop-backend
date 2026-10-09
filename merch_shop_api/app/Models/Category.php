<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Le nombre de produits n'est pas une colonne : il est posé par un `withCount`
 * à la lecture, d'où son type déclaré plutôt que défini par un cast.
 *
 * @property int $products_count
 */
#[Fillable(['name', 'description', 'slug', 'status', 'sort_order'])]
#[RouteKey('uuid')]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasPublicIdentifier, SoftDeletes;

    /**
     * Produits appartenant a la categorie.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Restreint la requete aux elements visibles du catalogue.
     *
     * @param  Builder<Category>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', CatalogStatus::ACTIVE);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
