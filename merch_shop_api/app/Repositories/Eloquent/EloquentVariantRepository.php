<?php

namespace App\Repositories\Eloquent;

use App\Models\Variant;
use App\Repositories\Contracts\VariantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Acces aux variantes sur Eloquent.
 *
 * @implements VariantRepositoryInterface
 */
final class EloquentVariantRepository extends EloquentRepository implements VariantRepositoryInterface
{
    protected function modelClass(): string
    {
        return Variant::class;
    }

    public function forProduct(int $productId): Collection
    {
        /** @var Collection<int, Variant> $variants */
        $variants = $this->query()
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get();

        return $variants;
    }

    public function forPublicCatalog(int $productId): Collection
    {
        /** @var Collection<int, Variant> $variants */
        $variants = $this->query()
            ->active()
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get();

        return $variants;
    }

    public function findForProduct(int $productId, string $uuid): ?Variant
    {
        /** @var Variant|null $variant */
        $variant = $this->query()
            ->where('product_id', $productId)
            ->where('uuid', $uuid)
            ->first();

        return $variant;
    }

    public function findBySku(string $sku): ?Variant
    {
        /** @var Variant|null $variant */
        $variant = $this->query()->withTrashed()->where('sku', $sku)->first();

        return $variant;
    }

    public function findTrashedForProductBySku(int $productId, string $sku): ?Variant
    {
        /** @var Variant|null $variant */
        $variant = $this->query()
            ->onlyTrashed()
            ->where('product_id', $productId)
            ->where('sku', $sku)
            ->first();

        return $variant;
    }

    public function deleteAllForProduct(int $productId): int
    {
        // La borne de lot 1000 limite la taille d'une seule instruction et
        // evite un verrou prolongé sur une table de stock lorsqu'un produit
        // porte beaucoup de declinaisons.
        $deleted = 0;

        do {
            $batch = $this->query()
                ->where('product_id', $productId)
                ->limit(1000)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += $this->query()
                ->whereIn('id', $batch->modelKeys())
                ->delete();
        } while (true);

        return $deleted;
    }

    public function lockForSale(array $uuids): Collection
    {
        /*
         * `product.category` est chargee avec le produit, et non laissee en
         * acces paresseux : la categorie est recopiee sur chaque ligne de
         * commande (OrderService::createItems), donc sans ce chargement elle
         * declencherait une requete par ligne au moment de la copie. Le verrou
         * ne porte que sur les variantes, et la categorie n'a pas a etre
         * verrouillee : la renommer ne rend pas une variante invendable.
         */
        /** @var Collection<int, Variant> $variants */
        $variants = $this->query()
            ->whereIn('uuid', $uuids)
            ->with('product:id,name,status,category_id')
            ->with('product.category:id,name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'uuid', 'product_id', 'sku', 'name', 'size', 'color', 'price', 'stock', 'status']);

        return $variants;
    }

    public function restock(Variant $variant, int $quantity): void
    {
        /*
         * Increment arithmetique et non recalcul : le stock n'a pas ete relu
         * depuis le decrement, donc lui ajouter la quantite restitue exactement
         * l'etat anterieur, sans dependre d'une valeur intermediaire.
         */
        $this->query()
            ->where('id', $variant->id)
            ->increment('stock', $quantity);

        $variant->stock = $variant->stock + $quantity;
    }
}
