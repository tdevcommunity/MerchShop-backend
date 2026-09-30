<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Origine d'une session de visite.
 *
 * Le nom de la table dit « visitor » alors que la cle est `session_id` : c'est
 * volontaire. La session est l'unite reellement observable cote client, et
 * l'ignorer pour la nommer « visiteur » reviendrait a pretendre qu'un meme
 * visiteur est connu, ce qu'aucun cookie de session ne permet.
 *
 * Seul endroit du schema ou une ligne est mise a jour apres creation
 * (`last_visit_at`), et la raison est expliquee dans la migration : la spec Data
 * (section 15) demande de conserver a la fois la premiere et la derniere visite,
 * ce qui est impossible avec une ligne par visite.
 *
 * @property string $session_id
 * @property \Illuminate\Support\Carbon $first_visit_at
 * @property \Illuminate\Support\Carbon $last_visit_at
 */
#[Fillable([
    'session_id',
    'source',
    'medium',
    'campaign',
    'content',
    'term',
    'referrer',
    'landing_page',
    'first_visit_at',
    'last_visit_at',
])]
class VisitorAcquisition extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_visit_at' => 'datetime',
            'last_visit_at' => 'datetime',
        ];
    }
}
