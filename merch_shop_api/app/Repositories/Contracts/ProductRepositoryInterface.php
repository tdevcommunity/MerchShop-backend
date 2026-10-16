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
     * `category` filtre par slug de categorie, l'identifiant lisible expose
     * par l'API : c'est la forme que le front connait, puisqu'il navigue par
     * URL `/shop?category=<slug>`. `category_id` reste accepte pour un appelant
     * qui dispose de la cle primaire.
     *
     * @param  array{status?: CatalogStatus|null, category_id?: int|null, category?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateForCatalog(int $perPage, array $filters = []): LengthAwarePaginator;

    /**
     * Liste paginee pour le back-office, tous statuts confondus.
     *
     * Distincte de `paginateForCatalog` sur un point qui change tout : elle ne
     * restreint pas au statut actif. Un ecran de gestion qui ne verrait que les
     * produits publies ne pourrait ni rouvrir un brouillon, ni remettre en ligne
     * un article archive — et le bouton « Publier » n'aurait rien a publier.
     *
     * Les variantes sont chargees sans filtre de statut, contrairement au
     * catalogue : une declinaison masquee reste modifiable.
     *
     * `q` cherche dans le nom, le slug et le SKU des variantes, ce que le front
     * appelle « rechercher un produit ou un SKU ». `status` filtre sur un statut
     * precis (`CatalogStatus`), et son absence vaut « tous les statuts » — un
     * `null` ici n'a donc pas le meme sens que dans le catalogue.
     *
     * @param  array{q?: string|null, status?: int|null, category?: string|null}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginateForManagement(int $perPage, array $filters = []): LengthAwarePaginator;

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
