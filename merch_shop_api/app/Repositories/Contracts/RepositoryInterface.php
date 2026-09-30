<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Contrat d'acces aux donnees d'une entite.
 *
 * Les services dependent de cette interface et jamais de l'implementation
 * Eloquent : la source de donnees reste substituable sans toucher au metier
 * (D de SOLID), et les services deviennent testables avec un double.
 *
 * Les requetes metier specifiques a une entite (filtrer par variante, verrouiller
 * une ligne de stock) ne doivent pas etre empilees ici si elles exigent une
 * regle metier : elles deviennent des methodes de l'interface propre
 * (`ProductRepositoryInterface`), heritee de celle-ci.
 *
 * @template TModel of Model
 */
interface RepositoryInterface
{
    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?Model;

    /**
     * @return TModel
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id): Model;

    /**
     * @return Collection<int, TModel>
     */
    public function all(): Collection;

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model;

    public function delete(Model $model): void;

    /**
     * Point d'entree pour composer une requete (with, where, orderBy) sans
     * multiplier les methodes du contrat. Les services s'en servent pour
     * charger les relations en eager loading et eviter les requetes N+1.
     *
     * @return Builder<TModel>
     */
    public function query(): Builder;
}
