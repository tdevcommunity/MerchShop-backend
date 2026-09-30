<?php

namespace App\Http\Resources;

use App\Enums\FulfillmentMethod;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PickupQrCodeService;
use App\Support\Orders\OrderAccess;
use Illuminate\Http\Request;

/**
 * Representation d'une commande.
 *
 * Deux usages se partagent cette ressource, et la difference tient a ce que
 * l'appelant a le droit de voir :
 *
 *   - le client voit sa commande et le contenu de son QR ;
 *   - le guichet voit en plus les transitions possibles et le retrait.
 *
 * Les champs sensibles au guichet sont donc passes par `when(...)` plutot que
 * laisses en clair, pour qu'ils ne puissent pas finir dans une reponse client
 * par simple oubli d'un `authorize`.
 *
 * @mixin Order
 */
final class OrderResource extends ApiResource
{
    /**
     * Jeton d'acces d'une commande invitee, a exposer une seule fois.
     *
     * Il n'est pas lu sur le modele, mais transmis par le controleur qui vient de
     * l'emettre : il n'existe qu'a cet instant, et le remettre dans la commande
     * le ferait ressortir sur toutes les lectures suivantes, y compris pour le
     * personnel. La ressource ne le connait donc que si on le lui donne.
     */
    private ?string $guestAccessToken = null;

    /**
     * Attache le jeton emis avec cette commande.
     */
    public function withGuestAccessToken(string $token): static
    {
        $this->guestAccessToken = $token;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $isStaff = $this->actingAsStaff($request);

        return [
            'uuid' => $order->uuid,

            /*
             * Reference lisible, dite au client. L'uuid reste la cle technique :
             * un numero sequentiel serait devinable, et cette API n'a pas a
             * dependre du secret d'un identifiant.
             */
            'orderNumber' => $order->order_number,

            'status' => $order->status->value,
            'fulfillmentMethod' => $order->fulfillment_method->value,

            /*
             * Le droit de retrait et l'usage sont deux etats distincts : une
             * commande payee a un droit ouvert alors qu'aucun retrait n'a encore
             * eu lieu. Les confondre ferait apparaitre un retrait enregistre
             * qui n'existe pas dans le suivi du plan de tracking.
             */
            'pickupStatus' => $order->pickup_status?->value,
            'pickupTime' => $order->pickup_time?->toIso8601String(),

            'participantId' => $order->participant_id,

            'subTotal' => $order->sub_total,
            'discount' => $order->discount,
            'total' => $order->total,

            'shippingAddress' => $order->shipping_address,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),

            /*
             * Le contenu du QR n'est rendu que pour la commande concernee et
             * pour un tiers de confiance : un client qui liste ses commandes
             * n'a pas a telecharger cinq PNG d'affilee, et un tiers ne doit pas
             * pouvoir fabriquer un QR d'une commande qui n'est pas la sienne.
             *
             * L'invite qui detient le jeton de sa commande compte comme son
             * proprietaire : sans cela, un client sans compte payerait puis ne
             * pourrait pas afficher le QR que son paiement vient d'ouvrir.
             */
            'pickupQrPayload' => $this->when(
                $order->fulfillment_method === FulfillmentMethod::PICKUP
                    && $this->canRead($request, $order),
                fn (): ?string => $this->qrPayload(),
            ),

            /*
             * Jeton d'acces d'une commande invitee, renvoye une seule fois, a la
             * creation. Le client doit le conserver : il n'est ni devinable depuis
             * l'uuid de la commande, ni rejouable apres coup, ni recuperable par
             * la suite, exactement comme un mot de passe.
             */
            'guestAccessToken' => $this->when(
                $this->guestAccessToken !== null,
                fn (): ?string => $this->guestAccessToken,
            ),

            'createdAt' => $order->created_at?->toIso8601String(),
            'updatedAt' => $order->updated_at?->toIso8601String(),

            /*
             * Transitions possibles, pour que le back-office n'essaie pas une
             * action refusée et que le client sache s'il peut encore annuler.
             * Le service est la seule source de verite sur ces transitions.
             */
            'allowedActions' => $this->when(
                $isStaff || $order->isOwnedBy($request->user()) || $this->grantedByToken($request, $order),
                fn (): array => app(OrderService::class)->allowedTransitions($order),
            ),
        ];
    }

    /**
     * L'appelant de cette requete lit-il cette commande ?
     *
     * La regle vit dans `OrderAccess`, qui reunit le jeton d'un invite et la
     * policy d'un compte connecte. La ressource s'y branche au lieu de
     * recomposer la condition : c'est la meme question que celle posee par le
     * controleur, et les deux reponses doivent rester d'accord.
     */
    private function canRead(Request $request, Order $order): bool
    {
        return app(OrderAccess::class)->canRead($request, $order);
    }

    /**
     * L'appelant tient-il le jeton de cette commande ?
     *
     * Seule voie d'acces d'un invite : ni son historique ni son QR ne sont
     * attaches a un compte, donc la propriete du jeton est ce qui remplace la
     * propriete de la commande.
     */
    private function grantedByToken(Request $request, Order $order): bool
    {
        return app(OrderAccess::class)->grantsAccessTo($order, $request->header(OrderAccess::HEADER));
    }

    /**
     * L'appelant est-il un membre du guichet ?
     *
     * Le role est lu ici plutot que passe en constructeur : une ressource ne
     * doit pas dependre de l'ordre d'injection du controleur pour savoir a qui
     * elle parle, et la lecture est faite sur la requete courante.
     */
    private function actingAsStaff(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && $user->role->canOperateMerch()
            && $user->status->isActive();
    }

    /**
     * Contenu encode du QR de retrait, ou null.
     *
     * Passe par le service : le jeton est un HMAC du secret de configuration,
     * et ne doit jamais etre recalcule dans la couche de presentation.
     */
    private function qrPayload(): ?string
    {
        $service = app(PickupQrCodeService::class);

        if (! $service->hasPickupRight($order = $this->resource)) {
            return null;
        }

        return $service->encodePayload($order);
    }
}
