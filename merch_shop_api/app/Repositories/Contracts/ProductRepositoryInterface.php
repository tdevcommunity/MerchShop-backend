<?php

namespace App\Repositories\Contracts;

use App\Enums\CatalogStatus;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Acces aux produits du catalogue, variantes comprises.
 *
 * @extends RepositoryInterface<Product>
 */
interface ProductRepositoryInterface extends RepositoryInterface
{
    /**
     * Charge un produit avec ses variantes et sa categorie.
     *
     * Le chargement est fait en une requete supplementaire (eager loading) et
     * non variante par variante : la liste de produits est la page la plus
     * consultee du shop, une requete par produit la rendrait quadratique.
     *
     * @param  array<string, mixed>  $relations
     *
     * `$id` est resolu par cle de route (l'UUID public), pas par cle primaire :
     * passer `$product->id` ne trouverait rien et lèverait une
     * `ModelNotFoundException` sur un produit qui vient d'etre écrit.
     */
    public function findWithRelations(int|string $id, array $relations = ['variants', 'category']): Product;

    /**
     * Charge un produit pour la lecture publique, ou `null` s'il n'existe pas
     * ou n'est pas actif.
     *
     * Le filtre sur le statut est la contrepartie de `paginateForCatalog` :
     * sans lui, un produit retire du catalogue resterait accessible par son
     * URL, ce qui est exactement ce que « retirer du catalogue » doit
     * empecher. La relecture d'ecriture, elle, passe par
     * `findWithRelations`, qui ignore le statut.
     */
    public function findForCatalog(int|string $id): ?Product;

    /**
     * Liste paginee du catalogue, filtres appliques.
     *
     * Les variantes sont toujours chargees en eager loading : le front doit
     * pouvoir afficher les declinaisons disponibles sans appel supplementaire.
     *
     * @param  array{status?: CatalogStatus|null, category_id?: int|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateForCatalog(int $perPage, array $filters = []): LengthAwarePaginator;

    public function findBySlug(string $slug): ?Product;

    /**
     * Le slug est-il deja pris par un autre produit ?
     */
    public function slugExists(string $slug, ?Product $except = null): bool;

    /**
     * Nombre de produits actifs rattaches a une categorie.
     *
     * Utilise avant une suppression : la cle etrangere est en RESTRICT, donc
     * supprimer une categorie encore peuplee doit echouer avec une erreur
     * explicite (« la categorie contient des produits ») et non une erreur
     * de base de donnees que personne ne sait interpreter.
     */
    public function countActiveByCategory(int $categoryId): int;
}
