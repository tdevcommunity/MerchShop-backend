<?php

namespace App\Support\Orders;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Accès aux commandes passees sans compte.
 *
 * Le passage de commande est public, donc un visiteur peut commander sans avoir
 * de compte. Mais les routes de lecture passent par une session : sans dispositif
 * complementaire, ce visiteur ne pourrait jamais relire sa commande, ni retrieve
 * son QR de retrait une fois son paiement confirme. La commande serait orpheline
 * de son proprietaire.
 *
 * Ce jeton corrige cela sans affaiblir les autres routes. Il est emis une seule
 * fois, a la creation, et ne donne acces qu'a la commande qui le porte : c'est
 * une cle porteuse, pas un role. Sa legitimite ne depend donc pas de la policy,
 * qui reste le seul juge des droits attaches a un compte, et du personnel.
 *
 * Deux precautions qui ne sont pas optionnelles :
 *
 * - Seule l'empreinte est stockee, comme pour le QR. Le jeton n'est renvoye que
 *   dans la reponse de creation : il n'est ni devinable depuis l'uuid de la
 *   commande, ni rejouable apres coup, ni recuperable par la suite.
 * - Il n'ouvre rien sur une commande rattachee a un compte. Si un invite cree un
 *   compte puis rattache sa commande, la session remplace le jeton, qui cesse
 *   immediatement de fonctionner : un acces deja accorde ne doit pas survivre au
 *   changement de proprietaire.
 */
final class OrderAccess
{
    /**
     * En-tete portant le jeton lors des lectures.
     */
    public const HEADER = 'X-Order-Token';

    /**
     * Taille du jeton en octets, avant encodage.
     *
     * 32 octets font 64 caracteres hexadecimaux, ce qui laisse environ 190 bits
     * d'entropie : chercher la commande par force brute n'est pas envisageable,
     * meme a l'echelle d'un festival entier.
     */
    private const TOKEN_BYTES = 32;

    /**
     * Emet un jeton d'acces pour une commande invitee.
     *
     * Renvoie null pour une commande rattachee a un compte : le client s'authentifie
     * alors par session, et un second moyen d'y acceder n'apporterait rien, tout
     * en creant une identite de plus a divulguer.
     */
    public function issueFor(Order $order): ?string
    {
        if ($order->user_id !== null) {
            return null;
        }

        $token = Str::random(self::TOKEN_BYTES * 2);

        $order->forceFill(['guest_access_token_hash' => $this->hash($token)])->save();

        return $token;
    }

    /**
     * Ce jeton ouvre-t-il l'acces a cette commande ?
     *
     * La commande est cherchee par l'uuid de l'URL, jamais par le jeton : c'est
     * l'empreinte de cette commande precis qui est comparee. Un jeton valide ne
     * donne donc pas acces a la commande voisine.
     */
    public function grantsAccessTo(Order $order, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        // Une commande appartenant a un compte s'ouvre par sa session, jamais par
        // le jeton : un jeton valide ne doit pas survivre au changement de
        // proprietaire.
        if ($order->user_id !== null) {
            return false;
        }

        $stored = $order->guest_access_token_hash;

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $this->hash($token));
    }

    /**
     * Le demandeur de cette requete peut-il lire cette commande ?
     *
     * Reunit les deux voies d'acces : le jeton porte par la requete pour un
     * invite, et la policy pour un compte connecte ou le personnel. La regle
     * propre a chaque voie reste ecrite a un seul endroit.
     */
    public function canRead(Request $request, Order $order): bool
    {
        return $this->grantsAccessTo($order, $request->header(self::HEADER))
            || Gate::allows('view', $order);
    }

    /**
     * Empreinte du jeton, forme unique de le stocker.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
