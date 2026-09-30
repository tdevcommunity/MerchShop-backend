<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Category\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Categories du catalogue.
 *
 * Lecture publique, ecriture reservee a l'administrateur. Le controle d'acces
 * est delegue aux policies : le controleur ne teste jamais le role lui-meme,
 * sinon la regle se dupliquerait ici et la deviendrait invisible.
 */
final class CategoryController extends ApiController
{
    public function __construct(private readonly CategoryService $categories) {}

    /**
     * Liste paginee des categories actives.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            $this->categories->listCatalog($this->perPage($request)),
        );
    }

    public function show(string $uuid): CategoryResource
    {
        return CategoryResource::make($this->categories->findOrFail($uuid));
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = $this->categories->create($request->body());

        return $this->jsonResource(CategoryResource::make($category), Response::HTTP_CREATED);
    }

    public function update(UpdateCategoryRequest $request, string $uuid): CategoryResource
    {
        $category = $this->categories->findForManagementOrFail($uuid);

        $this->authorize('update', $category);

        return CategoryResource::make($this->categories->update($category, $request->body()));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $category = $this->categories->findForManagementOrFail($uuid);

        $this->authorize('delete', $category);

        $this->categories->delete($category);

        return response()->json([
            'data' => [
                'message' => 'Categorie supprimee.',
            ],
        ]);
    }
}
