<?php

namespace App\Models;

use App\Models\Concerns\HasPublicIdentifier;
use App\Support\Api\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une trace d'action du back-office.
 *
 * La ligne ne dit pas ce que la ressource est devenue — elle ne le peut pas, la
 * ressource peut avoir ete supprimee depuis — elle dit qui a tente quoi, et avec
 * quelle valeur avant et apres. C'est ce qui permet de repondre a « qui a baisse
 * ce prix ? » apres coup, et la seule facon de repondre a « ce prix etait-il a
 * 5000 la semaine derniere ? ».
 *
 * Append-only, comme `InventoryAdjustment` : une trace ne se corrige pas, elle se
 * complete par son contraire.
 *
 * @property-read mixed $old_value
 * @property-read mixed $new_value
 */
#[Fillable([
    'user_id',
    'user_email',
    'action',
    'resource',
    'resource_id',
    'old_value',
    'new_value',
])]
#[RouteKey('uuid')]
class AuditLog extends Model
{
    use HasPublicIdentifier;

    /**
     * Les valeurs sont decodees a la lecture.
     *
     * Le cast est fait ici plutot que dans la ressource parce qu'il vaut pour
     * tout lecteur : un journal affiche dans un back-office et un journal lu dans
     * un script de reconciliation doivent voir la meme structure, et la decode
     * ne doit pas dependre de l'appelant.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    /**
     * Le membre du guichet qui a agi.
     *
     * Nullable par construction : la trace survit a la suppression du compte,
     * et l'adresse recopiee sur la ligne est alors la seule identite disponible.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ecrit une trace.
     *
     * Point d'ecriture unique, appele par `AuditLogger` : la ligne a ecrire est
     * decrite par ce qui change — l'action, la ressource, les valeurs avant et
     * apres — et cette classe sait seule de quoi une trace est faite, notamment
     * que le nom de la ressource est sa classe et non le nom de la table, et que
     * l'identifiant est l'uuid plutot que la cle primaire interne.
     *
     * Les valeurs avant et apres passent par le cast `array` du modele : un
     * `null` y reste `null` et ne devient pas `[]`, ce qui est ce qui permet au
     * lecteur de distinguer une creation (pas d'avant) d'une valeur qui etait
     * nulle.
     */
    public static function write(
        AuditAction|string $action,
        Model|string $resource,
        User $actor,
        mixed $old = null,
        mixed $new = null,
        ?string $resourceId = null,
    ): self {
        return static::query()->create([
            'user_id' => $actor->id,
            'user_email' => $actor->email,
            'action' => $action instanceof AuditAction ? $action->value : $action,
            'resource' => $resource instanceof Model ? class_basename($resource) : $resource,
            'resource_id' => $resourceId
                ?? ($resource instanceof Model ? $resource->getRouteKey() : null),
            'old_value' => $old,
            'new_value' => $new,
        ]);
    }
}