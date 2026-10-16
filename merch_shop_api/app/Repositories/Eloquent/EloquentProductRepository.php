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

    public function paginateForManagement(int $perPage, array $filters = []): LengthAwarePaginator
    {
        /*
         * Les variantes sont chargees sans la restriction de statut du
         * catalogue : une declinaison masquee doit rester modifiable depuis sa
         * fiche, sinon la retirer la rendrait impossible a remettre en vente.
         *
         * Le tri place les produits les plus recents en tete. C'est un ecran de
         * travail, pas une vitrine : ce qu'on vient de creer ou de corriger est
         * ce qu'on cherche a retrouver, et les 42 produits d'un catalogue ne se
         * lisent pas de la meme facon qu'un rayon de douze articles.
         */
        $query = $this->query()
            ->with(['category', 'variants'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyManagementFilters($query, $filters);

        return $query->paginate($perPage);
    }

    public function findBySlug(string $slug): ?Product
    {
        /*
         * Meme filtre sur le statut que `findForCatalog` : un produit masque
         * n'est pas resolvable par son slug. Une URL lisible partageant la meme
         * cle que le slug d'un produit retire ne doit pas le servir.
         *
         * Les relations de catalogue sont chargees pour que la ressource puisse
         * afficher les variantes sans appel supplementaire, comme pour le reste
         * du catalogue.
         */
        /** @var Product|null $product */
        $product = $this->query()
            ->active()
            ->with($this->catalogRelations())
            ->where('slug', $slug)
            ->first();

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
     * Le filtre `category` resout le slug de la categorie via une sous-requete
     * `whereHas` : elle exclut naturellement les categories supprimees
     * logiquement, sans avoir a joindre puis filtrer `deleted_at` a la main.
     *
     * @param  array{status?: CatalogStatus|null, category_id?: int|null, category?: string|null, search?: string|null}  $filters
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

        if (isset($filters['category']) && $filters['category'] !== null && $filters['category'] !== '') {
            $query->whereHas('category', fn (Builder $query): Builder => $query->where('slug', $filters['category']));
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

    /**
     * Filtres de la liste de gestion.
     *
     * Le filtre `status` ne bascule pas sur « actif par defaut » quand il est
     * absent, contrairement a `applyCatalogFilters` : l'ecran de gestion doit
     * montrer brouillons et archives, donc « pas de filtre » veut dire « tous »
     * et non « actifs seulement ».
     *
     * La recherche couvre le nom, le slug et le SKU des variantes, en
     * `whereHas` pour le SKU : c'est la requete que fait un gestionnaire qui
     * colle une reference produit. Chaque LIKE passe par un parametre lie et le
     * terme est borne en longueur en amont, dans le controleur.
     *
     * @param  array{q?: string|null, status?: int|null, category?: string|null}  $filters
     * @param  Builder<Product>  $query
     */
    private function applyManagementFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if ($status !== null) {
            $query->where('status', $status);
        }

        if (isset($filters['category']) && $filters['category'] !== null && $filters['category'] !== '') {
            $query->whereHas('category', fn (Builder $query): Builder => $query->where('slug', $filters['category']));
        }

        if (isset($filters['q']) && $filters['q'] !== null && $filters['q'] !== '') {
            $term = '%'.addcslashes((string) $filters['q'], '%_').'%';

            $query->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhereHas('variants', fn (Builder $query): Builder => $query->where('sku', 'like', $term));
            });
        }
    }
}
