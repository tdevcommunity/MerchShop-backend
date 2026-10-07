<?php

namespace App\Http\Resources;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PickupQrCodeService;
use App\Support\Orders\OrderAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

            /*
             * Qui a servi la commande.
             *
             * Distinct du client, qui n'est pas expose ici, et c'est la seule
             * distinction qui compte : les deux sont des comptes `User`, et les
             * confondre au moment d'afficher un nom serait une faute qui ne se
             * voit pas — les deux sont « un nom d'utilisateur ».
             *
             * La relation est chargee par le service au moment du service, donc
             * presente sur une commande servie. Elle ne l'est pas sur une
             * commande seulement lue : la ligne est alors absente plutot que
             * nulle, ce qui distingue « pas encore servie » de « servie par
             * quelqu'un dont le compte a disparu » — deux situations qu'il faut
             * pouvoir differencier dans un etat de stock.
             */
            'pickupAgent' => $this->when(
                $order->relationLoaded('pickupAgent'),
                fn (): array => [
                    'id' => $order->pickupAgent?->uuid,
                    'name' => $order->pickupAgent?->fullName(),
                    'email' => $order->pickupAgent?->email,
                ],
            ),

            /*
             * Identite de l'acheteur.
             *
             * Elle est recopiee sur la commande, et non lue depuis le compte,
             * parce qu'une commande invitee n'a pas de compte : sans cette copie,
             * la moitie du festival — les commandes passees sans s'identifier —
             * n'aurait aucun nom ni aucun numero a showing au guichet. C'est
             * aussi ce qui rend un remboursement possible.
             *
             * Le nom est expose tel quel, dans la forme qui a ete saisie, et le
             * numero dans sa forme nationale sans indicatif. Une commande lue par
             * son proprietaire ne lui apprend donc rien qu'il ne sache deja, et
             * `phoneCountry` reste disponible pour qui doit composer le numero
             * chez l'operateur.
             *
             * L'adresse n'est pas ici : elle vit dans `shippingAddress`, ou elle
             * est deja, et la dupliquer donnerait deux endroits a corriger.
             */
            'customerName' => $order->customer_name,
            'customerPhoneNumber' => $order->customer_phone_number,
            'customerPhoneCountry' => $order->customer_phone_country,

            'participantId' => $order->participant_id,

            'subTotal' => $order->sub_total,
            'discount' => $order->discount,

            /*
             * Devise et frais de livraison.
             *
             * Les frais sont exprimes separement du sous-total et de la remise
             * parce que la spec Data du festival (section 13) les veut distincts :
             * le revenu du festival et le panier moyen ne doivent pas confondre ce
             * qui vient de la vente de goodies avec ce qui vient du transport.
             *
             * La devise est stockee bien qu'elle soit constante aujourd'hui. Un
             * montant sans devise n'est pas interpretable, et le jour ou une
             * seconde devise est acceptee, la modifier sur les lignes existantes
             * ferait dire « XOF » a des montants qui ne l'etaient pas.
             */
            'deliveryFee' => $order->delivery_fee,
            'currency' => $order->currency,

            'total' => $order->total,

            /*
             * Etat du paiement au niveau de la commande.
             *
             * Les paiements restent exposes tels quels dans `payments` : c'est
             * l'historique complet, avec le detail par tentatives. Ces deux
             * champs en sont la condensation, prise sur la tentative la plus
             * recente, et la spec Data (section 13) les exige par commande et
             * non par paiement : un tableau de bord qui agrege des lignes de
             * commande ne peut pas se permettre de faire lui-meme cet arbitrage.
             *
             * Absents tant que la relation n'est pas chargee, plutot que nuls :
             * « paiement inconnu » et « aucun paiement » ne sont pas la meme
             * information, et les confondre ferait disparaitre des commandes d'un
             * rapport au lieu d'y indiquer une donnee manquante.
             */
            /*
             * `PaymentStatus` est un enum a backing int : son `value` est un
             * entier, comme celui de `OrderStatus` deux lignes plus haut. Ecrire
             * `?string` ici ne signalait donc pas une intention, seulement une
             * conversion : le JSON renvoyait `"1"` la ou le meme champ vaut `1`
             * partout ailleurs dans l'API. Un client qui comparait a l'entier
             * voyait une commande payee refusée. Le type annonce est donc celui
             * que la valeur a reellement.
             */
            'paymentStatus' => $this->when(
                $order->relationLoaded('payments'),
                fn (): ?int => $this->latestPayment($order)?->status->value,
            ),
            'paymentMethod' => $this->when(
                $order->relationLoaded('payments'),
                fn (): ?string => $this->latestPayment($order)?->method->value,
            ),

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

            /*
             * Les transitions qu'un guichetier peut demander depuis le
             * back-office, et non toutes celles que le service.accepte.
             *
             * `allowedActions` repond a « qu'est-ce que le service sait faire »,
             * et cette reponse est plus large que « qu'est-ce que je peux faire
             * au guichet » : elle contient les trois etats d'argent, que le
             * client atteint par le webhook et que le guichet n'atteint d'aucune
             * facon. Sans cette liste, un menu construit depuis
             * `allowedActions` proposerait « marquer payee » — et se ferait
             * refuser avec une 409 dont le message ne ressemble pas a ce qu'il
             * vient de tenter.
             *
             * Elle est donc l'intersection des deux : ce que le service autorise
             * et ce que le guichet peut demander. Exposee a cote de
             * `allowedActions` plutot qu'a sa place, parce que le client et le
             * guichet n'ont pas les memes droits et que melanger les deux
             * produirait un menu unique qui serait faux pour l'un des deux.
             */
            'counterActions' => $this->when(
                $isStaff,
                fn (): array => array_values(array_filter(
                    app(OrderService::class)->allowedTransitions($order),
                    /*
                     * La comparaison se fait sur la valeur, parce que
                     * `allowedTransitions()` rend des enum et
                     * `counterTransitions()` des entiers : ce sont les deux
                     * seules formes qui traversent l'API, et les mettre en
                     * confrontation ici forcerait a convertir l'une des deux.
                     * Le comparaison stricte reste possible en comparant
                     * `OrderStatus::from($value)`.
                     */
                    static fn (OrderStatus $status): bool => in_array(
                        $status->value,
                        OrderService::counterTransitions(),
                        true,
                    ),
                )),
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
     * Paiement le plus recent de la commande, ou null.
     *
     * Une commande peut avoir plusieurs tentatives, typiquement un client qui
     *change de moyen de paiement apres un echec. L'etat affiche au niveau de la
     * commande est donc celui de la derniere, pas le premier ni un cumul : un
     * client qui reessaie avec succes n'a pas eu un paiement en echec, il a eu
     * une tentative en echec suivie d'une reussite.
     *
     * Le tri se fait en memoire plutot que par une requete, parce que la
     * ressource ne peut pas charger la relation a la demande : le faire
     * declencherait une requete par commande dans une liste, la ou le
     * controleur l'a deja chargee ou a choisi de s'en passer.
     *
     * L'identifiant departage les dates egales, et c'est ce meme couple
     * `(created_at, id)` qui definit « la tentative la plus recente » dans le
     * filtre d'etat du back-office. Sans ce second critere, une commande dont
     * deux tentatives ont ete ouvertes dans la meme milliseconde pourrait etre
     * affichee avec l'etat de l'une et filtrer sur celui de l'autre.
     *
     * Le type de retour est `CarbonInterface` et non `CarbonImmutable` parce que
     * `created_at` est une colonne `timestamp` : Eloquent la rend en
     * `Illuminate\Support\Carbon`, qui n'herite pas de `CarbonImmutable`.
     * Annoncer cette derniere ferait echouer la ressource des la premiere
     * commande rendue, sur toutes les reponses de commande de l'API.
     */
    private function latestPayment(Order $order): ?Payment
    {
        /** @var Collection<int, Payment> $payments */
        $payments = $order->payments;

        return $payments
            ->sortBy([
                fn (Payment $payment): CarbonInterface => $payment->created_at ?? CarbonImmutable::now(),
                fn (Payment $payment): int => $payment->id ?? 0,
            ], SORT_REGULAR, descending: true)
            ->first();
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
