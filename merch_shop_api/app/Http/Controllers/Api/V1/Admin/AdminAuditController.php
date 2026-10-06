<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\V1\Admin\Concerns\GuardsBackofficeAction;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Le journal d'audit.
 *
 * Une seule route, et c'est le maximum que ce journal doit offrir : lire. Il
 * n'y a ni creation, ni modification, ni suppression cote back-office.
 *
 * C'est la propriete qui le distingue du journal de stock. Un journal d'actions
 * dont on peut effacer une ligne ne repond plus a la question pour laquelle on
 * le consulte — « qui a change ce prix ? » — des que la reponse estGenee. Il
 * reste donc append-only : corriger une trace passe par l'action inverse, qui
 * laisse voir le detour, et non par l'effacement du procede.
 *
 * Rien n'est donc ecrit par ce controleur : les traces naissent la ou l'action
 * a eu lieu, via `AuditLogger`. Les lire ailleurs ne les rendrait pas plus
 * fiables, seulement plus faciles a perdre.
 */
final class AdminAuditController extends ApiController
{
    use GuardsBackofficeAction;
    /**
     * Les traces, de la plus recente a la plus ancienne.
     *
     * Le journal se lit par « qu'est-ce qui s'est passe ces derniers minutes »,
     * donc en temps inverse. C'est aussi l'ordre qui rend une anomalie
     * reproductible : deux guichetiers se relaient, et la question est de ce que
     * chacun a fait depuis que l'autre a pris sa place.
     *
     * Le compte d'actions est renvoye par la pagination, donc un journal de dix
     * mille lignes affiche son total sans qu'on en charge une seule.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /*
         * Le journal est reserve a l'administrateur, et ce controle est la
         * raison pour laquelle il l'est. Une trace dit qui a reinitialise quel
         * mot de passe et qui a change quel role : c'est une cartographie des
         * droits de chacun, et la donner au guichet qui la lit revient a lui
         * donner, en lecture, la liste de ce qu'il pourrait viser.
         */
        $this->assertCanManage($request, 'audit', 'consulter le journal');

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'resource' => ['nullable', 'string', 'max:64'],
            'resource_id' => ['nullable', 'string', 'max:64'],
            'user' => ['nullable', 'uuid', 'exists:users,uuid'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return AuditLogResource::collection($this->search($validated, $this->perPage($request)));
    }

    /**
     * La liste filtree.
     *
     * Le compte est filtre par uuid, comme partout ailleurs : c'est ce que le
     * back-office a sous la main apres avoir ouvert la fiche d'un guichetier, et
     * faire passer une adresse pour retrouver un compte n'apporterait rien.
     *
     * L'utilisateur est chargee — et non lu par une jointure sur `user_email` —
     * parce que le nom affiche n'est pas dans la ligne : il est sur le compte.
     * Son absence apres suppression se lit alors par `userId` vide, ce qui
     * distingue « trace d'un compte supprime » de « trace sans auteur ».
     *
     * @param  array{action?: string|null, resource?: string|null, resource_id?: string|null, user?: string|null, q?: string|null}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    private function search(array $filters, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user')
            ->when($filters['action'] ?? null, fn (Builder $query, string $action): Builder => $query->where('action', $action))
            ->when($filters['resource'] ?? null, fn (Builder $query, string $resource): Builder => $query->where('resource', $resource))
            ->when($filters['resource_id'] ?? null, fn (Builder $query, string $id): Builder => $query->where('resource_id', $id))
            ->when($filters['user'] ?? null, fn (Builder $query, string $uuid): Builder => $query->whereHas(
                'user',
                fn (Builder $users): Builder => $users->where('uuid', $uuid),
            ))

            /*
             * La recherche porte sur l'action et l'adresse de l'auteur.
             *
             * Ce sont les deux seules chaines qu'un guichet lit d'une trace, et
             * les seules qu'il ait sous les yeux pour chercher. Le nom de la
             * ressource et les valeurs avant/apres en sont exclus : ce sont des
             * donnees structurees, dont la recherche par fragment de texte
             * rendrait plus de resultats que de reponses.
             */
            ->when(isset($filters['q']), function (Builder $query) use ($filters): void {
                $term = trim((string) $filters['q']);

                $query->where(fn (Builder $inner): Builder => $inner
                    ->whereRaw('lower(action) like ?', ['%'.mb_strtolower($term).'%'])
                    ->orWhereRaw('lower(user_email) like ?', ['%'.mb_strtolower($term).'%']));
            })

            /*
             * Tri inverse sur `created_at`, avec l'identifiant interne en second
             * critere.
             *
             * Deux traces n'ont la meme date que si elles sont ecrites dans la
             * meme transaction, et le second critere ne sert donc qu'a stabiliser
             * l'ordre. Il est pris sur la colonne interne parce qu'elle est un
             * entier monotone, la ou un uuid est tire au hasard : c'est la seule
             * des deux qui donne un ordre reproductible d'un appel a l'autre.
             */
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }
}