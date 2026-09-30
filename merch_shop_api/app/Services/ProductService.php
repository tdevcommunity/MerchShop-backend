<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Variant;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\Api\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regle metier des produits du catalogue et de leurs variantes.
 *
 * Deux responsibilites ici :
 *
 *  - le cycle de vie d'un produit (publication, slug, suppression) ;
 *  - la synchronisation de ses declinaisons, qui est la partie delicate. Un
 *    produit et ses variantes forment un tout : la creation, la mise a jour et
 *    la suppression se passent toujours en transaction, pour qu'un back-office
 *    ne laisse jamais un produit sans declinaison alors que sa variante a
 *    echoue.
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly CategoryRepositoryInterface $categories,
        private readonly VariantRepositoryInterface $variants,
        private readonly Money $money,
    ) {}

    /**
     * Liste paginee du catalogue.
     *
     * Un filtre de statut demande explicitement l'ADMIN : la liste publique ne
     * montre que l'ACTIVE, et un inactive n'a de sens que pour l'exploitation.
     *
     * @param  array{status?: CatalogStatus|null, category_id?: int|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function listCatalog(int $perPage, array $filters = []): LengthAwarePaginator
    {
        return $this->products->paginateForCatalog($perPage, [
            'status' => $filters['status'] ?? null,
            'category_id' => $filters['category_id'] ?? null,
            'search' => $filters['search'] ?? null,
        ]);
    }

    /**
     * Charge un produit avec ses variantes, pour la lecture publique.
     *
     * Un produit masque n'est pas resolvable : le catalogue le retire de la
     * liste, son URL doit donc repondre 404 egalement. Le servir sur lien direct
     * exposerait une annonce avant sa publication, et surtout laisserait le
     * back-office dans un etat incoherent ou un produit n'apparait ni dans les
     * listes ni dans sa propre fiche.
     */
    public function findOrFail(string $uuid): Product
    {
        $product = $this->products->findForCatalog($uuid);

        if ($product === null) {
            throw new ApiException('Produit introuvable.', 404, 'PRODUCT_NOT_FOUND');
        }

        return $product;
    }

    /**
     * Charge un produit pour une ecriture, quel que soit son statut.
     *
     * Distinct de `findOrFail` : un administrateur doit pouvoir reactiver un
     * produit masque, ce qui serait impossible si la lecture de gestion
     * echouait des la premiere operation ayant declenche le masquage.
     */
    public function findForManagementOrFail(string $uuid): Product
    {
        return $this->products->findWithRelations($uuid);
    }

    /**
     * Charge un produit par son slug, pour les URLs lisibles du shop.
     *
     * Meme logique que `findOrFail` : un produit masque n'est pas resolvable.
     * Retourne `null` au lieu de lever une exception, laisse au controleur le
     * choix de la forme d'erreur a renvoyer.
     */
    public function findBySlug(string $slug): ?Product
    {
        return $this->products->findBySlug($slug);
    }

    /**
     * Variantes d'un produit, dans un ordre stable.
     *
     * @return Collection<int, Variant>
     */
    public function listVariants(Product $product): Collection
    {
        return $this->variants->forPublicCatalog((int) $product->id);
    }

    /**
     * Cree un produit et ses variantes initiales.
     *
     * La transaction est le point important : un produit cree sans aucune de ses
     * declinaises vendables n'a aucun interet pour la boutique, et mieux vaut
     * que l'echec porte sur l'ensemble.
     *
     * @param  array{name: string, description?: string|null, image_url?: string|null, category_id: int, slug?: string|null, status?: CatalogStatus, variants?: array<int, array<string, mixed>>, default_variant?: array<string, mixed>|null}  $data
     */
    public function create(array $data): Product
    {
        $category = $this->resolveCategory((int) $data['category_id']);

        return DB::transaction(function () use ($data, $category): Product {
            $product = $this->products->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'image_url' => $data['image_url'] ?? null,
                'category_id' => $category->id,
                'slug' => $this->resolveSlug($data['slug'] ?? null, $data['name']),
                'status' => $data['status'] ?? CatalogStatus::ACTIVE,
            ]);

            $variants = ($data['variants'] ?? []) === []
                ? [$this->defaultVariantFor($product, $data['default_variant'] ?? [])]
                : $data['variants'];

            foreach ($variants as $variantData) {
                $this->createVariant($product, $variantData);
            }

            // Relecture avec les relations : la ressource expose les variantes,
            // la categorie et leurs compteurs, et le modele cree ne les a pas
            // encore en memoire. On interroge par UUID et non par `$product->id`
            // : `findWithRelations` resout par cle de route, qui est l'identifiant
            // public expose au client.
            return $this->products->findWithRelations($product->uuid);
        });
    }

    /**
     * Variante unique portant le prix et le stock d'un produit non decoupe.
     *
     * Le prix et le stock viennent du back-office, jamais du produit : ce sont
     * les deux seules informations qui manquent a un produit sans declinaison
     * pour etre vendu, et les exiger evite qu'un produit invisible au catalogue
     * soit cree par oubli.
     *
     * Le SKU est deduit du slug, qui est unique en base, et le nom du produit
     * sert de libelle de declinaison. Un client qui affiche « T-shirt » plutot
     * que « T-shirt, declinaison par defaut » n'y perd rien : il n'y a rien a
     * choisir, et le back-office garde une reference stable au stand.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function defaultVariantFor(Product $product, array $default): array
    {
        return [
            'sku' => $default['sku'] ?? strtoupper((string) $product->slug).'-DEF',
            'name' => $default['name'] ?? $product->name,
            'price' => $this->money->toAmount($default['price']),
            'stock' => $default['stock'],
            'status' => $default['status'] ?? $product->status,
            'size' => $default['size'] ?? null,
            'color' => $default['color'] ?? null,
        ];
    }

    /**
     * Met a jour un produit et synchronise ses variantes.
     *
     * La cle `variants` est le point le plus subtil du contrat :
     *
     *  - absente : les variantes ne sont pas touchees. Un back-office qui
     *    renomme un produit ne doit pas effacer ses declinaisons.
     *  - presente (y compris un tableau vide) : la liste recue fait foi. Ce qui
     *    n'y figure plus est supprime logiquement.
     *
     * Cette distinction permet les deux usages reels sans qu'ils s'excluent : la
     * mise a jour partielle d'un produit, et la redefinition complete de ses
     * declinaisons depuis un seul appel.
     *
     * @param  array{name?: string, description?: string|null, image_url?: string|null, category_id?: int, slug?: string|null, status?: CatalogStatus, variants?: array<int, array<string, mixed>>}  $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            if (array_key_exists('description', $data)) {
                $attributes['description'] = $data['description'];
            }

            /*
             * `array_key_exists` et non un `??` : la validation accepte `null`,
             * et envoyer une photo vide doit retirer la photo. Avec un `??`, une
             * valeur nulle serait lue comme absente et l'ancienne photo
             * resterait en place sans que l'appelant puisse la retirer.
             */
            if (array_key_exists('image_url', $data)) {
                $attributes['image_url'] = $data['image_url'];
            }

            if (array_key_exists('status', $data)) {
                $attributes['status'] = $data['status'];
            }

            if (array_key_exists('category_id', $data)) {
                $attributes['category_id'] = $this->resolveCategory((int) $data['category_id'])->id;
            }

            if (array_key_exists('slug', $data) && $data['slug'] !== null) {
                $attributes['slug'] = $this->resolveSlug($data['slug'], $data['name'] ?? $product->name, $product);
            } elseif (array_key_exists('name', $data)) {
                $attributes['slug'] = $this->resolveSlug(null, $data['name'], $product);
            }

            if ($attributes !== []) {
                $this->products->update($product, $attributes);
            }

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants'] ?? []);
            }

            return $this->products->findWithRelations($product->uuid);
        });
    }

    /**
     * Supprime logiquement un produit et toutes ses variantes.
     *
     * Dans le meme ordre que dans Eloquent : les variantes d'abord, pour qu'aucune
     * ligne ne reste rattachee a un produit qui n'existe plus. Le tout est
     * atomique, donc un echec partiel ne laisse pas le produit sans declinaisons
     * actives.
     */
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $this->variants->deleteAllForProduct((int) $product->id);

            $this->products->delete($product);
        });
    }

    /**
     * Aligne les variantes d'un produit sur la liste recue.
     *
     * Pour chaque variante entrante, trois cas :
     *
     *  1. un `uuid` est fourni : c'est une variante existante a modifier. Elle
     *     doit appartenir a ce produit, sinon la requete est refusee plutot
     *     que de deplacer une variante d'un autre produit ;
     *  2. aucun `uuid`, mais un `sku` deja connu : on la considere comme une
     *     modification. C'est le cas le plus courant quand un back-office
     *     envoie un tableau reconstruit a partir d'un tableur ou d'un formulaire
     *     qui n'a pas conserve les identifiants ;
     *  3. ni l'un ni l'autre : creation.
     *
     * Les variantes restantes, absentes de la liste, sont supprimees
     * logiquement. Elles ne sont pas effacees physiquement : une variante peut
     * etre referencee par des commandes passees, et l'historique de vente doit
     * rester consultable.
     *
     * @param  array<int, array<string, mixed>>  $incoming
     */
    private function syncVariants(Product $product, array $incoming): void
    {
        $existing = $this->variants->forProduct((int) $product->id);

        /** @var array<string, Variant> $byUuid */
        $byUuid = $existing->keyBy('uuid')->all();

        /** @var array<string, Variant> $bySku */
        $bySku = $existing->keyBy(fn (Variant $variant): string => Str::upper((string) $variant->sku))->all();

        /** @var array<string, true> $keptUuids */
        $keptUuids = [];
        $seenSkus = [];

        foreach ($incoming as $variantData) {
            $sku = (string) $variantData['sku'];
            $normalisedSku = Str::upper($sku);

            // Deux entrees portant le meme SKU dans la meme requete
            // produiraient une violation de l'index unique en base. Le detecter
            // ici donne une erreur 422 exploitable, nommant le SKU en cause.
            if (isset($seenSkus[$normalisedSku])) {
                throw new ApiException(
                    'Le SKU '.$sku.' apparait plusieurs fois dans la liste des variantes.',
                    422,
                    'DUPLICATED_VARIANT_SKU',
                    ['sku' => $sku],
                );
            }

            $seenSkus[$normalisedSku] = true;

            $attributes = [
                'sku' => $sku,
                'name' => (string) $variantData['name'],

                /*
                 * Taille et couleur se copient telles quelles, sans repli sur
                 * `name`. Le libelle reste le libelle : forcer « Taille unique »
                 * dans une colonne `size` et « Noir » dans `color` quand
                 * l'administrateur ne les a pas saisis inventerait une donnee
                 * qu'aucun etage superieur n'a produite. Le guichet et la
                 * boutique s'en servent pour l'affichage, ou `name` suffit.
                 */
                'size' => $variantData['size'] ?? null,
                'color' => $variantData['color'] ?? null,

                'price' => $this->priceAsAmount($variantData['price']),
                'stock' => $variantData['stock'],
                'status' => $variantData['status'] ?? $product->status,
            ];

            $variant = $this->locateExistingVariant($variantData, $product, $byUuid, $bySku);

            if ($variant !== null) {
                $this->variants->update($variant, $attributes);
                $keptUuids[$variant->uuid] = true;

                continue;
            }

            $this->createVariant($product, $attributes);
        }

        // Suppression de ce qui n'est plus dans la liste. La lecture se fait sur
        // la collection chargee au debut : un appel de suppression par variante
        // suffit, et l'ensemble reste dans la transaction deja ouverte.
        foreach ($existing as $variant) {
            if (! isset($keptUuids[$variant->uuid])) {
                $this->variants->delete($variant);
            }
        }
    }

    /**
     * Ajoute une variante a un produit.
     *
     * Le SKU est verifie avant l'insertion parce que son unicite est globale en
     * base. Sans ce controle, une reference deja prise ailleurs produirait une
     * violation de contrainte traduite en erreur 500, sans que le back-office
     * sache quoi corriger.
     *
     * Un SKU correspondant a une variante retiree de CE produit la restaure
     * plutot que d'echouer. C'est le cas courant d'un aller-retour au
     * back-office : on supprime une declinaison par erreur, on la remet le
     * lendemain. La ligne portant le SKU n'a pas disparu de l'index unique, donc
     * une creation « normale » echouerait ; restaurer rend l'aller-retour
     * possible sans changer de SKU ni intervenir en base.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ApiException si le SKU est deja porte par une variante active de
     *                      ce produit apres traitement des entrees precedentes
     */
    /**
     * Prix d'une variante, en francs CFA entiers.
     *
     * La validation accepte « 2500,00 » comme « 2500 », parce qu'un back-office
     * saisit au clavier et n'ecrit pas forcement la forme de l'API. Mais la
     * colonne est entiere : ecrire la chaine telle quelle ferait echouer
     * l'insertion sur PostgreSQL, ou laisserait un texte dans une colonne de
     * prix sans que rien ne le signale. La conversion est donc faite ici, au
     * point ou la variante est ecrite, et non a la validation — c'est le seul
     * endroit qui garantit la valeur quelle que soit la voie d'ecriture.
     */
    private function priceAsAmount(mixed $price): int
    {
        return $this->money->toAmount($price);
    }

    /**
     * Cree une variante, ou reprend celle qui porte deja son SKU.
     */
    private function createVariant(Product $product, array $attributes): void
    {
        $sku = (string) $attributes['sku'];

        $attributes['price'] = $this->priceAsAmount($attributes['price']);

        $existing = $this->variants->findBySku($sku);

        if ($existing === null) {
            $this->variants->create(array_merge($attributes, [
                'product_id' => $product->id,
            ]));

            return;
        }

        if ((int) $existing->product_id !== (int) $product->id) {
            throw new ApiException(
                'Cette reference (SKU) est deja utilisee par un autre produit.',
                409,
                'SKU_ALREADY_EXISTS',
                ['sku' => $sku],
            );
        }

        if ($existing->trashed()) {
            $existing->restore();
            $this->variants->update($existing, $attributes);

            return;
        }

        /*
         * La variante porteuse du SKU est active et appartient deja a ce
         * produit. La resynchronisation ne l'avait pas reconnue comme
         * existante, ce qui arrive quand deux entrees du payload se rejoignent
         * sur le meme SKU enchang chacune d'identifiant : la premiere a
         * renomme une variante en X, la seconde réclame X. Continuer
         * reviendrait a laisser deux lignes en competition sur l'index
         * unique, dont l'une echouerait en base sans que l'appelant comprenne
         * pourquoi.
         */
        throw new ApiException(
            'Ce SKU designe deux variantes de la meme liste.',
            422,
            'DUPLICATED_VARIANT_SKU',
            ['sku' => $sku],
        );
    }

    /**
     * Retrouve la variante existante qu'une entree du payload designe.
     *
     * @param  array<string, mixed>  $variantData
     * @param  array<string, Variant>  $byUuid
     * @param  array<string, Variant>  $bySku
     */
    private function locateExistingVariant(
        array $variantData,
        Product $product,
        array $byUuid,
        array $bySku,
    ): ?Variant {
        $uuid = $variantData['uuid'] ?? null;

        if (is_string($uuid) && $uuid !== '') {
            $variant = $byUuid[$uuid] ?? null;

            if ($variant === null) {
                /*
                 * L'uuid designe quelque chose qui n'appartient pas a ce
                 * produit : soit un uuid inconnu, soit celui d'une variante d'un
                 * autre produit. Les deux cas sont refuses, dans le premier cas
                 * parce qu'il n'y a rien a modifier, dans le second parce que
                 * detruire une variante d'un autre produit depuis cette route
                 * serait une escalade de privileges.
                 */
                throw new ApiException(
                    'Cette variante n\'appartient pas au produit indique.',
                    422,
                    'VARIANT_NOT_IN_PRODUCT',
                    ['uuid' => $uuid],
                );
            }

            return $variant;
        }

        $sku = Str::upper((string) $variantData['sku']);

        return $bySku[$sku] ?? null;
    }

    /**
     * Verifie que la categorie existe et peut recevoir des produits.
     */
    private function resolveCategory(int $categoryId): Category
    {
        // Cle primaire et non cle de route : `category_id` est la colonne
        // reellement stockee, validee par `exists` sur la table `categories`.
        $category = $this->categories->findById($categoryId);

        // Une categorie supprimee logiquement ou masquee n'est pas une cible
        // valide : y ranger un produit le rendrait invisible du catalogue
        // public, ce qui est le plus souvent une faute de saisie qu'on ne
        // veut pas rendre definitive.
        if ($category === null || $category->status !== CatalogStatus::ACTIVE) {
            throw new ApiException('Categorie introuvable.', 404, 'CATEGORY_NOT_FOUND');
        }

        return $category;
    }

    /**
     * Produit un slug unique pour le produit.
     *
     * Meme convention que pour les categories : un slug fourni explicitement et
     * deja pris est refuse (il a ete choisi pour une raison), un slug derive du
     * nom est suffixe automatiquement.
     */
    private function resolveSlug(?string $requested, string $name, ?Product $except = null): string
    {
        if ($requested !== null && $requested !== '') {
            $slug = Str::slug($requested);

            if ($this->products->slugExists($slug, $except)) {
                throw new ApiException(
                    'Ce slug est deja utilise par un autre produit.',
                    409,
                    'SLUG_ALREADY_EXISTS',
                    ['slug' => $slug],
                );
            }

            return $slug;
        }

        $base = Str::slug($name) !== '' ? Str::slug($name) : 'produit';

        $slug = $base;
        $suffix = 1;

        while ($this->products->slugExists($slug, $except)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
