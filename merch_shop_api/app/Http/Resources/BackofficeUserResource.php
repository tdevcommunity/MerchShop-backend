<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Representation d'un compte dans le back-office.
 *
 * Distincte de `UserResource`, qui represente le compte connecte ou le
 * proprietaire d'une ressource. La difference n'est pas cosmetique : ici la
 * liste est celle des comptes du festival, donc l'adresse de chacun est une
 * donnee que le guichet a besoin de voir — c'est elle qu'on lit a voix haute
 * pour verifier qu'on parle a la bonne personne.
 *
 * Ce qui n'est pas expose, en revanche, est le hash du mot de passe. Il ne sert
 * a rien dans un back-office : un administrateur qui veut reinitialiser le mot
 * de passe d'un compte passe par une action ecrite dans le journal d'audit, et
 * non en relisant le hash pour le recomposer. Il figurerait ici comme une fuite
 * sans usage.
 *
 * Les permissions ne sont pas non plus renvoyees en toutes lettres. La matrice
 * est portee par le compte, et la republier ici obligerait le front a la
 * reimplementer, sous peine que les deux divergent a la premiere permission
 * ajoutee. `canOperate` repond a la seule question que le back-office pose en
 * boucle — cette personne peut-elle faire tourner le merch ? — et la matrice
 * reste la seule source.
 *
 * @mixin User
 */
final class BackofficeUserResource extends ApiResource
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
            'fullName' => $user->fullName(),

            'email' => $user->email,
            'phone' => $user->phone,

            'role' => $user->role->value,

            /*
             * L'activite est exposee telle qu'elle est stockee — un entier, comme
             * le statut de commande et le statut de paiement — plutot que
             * convertie en `active: true|false`.
             *
             * Un booleien perdrait le troisieme etat qu'un compte doit pouvoir
             * distinguer d'un compte qui n'a jamais existe : cree, present dans
             * la liste, mais pas encore autorise a se connecter. C'est un etat
             * normal pour un compte invite a rejoindre le stand, et c'est celui-la
             * qu'un booleen rendrait invisible.
             */
            'status' => $user->status->value,

            'canOperate' => $user->canManage('orders'),

            /*
             * Les permissions, sous les noms que les ecrans utilisent.
             *
             * La matrice vient du compte et n'est pas recopiee : la republier ici
             * obligerait le front a la reimplementer, et les deux divergeraient a la
             * premiere permission ajoutee. Les noms sont donc des reponses a des
             * questions posees par des ecrans — « peut-on modifier le
             * catalogue », « peut-on corriger le stock » — et non les cles
             * internes de `BACKOFFICE_PERMISSIONS`. Une cle qui disparaitrait
             * donnerait `false`, donc un bouton masque, ce qui est la faute
             * silencieuse a eviter.
             *
             * Chaque cle presente est une permission reellement consultee : une
             * liste ou tout vaut `true` ne distingue plus rien, et le
             * controleur d'acces doit rester le seul a poser la question.
             */
            'permissions' => [
                'manageCatalog' => $user->canManage('catalog'),
                'adjustStock' => $user->canManage('inventoryAdjust'),
                'changeOrderStatus' => $user->canManage('orderStatus'),
                'manageUsers' => $user->canManage('users'),
                'viewAudit' => $user->canManage('audit'),
            ],

            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}