<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicIdentifier;
use Database\Factories\UserFactory;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['firstname', 'lastname', 'phone', 'email', 'password', 'status', 'role'])]
#[Hidden(['password', 'remember_token'])]
#[RouteKey('uuid')]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPublicIdentifier, Notifiable, SoftDeletes;

    /**
     * Commandes passees par l'utilisateur.
     *
     * La cle etrangere est nullable : la relation laisse donc un trou pour les
     * commandes invitees, ce qui est le comportement attendu.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Restreint la requete aux comptes actifs.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', UserStatus::ACTIVE);
    }

    /**
     * Restreint la requete au personnel d'exploitation du merch.
     *
     * Utilise par le back-office pour le guichet : servir une commande ou
     * valider un scan exige ce role, un compte client ne suffit pas.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function staff(Builder $query): void
    {
        $query->whereIn('role', [UserRole::STAFF->value, UserRole::ADMIN->value]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'role' => UserRole::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
