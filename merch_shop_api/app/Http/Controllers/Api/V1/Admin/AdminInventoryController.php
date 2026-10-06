<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CatalogStatus;
use App\Enums\InventoryReason;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\V1\Admin\Concerns\GuardsBackofficeAction;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Http\Resources\VariantResource;
use App\Models\InventoryAdjustment;
use App\Models\Variant;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * L'etat du stock, et sa correction.
 *
 * Deux lectures et une ecriture, et la separation n'est pas une commodite : le
 * stock se lit tous les jours pendant le festival, et il ne se corrige qu'a
 * l'arrivee, sur un ecart de comptage ou une piece abimee. Un role qui peut
 * corriger le stock est donc un role qui peut desaccorder le plan de tracking
 * sur le stock reel — ce qui est précisément la raison pour laquelle ce
 * controle existe et n'est pas ouvert au seul `staff`.
 *
 * L'ecriture passe par `InventoryService`, qui est le seul a poser l'invariant
 * que `previous + delta = next` et que le stock ne devient pas negatif. Un
 * controleur qui ecrivait `stock = stock + delta` laisserait passer un retrait
 * de plus que ce qu'il y a en rayon, et le catalogue promettrait alors un article
 * que le stand n'a pas.
 */
final class AdminInventoryController extends ApiController
{
    use GuardsBackofficeAction;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Le stock de toutes les declinaisons.
     *
     * Triees par produit puis par declinaison, parce que c'est l'ordre de lecture
     * d'un stand : on regarde une ligne de caisse, et les tailles et les couleurs
     * du meme article se suivent.
     *
     * Le stock et le seuil sont renvoyes ensemble, et le niveau en est deduit
     * dans la ressource plutot que dans le front : c'est une comparaison, et une
     * comparaison faite a deux endroits finirait par diverger des le premier
     * seuil modifie.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertCanManage($request, 'inventory', 'consulter le stock');

        $validated = $request->validate([
            'level' => ['nullable', 'string', Rule::in(['available', 'low', 'out', 'disabled'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $level = $validated['level'] ?? null;

        $query = Variant::query()
            ->with(['product.category']);

        /*
         * Le niveau selectionne est un `match` sur une seule chaine, et non une
         * suite de conditions cumulables.
         *
         * Cumulables, elles se contrediraient : `disabled` demande `status = 0`
         * et `out` demande `status = 1`, donc un filtre « desactive et en rupture »
         * ne rendrait jamais rien — silencieusement, sans erreur. Le `match` met
         * les quatre etats dans le meme etat d'esprit et rend impossible d'en
         * demander deux.
         *
         * L'absence de filtre liste les declinaisons actives : la liste de stock
         * d'un stand est la liste de ce qu'il peut servir. Une declinaison
         * desactivee apparait des qu'on demande `disabled`, sans quoi elle
         * serait invisible et son stock jamais reconcilie.
         */
        match ($level) {
            'disabled' => $query->where('status', CatalogStatus::INACTIVE->value),
            'out' => $query->where('status', CatalogStatus::ACTIVE->value)->where('stock', '<=', 0),
            'low' => $query->where('status', CatalogStatus::ACTIVE->value)
                ->where('stock', '>', 0)
                ->whereColumn('stock', '<=', 'low_stock_threshold'),
            'available' => $query->where('status', CatalogStatus::ACTIVE->value)
                ->whereColumn('stock', '>', 'low_stock_threshold'),
            default => $query->where('status', CatalogStatus::ACTIVE->value),
        };

        if (isset($validated['q'])) {
            $term = trim((string) $validated['q']);

            $query->where(function (Builder $inner) use ($term): void {
                $inner->whereRaw('lower(sku) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereHas('product', fn (Builder $products): Builder => $products->whereRaw('lower(name) like ?', ['%'.mb_strtolower($term).'%']));
            });
        }

        /*
         * Par produit puis par ordre de creation.
         *
         * Un tri par nom de declinaison sans le groupement du produit disperserait
         * chaque article dans toute la liste : les tailles d'un tee-shirt
         * apparaitraient entre deux articles sans rapport, et le guichet
         * n'aurait plus la ligne de caisse sous les yeux.
         */
        $variants = $query->orderBy('product_id')->orderBy('id')->get();

        return VariantResource::collection($variants);
    }

    /**
     * Le journal des ajustements, du plus recent au plus ancien.
     *
     * Se lit a l'envers : c'est la derniere entree qui explique le stock actuel,
     * et c'est donc la premiere qu'on cherche. Un journal lu dans l'ordre
     * chronologique obligeait a parcourir toute l'histoire pour atteindre le
     * dernier mouvement.
     *
     * Le filtre par declinaison passe par son uuid, parce que c'est ce que le
     * back-office a sous la main : il vient d'ouvrir une ligne de stock et sait
     * deja de quelle declinaison il s'agit.
     */
    public function logs(Request $request): AnonymousResourceCollection
    {
        /*
         * Le journal de stock se lit avec la meme permission que le journal
         * d'audit, et pour la meme raison : il dit qui a retire combien de
         * pieces, donc qui decide de ce qui a disparu. C'est une information de
         * controle, pas de vente.
         */
        $this->assertCanManage($request, 'audit', 'consulter le journal');

        $validated = $request->validate([
            'variant' => ['nullable', 'uuid', 'exists:product_variants,uuid'],
            'reason' => ['nullable', 'string', Rule::enum(InventoryReason::class)],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $paginator = InventoryAdjustment::query()
            ->with(['variant', 'product'])
            ->when($validated['variant'] ?? null, fn (Builder $query, string $uuid): Builder => $query->whereHas(
                'variant',
                fn (Builder $variants): Builder => $variants->where('uuid', $uuid),
            ))
            ->when($validated['reason'] ?? null, fn (Builder $query, string $reason): Builder => $query->where('reason', $reason))
            ->when(isset($validated['q']), function (Builder $query) use ($validated): void {
                $term = trim((string) $validated['q']);

                $query->where(fn (Builder $inner): Builder => $inner
                    ->whereRaw('lower(sku) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(product_name) like ?', ['%'.mb_strtolower($term).'%']));
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate($this->perPage($request));

        return InventoryAdjustmentResource::collection($paginator);
    }

    /**
     * Corriger le stock d'une declinaison.
     *
     * La variation est demandee, pas le stock final. C'est la seule forme qui
     * sAISit sans que le guichetier ait a calculer : « on a recu douze pieces »
     * est une information, « le stock est maintenant trente-sept » en est une
     * autre, et la seconde se trompe des que deux personnes ajustent en meme temps.
     *
     * Le service tranche ces deux questions : la variation doit etre non nulle,
     * bornee, et ne pas rendre le stock negatif. Il rend aussi la ligne de
     * journal, dans la meme transaction — donc il n'y a pas de chemin par lequel
     * le stock bouge sans trace.
     */
    public function adjust(Request $request, string $uuid): InventoryAdjustmentResource
    {
        $this->assertCanManage($request, 'inventoryAdjust', 'corriger le stock');

        $actor = $this->actor($request);

        $validated = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', Rule::enum(InventoryReason::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'delta.not_in' => 'Un ajustement doit modifier le stock.',
            'delta.required' => 'Indiquez de combien le stock change.',
        ]);

        $variant = Variant::query()->with('product')->where('uuid', $uuid)->first();

        if ($variant === null) {
            throw new ApiException('Cette déclinaison n\'existe plus.', 404, 'VARIANT_NOT_FOUND');
        }

        $adjustment = $this->inventory->adjust(
            $variant,
            (int) $validated['delta'],
            $validated['reason'],
            $actor,
            (string) ($validated['note'] ?? ''),
        );

        return InventoryAdjustmentResource::make($adjustment);
    }

    /**
     * Le compte qui ajuste.
     *
     * Non nullable pour la meme raison que dans le controleur des commandes : le
     * middleware d'administration a deja refuse toute requete sans session. Mais
     * ici l'auteur est recopie sur la ligne de journal, donc une evolution du
     * middleware qui laisserait passer un appel sans compte produirait un
     * mouvement de stock sans auteur — traçable, mais pas imputable. L'echec
     * franche est donc preferable a une trace incomplete.
     */
}