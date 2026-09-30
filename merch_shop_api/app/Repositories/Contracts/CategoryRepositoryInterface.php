<?php

namespace App\Repositories\Contracts;

use App\Enums\CatalogStatus;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Acces aux categories du catalogue.
 *
 * @extends RepositoryInterface<Category>
 */
interface CategoryRepositoryInterface extends RepositoryInterface
{
    /**
     * Retrouve une categorie par son slug.
     *
     * Le slug est la forme lisible et partageable d'une URL de catalogue ; il
     * n'est donc resolu que par cette methode, jamais par un nom arbitraire
     * construit a la volee.
     */
    public function findBySlug(string $slug): ?Category;

    /**
     * Retrouve une categorie par sa cle primaire numerique.
     *
     * Distinct de `find()`, qui resout par cle de route (le UUID expose au
     * public). Une commande d'API peut legitimement designer une categorie par
     * son `category_id` — c'est la forme stockee dans la colonne et validee
     * par `exists` — et le melanger aux UUID rendrait la resolution ambigue :
     * un identifiant numerique ne trouverait jamais rien et remonterait un 404
     * trompeur sur une requete parfaitement valide.
     */
    public function findById(int $id): ?Category;

    /**
     * Retrouve une categorie pour la lecture du catalogue.
     *
     * Meme chargement que `paginateForCatalog`, `withCount('products')` compris :
     * la ressource expose le nombre de produits, et les deux points de lecture
     * publique doivent renvoyer la meme forme. Sans cela, la liste et le detail
     * diviseraient sur la presence du compteur.
     *
     * Seules les categories actives sont renvoyees : une categorie masquee
     * disparait du catalogue, donc son URL doit repondre 404 plutot que de
     * servir une fiche que le public ne doit pas voir. Pour la relecture d'une
     * ecriture, utiliser `findForManagement`.
     */
    public function findForCatalog(int|string $id): ?Category;

    /**
     * Retrouve une categorie pour une ecriture, quel que soit son statut.
     *
     * Un administrateur doit pouvoir modifier, republier ou supprimer une
     * categorie masquee. Si la lecture de gestion filtrait sur le statut, la
     * republication deviendrait impossible : l'element serait introuvable des la
     * premiere operation qui l'aurait masque.
     */
    public function findForManagement(int|string $id): ?Category;

    /**
     * Liste paginee des categories, avec filtre eventuel sur le statut.
     *
     * La lecture publique du catalogue ne doit renvoyer que les categories
     * actives : un INACTIVE existe pour l'historique des commandes, pas pour la
     * vitrine.
     *
     * Le nombre de produits est charge en meme temps (withCount), sans quoi la
     * ressource en emetterait une requete par ligne de la page.
     *
     * @param  array{status?: CatalogStatus|null}  $filters
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginateForCatalog(int $perPage, ?CatalogStatus $status = null): LengthAwarePaginator;

    /**
     * Le slug est-il deja pris par une autre categorie ?
     *
     * Interroge en ignorant les lignes supprimer (soft delete) et, pour une
     * mise a jour, la categorie concernee : sans cette exclusion, renommer une
     * categorie vers son propre slug echouerait.
     */
    public function slugExists(string $slug, ?Category $except = null): bool;
}
