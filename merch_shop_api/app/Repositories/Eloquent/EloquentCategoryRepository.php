<?php

namespace App\Repositories\Eloquent;

use App\Enums\CatalogStatus;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Acces aux categories sur Eloquent.
 *
 * @implements CategoryRepositoryInterface
 */
final class EloquentCategoryRepository extends EloquentRepository implements CategoryRepositoryInterface
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    public function findBySlug(string $slug): ?Category
    {
        /** @var Category|null $category */
        $category = $this->query()->where('slug', $slug)->first();

        return $category;
    }

    public function findById(int $id): ?Category
    {
        /** @var Category|null $category */
        $category = $this->query()->find($id);

        return $category;
    }

    public function findForCatalog(int|string $id): ?Category
    {
        /** @var Category|null $category */
        $category = $this->query()
            ->active()
            ->withCount('products')
            ->where($this->newModel()->getRouteKeyName(), $id)
            ->first();

        return $category;
    }

    public function findForManagement(int|string $id): ?Category
    {
        /** @var Category|null $category */
        $category = $this->query()
            ->withCount('products')
            ->where($this->newModel()->getRouteKeyName(), $id)
            ->first();

        return $category;
    }

    public function paginateForCatalog(int $perPage, ?CatalogStatus $status = null): LengthAwarePaginator
    {
        $query = $this->query()->withCount('products')->orderBy('name');

        if ($status === null) {
            $query->active();
        } else {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function slugExists(string $slug, ?Category $except = null): bool
    {
        $query = $this->query()->withTrashed()->where('slug', $slug);

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        return $query->exists();
    }
}
