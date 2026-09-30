<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\Orders\OrderAccess;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
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
    #[QueryParameter(
        name: 'status',
        type: 'integer',
        description: 'Restreint la liste a un statut de commande. La valeur est l\'entier expose par `data.status` (1 en attente de paiement, 2 payee, 3 prete au retrait, 4 retiree, 5 annulee, 6-remboursee). Une valeur inconnue est refusee par un 422 `INVALID_FILTER` plutot que d\'etre ignoree : un front ne doit pas croire avoir filtre alors que la liste est complete.',
        example: 2,
    )]
    #[OpenApiResponse(
        status: 200,
        description: 'Page des commandes du client connecte.',
        type: 'array{data: array<int, \App\Http\Resources\OrderResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{currentPage: int, from: int|null, lastPage: int, path: string, perPage: int, to: int|null, total: int, links: array<int, array{url: string|null, label: string, page: int|null, active: bool}>}}',
    )]
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
    #[HeaderParameter(
        name: OrderAccess::HEADER,
        type: 'string',
        required: false,
        description: 'Jeton d\'acces d\'une commande invitee, renvoye une seule fois par `POST /orders`. A presenter en plus d\'une session pour lire la commande qui l\'a emis, et son QR de retrait. Un jeton n\'ouvre rien sur une commande rattachee a un compte : un client connecte est juge par sa session.',
        example: '9f2c1d0b7a4e6f8c5b3d2a1908e7f6c5d4b3a2918e7f6c5d4b3a29180e7f6c5d',
    )]
    #[OpenApiResponse(
        status: 200,
        description: 'Commande, avec le contenu de son QR de retrait s\'il en existe un.',
        type: OrderResource::class,
    )]
    #[OpenApiResponse(
        status: 403,
        description: 'Ni la session ni le jeton ne permettent de lire cette commande.',
    )]
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
