<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Le controle d'acces a l'action, une fois la porte du back-office franchie.
 *
 * Le middleware `backoffice` repond a une seule question — « ce compte entre-t-il
 * dans le back-office ? » — et il la pose avant toute autre. C'est la bonne
 * place pour elle, parce qu'elle ne depend pas de la route : elle est vraie
 * pour toutes, y compris pour celle qu'on ajoutera dans six mois sans y penser.
 *
 * Elle ne suffit pas, et l'echec de l'oublier est silencieux. Le back-office a
 * deux populations — le guichetier qui vend au stand et l'administrateur qui
 * configure — et une fois la porte franchie, la question n'est plus « entre
 * -t-il » mais « a-t-il le droit de faire cela ». Un guichetier qui lit le
 * journal d'audit voit qui a reinitialise quel mot de passe ; un guichetier qui
 * invite un compte peut se donner un role `admin` et revenir avec. Ni l'un ni
 * l'autre n'a rien a faire au comptoir, et aucun des deux ne casserait quoi que
 * ce soit de visible : la reponse serait un 200 comme un autre.
 *
 * Cette separation est aussi la seule qui rende la matrice lisible. Le
 * middleware porte un fait — etre ou ne pas etre du back-office —, et la matrice
 * `User::BACKOFFICE_PERMISSIONS` porte des capacites. Les confondre donnerait un
 * role `staff` qui ne peut ni voir le journal ni inviter, sans qu'aucun endroit
 * ne dise pourquoi.
 *
 * Le controle est ici, dans les controleurs, et non dans le middleware, parce
 * qu'un alias de middleware ne voit pas le corps de la requete : il ne sait pas
 * quelle action on lui demande. Meme principe que pour le refus des etats
 * d'argent, qui ne peut pas non plus etre decide par une couche qui ignore
 * l'action.
 *
 * @see \App\Http\Middleware\EnsureBackoffice pour la question d'entree.
 * @see \App\Models\User::BACKOFFICE_PERMISSIONS pour la matrice.
 */
trait GuardsBackofficeAction
{
    /**
     * Le compte qui appelle, pour un usage interne.
     *
     * Non nullable pour la meme raison que dans les controleurs eux-memes : le
     * middleware a deja refuse toute requete sans session, donc une valeur nulle
     * ici signifierait que le middleware a change sans que les controleurs ne le
     * voient. L'echec franc vaut mieux qu'une trace sans auteur.
     */
    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Refuse l'appel si le role ne porte pas la permission demandee.
     *
     * Le message nomme la permission plutot que de dire « acces refuse », parce
     * que « acces refuse » se lit comme une erreur de session — ce que
     * l'administrateur corrigerait en reconnectant, puis en decouvrant que le
     * resultat est le meme. « Votre role ne permet pas de gerer les comptes »
     * se lit comme ce qu'il est : une limite de votre role, et non de votre
     * session.
     *
     * Le 403 et non le 401 : la session est valide, c'est l'action qui ne l'est
     * pas. La distinction est la meme que dans le middleware, et elle compte
     * parce qu'un 401 ferait boucler un client qui se reconnecte en boucle.
     *
     * @throws ApiException
     */
    private function assertCanManage(Request $request, string $permission, string $readable): void
    {
        if ($this->actor($request)->canManage($permission)) {
            return;
        }

        throw new ApiException(
            "Votre rôle ne permet pas de {$readable}.",
            403,
            'BACKOFFICE_FORBIDDEN',
            ['required' => $permission],
        );
    }
}
