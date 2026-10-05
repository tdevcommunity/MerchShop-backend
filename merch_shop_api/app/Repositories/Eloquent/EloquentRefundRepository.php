<?php

namespace App\Repositories\Eloquent;

use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Repositories\Contracts\RefundRepositoryInterface;

/**
 * Acces Eloquent aux demandes de restitution.
 *
 * @extends EloquentRepository<Refund>
 */
final class EloquentRefundRepository extends EloquentRepository implements RefundRepositoryInterface
{
    /**
     * @return class-string<Refund>
     */
    protected function modelClass(): string
    {
        return Refund::class;
    }

    public function findWithRelations(int|string $id): ?Refund
    {
        return $this->query()
            ->with(['order', 'payment'])
            ->where($this->routeKeyName(), $id)
            ->first();
    }

    public function openForOrder(Order $order): ?Refund
    {
        return $this->query()
            ->where('order_id', $order->id)
            ->where('status', RefundStatus::PENDING)
            ->latest('id')
            ->first();
    }

    public function findByPayoutReference(string $reference): ?Refund
    {
        return $this->query()
            ->with(['order', 'payment'])
            ->where('payout_reference', $reference)
            ->first();
    }
}
