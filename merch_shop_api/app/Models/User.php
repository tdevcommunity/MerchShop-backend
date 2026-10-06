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
     * Ce que chaque role peut faire dans le back-office.
     *
     * La matrice vit ici, et non dans le front, parce qu'elle decide de ce que
     * l'API accepte. Le back-office s'en sert pour masquer un bouton, ce qui est
     * un confort : une regle reimplementee cote client serait une declaration
     * d'intention, pas une regle, et le front et l'API divergeraient a la
     * premiere permission ajoutee.
     *
     * Elle separe deux droits que l'on confond d'habitude : voir, et agir. Un
     * membre du stand (`staff`) lit et fait tourner la boutique, mais ne touche
     * ni au catalogue, ni aux comptes, ni au journal — ce sont des decisions
     * d'organisation, pas du travail de caisse.
     *
     * @var array<string, array<int, string>>
     */
    public const BACKOFFICE_PERMISSIONS = [
        // Catalogue : creer, modifier, publier un produit ou un rayon.
        'catalog' => [UserRole::ADMIN->value],

        // Lire l'etat du stock sans pouvoir le corriger.
        'inventory' => [UserRole::ADMIN->value, UserRole::STAFF->value],

        /*
         * Corriger le stock separe de le lire, parce que les deux ne se
         * remplacent pas : un guichetier doit voir ce qu'il reste, mais ce n'est
         * pas lui qui decide qu'un article est perdu.
         */
        'inventoryAdjust' => [UserRole::ADMIN->value, UserRole::STAFF->value],

        'orders' => [UserRole::ADMIN->value, UserRole::STAFF->value],

        /*
         * Changer l'etat d'une commande separe de la lire : lire est un constat,
         * changer une transition declare un encaissement ou une sortie de stock.
         * Une seule permission pour les deux ferait du role `staff` une cle
         * capable de s'accorder un paiement.
         */
        'orderStatus' => [UserRole::ADMIN->value, UserRole::STAFF->value],

        'payments' => [UserRole::ADMIN->value, UserRole::STAFF->value],
        'pickups' => [UserRole::ADMIN->value, UserRole::STAFF->value],

        /*
         * Configuration, comptes et journal sont reserves a l'administrateur.
         *
         * Un journal d'audit dont on peut supprimer les lignes ne vaut pas son
         * nom, et un role `staff` qui peut reinitialiser le mot de passe de
         * l'administrateur n'est plus un role `staff`.
         */
        'settings' => [UserRole::ADMIN->value],
        'users' => [UserRole::ADMIN->value],
        'audit' => [UserRole::ADMIN->value],
    ];

    /**
     * Le role autorise-t-il cette permission de back-office ?
     *
     * Le role seul : l'activite est une autre question, posee une fois par le
     * middleware d'administration. Une regle qui melange les deux devrait etre
     * reecrite a chaque appel, et il en resterait toujours un qui oublie.
     *
     * La methode ne s'appelle pas `can` : `Illuminate\Foundation\Auth\User`
     * expose deja `can($abilities)` pour les abilities de Gate, et une
     * surcharge de meme nom avec une signature differente est une erreur
     * fatale au chargement de la classe — donc de toute la reponse, pas seulement
     * de la route qui l'aurait appelee. Le nom dit ce que la methode teste, ce
     * que `can` ne fait plus : une permission de back-office, et non une
     * ability.
     *
     * @param  string  $permission  cle de `BACKOFFICE_PERMISSIONS`
     */
    public function canManage(string $permission): bool
    {
        return in_array($this->role->value, self::BACKOFFICE_PERMISSIONS[$permission] ?? [], true);
    }

    /**
     * Le compte entre-t-il dans le back-office ?
     *
     * Role et activite sont cumules ici parce que c'est la seule question posee a
     * l'entree, avant toute permission : un compte desactive ne doit pas
     * pouvoir se connecter, meme s'il est administrateur, et c'est cette
     * fermeture qui evite de desactiver cinquante comptes un par un.
     */
    public function isBackoffice(): bool
    {
        return $this->status->isActive() && $this->role->canOperateMerch();
    }

    /**
     * Le nom affiche au guichet.
     *
     * Les deux champs sont donnes separement parce qu'ils le sont partout :
     * une commande les enregistre sous cette forme, et les recomposer en un seul
     * nom les presenterait dans l'ordre de la saisie, qui n'est pas forcement
     * celui-la.
     */
    public function fullName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    /**
     * Ajustements de stock saisis par ce membre.
     *
     * @return HasMany<InventoryAdjustment, $this>
     */
    public function inventoryAdjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    /**
     * Traces d'action portees par ce membre.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
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
