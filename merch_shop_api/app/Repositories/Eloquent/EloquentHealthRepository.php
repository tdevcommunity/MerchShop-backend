<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\HealthRepositoryInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Sonde de disponibilite de la base de donnees.
 *
 * Elle vit dans la couche infrastructure : la version Eloquent du contrat
 * separe des repositories metier. Aucune exception n'est attrapee ici, afin
 * que la cause reelle (hote injoignable, identifiants invalides, timeout) reste
 * exploitable dans les logs du service appelant.
 */
final class EloquentHealthRepository implements HealthRepositoryInterface
{
    public function __construct(private readonly ConnectionResolverInterface $connections) {}

    public function pingDatabase(): void
    {
        $this->connections->connection()->select('select 1');
    }
}
