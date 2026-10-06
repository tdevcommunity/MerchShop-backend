<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Api\AuditAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Ecriture du journal d'audit.
 *
 * Ce service existe pour que l'ecriture soit aussi inevitable que l'action.
 * Un journal ecrit « quand on y pense » est un journal dont l'absence ne se voit
 * que le jour ou il sert, donc un service qui ne fait qu'appeler `AuditLog::create`
 * ne servirait a rien.
 *
 * Il porte donc deux garanties :
 *
 *  - il n'ecrit jamais d'action de la ou le nom de la ressource n'est pas connu.
 *    Une trace sans ressource n'a pas de quoi etre retrouvee, et une trace qui
 *    ne dit pas sur quoi porte est un bruit qu'il faudra un jour trier ;
 *
 *  - il ne leve pas. Une action qui reussit et dont la trace echoue doit rester
 *    une action reussie : le stock a ete corrige, et le faire echouer
 *   Transactionalement ferait croire que le stock n'a pas bouge, donc que
 *    l'operateur peut recommencer. Le journal est un temoin, pas une condition
 *    de l'ecriture — mais son absence est donc signalee au journal technique,
 *    ou elle se voit.
 *
 * L'ecriture elle-meme est dans la transaction de l'appelant quand il y en a une,
 * et la ou il n'y en a pas — une invitation d'utilisateur, par exemple — la trace
 * survit seule, ce qui est preferable a pas de trace du tout.
 */
final class AuditLogger
{
    /**
     * Trace une action sur une ressource.
     *
     * @param  Model|string  $resource  Le modele concerne, ou son nom de classe
     * @param  array<string, mixed>|scalar|null  $old  Valeur avant, si elle existe
     * @param  array<string, mixed>|scalar|null  $new  Valeur apres, si elle existe
     */
    public function record(
        AuditAction|string $action,
        Model|string $resource,
        User $actor,
        mixed $old = null,
        mixed $new = null,
        ?string $resourceId = null,
    ): void {
        try {
            AuditLog::write($action, $resource, $actor, $old, $new, $resourceId);
        } catch (\Throwable $exception) {
            /*
             * Signale et laisse passer : l'action, elle, a ete faite. Cacher
             * l'echec ici donnerait un back-office qui semble ne jamais
             * journaliser, ce qui est le pire des deux mondes — on perdrait la
             * trace en croyant qu'elle n'existe pas.
             */
            report($exception);
        }
    }

    /**
     * Trace une action sans donnees avant/apres.
     *
     * Pour une creation : il n'y a pas de « avant ». Le cas est distinct plutot
     * que passe avec `null` dans les deux sens, parce que `null` des deux cotes
     * veut dire « la valeur etait nulle et elle l'est toujours », ce qui est
     * faux pour une creation.
     */
    public function created(AuditAction|string $action, Model|string $resource, User $actor, ?string $resourceId = null): void
    {
        $this->record($action, $resource, $actor, null, null, $resourceId);
    }

    /**
     * Trace la suppression d'une ressource.
     *
     * La valeur d'avant est passee par l'appelant, qui l'a lue avant de
     * supprimer : la ligne disparue ne peut plus la dire. C'est la seule
     * information qu'on ne peut pas reconstituer apres coup, et c'est
     * precisement celle qu'on demande au journal.
     */
    public function deleted(AuditAction|string $action, Model|string $resource, User $actor, mixed $old = null, ?string $resourceId = null): void
    {
        $this->record($action, $resource, $actor, $old, null, $resourceId);
    }
}