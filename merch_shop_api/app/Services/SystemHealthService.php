<?php

namespace App\Services;

use App\Repositories\Contracts\HealthRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cas d'usage « l'API est-elle prete a servir du trafic ? ».
 *
 * Le service porte la decision : il sait qu'une dependance en panne doit
 * produire un statut degrade et un 503, et il journalise la cause. La
 * presentation ne fait que traduire ce resultat en reponse HTTP.
 */
final class SystemHealthService
{
    public function __construct(private readonly HealthRepositoryInterface $health) {}

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function readiness(): array
    {
        $isDatabaseReachable = $this->databaseIsReachable();

        return [
            'status' => $isDatabaseReachable ? 'ok' : 'degraded',
            'checks' => ['database' => $isDatabaseReachable ? 'up' : 'down'],
        ];
    }

    private function databaseIsReachable(): bool
    {
        try {
            $this->health->pingDatabase();
        } catch (Throwable $exception) {
            // La cause technique (hote, identifiants, timeout) part dans les
            // logs serveur ; le client ne reçoit que le statut "down".
            Log::warning('Readiness check failed: database unreachable.', [
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }
}
