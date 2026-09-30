<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evenement d'analyse, en ajout seul.
 *
 * Modele volontairement minimal : il ne porte ni relation ni regle metier. La
 * table est un reservoir de donnees brutes destinees aux tableaux de bord du
 * festival, et la seule chose que ce modele doit garantir est qu'une ligne
 * ecrite ne soit jamais reecrite ensuite (section 26 de la spec Data).
 *
 * Volontairement sans SoftDeletes, alors que toutes les autres entites du shop
 * en utilisent : une suppression logique ici laisserait la ligne invisible aux
 * requetes tout en conservant sa place en base, ce qui est le pire des deux
 * mondes pour un journal. La retention eventuelle se fait par une purge datee,
 * decidee par l'equipe data, et non a la discretion du code applicatif.
 *
 * L'identifiant s'appelle `event_id` et non `uuid`, contrairement aux autres
 * entites : cette table n'est pas un modele metier du shop mais le miroir du
 * vocabulaire de la spec Data, qui nomme cet identifiant `event_id`. L'ecrire
 * sous le nom du spec evite qu'un analyste translate un nom de colonne en
 * arrivant sur la base.
 *
 * @property string $event_id
 * @property string $event_name
 */
#[Fillable([
    'event_id',
    'event_name',
    'properties',
    'event_time',
    'received_at',
    'session_id',
    'participant_id',
    'ticket_id',
    'page',
    'product_id',
    'device_type',
    'browser',
    'os',
    'source',
    'campaign',
])]
class AnalyticsEvent extends Model
{
    /**
     * Restreint la requete a une plage de temps, en temps reel.
     *
     * Indexe sur (event_name, event_time), qui est la lecture principale : compter
     * un evenement sur une journee, puis comparer deux journees.
     *
     * @param  Builder<AnalyticsEvent>  $query
     */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('event_time', [$from, $to]);
    }

    /**
     * Restreint la requete au parcours d'un participant donne.
     *
     * `participant_id` est la charniere de la spec (section 3) : c'est par lui
     * qu'une vente se relie a une entree, un acces Free Food et un scan de
     * retrait, lui qui vient des autres systemes.
     *
     * @param  Builder<AnalyticsEvent>  $query
     */
    public function scopeForParticipant(Builder $query, string $participantId): void
    {
        $query->where('participant_id', $participantId);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Le JSON est decode en tableau : sans ce cast, la propriete serait
            // une chaine et il faudrait la re-decoder a chaque lecture analytique.
            'properties' => 'array',
            'event_time' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
