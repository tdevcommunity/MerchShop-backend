<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Implementation Eloquent du contrat generique d'acces aux donnees.
 *
 * Cette classe ne porte aucune regle metier : elle se contente de traduire le
 * contrat en requetes Eloquent. Les methodes propres a une entite vivent dans
 * une classe fille, qui expose le modele et enrichit le contrat.
 *
 * @template TModel of Model
 *
 * @implements RepositoryInterface<TModel>
 */
abstract class EloquentRepository implements RepositoryInterface
{
    /**
     * @return class-string<TModel>
     */
    abstract protected function modelClass(): string;

    public function query(): Builder
    {
        return $this->newModel()->newQuery();
    }

    public function find(int|string $id): ?Model
    {
        return $this->query()->where($this->routeKeyName(), $id)->first();
    }

    public function findOrFail(int|string $id): Model
    {
        $model = $this->find($id);

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel($this->modelClass(), [$id]);
        }

        return $model;
    }

    public function all(): Collection
    {
        return $this->query()->get();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }

    public function create(array $attributes): Model
    {
        return $this->newModel()->newQuery()->create($attributes);
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    /**
     * Nom de la colonne servant d'identifiant dans l'URL.
     *
     * Surchargeable pour un modele dont la cle de route n'est pas `id`
     * (un slug produit, une reference de commande).
     */
    protected function routeKeyName(): string
    {
        return $this->newModel()->getRouteKeyName();
    }

    /**
     * @return TModel
     */
    protected function newModel(): Model
    {
        $modelClass = $this->modelClass();

        return new $modelClass;
    }
}
