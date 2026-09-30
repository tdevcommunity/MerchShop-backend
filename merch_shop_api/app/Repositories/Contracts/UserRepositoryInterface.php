<?php

namespace App\Repositories\Contracts;

use App\Models\User;

/**
 * Acces aux comptes utilisateurs.
 *
 * Complete RepositoryInterface par la seule requete propre au domaine : la
 * recherche par email, qui est le point d'entree de l'authentification. Elle
 * n'est volontairement pas dans le contrat generique, car elle n'a de sens que
 * pour cette entite.
 *
 * @extends RepositoryInterface<User>
 */
interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * Retrouve un compte par son email, quel que soit son statut.
     *
     * La recherche est insensible a la casse : MySQL comme PostgreSQL
     * traitent differemment les collations, donc la comparaison se fait
     * explicitement plutot que de dependre du SGBD. Le filtre porte sur l'email
     * seul, le controle du statut (actif, desactive) relevant du service
     * d'authentification, qui doit pouvoir distinguer « compte inconnu » de
     * « compte desactive ».
     */
    public function findByEmail(string $email): ?User;
}
