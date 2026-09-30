<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Product\StoreProductRequest;
use App\Http\Requests\Api\V1\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\VariantResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Produits du catalogue et leurs variantes.
 *
 * Lecture publique, écriture réservée à l'administrateur, comme pour les
 * catégories. Le contrôle d'accès passe par les policies : le contrôleur ne
 * teste jamais le rôle lui-même.
 */
final class ProductController extends ApiController
{
    public function __construct(private readonly ProductService $products) {}

    /**
     * Liste paginee du catalogue.
     *
     * Seuls les produits actifs sont visibles par defaut. Les filtres
     * `category_id` et `search` servent a la navigation ; le filtre `status` est
     * volontairement absent de l'API publique (voir ProductService::listCatalog,
     * qui en refuse l'usage non admin).
     *
     * Les attributs de documentation sont portés par l'action et non par les
     * méthodes privées de filtre : la génération ne lit que l'action de route,
     * un attribut posé plus bas ne serait jamais collecté.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection(
            $this->products->listCatalog($this->perPage($request), [
                'category_id' => $this->filterCategoryId($request),
                'search' => $this->filterSearch($request),
            ]),
        );
    }

    public function show(string $uuid): ProductResource
    {
        return ProductResource::make($this->products->findOrFail($uuid));
    }

    /**
     * Variantes d'un produit.
     *
     * Exposee separement de `show` parce que la page de detail d'un produit
     * affiche la fiche, tandis que le selectionneur de declinaison recharge
     * souvent la seule liste des variantes en changeant de taille, sans
     * recharger tout le produit.
     */
    public function variants(string $uuid): AnonymousResourceCollection
    {
        $product = $this->products->findOrFail($uuid);

        return VariantResource::collection($this->products->listVariants($product));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->products->create($request->body());

        return $this->jsonResource(ProductResource::make($product), Response::HTTP_CREATED);
    }

    public function update(UpdateProductRequest $request, string $uuid): ProductResource
    {
        $product = $this->products->findForManagementOrFail($uuid);

        $this->authorize('update', $product);

        return ProductResource::make($this->products->update($product, $request->body()));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $product = $this->products->findForManagementOrFail($uuid);

        $this->authorize('delete', $product);

        $this->products->delete($product);

        return response()->json([
            'data' => [
                'message' => 'Produit supprime.',
            ],
        ]);
    }

    /**
     * Identifiant de categorie demande, ou null.
     *
     * Valide ici plutot qu'avec un FormRequest : un parametre de filtrage
     * n'est pas une entree metier, et `category_id` n'a pas a etre copie dans
     * le payload. Un parametre invalide est refuse par une ApiException, donc
     * avec un code d'erreur stable, et non par une redirection d'abort() dont
     * le rendu generique n'exposerait pas la cause.
     */
    private function filterCategoryId(Request $request): ?int
    {
        $value = $request->query('category_id');

        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new ApiException(
                'Le parametre category_id doit etre un identifiant entier positif.',
                422,
                'INVALID_FILTER',
                ['filter' => 'category_id'],
            );
        }

        return (int) $value;
    }

    /**
     * Terme de recherche demande, ou null.
     *
     * Un tableau envoye dans `search` (par exemple `?search[]=a`) est ignore
     * plutot que fatal : le filtre n'a pas a devenir un vecteur d'attaque sur
     * la clause LIKE.
     */
    private function filterSearch(Request $request): ?string
    {
        $value = $request->query('search');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Borne de longueur : un terme de recherche n'a pas d'usage au-dela de
        // quelques dizaines de caracteres, et une chaine enorme finirait dans
        // la clause LIKE de chaque ligne du catalogue.
        return mb_substr($value, 0, 100);
    }
}
