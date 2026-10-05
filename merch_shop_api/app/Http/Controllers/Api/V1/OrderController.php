<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\RefundService;
use App\Support\Orders\OrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commandes du client.
 *
 * La creation est publique — un visiteur n'a pas de compte au moment d'
 * acheter — mais la lecture ne l'est pas. C'est la seule asymetrie du
 * controleur, et elle est voulue : commander ne demande aucune identite, lire
 * l'historique d'une commande en demande une.
 */
final class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CheckoutService $checkout,
        private readonly RefundService $refunds,
        private readonly OrderAccess $access,
    ) {}

    /**
     * Commandes du client connecte.
     *
     * La liste ne sort jamais les commandes d'un autre compte, quelle que soit
     * la personne connectee : elle est filtree sur `user_id` dans la couche
     * service, et non dans le controleur, pour que la meme requete SQL reste
     * la seule voie d'acces aux commandes d'un client.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection(
            $this->orders->listForUser(
                $request->user(),
                $this->perPage($request),
                $this->filterStatus($request),
            ),
        );
    }

    /**
     * Une commande, avec son QR de retrait s'il en existe un.
     *
     * Deux voies d'acces : la session, jugee par la policy, et le jeton d'une
     * commande invitee, juge par `OrderAccess`. Elles ne se recouvrent pas — un
     * jeton n'ouvre rien sur une commande rattachee a un compte — et c'est
     * pourquoi la policy n'a pas besoin de connaitre le jeton.
     */
    public function show(Request $request, string $uuid): OrderResource
    {
        $order = $this->orders->findOrFail($uuid);

        $this->authorizeRead($request, $order);

        return OrderResource::make($order);
    }

    /**
     * Passage de commande.
     *
     * La session est lue ici et non dans la validation : la commande est
     * publique, donc la requete ne peut pas exiger d'etre authentifiee, et
     * rattacher un compte est une consequence de l'etat de la session, pas une
     * donnee fournie par le client.
     *
     * Une commande invitee part avec son jeton d'acces, qui est sa seule voie de
     * retour : les routes de lecture exigent une session, et sans ce jeton ce
     * client ne pourrait ni relire sa commande, ni afficher son QR apres
     * paiement. Le jeton n'est renvoye qu'ici, une fois ; les lectures suivantes
     * s'en servent en entete.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create([
            ...$request->body(),
            'user' => $request->user(),
        ]);

        $token = $this->access->issueFor($order);

        $resource = OrderResource::make($order);

        /*
         * Le jeton n'est pas lu sur la commande, mais transmis a la ressource qui
         * la rend : il n'existe qu'a cet instant. Le mettre dans le modele le
         * ferait ressortir sur toutes les lectures ulterieures, y compris pour
         * le personnel, alors qu'il n'a pas a etre rejoue apres la creation.
         */
        if ($token !== null) {
            $resource->withGuestAccessToken($token);
        }

        return $this->jsonResource($resource, Response::HTTP_CREATED);
    }

    /**
     * Ouvre — ou rend — le paiement d'une commande.
     *
     * La commande est d'abord lue comme elle l'est dans `show`, et l'autorisation
     * verifiee par les memes deux voies. Un invite paie donc avec le jeton qu'il
     * a recu a la creation, comme il relit sa commande avec ce meme jeton : les
     * deux routes ont le meme public, et il aurait ete incoherent d'exiger un
     * compte pour lire une commande que l'on vient de creer sans compte.
     *
     * L'appel a l'operateur est fait ici et non a la creation de la commande,
     * pour deux raisons : il est lent, et il n'a lieu qu'une fois que l'acheteur
     * a decide de payer. Le declencher a la commande ouvrirait une transaction
     * chez l'operateur pour des paniers abandonnes.
     *
     * La reponse est toujours un 200. La route est idempotente : la rappeler
     * rend la meme adresse de paiement sans appeler l'operateur une seconde
     * fois, ce qui rend un 201 ambigu — impossible de savoir, cote client, s'il
     * decrit une creation ou un simple rappel.
     */
    public function pay(Request $request, string $uuid): PaymentResource
    {
        $order = $this->orders->findOrFail($uuid);

        $this->authorizeRead($request, $order);

        return PaymentResource::make($this->checkout->start($order)->load('order'));
    }

    /**
     * Demande le remboursement d'une commande reglee.
     *
     * La route ne dit pas « la commande est remboursee » : elle dit « le
     * remboursement est demande ». L'argent sort apres, sur une notification de
     * l'operateur, et le guichet doit pouvoir consulter cet etat intermediaire
     * plutot que d'inferer d'un statut final une sortie qui n'a pas eu lieu.
     *
     * Elle rend toujours 200, comme le checkout : la demande est enregistree et
     * un depot demande, meme en attente de confirmation. Un 201 ou un 202
     * dirait la meme chose et laisserait croire que l'argent est deja parti.
     */
    public function refund(Request $request, string $uuid): RefundResource
    {
        $order = $this->orders->findOrFail($uuid);

        /*
         * Le droit de rembourser est decide par la policy, et non par la
         * session : il appartient au guichet et a lui seul. C'est aussi
         * exactement ce que `OrderPolicy::refund` dit deja, ce qui explique
         * qu'aucun client ne puisse s'attribuer la restitution de sa commande
         * — ni celle d'autrui.
         */
        $this->authorize('refund', $order);

        return RefundResource::make($this->refunds->request($order));
    }

    /**
     * Annulation d'une commande non encore reglee.
     *
     * Le statut est verifie par le service dans la meme transaction que la
     * restitution du stock : entre la lecture et l'ecriture, une confirmation
     * de paiement a pu arriver, et c'est la transition qui tranche.
     */
    public function cancel(Request $request, string $uuid): OrderResource
    {
        $order = $this->orders->findOrFail($uuid);

        $this->authorize('cancel', $order);

        return OrderResource::make($this->orders->cancel($order));
    }

    /**
     * Autorise la lecture d'une commande par l'une de ses deux voies.
     *
     * Le jeton est verifie avant la policy, pour qu'un invite ne soit pas
     * rejete par une regle ecrite pour les comptes connectes. La policy n'est
     * donc evaluee que si le jeton n'a rien ouvert, et son refus donne le meme
     * 403 que si aucun jeton n'avait ete fourni : distinguer les deux cas
     * renseignerait un attaquant sur l'existence d'une commande.
     */
    private function authorizeRead(Request $request, Order $order): void
    {
        if (! $this->access->grantsAccessTo($order, $request->header(OrderAccess::HEADER))) {
            $this->authorize('view', $order);
        }
    }

    /**
     * Statut demande dans la liste, ou null.
     */
    private function filterStatus(Request $request): ?OrderStatus
    {
        $value = $request->query('status');

        if ($value === null || $value === '') {
            return null;
        }

        $status = OrderStatus::tryFrom((string) $value);

        if ($status === null) {
            throw new ApiException(
                'Le parametre status doit etre le numero d\'un statut de commande connu.',
                422,
                'INVALID_FILTER',
                [
                    'filter' => 'status',
                    'expected' => array_map(
                        static fn (OrderStatus $case): string => $case->value,
                        OrderStatus::cases(),
                    ),
                ],
            );
        }

        return $status;
    }
}
