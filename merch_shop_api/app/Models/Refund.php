<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\RefundStatus;
use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une demande de restitution d'argent, et son suivi.
 *
 * Une commande qui n'a pas cette trace ne dit pas si l'acheteur a ete
 * rembourse : elle dit seulement que le statut a ete ecrit. Cette table est ce
 * qui rend la distinction consultable, aussi bien pour repondre a un client que
 * pour reessayer un depot que l'operateur a refuse.
 */
#[Fillable([
    'payment_id',
    'order_id',
    'provider',
    'payout_reference',
    'amount',
    'currency',
    'status',
    'failure_reason',
    'requested_at',
    'settled_at',
    'failed_at',
    'order_status_before',
])]
#[RouteKey('uuid')]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasPublicIdentifier, SoftDeletes;

    /**
     * Paiement dont la somme est restituee.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Commande concernee.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Restreint la requete aux demandes encore en attente de confirmation.
     *
     * @param  Builder<Refund>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', RefundStatus::PENDING);
    }

    /**
     * L'argent est-il sorti ?
     *
     * Se base sur la date de reglement, comme le paiement se base sur `paid_at` :
     * un statut pourrait avoir ete ecrit sur la seule foi d'un appel d'API, alors
     * que la date n'est posee que par la notification de l'operateur.
     */
    public function hasLeft(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Etat a rendre a la commande si le depot echoue.
     *
     * Relit la valeur capturee au moment de la demande. `PAID` sert de repli
     * pour les lignes les plus anciennes, ou une demande aurait ete enregistree
     * avant que cette colonne n'existe.
     */
    public function statusToRestore(): OrderStatus
    {
        return OrderStatus::tryFrom($this->order_status_before) ?? OrderStatus::PAID;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => RefundStatus::class,
            'amount' => 'integer',
            'requested_at' => 'datetime',
            'settled_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
