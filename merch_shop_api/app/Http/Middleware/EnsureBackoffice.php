<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'appelant entre-t-il dans le back-office ?
 *
 * Ce middleware pose une seule question, et elle est posee une fois pour toutes
 * sur toutes les routes d'administration : la session existe-t-elle, le compte
 * est-il actif, et son role donne-t-il accès au merch. Les permissions
 * individuelles restent ensuite decidées par les controleurs et les policies,
 * qui savent de quoi ils Shield une action donnée — un middleware ne peut pas
 * le savoir, puisqu'il ne voit pas la route qu'il protege.
 *
 * Il rend toutefois une decision qu'aucun policy ne peut rendre de la meme
 * facon : ce que repond un compte qui n'a pas le role. Un client connecte qui
 * tombe sur `/api/v1/admin/orders` ne doit pas recevoir un 403 que le front
 * traduit en « connexion », et un compte desactive ne doit pas non plus etre
 * traite comme un client ordinaire. Les deux sont renvoyes vers la connexion
 * avec un code distinct, ce qui permet au front de dire « votre compte n'a pas
 * acces » plutot que « mot de passe incorrect ».
 *
 * La verification du statut et du role est donc ici plutot que dans
 * `User::isBackoffice()` seul, et ce n'est pas une redondance : la methode
 * exprime la question, le middleware garantit qu'elle est posee.
 *
 * Le role est lu sur le compte en base a chaque requete, jamais sur la session.
 * Une session contient un identifiant, pas une autorisation : un compte retrograde
 * de `staff` a `customer` le doit etre immediatement, alors que sa session est
 * encore valable pour douze heures.
 */
final class EnsureBackoffice
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            /*
             * 401 et non 403 : il n'y a pas de session, donc rien a autorise.
             * Confondre les deux ferait afficher « acces refuse » a un guichetier
             * dont la session a expire, qui retryait sans se reconnecter.
             */
            return $this->deny($request, 401, 'Un guichet est connecte pour ouvrir cette page.');
        }

        if (! $user->isBackoffice()) {
            /*
             * 403 avec un code qui distingue les deux causes. Un compte desactive
             * se reconnectera avec le meme resultat, donc le dire evite une
             * boucle de tentatives de connexion ; un role trop bas, en revanche,
             * doit etre signale comme une erreur de droits, parce que le compte
             * est parfaitement valide et qu'il faut aller demander un acces.
             */
            return $user->status->isActive()
                ? $this->deny($request, 403, 'Votre rôle ne donne pas accès au back-office.')
                : $this->deny($request, 403, 'Ce compte est désactivé.');
        }

        return $next($request);
    }

    /**
     * Refus, dans le format d'erreur de l'API.
     *
     * La reponse est construite ici plutot que laissee a une redirection,
     * parce que ces routes sont consommees par un front qui lit du JSON : une
     * page de connexion HTML renvoyee a un `fetch` se lirait comme une coupure
     * reseau, et le guichetier ne verrait jamais la raison du refus.
     */
    private function deny(Request $request, int $status, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $status === 401 ? 'UNAUTHENTICATED' : 'BACKOFFICE_FORBIDDEN',
                'message' => $message,
            ],
        ], $status);
    }
}