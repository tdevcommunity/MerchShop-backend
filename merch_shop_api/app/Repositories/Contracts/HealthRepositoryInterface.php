<?php

namespace App\Repositories\Contracts;

/**
 * Verifie que les dependances critiques de l'API repondent.
 *
 * Implemente le controle de readiness : la sonde distingue « le processus
 * tourne » de « l'API peut reellement servir du trafic ». Elle ne renvoie
 * aucune donnee metier.
 */
interface HealthRepositoryInterface
{
    /**
     * Lance une requete triviale sur la base de donnees.
     *
     * L'echec n'est pas masque : l'exception remonte, l'appelant (le service)
     * la journalise et decide du statut a exposer.
     *
     * @throws \Throwable si la base de donnees est injoignable
     */
    public function pingDatabase(): void;
}
