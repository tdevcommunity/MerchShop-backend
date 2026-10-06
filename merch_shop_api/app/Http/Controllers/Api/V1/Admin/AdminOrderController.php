<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\V1\Admin\Concerns\GuardsBackofficeAction;
use App\Http\Resources\OrderResource;
use App\Services\AuditLogger;
use App\Services\OrderService;
use App\Support\Api\AuditAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Commandes, vues par le back-office.
 *
 * Ce controleur ne remplace pas `OrderController` : il ne fait que repondre a
 * une question que celui-la ne peut pas poser. Un client liste ses propres
 * commandes, et un guichetier liste celles d'un stand ; aucun des deux ne peut
 * repondre a « ou est la commande MS-0402 ? » pour une commande passee sans
 * compte, qui n'a donc ni proprietaire ni file.
 *
 * La lecture renvoie `OrderResource` et non une ressource d'administration
 * Distincte. C'est deliberé : le guichet voit un sous-ensemble de ce que voit le
 * proprietaire de la commande — jamais davantage — donc il n'y a rien a retirer
 * et deux representations du meme objet divergeraient des que l'une des deux
 * evoluerait. Les champs que le proprietaire n'a pas le droit de voir, la
 * ressource ne les rend pas non plus.
 *
 * Une seule exception : `allowedActions` expose les transitions possibles, que
 * la ressource ne rend qu'a ceux qui peuvent agir sur la commande. C'est ce qui
 * permet au back-office de n'afficher que les boutons acceptables, sans
 * reimplementer la table des transitions — et donc de ne pas proposer une
 * action qui serait refusee.
 */
final class AdminOrderController extends ApiController
{
    use GuardsBackofficeAction;

    public function __construct(
        private readonly OrderService $orders,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Toutes les commandes, tous clients confondus.
     *
     * Les filtres sont tous facultatifs et se combinent : `?status=2&q=MS-`
     * demande les commandes payees dont le numero contient « MS- », ce qui est la
     * recherche qu'on fait quand un client dit « j'ai paye MS-0417 » au stand.
     *
     * La recherche est filtree par le service et non ici : c'est lui qui sait
     * qu'une saisie de deux lettres ne selectionne rien, et une liste entiere en
     * desordre est plus difficile a lire qu'un message.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertCanManage($request, 'orders', 'consulter les commandes');

        $paginator = $this->orders->listForBackoffice($this->perPage($request), [
            'status' => $request->query('status'),
            'paymentStatus' => $request->query('payment_status'),
            'fulfillment' => $request->query('fulfillment'),
            'q' => $request->query('q'),
        ]);

        return OrderResource::collection($paginator);
    }

    /**
     * Une commande, pour la fiche du back-office.
     *
     * Le jeton d'acces d'une commande invitee n'est pas exige ici, contrairement
     * aux routes du client : le guichet a deja passe le middleware
     * d'administration, et exiger un second jeton pour lire une commande que le
     * guichet est cense servir rendrait le retrait impossible depuis le
     * back-office.
     *
     * Les transitions possibles sont ajoutees, pour que la fiche n'offre que les
     * actions que le service acceptera.
     */
    public function show(Request $request, string $uuid): OrderResource
    {
        $this->assertCanManage($request, 'orders', 'consulter les commandes');

        $order = $this->orders->findForBackoffice($uuid);

        return OrderResource::make($order);
    }

    /**
     * Changer l'etat d'une commande depuis le guichet.
     *
     * Le statut demande passe par `OrderService`, qui est le seul a connaitre les
     * transitions autorisees et a savoir ce qu'elles impliquent — rendre le stock
     * lors d'une annulation, inscrire la commande comme payee lors d'un encaissement
     * au comptoir. Un controleur qui ecrivait le statut directement pourrait
     * produire une commande « annulee » dont le stock reste bloque.
     *
     * Ce qui est refuse ici n'est donc pas « une transition interdite » : c'est
     * deja la reponse de `OrderService`, avec son message et son code. Ce
     * controleur ne rajoute que la lecture du statut demande et le controle que
     * l'appelant a bien le droit de changer l'etat d'une commande.
     */
    public function updateStatus(Request $request, string $uuid): OrderResource
    {
        $order = $this->orders->findForBackoffice($uuid);

        /*
         * `orderStatus` et non `orders` : lire une commande est un constat,
         * changer son etat est une affirmation. Les deux dans la meme permission
         * feraient du role `staff` une clef capable de s'accorder un paiement —
         * ce que la separation des trois etats d'argent, plus bas, rend sinon
         * inatteignable.
         */
        $this->assertCanManage($request, 'orderStatus', "changer l'état d'une commande");

        $actor = $this->actor($request);

        /*
         * La valeur est lue comme un entier de la liste des statuts connus, et
         * non « un entier » : sans `in`, une valeur hors nomenclature produirait
         * un 500 sur `OrderStatus::from()`, la ou une 422 se lirait dans la
         * reponse du controleur. La liste des valeurs acceptables est rendue
         * avec l'erreur, ce qui evite au front d'avoir a la connaitre.
         */
        $validated = $request->validate([
            'status' => ['required', 'integer', 'in:'.implode(',', array_column(OrderStatus::cases(), 'value'))],
        ], [
            'status.in' => 'Cet état de commande n\'existe pas.',
        ]);

        $target = OrderStatus::from((int) $validated['status']);

        /*
         * L'etat d'avant est lu avant la transition, et non deduit apres : c'est
         * lui que la trace doit contenir. Une transition qui modifie aussi la
         * commande — en remettant le stock, par exemple — rendrait l'apres trop
         * tard pour dire d'ou l'on venait.
         */
        $from = $order->status;

        /*
         * `advanceTo` et non `transition` : c'est lui qui refuse les etats
         * d'argent, avec la raison. Passer par l'ecriture directe du statut
         * donnerait au guichet le moyen de marquer une commande « payee » sans
         * que personne n'ait paye.
         */
        $updated = $this->orders->advanceTo($order, $target, $actor);

        $this->audit->record(
            AuditAction::ORDER_STATUS_CHANGED,
            $order,
            $actor,
            ['status' => $from->value],
            ['status' => $target->value],
        );

        return OrderResource::make($updated);
    }

    /**
     * Les transitions qu'un guichetier peut demander.
     *
     * Expose pour que le back-office construise son menu a partir de la meme
     * liste que celle que le service acceptera. Le menu est donc construit par
     * la regle, et non par un tableau recopie dans le front : c'est la seule
     * facon qu'un bouton ne puisse pas promettre une action qui sera refusee.
     */
    public function transitions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'counter' => OrderService::counterTransitions(),
                'all' => array_column(OrderStatus::cases(), 'value'),
            ],
        ]);
    }

    /**
     * Le compte derriere la requete.
     *
     * Non nullable : le middleware d'administration a refuse toute requete sans
     * session valide, donc le cas « pas de compte » ne peut pas se presenter ici.
     * Il est donc type plutot que verifie, et une evolution du middleware qui
     * laisserait passer un appel sans session se verrait ici, en erreur franche,
     * plutot qu'en trace ecrite sous un auteur vide.
     */
}