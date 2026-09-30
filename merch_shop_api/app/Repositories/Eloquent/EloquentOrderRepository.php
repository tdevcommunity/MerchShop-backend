<?php

namespace App\Repositories\Eloquent;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
