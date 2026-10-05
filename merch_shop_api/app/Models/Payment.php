<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_id',
    'amount',
    'method',
    'provider',
    'transaction_id',
    'checkout_url',
    'status',
    'paid_at',
    'failed_at',
    'failure_reason',
])]
#[RouteKey('uuid')]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasPublicIdentifier, SoftDeletes;

    /**
     * Commande reglee par ce paiement.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Restreint la requete aux paiements confirmes par l'operateur.
     *
     * @param  Builder<Payment>  $query
     */
    #[Scope]
    protected function successful(Builder $query): void
    {
        $query->where('status', PaymentStatus::SUCCESS);
    }

    /**
     * La transaction a-t-elle abouti ?
     *
     * Se base sur `paid_at` plutot que sur le statut : la date de reglement
     * est la seule preuve bancaire, un statut seul pouvant avoir ete ecrit a la
     * reception d'un webhook sans confirmation reelle.
     */
    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'provider' => PaymentProvider::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
