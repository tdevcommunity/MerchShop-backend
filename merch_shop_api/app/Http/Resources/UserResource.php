<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Representation publique d'un compte.
 *
 * Le mot de passe et le jeton de memorisation sont declares caches sur le
 * modele, mais la ressource ne s'y fie pas : elle n'enumere que les champs
 * qu'elle veut exposer. Une exclusionoubliee ici n'a alors aucun effet, la
 * defense ne depend pas du modele.
 *
 * L'adresse email d'un tiers n'est pas exposee par cette ressource, qui
 * represente toujours le compte connecte ou le proprietaire de la ressource.
 *
 * @mixin User
 */
final class UserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'uuid' => $user->uuid,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
