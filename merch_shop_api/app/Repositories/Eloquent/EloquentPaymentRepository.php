<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Acces Eloquent aux paiements.
 *
 * @extends EloquentRepository<Payment>
 */
final class EloquentPaymentRepository extends EloquentRepository implements PaymentRepositoryInterface
{
    /**
     * @return class-string<Payment>
     */
    protected function modelClass(): string
    {
        return Payment::class;
    }

    public function findWithOrder(int|string $id): ?Payment
    {
        return $this->query()
            ->with('order')
            ->where($this->routeKeyName(), $id)
            ->first();
    }

    public function findByTransactionId(string $transactionId): ?Payment
    {
        return $this->query()->where('transaction_id', $transactionId)->first();
    }

    public function forOrder(Order $order): Collection
    {
        return $this->query()
            ->where('order_id', $order->id)
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    public function attachTransactionId(Payment $payment, string $transactionId): Payment
    {
        /*
         * L'unicite de `transaction_id` est portee par un index unique en base.
         * Deux webhooks concurrents portant la meme transaction peuvent donc
         * tous deux passer le controle applicatif et n'aboutir qu'a une seule
         * ecriture : le second se heurte a la contrainte. On la rattrape alors
         * pour renvoyer l'erreur metier attendue plutot qu'une 500, le cas
         * ordinaire etant l'operateur qui rejoue un webhook sur une transaction
         * deja rattachee a une autre commande.
         */
        try {
            $payment->transaction_id = $transactionId;
            $payment->save();
        } catch (QueryException $exception) {
            throw new ApiException(
                'Cette transaction est deja rattachee a un autre paiement.',
                409,
                'TRANSACTION_ALREADY_ATTACHED',
                ['transaction_id' => $transactionId],
                $exception,
            );
        }

        return $payment;
    }

    public function settledAmount(Order $order): string
    {
        $settled = $this->query()
            ->where('order_id', $order->id)
            ->whereNotNull('paid_at')
            ->sum('amount');

        return number_format((float) $settled, 2, '.', '');
    }
}
