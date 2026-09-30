<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;

/**
 * Acces aux comptes utilisateurs sur Eloquent.
 *
 * @implements UserRepositoryInterface
 */
final class EloquentUserRepository extends EloquentRepository implements UserRepositoryInterface
{
    protected function modelClass(): string
    {
        return User::class;
    }

    public function findByEmail(string $email): ?User
    {
        /*
         * Sans withTrashed() : un compte supprime logiquement ne doit pas
         * pouvoir se connecter. En revanche son email reste occupe par l'index
         * unique, ce que la regle `unique()->withTrashed()` du RegisterRequest
         * signale deja a l'inscription.
         */
        /** @var User|null $user */
        $user = $this->query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        return $user;
    }
}
