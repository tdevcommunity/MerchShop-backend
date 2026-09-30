<?php

namespace App\Repositories\Eloquent;

use App\Enums\CatalogStatus;
use App\Models\Product;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Acces aux produits sur Eloquent.
 *
 * @implements ProductRepositoryInterface
 */
final class EloquentProductRepository extends EloquentRepository implements ProductRepositoryInterface
{
    protected function modelClass(): string
    {
        return Product::class;
    }

    /**
     * Relations chargees pour la lecture publique, avec restriction de statut
     * sur les variantes.
     *
     * La restriction est posee ici plutot que dans la ressource : un
     * `variants` eager-loade sans filtre exposerait publiquement une
     * declinaison masquee par le back-office, et la ressource n'aurait aucun
     * moyen de distinguer une variante genuinely absente d'une variante
     * volontairement masquee.
     *
     * @return array<int|string, mixed>
     */
    private function catalogRelations(): array
    {
        return [
            'category',
            // La contrainte d'un `with` recoit la relation, pas le builder :
            // `active()` est transmis a la requete sous-jacente et la relation
            // reste le retour attendu par le chargeur.
            'variants' => fn (Relation $query): Relation => $query->active(),
        ];
    }

    public function findWithRelations(int|string $id, array $relations = ['variants', 'category']): Product
    {
        $with = $relations === ['variants', 'category'] ? $this->catalogRelations() : $relations;

        /** @var Product|null $product */
        $product = $this->query()->with($with)->where(
            $this->newModel()->getRouteKeyName(),
            $id,
        )->first();

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
        }

        return $product;
    }

    public function findForCatalog(int|string $id): ?Product
    {
        /** @var Product|null $product */
        $product = $this->query()
            ->active()
            ->with($this->catalogRelations())
            ->where($this->newModel()->getRouteKeyName(), $id)
            ->first();

        return $product;
    }

    public function paginateForCatalog(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query()
            ->with($this->catalogRelations())
            ->orderBy('name');

        $this->applyCatalogFilters($query, $filters);

        return $query->paginate($perPage);
    }

    public function findBySlug(string $slug): ?Product
    {
        /** @var Product|null $product */
        $product = $this->query()->where('slug', $slug)->first();

        return $product;
    }

    public function slugExists(string $slug, ?Product $except = null): bool
    {
        $query = $this->query()->withTrashed()->where('slug', $slug);

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        return $query->exists();
    }

    public function countActiveByCategory(int $categoryId): int
    {
        return $this->query()
            ->where('category_id', $categoryId)
            ->where('status', CatalogStatus::ACTIVE)
            ->count();
    }

    /**
     * Filtres de la liste de catalogue.
     *
     * Isoles pour etre reutilises par la recherche d'un produit; chaque filtre
     * est un AND, donc ils se cumulent naturellement.
     *
     * @param  array{status?: CatalogStatus|null, category_id?: int|null, search?: string|null}  $filters
     * @param  Builder<Product>  $query
     */
    private function applyCatalogFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if ($status === null) {
            $query->active();
        } else {
            $query->where('status', $status);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['search']) && $filters['search'] !== null && $filters['search'] !== '') {
            // LIKE paramétré : la valeur arrive en paramètre lié, jamais
            // concaténée dans le SQL. Le "%" ajouté ici est un motif, les
            // wildcards eux-mêmes sont échappés par la fonction de recherche.
            $term = '%'.addcslashes((string) $filters['search'], '%_').'%';

            $query->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }
    }
}
