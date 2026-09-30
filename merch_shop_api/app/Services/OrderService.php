<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PickupStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\VariantRepositoryInterface;
use App\Support\Api\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regle metier des commandes.
 *
 * Porte les decisions qu'aucune autre couche ne peut prendre a sa place : le
 * moment ou le stock est reserve, la maniere dont les montants sont calcules, et
 * les transitions d'etat autorisees.
 *
 * Deux invariants gobernent tout le reste :
 *
 *  1. le client n'est jamais une source de verite sur les prix. Le payload ne
 *     porte qu'un identifiant de variante et une quantite ; le prix, le total et
 *     les frais sont recalcules ici a partir du catalogue au moment de la vente.
 *     Un montant recu du client ne serait pas seulement inexact, il serait
 *     falsifiable, ce que le plan de tracking interdit explicitement ;
 *
 *  2. le stock est reserve des la creation de la commande, dans la meme
 *     transaction que son enregistrement. Le decrementer a la confirmation du
 *     paiement laisserait la place a deux commandes payables pour un seul
 *     article : le stock reel ne serait alors verifie par personne, et la
 *     survente ne se decouvrirait qu'a la preparation des commandes.
 */
final class OrderService
{
    /**
     * Nombre de tentatives d'attribution d'un numero de commande.
     *
     * Le numero est unique en base, mais deux commandes creees dans la meme
     * milliseconde peuvent puiser la meme sequence. L'index unique tranche, et
     * l'echec est donc attendu plutot que subi : quelques tentatives suffisent
     * car la probabilite de collision sur huit caracteres aleatoires est
     * negligeable.
     */
    private const NUMBER_ATTEMPTS = 5;

    /**
     * Transitions d'etat autorisees.
     *
     * La table vit dans le service, et non dans l'enumeration, parce que la
     * regle est metier et non etat : elle dit ce que le guichet et le tunnel
     * ont le droit de faire, ce qui n'appartient pas a la description d'une
     * valeur.
     *
     * Deux choix meritent d'etre explicites :
     *
     *  - une commande payee ne peut pas etre annulee, seulement remboursee. Une
     *    annulation apres encaissement ferait disparaitre de la commande le
     *    fait que de l'argent a ete pris, ce qui rendrait le rapprochement
     *    comptable impossible. Les deux etats restent distincts jusqu'au bout :
     *    l'annulation est un echec de vente, le remboursement un mouvement de
     *    tresorerie ;
     *
     *  - le retrait n'est offert qu'a une commande payee et non livree, donc
     *    `PICKED_UP` n'est atteignable que depuis `READY_FOR_PICKUP`. On ne
     *    sert pas un article dont le paiement n'est pas confirme.
     *
     * @var array<int, array<int, OrderStatus>>
     */
    private const TRANSITIONS = [
        OrderStatus::PENDING_PAYMENT->value => [
            OrderStatus::PAID->value,
            OrderStatus::CANCELLED->value,
        ],
        OrderStatus::PAID->value => [
            OrderStatus::READY_FOR_PICKUP->value,
            OrderStatus::REFUNDED->value,
        ],
        OrderStatus::READY_FOR_PICKUP->value => [
            OrderStatus::PICKED_UP->value,
            OrderStatus::REFUNDED->value,
        ],
        OrderStatus::PICKED_UP->value => [
            OrderStatus::REFUNDED->value,
        ],
        OrderStatus::CANCELLED->value => [],
        OrderStatus::REFUNDED->value => [],
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly VariantRepositoryInterface $variants,
        private readonly PaymentRepositoryInterface $payments,
        private readonly Money $money,
    ) {}

    /**
     * Enregistre une commande, reserve le stock et ouvre la tentative de paiement.
     *
     * @param  array{user: User|null, items: array<int, array{uuid: string, quantity: int}>, fulfillment_method: FulfillmentMethod, shipping_address: string|null, payment_method: PaymentMethod, participant_id: string|null}  $data
     */
    public function create(array $data): Order
    {
        /*
         * Les enums sont resolus ici et non dans le controleur : la validation
         * HTTP garantit deja que la valeur est connue, mais le service est
         * aussi appele par les tests, les commandes artisan et le routage des
         * evenements, qui passent des chaines. Accepter les deux formes evite
         * que chacun de ces appelants fasse sa propre conversion, et qu'une
         * conversion oubliee echoue en 500 sur une donnee pourtant valide.
         */
        $data['fulfillment_method'] = $data['fulfillment_method'] instanceof FulfillmentMethod
            ? $data['fulfillment_method']
            : FulfillmentMethod::from((string) $data['fulfillment_method']);

        $data['payment_method'] = $data['payment_method'] instanceof PaymentMethod
            ? $data['payment_method']
            : PaymentMethod::from((string) $data['payment_method']);

        return DB::transaction(function () use ($data): Order {
            /*
             * Le verrou est pose avant tout calcul de montant, et les lignes
             * sont resolues par identifiant interne triee : c'est cet ordre-la
             * qui garantit que deux commandes simultanees sur le meme panier
             * n'attendent pas l'une l'autre indefiniment.
             */
            $variants = $this->lockRequestedVariants($data['items']);

            $lines = $this->priceLines($variants, $data['items']);

            $subTotal = $this->money->sum(array_column($lines, 'total'));

            /*
             * Aucune remise n'est calculee ici : le mecanisme de codes promo
             * n'existe pas encore. La colonne reste a zero plutot que d'annoncer
             * une regle qui n'est pas ecrite : un montant applique sans
             * justification serait recopie dans la facture, donc dans la
             * comptabilite.
             */
            $discount = 0;

            /*
             * Frais de livraison.
             *
             * Lus dans la configuration et jamais dans le payload : un montant
             * envoye par le client permettrait d'ecrire le chiffre a encaisser. Le
             * retrait au stand n'en a pas, ce qui est le cas par defaut, donc le
             * montant est nul sauf livraison.
             */
            $deliveryFee = $data['fulfillment_method'] === FulfillmentMethod::DELIVERY
                ? (int) config('orders.delivery_fee')
                : 0;

            $order = $this->createWithUniqueNumber([
                'user_id' => ($data['user'] ?? null)?->id,
                'sub_total' => $subTotal,
                'shipping_address' => $data['shipping_address'] ?? null,
                'discount' => $discount,
                'delivery_fee' => $deliveryFee,

                /*
                 * La devise est ecrite sur la commande plutot que deduite a la
                 * lecture. Le festival n'encaisse qu'en francs CFA, mais un
                 * montant sans devise n'est pas interpretable, et un jour ou une
                 * seconde devise est acceptee, corriger celle-ci sur les lignes
                 * existantes ferait dire « XOF » a des montants qui ne l'etaient
                 * pas.
                 */
                'currency' => Money::CURRENCY,

                /*
                 * L'identite du total : sous-total, moins la remise, plus les
                 * frais de livraison. Elle est ecrite ici, dans le seul endroit ou
                 * le total est calcule, plutot que recomposee a chaque lecture,
                 * pour qu'un montant affiche, facture et encaisse ne puissent pas
                 * diverger.
                 */
                'total' => $subTotal - $discount + $deliveryFee,

                'status' => OrderStatus::PENDING_PAYMENT,
                'fulfillment_method' => $data['fulfillment_method'],
                // Le droit au retrait n'existe que pour une commande de stand.
                // Une commande livree n'a pas de retrait a exercer, et sa ligne
                // reste nulle plutot que PENDING : la colonne distingue le droit
                // de l'usage, un droit qui n'existe pas ne doit pas avoir d'etat.
                'pickup_status' => $data['fulfillment_method']->requiresPickupQrCode()
                    ? PickupStatus::PENDING
                    : null,
                'participant_id' => $data['participant_id'] ?? null,
            ]);

            foreach ($lines as $line) {
                $this->variants->restock($line['variant'], -$line['quantity']);
            }

            $this->createItems($order, $lines);

            /*
             * La tentative de paiement est ouverte des la creation, et non a
             * l'appel chez l'operateur : c'est elle qui porte l'identifiant
             * envoye a l'agregateur, et cet identifiant doit exister avant que
             * l'operateur puisse rappeler. Un webhook arrivant avant la fin de
             * cette transaction la trouvera donc deja en base.
             */
            $this->payments->create([
                'order_id' => $order->id,
                'amount' => $order->total,
                'method' => $data['payment_method'],
                'status' => PaymentStatus::PENDING,
            ]);

            return $this->reload($order);
        });
    }

    /**
     * Commandes d'un client.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function listForUser(User $user, int $perPage, ?OrderStatus $status = null): LengthAwarePaginator
    {
        return $this->orders->paginateForUser($user, $perPage, $status);
    }

    /**
     * File des commandes a servir au stand.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function listForPickup(int $perPage): LengthAwarePaginator
    {
        return $this->orders->paginateForPickup($perPage);
    }

    /**
     * Une commande par son identifiant public.
     *
     * Renvoie 404 plutot que 403 sur une commande qui n'est pas la sienne : la
     * reponse ne doit pas reveler l'existence d'une commande qui appartient a
     * quelqu'un d'autre. C'est le meme arbitrage que pour le catalogue.
     */
    public function findOrFail(int|string $id): Order
    {
        $order = $this->orders->findWithRelations($id, ['items.variant.product', 'user', 'payments', 'invoice']);

        if ($order === null) {
            throw new ApiException('Commande introuvable.', 404, 'ORDER_NOT_FOUND');
        }

        return $order;
    }

    /**
     * Annule une commande avant paiement et rend le stock.
     *
     * Le stock n'est rendu que si la commande ne l'etait pas deja : une double
     * annulation ne peut pas credited deux fois la variante.
     */
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $this->assertCanTransition($order, OrderStatus::CANCELLED);

            $this->releaseStock($order);
            $this->payments->forOrder($order)->each(function ($payment): void {
                // Une tentative encore en attente est sans issue des lors que la
                // commande est fermee : la laisser en attente ferait croire a un
                // reglement possible.
                if ($payment->status === PaymentStatus::PENDING) {
                    $payment->update(['status' => PaymentStatus::FAILED]);
                }
            });

            $order->update([
                'status' => OrderStatus::CANCELLED,
                'pickup_status' => $order->pickup_status === PickupStatus::PENDING
                    ? PickupStatus::CANCELLED
                    : $order->pickup_status,
            ]);

            return $this->reload($order);
        });
    }

    /**
     * Enregistre le reglement integral d'une commande.
     *
     * Reservee au service de paiement, qui est le seul a savoir qu'un reglement
     * a ete confirme : un back-office ne declare pas un encaissement sur la seule
     * foi d'une declaration du client.
     */
    public function markPaid(Order $order): Order
    {
        return $this->transition($order, OrderStatus::PAID);
    }

    /**
     * Passe une commande payee en attente de service au stand.
     */
    public function markReadyForPickup(Order $order): Order
    {
        $this->assertPickupOrder($order);

        return $this->transition($order, OrderStatus::READY_FOR_PICKUP);
    }

    /**
     * Enregistre le retrait effectif d'une commande au stand.
     *
     * Le retrait est un evenement externe, constate par le scan du QR : le
     * service ne verifie donc pas le jeton, ce que fait l'appelant qui possede
     * le secret de comparaison.
     */
    public function markPickedUp(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $this->assertPickupOrder($order);
            $this->assertCanTransition($order, OrderStatus::PICKED_UP);

            $order->update([
                'status' => OrderStatus::PICKED_UP,
                'pickup_status' => PickupStatus::PICKED_UP,
                'pickup_time' => now(),
                /*
                 * Le jeton est efface apres usage. Un QR Code est une photo :
                 * sans cette mesure, un client photographie avant le festival
                 * pourrait presenter la meme image toute la journee, et le
                 * comptage des goodies distribuees serait faux.
                 */
                'pickup_token_hash' => null,
            ]);

            return $this->reload($order);
        });
    }

    /**
     * Rembourse une commande payee et rend le stock.
     *
     * Le stock revient en jeu : les articles n'ont pas ete distribues s'ils
     * n'ont pas ete retires, et les immobiliser ne servirait a rien.
     */
    public function refund(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $this->assertCanTransition($order, OrderStatus::REFUNDED);

            /*
             * Un article deja remis au participant n'est plus en stock : le
             * remettre une fois de plus sellingrait deux fois la meme piece. La
             * restitution au stock ne concerne donc que ce qui est encore en
             * rayon au moment du remboursement.
             */
            if ($order->status !== OrderStatus::PICKED_UP) {
                $this->releaseStock($order);
            }

            $this->payments->forOrder($order)->each(function ($payment): void {
                if ($payment->isPaid()) {
                    $payment->update(['status' => PaymentStatus::REFUNDED]);
                }
            });

            $order->update(['status' => OrderStatus::REFUNDED]);

            return $this->reload($order);
        });
    }

    /**
     * La transition demandee est-elle permise depuis l'etat courant ?
     */
    public function canTransition(Order $order, OrderStatus $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$order->status->value] ?? [], true);
    }

    /**
     * Verifie une transition, et explique son refus.
     *
     * Le message nomme l'etat courant et l'etat demande : un back-office qui
     * tente de servir une commande deja servie doit comprendre qu'il a doublon
     * d'operation, pas lire un refus generique.
     */
    public function assertCanTransition(Order $order, OrderStatus $target): void
    {
        if ($this->canTransition($order, $target)) {
            return;
        }

        throw new ApiException(
            sprintf(
                'Une commande au statut « %s » ne peut pas passer au statut « %s ».',
                $this->statusLabel($order->status),
                $this->statusLabel($target),
            ),
            409,
            'INVALID_ORDER_TRANSITION',
            ['current_status' => $order->status->value, 'requested_status' => $target->value],
        );
    }

    /**
     * Transitions possibles depuis l'etat courant.
     *
     * Exposee pour que la ressource puisse annoncer la suite au client, qui
     * n'a pas a deviner quelles actions le back-office propose.
     *
     * @return array<int, OrderStatus>
     */
    public function allowedTransitions(Order $order): array
    {
        return array_map(
            static fn (int $value): OrderStatus => OrderStatus::from($value),
            self::TRANSITIONS[$order->status->value] ?? [],
        );
    }

    /**
     * Verifie, sous verrou, que chaque variante demandee est commandable.
     *
     * Les lignes sont rendues triees par identifiant interne, quel que soit
     * l'ordre du panier : le verrou suit donc toujours la meme sequence, et deux
     * paniers identiques ne peuvent pas s'interbloquer.
     *
     * @param  array<int, array{uuid: string, quantity: int}>  $items
     * @return Collection<int, Variant>
     */
    private function lockRequestedVariants(array $items): Collection
    {
        $uuids = array_column($items, 'uuid');
        $variants = $this->variants->lockForSale($uuids);

        $found = $variants->pluck('uuid')->all();
        $missing = array_values(array_diff($uuids, $found));

        if ($missing !== []) {
            throw new ApiException(
                'Certaines declinaisons demandees n\'existent pas ou ne sont plus au catalogue.',
                422,
                'VARIANT_NOT_FOUND',
                ['variants' => $missing],
            );
        }

        return $variants;
    }

    /**
     * Verifie la vendabilite et fige le prix de chaque ligne.
     *
     * Le prix est lu sur la variante verrouillee, donc sur la valeur qui sera
     * facturee : aucune promotion lancee entre l'affichage et le passage en
     * caisse ne peut s'appliquer retroactivement, et le client ne peut pas
     * choisir son prix.
     *
     * @param  Collection<int, Variant>  $variants
     * @param  array<int, array{uuid: string, quantity: int}>  $items
     * @return array<int, array{variant: Variant, quantity: int, unit_price: int, total: int}>
     */
    private function priceLines(Collection $variants, array $items): array
    {
        /** @var array<string, int> $quantities */
        $quantities = [];

        foreach ($items as $item) {
            $quantities[$item['uuid']] = ($quantities[$item['uuid']] ?? 0) + $item['quantity'];
        }

        $lines = [];

        foreach ($variants as $variant) {
            $quantity = $quantities[$variant->uuid];

            $this->assertSellable($variant);
            $this->assertStockAvailable($variant, $quantity);

            $unitPrice = $this->money->toAmount($variant->price);

            $lines[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $unitPrice * $quantity,
            ];
        }

        return $lines;
    }

    /**
     * Une variante publiee et non masquee, dont le produit l'est aussi.
     *
     * Le produit est verifie avec la variante : un back-office qui masque un
     * produit sans toucher a ses declinaisons doit voir disparaitre le produit
     * entier du tunnel, sinon ses articles resteraient achetables.
     */
    private function assertSellable(Variant $variant): void
    {
        if ($variant->status !== CatalogStatus::ACTIVE || $variant->product?->status !== CatalogStatus::ACTIVE) {
            throw new ApiException(
                'Une des declinaisons demandees n\'est plus en vente.',
                422,
                'VARIANT_NOT_SELLABLE',
                ['variants' => [$variant->uuid]],
            );
        }
    }

    private function assertStockAvailable(Variant $variant, int $quantity): void
    {
        if ($variant->stock < $quantity) {
            throw new ApiException(
                'Stock insuffisant pour une des declinaisons demandees.',
                409,
                'INSUFFICIENT_STOCK',
                [
                    'variants' => [[
                        'uuid' => $variant->uuid,
                        'available' => $variant->stock,
                        'requested' => $quantity,
                    ]],
                ],
            );
        }
    }

    /**
     * Enregistre les lignes de commande.
     *
     * Le nom du produit et celui de la variante sont copies such chargee. La
     * facture doit rester fidele a ce qui a ete vendu : relire le catalogue au
     * moment de l'emission ferait changer un document fiscal apres un
     * renommage ou un depubliment.
     *
     * @param  array<int, array{variant: Variant, quantity: int, unit_price: int, total: int}>  $lines
     */
    private function createItems(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $variant = $line['variant'];

            $order->items()->create([
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'total_price' => $line['total'],
                'product_name' => $variant->product?->name ?? 'Article retire du catalogue',
                'variant_name' => $variant->name,

                /*
                 * Categorie, taille et couleur sont figees ici, au meme titre que
                 * le nom : la ligne doit dire ce qui a ete vendu, pas ce que le
                 * catalogue en dit aujourd'hui. Un produit deplace de categorie
                 * entre deux festivals laisserait sinon ses ventes de l'ancien
                 * rayon suivre le produit, et le comptage par taille et par
                 * couleur deviendrait faux. La spec Data (section 13) en fait des
                 * donnees exigees par ligne, ce qui suppose qu'elles soient
                 * lisibles sans remonter au catalogue.
                 */
                'product_category' => $variant->product?->category?->name,
                'size' => $variant->size,
                'color' => $variant->color,
            ]);
        }
    }

    /**
     * Rend le stock reserve par une commande.
     */
    private function releaseStock(Order $order): void
    {
        $order->items()->with('variant')->get()->each(function ($item): void {
            $variant = $item->variant;

            if ($variant === null) {
                /*
                 * La ligne pointe une variante disparue : la cle etrangere est
                 * en nullOnDelete precisely pour que l'historique comptable
                 * survive. Le stock correspondant n'appartient plus a personne,
                 * il n'y a donc rien a rendre.
                 */
                return;
            }

            $this->variants->restock($variant, $item->quantity);
        });
    }

    /**
     * Cree la commande en lui attribuant un numero libre.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueNumber(array $attributes): Order
    {
        for ($attempt = 0; $attempt < self::NUMBER_ATTEMPTS; $attempt++) {
            $orderNumber = $this->generateOrderNumber();

            if ($this->orders->orderNumberExists($orderNumber)) {
                continue;
            }

            try {
                /** @var Order $order */
                $order = $this->orders->create($attributes + ['order_number' => $orderNumber]);

                return $order;
            } catch (QueryException $exception) {
                /*
                 * Collision survenue entre le controle applicatif et l'ecriture,
                 * donc un autre tunnel a tire le meme numero. On retente plutot
                 * que de renvoyer une erreur : le client n'y est pour rien, et la
                 * transaction qui contient ce decrement de stock ne doit pas
                 * echouer pour un detail de numerotation.
                 */
                if (! $this->isOrderNumberConflict($exception)) {
                    throw $exception;
                }
            }
        }

        throw new ApiException(
            'Impossible d\'attribuer un numero de commande. Reessayez dans un instant.',
            503,
            'ORDER_NUMBER_UNAVAILABLE',
        );
    }

    /**
     * Numero de commande lisible au guichet.
     *
     * La forme reprend celle de la factory, `TDEV-AAAAMMJJ-XXXXXX` : le prefixe
     * identifie la campagne, la date permet de lire a voix haute quand la vente
     * a eu lieu, et la partie aleatoire evite la collision.
     */
    private function generateOrderNumber(): string
    {
        return sprintf(
            '%s-%s-%s',
            (string) config('orders.numbering.prefix'),
            now()->format('Ymd'),
            Str::upper(Str::random(6)),
        );
    }

    /**
     * L'echec vient-il bien de l'unicite du numero de commande ?
     */
    private function isOrderNumberConflict(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'order_number');
    }

    /**
     * Applique une transition d'etat autorisee.
     *
     * Ne verifie que la legalite de la transition : le controle propre a chaque
     * etat (commande de stand, retrait) est pose par la methode exposee qui
     * l'appelle, pour qu'un reglement reste possible sur une commande livree.
     */
    private function transition(Order $order, OrderStatus $target): Order
    {
        return DB::transaction(function () use ($order, $target): Order {
            $this->assertCanTransition($order, $target);

            $order->update(['status' => $target]);

            return $this->reload($order);
        });
    }

    /**
     * Les transitions de stand ne concernent que les commandes de retrait.
     */
    private function assertPickupOrder(Order $order): void
    {
        if ($order->fulfillment_method !== FulfillmentMethod::PICKUP) {
            throw new ApiException(
                'Cette commande est livree : elle n\'a pas de retrait a enregistrer au stand.',
                409,
                'NOT_A_PICKUP_ORDER',
            );
        }
    }

    /**
     * Relecture complete apres ecriture, pour la ressource.
     */
    private function reload(Order $order): Order
    {
        return $this->orders->findWithRelations($order->uuid, ['items.variant', 'user', 'payments', 'invoice']);
    }

    /**
     * Libelle lisible d'un statut, pour les messages d'erreur.
     */
    private function statusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PENDING_PAYMENT => 'en attente de paiement',
            OrderStatus::PAID => 'payée',
            OrderStatus::READY_FOR_PICKUP => 'prête au retrait',
            OrderStatus::PICKED_UP => 'retirée',
            OrderStatus::CANCELLED => 'annulée',
            OrderStatus::REFUNDED => 'remboursée',
        };
    }
}
