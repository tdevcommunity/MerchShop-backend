<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\V1\Admin\Concerns\GuardsBackofficeAction;
use App\Http\Resources\ProductResource;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Produits, vus par le back-office.
 *
 * La liste publique (`ProductController::index`) repond a « qu'est-ce qui est
 * en vente ? » et ne montre que les produits actifs. L'ecran de gestion, lui,
 * doit repondre a « ou est passe mon produit ? » — y compris quand ce produit
 * est un brouillon, un article archive ou la derniere creation, qui n'est pas
 * encore publiee.
 *
 * C'est la raison d'etre de ce controleur : sans lui, un produit enregistre
 * mais non publie n'apparait nulle part, ni dans le catalogue (filtre actif),
 * ni dans le back-office (meme endpoint). L'administrateur vient de le creer et
 * ne le voit plus, ce qui ressemble a une perte de donnees.
 *
 * Le controle d'acces est pose par le middleware d'entree (`backoffice`) puis,
 * ici, par la permission `catalog` : gerer le catalogue est une decision
 * d'organisation, reservee a l'administrateur, comme pour la creation.
 */
final class AdminProductController extends ApiController
{
    use GuardsBackofficeAction;

    public function __construct(
        private readonly ProductService $products,
    ) {}

    /**
     * Tous les produits, tous statuts confondus.
     *
     * Les filtres sont facultatifs et se combinent : `?status=0&q=stylo`
     * demande les brouillons dont le nom, le slug ou un SKU contient « stylo ».
     * L'absence de `status` vaut « tous les statuts », contrairement au
     * catalogue ou l'absence de filtre vaut « actifs seulement ».
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertCanManage($request, 'catalog', 'consulter le catalogue');

        return ProductResource::collection(
            $this->products->listForBackoffice($this->perPage($request), [
                'q' => $this->filterKeyword($request),
                'status' => $this->filterStatus($request),
                'category' => $this->filterCategorySlug($request),
            ]),
        );
    }

    /**
     * Une fiche produit, quel que soit son statut.
     *
     * Le catalogue public repond 404 sur un produit masque ; sa propre fiche de
     * gestion ne le peut pas, sinon l'administrateur ne pourrait pas rouvrir le
     * brouillon qu'il vient de creer. On passe donc par la lecture de gestion,
     * la meme que celle utilisee par l'ecriture.
     */
    public function show(Request $request, string $uuid): ProductResource
    {
        $this->assertCanManage($request, 'catalog', 'consulter le catalogue');

        return ProductResource::make($this->products->findForManagementOrFail($uuid));
    }

    /**
     * Statut demande, ou null pour « tous ».
     *
     * La valeur est comparee aux statuts connus (`CatalogStatus`) plutot qu'a
     * « un entier » : une valeur hors nomenclature produirait sinon une liste
     * vide incomprehensible, la ou une 422 nomme le filtre fautif.
     */
    private function filterStatus(Request $request): ?int
    {
        $value = $request->query('status');

        if ($value === null || $value === '') {
            return null;
        }

        $intValue = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT);

        if ($intValue === false || CatalogStatus::tryFrom($intValue) === null) {
            throw new ApiException(
                'Le parametre status ne correspond a aucun statut connu.',
                422,
                'INVALID_FILTER',
                ['filter' => 'status', 'allowed' => array_column(CatalogStatus::cases(), 'value')],
            );
        }

        return $intValue;
    }

    /**
     * Slug de categorie demande, ou null.
     *
     * Meme forme que le filtre public : un slug valide en base est en
     * `alpha_dash` borne a 150, donc une valeur hors de cette forme ne trouvera
     * rien et est refusee comme filtre invalide plutot que transmise a la base.
     */
    private function filterCategorySlug(Request $request): ?string
    {
        $value = $request->query('category');

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,150}$/', $value)) {
            throw new ApiException(
                'Le parametre category doit etre un slug valide.',
                422,
                'INVALID_FILTER',
                ['filter' => 'category'],
            );
        }

        return $value;
    }

    /**
     * Terme de recherche demande, ou null.
     *
     * Un tableau (`?q[]=a`) est ignore plutot que fatal, et le terme est borne
     * en longueur : il finit dans la clause LIKE de chaque ligne, donc aucune
     * chaine arbitrairement longue ne doit y entrer.
     */
    private function filterKeyword(Request $request): ?string
    {
        $value = $request->query('q');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 100);
    }
}
