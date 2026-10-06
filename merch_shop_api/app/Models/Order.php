<?php

namespace App\Models;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PickupStatus;
use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_number',
    'customer_name',
    'customer_phone_number',
    'customer_phone_country',
    'fedapay_customer_id',
    'user_id',
    'sub_total',
    'shipping_address',
    'discount',
    'currency',
    'delivery_fee',
    'total',
    'status',
    'fulfillment_method',
    'pickup_status',
    'pickup_time',
    'picked_up_by_user_id',
    'pickup_token_hash',
    'guest_access_token_hash',
    'participant_id',
])]
#[RouteKey('uuid')]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasPublicIdentifier, SoftDeletes;

    /**
     * Client ayant passe la commande, null pour une commande invitee.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le guichetier qui a servi la commande.
     *
     * Distinct de `user`, qui est l'acheteur : les deux comptes ne se confondent
     * jamais, mais ils se ressemblent assez pour qu'une confusion soit tentante
     * au moment de relire le modele. Nommer la relation par son role evite.
     *
     * Nullable pour la raison ecrite dans la migration : la commande peut avoir
     * ete livree, ou pas encore servie.
     *
     * @return BelongsTo<User, $this>
     */
    public function pickupAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_up_by_user_id');
    }

    /**
     * La commande appartient-elle a ce compte ?
     *
     * Une commande invitee n'appartient a personne : elle n'est donc rendue a
     * aucun compte, pas meme a un administrateur par cette voie. Le guichet y
     * accede par ses propres routes, qui ont leur propre autorisation.
     *
     * Le typage accepte `null` pour que l'appel reste possible sur une
     * requete anonyme : sans cela, il faudrait tester le compte avant d'appeler,
     * et le forgot du cas anonyme deviendrait un 500.
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id !== null && $this->user_id === $user->id;
    }

    /**
     * Lignes de commande.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Produits commandes, via les lignes de commande.
     *
     * Expose la relation plusieurs-a-plusieurs que le diagramme de classes
     * materialise par la classe d'association OrderItem : la commande ne
     * reference pas directement ses produits, elle passe par ses lignes.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'order_items')
            ->withPivot(['quantity', 'unit_price', 'total_price']);
    }

    /**
     * Facture associee : cardinalite 1-1 garantie par la base.
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Tentatives de paiement : une commande peut en avoir plusieurs.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Restreint la requete aux commandes en attente de paiement.
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function awaitingPayment(Builder $query): void
    {
        $query->where('status', OrderStatus::PENDING_PAYMENT);
    }

    /**
     * Restreint la requete aux commandes de retrait, seules porteuses d'un QR.
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function forPickup(Builder $query): void
    {
        $query->where('fulfillment_method', FulfillmentMethod::PICKUP);
    }

    /**
     * La commande a-t-elle deja ete retiree au stand ?
     *
     * Le plan de tracking impose de distinguer le droit (commande payee en
     * attente de retrait) et l'usage (retrait effectif) : cette methode ne
     * repond qu'a l'usage, `isPaid` couvre le droit.
     */
    protected function isPickedUp(): Attribute
    {
        return Attribute::get(fn (): bool => $this->pickup_status === PickupStatus::PICKED_UP);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'fulfillment_method' => FulfillmentMethod::class,
            'pickup_status' => PickupStatus::class,
            'pickup_time' => 'datetime',
        ];
    }
}
