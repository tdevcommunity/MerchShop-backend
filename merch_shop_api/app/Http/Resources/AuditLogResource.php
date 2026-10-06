<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Une trace d'action du back-office.
 *
 * Les valeurs avant et apres sont renvoyees telles quelles, decodees. C'est ce
 * qui distingue cette trace d'une ligne de journal de stock : la-dedans la
 * cause est une difference de nombre, ici c'est une difference de valeur, et
 * elle n'a pas d'autre representation. Les tronquer pour les faire tenir dans
 * une colonne — ou les mettre dans une seule, en ne gardant que la plus recente —
 * ferait perdre exactement ce que la ligne existe pour conserver.
 *
 * Elles sont donc presentes meme quand une seule des deux existe : une creation
 * n'a pas d'avant, une suppression n'a pas d'apres, et `null` se distingue d'un
 * objet vide. C'est ce qui permet de lire une trace sans deja savoir ce que
 * l'action fait.
 *
 * La colonne `resource` est le nom du modele et non le nom de la table, parce
 * que c'est le vocabulaire du metier que le lecteur cherche — « Product » et non
 * « products ». `resourceId` est son identifiant public, donc un uuid, quel que
 * soit l'objet : c'est ce qui permet de renvoyer vers la fiche lorsque l'objet
 * existe encore.
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $log */
        $log = $this->resource;

        return [
            'uuid' => $log->uuid,

            'action' => $log->action,
            'resource' => $log->resource,
            'resourceId' => $log->resource_id,

            /*
             * Avant et apres, ou leur absence explicite.
             *
             * `null` et non une chaine vide : le lecteur doit pouvoir distinguer
             * « la valeur etait nulle » de « la valeur etait la chaine vide », et
             * le cast `array` du modele preserves deja cette difference. La
             * convertir en `{}` ici l'effacerait.
             */
            'oldValue' => $log->old_value,
            'newValue' => $log->new_value,

            /*
             * L'auteur, recopie sur la ligne.
             *
             * L'adresse sert meme apres suppression du compte, ce qui est
             * precisement le moment ou l'on cherche qui avait fait quoi. L'uuid
             * n'est present que si le compte existe encore, et son absence se lit
             * donc sans ambiguite.
             */
            'userId' => $log->user_id !== null ? $log->user?->uuid : null,
            'userEmail' => $log->user_email,
            'userName' => $log->user?->fullName(),

            'createdAt' => $log->created_at?->toIso8601String(),
        ];
    }
}