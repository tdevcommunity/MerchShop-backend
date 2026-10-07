<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\Order\PickupScanRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PickupQrCodeService;
use App\Support\Orders\OrderAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Guichet : file de retrait, scan et service.
 *
 * Toutes ces routes exigent une session et un role de guichet. Le controle est
 * porte par `authorize` sur la policy, comme ailleurs dans l'API : le role
 * applicable depend de l'action, une policy le sait, un alias de middleware
 * non.
 */
final class OrderPickupController extends ApiController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PickupQrCodeService $pickupQrCodes,
        private readonly OrderAccess $access,
    ) {}

    /**
     * File des commandes a servir au stand.
     *
     * Les commandes livrees n'y apparaissent pas : elles n'ont pas de QR, donc
     * aucun guichetier ne peut les servir par un scan.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('useCounter', Order::class);

        return OrderResource::collection($this->orders->listForPickup($this->perPage($request)));
    }

    /**
     * Marquer une commande payee comme prete a etre remise.
     */
    public function markReady(Request $request, string $uuid): OrderResource
    {
        $order = $this->orders->findOrFail($uuid);

        $this->authorize('operate', $order);

        return OrderResource::make($this->orders->markReadyForPickup($order));
    }

    /**
     * Servir une commande scannee.
     *
     * Le scan et le marquage « prete » sont deux moments distincts : le premier
     * dit que l'argent est entre, le second que l'article est en rayon et peut
     * partir. Confondre les deux ferait disparaitre la file d'attente, qui
     * existe precisement pour que le guichetier sache quoi servir en premier.
     *
     * Le QR est verifie avant toute ecriture : un contenu fabrique a la main,
     * ou le QR d'une commande deja servie, est refuse sans toucher a la base.
     */
    public function scan(PickupScanRequest $request): OrderResource
    {
        $this->authorize('useCounter', Order::class);

        $order = $this->pickupQrCodes->resolveOrderFromPayload($request->string('payload')->toString());

        $this->pickupQrCodes->assertServable($order);

        return OrderResource::make($this->orders->markPickedUp($order, $this->agent($request)));
    }

    /**
     * Servir une commande connue, sans scan.
     *
     * Sert au rattrapage quand le telephone du guichet est hors service : la
     * commande est alors choisie dans la liste plutot que scannee. Le controle
     * d'acces est le meme, seule l'entree change.
     */
    public function markPickedUp(Request $request, string $uuid): OrderResource
    {
        $order = $this->orders->findOrFail($uuid);

        $this->authorize('operate', $order);

        return OrderResource::make($this->orders->markPickedUp($order, $this->agent($request)));
    }

    /**
     * Le guichetier derriere la requete.
     *
     * Le compte vient de la session, jamais du corps de la requete : un guichet
     * qui pourrait declarer avoir servi la commande au nom d'un autre rendrait
     * la colonne « servi par » sans valeur, ce qui est la seule chose qu'elle
     * demande.
     *
     * Nullable parce que la session peut avoir expire entre le middleware et
     * cette ligne — un poste de stand ouvert depuis le matin. La commande est
     * alors servie sans auteur plutot que refusee : l'article est sorti, et
     * bloquer sa sortie sur une session expiree echouerait le client qui attend
     * au comptoir. L'absence reste visible dans la reponse, donc traçable.
     */
    private function agent(Request $request): ?User
    {
        /** @var User|null $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Image PNG du QR d'une commande.
     *
     * L'image est envoyee telle quelle, avec un nom de fichier non devinable
     * et un cache court : le QR vaut jusqu'au retrait, et il ne doit pas
     * rester dans un cache intermediaire plus longtemps que la commande.
     *
     * Le client sans compte atteint aussi cette image, avec le jeton de sa
     * commande : son QR doit pouvoir etre affiche avant d'arriver au stand,
     * faute de quoi il n'a aucun moyen de montrer ce qu'il a paye. Un invite
     * present au guichet n'a pas ce probleme, puisque le guichetier lit la
     * commande dans sa file.
     */
    public function qrCode(Request $request, string $uuid): Response
    {
        $order = $this->orders->findOrFail($uuid);

        if (! $this->access->grantsAccessTo($order, $request->header(OrderAccess::HEADER))) {
            $this->authorize('view', $order);
        }

        return response($this->pickupQrCodes->renderPng($order), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
