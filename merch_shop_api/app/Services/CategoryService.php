<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Exceptions\ApiException;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regle metier des categories du catalogue.
 *
 * Porte les decisions que ni le controleur ni le repository ne peuvent
 * prendre : unicite du slug, refus de supprimer une categorie encore peuplee,
 * traitement du parametre `status` de la liste publique.
 */
final class CategoryService
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly ProductRepositoryInterface $products,
    ) {}

    /**
     * Liste paginee des categories actives.
     *
     * Le public ne voit que l'ACTIVE. Lister les inactives demande un compte
     * authentifie et un role d'exploitation, ce qui releve d'un autre usage
     * (back-office) que le catalogue.
     *
     * @return LengthAwarePaginator<int, Category>
     */
    public function listCatalog(int $perPage): LengthAwarePaginator
    {
        return $this->categories->paginateForCatalog($perPage);
    }

    public function findOrFail(string $uuid): Category
    {
        $category = $this->categories->findForCatalog($uuid);

        // Une categorie supprimee logiquement ou masquee n'est pas introuvable :
        // elle n'est simplement pas visible. La distinction est un choix, documente
        // ici : le catalogue renvoie 404 sur tout ce que le public ne doit pas
        // voir, plutot que d'exposer l'existence d'un element masque.
        if ($category === null) {
            throw new ApiException('Categorie introuvable.', 404, 'CATEGORY_NOT_FOUND');
        }

        return $category;
    }

    /**
     * Relecture destinee a une ecriture (modification, suppression).
     *
     * Separee de `findOrFail` parce que les deux lectures n'ont pas le meme
     * public : un administrateur doit pouvoir depublier puis reactiver une
     * categorie, donc doit atteindre une categorie masquee. Utiliser `findOrFail` ici
     * rendrait impossible de reactiver ce qu'on vient de masquer, puisque
     * l'element deviendrait introuvable des la premiere operation.
     */
    public function findForManagementOrFail(string $uuid): Category
    {
        $category = $this->categories->findForManagement($uuid);

        if ($category === null) {
            throw new ApiException('Categorie introuvable.', 404, 'CATEGORY_NOT_FOUND');
        }

        return $category;
    }

    /**
     * @param  array{name: string, description?: string|null, slug?: string|null, status?: CatalogStatus}  $data
     */
    public function create(array $data): Category
    {
        return DB::transaction(fn (): Category => $this->categories->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'slug' => $this->resolveSlug($data['slug'] ?? null, $data['name']),
            'status' => $data['status'] ?? CatalogStatus::ACTIVE,
        ]));
    }

    /**
     * @param  array{name?: string, description?: string|null, slug?: string|null, status?: CatalogStatus, sort_order?: int}  $data
     */
    public function update(Category $category, array $data): Category
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $attributes['description'] = $data['description'];
        }

        if (array_key_exists('status', $data)) {
            $attributes['status'] = $data['status'];
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $data['sort_order'];
        }

        /*
         * Le slug n'est recalcule que si le nom change et qu'aucun slug n'est
         * fourni. Sans cette condition, une simple correction de libelle
         * casserait toutes les URL de la categorie deja partagees.
         */
        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $attributes['slug'] = $this->resolveSlug($data['slug'], $category->name, $category);
        } elseif (array_key_exists('name', $data)) {
            $attributes['slug'] = $this->resolveSlug(null, $data['name'], $category);
        }

        return DB::transaction(fn (): Category => $this->categories->update($category, $attributes));
    }

    /**
     * Supprime logiquement une categorie.
     *
     * Refuse si des produits actifs y sont encore rattaches. La cle etrangere
     * est en RESTRICT : sans ce controle, la base rejetterait l'operation avec
     * une erreur de contrainte que le front ne saurait pas expliquer, et
     * surtout la suppression « reussirait » pour une categorie vide de
     * produits visibles tout en restant bloquee par des produits archives.
     */
    public function delete(Category $category): void
    {
        $activeProducts = $this->products->countActiveByCategory((int) $category->id);

        if ($activeProducts > 0) {
            throw new ApiException(
                'Cette categorie contient encore des produits actifs. Deplacez-les avant de la supprimer.',
                409,
                'CATEGORY_NOT_EMPTY',
                ['active_products_count' => $activeProducts],
            );
        }

        DB::transaction(fn () => $this->categories->delete($category));
    }

    /**
     * Produit un slug unique a partir d'une valeur demandee ou d'un nom.
     *
     * Si aucun slug n'est fourni, il est derive du nom. En cas de collision, on
     * suffixe plutot que de refuser : c'est le comportement attendu d'un
     * back-office, ou creer deux categories « T-shirts » ne doit pas
     * necessiter d'inventer un second nom. En revanche un slug impose
     * explicitement qui entre en conflit est refuse, car la valeur a ete
     * choisie pour une raison metier (une URL, un import) qu'un suffixe
     * silencieux detruirait.
     */
    private function resolveSlug(?string $requested, string $name, ?Category $except = null): string
    {
        if ($requested !== null && $requested !== '') {
            $slug = Str::slug($requested);

            if ($this->categories->slugExists($slug, $except)) {
                throw new ApiException(
                    'Ce slug est deja utilise par une autre categorie.',
                    409,
                    'SLUG_ALREADY_EXISTS',
                    ['slug' => $slug],
                );
            }

            return $slug;
        }

        $base = Str::slug($name);

        if ($base === '') {
            // Un nom entierement compose de caracteres accentues ou de
            // symboles ne produit aucun slug. On retombe sur un identifiant
            // technique plutot que d'ecrire une ligne vide, qui violerait la
            // contrainte de longueur et legerement d'unicite.
            $base = 'categorie';
        }

        $slug = $base;
        $suffix = 1;

        while ($this->categories->slugExists($slug, $except)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
