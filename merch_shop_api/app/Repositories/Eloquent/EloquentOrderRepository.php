<?php

namespace App\Repositories\Eloquent;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Acces Eloquent aux commandes.
 *
 * @extends EloquentRepository<Order>
 */
final class EloquentOrderRepository extends EloquentRepository implements OrderRepositoryInterface
{
    /**
     * @return class-string<Order>
     */
    protected function modelClass(): string
    {
        return Order::class;
    }

    public function findWithRelations(int|string $id, array $with = []): ?Order
    {
        $query = $this->query()->with($with);

        /*
         * Resout par identifiant interne en premier : c'est la forme utilisee
         * par le tunnel, qui connait la variante commandee. Le repli sur la cle
         * de route couvre le cas du client, qui adresse la commande par son uuid.
         */
        if (is_int($id)) {
            $order = $query->where('id', $id)->first();

            if ($order !== null) {
                return $order;
            }
        }

        return $query->where($this->routeKeyName(), $id)->first();
    }

    public function paginateForUser(User $user, int $perPage, ?OrderStatus $status = null): LengthAwarePaginator
    {
        return $this->query()
            ->where('user_id', $user->id)
            ->when($status, fn (Builder $query, OrderStatus $status): Builder => $query->where('status', $status))
            ->with(['items.variant', 'payments', 'invoice'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    public function paginateForPickup(int $perPage): LengthAwarePaginator
    {
        return $this->query()
            ->where('fulfillment_method', FulfillmentMethod::PICKUP)
            ->whereNotIn('status', [OrderStatus::CANCELLED, OrderStatus::REFUNDED])
            ->with(['items', 'user'])
            ->oldest('created_at')
            ->oldest('id')
            ->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateForBackoffice(int $perPage, array $filters = []): LengthAwarePaginator
    {
        return $this->query()
            ->when($filters['status'] ?? null, fn (Builder $query, OrderStatus $status): Builder => $query->where('status', $status))
            ->when($filters['fulfillment'] ?? null, fn (Builder $query, FulfillmentMethod $method): Builder => $query->where('fulfillment_method', $method))

            /*
             * Le filtre sur le paiement porte sur l'etat de la tentative la plus
             * recente, et non sur l'existence d'une tentative.
             *
             * C'est ce que le back-office affiche dans sa colonne : une commande
             * dont le premier essai a echoue puis dont le second a reussi est
             * « reglee », pas « en echec ». Filtrer sur `payments.status` tout
             * court ferait au contraire remonter cette commande la-dedans, et
             * le guichet relancerait un client qui a deja paye.
             *
             * Le sous-requete porte donc sur le plus grand identifiant de
             * paiement de la commande, qui est l'ordre chronologique des
             * tentatives : c'est ce meme critere que celui de la ressource, donc
             * le filtre et l'affichage ne peuvent pas diverger.
             */
            ->when($filters['paymentStatus'] ?? null, fn (Builder $query, PaymentStatus $status): Builder => $query->whereIn(
                'id',
                $this->paymentsWithLatestStatus($status)
            ))

            ->when($this->searchTerm($filters['q'] ?? null), function (Builder $query, string $term): Builder {
                /*
                 * La comparaison est faite sur les deux cotes en minuscules.
                 *
                 * PostgreSQL dispose d'`ilike`, qui fait exactement cela, mais
                 * SQLite — la base des tests — ne l'a pas. Ecrire `ilike`
                 * fonctionnerait donc en developpement et echouerait a la suite
                 * de tests, ce qui est le pire des ordres : la faute n'apparait
                 * que dans un des deux environnements. `lower()` des deux cotes
                 * est portable et equivalent, et c'est deja la convention du
                 * depot pour une comparaison insensible a la casse
                 * (`EloquentUserRepository::findByEmail`).
                 *
                 * Le second `lower()` n'est pas redondant : sans lui, la
                 * comparaison resterait sensible a la casse du motif, donc une
                 * recherche en majuscules ne trouverait rien.
                 */
                $folded = mb_strtolower($term);

                $query->where(function (Builder $inner) use ($folded): void {
                    $inner->whereRaw('lower(order_number) like ?', ['%'.$folded.'%'])
                        ->orWhereRaw('lower(customer_name) like ?', ['%'.$folded.'%'])

                        /*
                         * Le numero est recherche aussi avec son indicatif, parce
                         * que c'est la forme que le client a sous les yeux quand
                         * il paie : il lit « +228 90 12 34 56 » sur son
                         * recapitulatif et ne sait pas que le stand l'a stocke
                         * sans les 228. Chercher « 90 12 34 56 » doit le
                         * retrouver — les separateurs sont donc ignores.
                         *
                         * Les chiffres ne sont pas replies : ils n'ont pas de
                         * casse, donc le `lower()` n'aurait rien a faire et
                         * coutrait une passe sans effet.
                         */
                        ->orWhere('customer_phone_number', 'like', '%'.$this->digitsOnly($folded).'%');
                });
            })

            /*
             * Les relations sont chargees comme pour une lecture de commande
             * complete : la liste du back-office affiche les lignes du panier et
             * l'etat du paiement. Sans ce chargement, chaque ligne de la page
             * emettrait une requete par relation, donc le nombre de requetes
             * croirait avec le nombre de commandes affichees.
             */
            ->with(['items', 'payments', 'user'])

            /*
             * Tri par creation decroissante, comme `paginateForUser`.
             *
             * Le guichet cherche la commande qu'il vient d'encaisser, donc la
             * plus recente d'abord. `id` en second tri rend le resultat stable
             * quand deux commandes partagent la meme seconde : sans lui, la
             * meme page peut secomposer differemment d'un appel a l'autre.
             */
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Le terme de recherche, nettoye des separateurs.
     *
     * Une saisie de trois lettres ou moins ne selectionne rien : au stand, on
     * cherche un numero de commande ou un nom, et un fragment de deux lettres
     * ne rendrait que la liste entiere dans le desordre.
     */
    private function searchTerm(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $term = trim($search);

        return mb_strlen($term) >= 3 ? $term : null;
    }

    /**
     * Les commandes dont la tentative la plus recente est dans cet etat.
     *
     * « La plus recente » est le couple `(created_at, id)`, dans cet ordre : la
     * ressource qui affiche l'etat trie pareil, et un filtre qui choisirait une
     * autre ligne ferait diverger le filtre et ce qu'il filtre. Le second
     * critere n'est pas cosmetique — deux tentatives ouvertes dans la meme
     * milliseconde ont la meme date, et c'est la plus recente qui compte.
     *
     * La negation existe plutot qu'un `MAX()` groupe par commande parce qu'elle
     * se lit comme la definition : une tentative est la derniere si aucune autre
     * n'est plus recente. Un `GROUP BY` exigerait deKnow a l'avance que le
     * tri soit stable en base, ce qui n'est pas garanti.
     */
    private function paymentsWithLatestStatus(PaymentStatus $status): Builder
    {
        return DB::table('payments')
            ->select('order_id')
            ->where('status', $status->value)
            ->whereNotExists(function (QueryBuilder $newer): void {
                $newer->selectRaw('1')
                    ->from('payments as newer')
                    ->whereColumn('newer.order_id', 'payments.order_id')
                    ->whereRaw(
                        '(newer.created_at, newer.id) > (payments.created_at, payments.id)',
                    );
            });
    }

    /**
     * Un terme de recherche reduit a ses chiffres.
     *
     * Utilise pour le numero de telephone, ou les separateurs sont une question
     * de mise en forme et non d'identite.
     */
    private function digitsOnly(string $term): string
    {
        return preg_replace('/\D+/', '', $term) ?? '';
    }

    public function findByPickupTokenHash(string $hash): ?Order
    {
        return $this->query()
            ->where('pickup_token_hash', $hash)
            ->where('fulfillment_method', FulfillmentMethod::PICKUP)
            ->first();
    }

    public function orderNumberExists(string $orderNumber): bool
    {
        return $this->query()
            ->withTrashed()
            ->where('order_number', $orderNumber)
            ->exists();
    }
}
