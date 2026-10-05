<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Support\Api\Money;
use App\Support\Payments\FedapayCustomerResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ouverture d'une tentative de paiement chez l'operateur.
 *
 * Ce service fait ce qu'aucun autre ne peut faire a sa place : appeler un tiers
 * au moment ou l'acheteur demande a payer. Cette contrainte commande tout son
 * fonctionnement.
 *
 * L'appel a l'operateur est hors de toute transaction base de donnees. L'inverse
 * est tentant et faux : la transaction garantit qu'ecrire la reference operateur
 * et ouvrir la transaction distantement se font ou ne se font pas. Mais elle
 * immobilise aussi les lignes lues pendant toute la latence de l'operateur, et
 * cette latence n'est pas la notre : une FedaPay lente immobiliserait une
 * commande, donc un retrait au guichet, pour une duree que personne ne maitrise.
 * L'ecriture suit donc l'appel, et la reprise est assuree par le verrou.
 *
 * Ce verrou est la seule protection contre deux appels simultanes pour la meme
 * commande. Sans lui, deux onglets ouverts sur la meme page de paiement creeraient
 * deux transactions chez l'operateur, dont une seule serait rapprochable — et le
 * rapprochement par notre reference deviendrait ambigu.
 */
final class CheckoutService
{
    /** Duree de validite du verrou : le temps d'un aller-retour a l'operateur. */
    private const LOCK_SECONDS = 15;

    /**
     * Attente maximale avant de dire que le paiement est deja en cours.
     *
     * Au-dela, l'appelant est renvoye sans erreur plutot que bloque : il peut
     * consulter sa commande, dont le paiement sera visible, et reessayer.
     */
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly Money $money,
        private readonly FedapayCustomerResolver $customers,
    ) {}

    /**
     * Ouvre, ou rend, une tentative de paiement pour une commande.
     *
     * L'appel est idempotent : deux requetes successives ne creent pas deux
     * transactions chez l'operateur. La seconde rend la meme adresse de paiement
     * que la premiere, ce qui est indispensable — un client qui a perdu son
     * onglet doit pouvoir reprendre son paiement sans en creer un nouveau, sous
     * peine de laisser deux regllements en attente sur une seule commande.
     */
    public function start(Order $order): Payment
    {
        $gateway = $this->gateways->default();

        return Cache::lock('payment-checkout:'.$order->uuid, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn (): Payment => $this->open($order, $gateway),
        );
    }

    /**
     * Ouverture effective, une fois le verrou obtenu.
     */
    private function open(Order $order, PaymentGateway $gateway): Payment
    {
        $order->loadMissing(['items.variant', 'user', 'payments', 'invoice']);

        $this->assertPayable($order);

        /*
         * La commande porte deja une tentative en attente depuis sa creation :
         * c'est elle qui est completee plutot qu'une nouvelle creee, afin qu'une
         * commande n'accumule pas une ligne de paiement par appel a cette route.
         */
        $payment = $this->reusable($order, $gateway);

        if ($payment?->checkout_url !== null) {
            return $payment;
        }

        $callbackUrl = config('payments.callback_url');

        if (! is_string($callbackUrl) || $callbackUrl === '') {
            throw new ApiException(
                'Le prestataire de paiement est indisponible.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => $gateway->provider()->value],
            );
        }

        /*
         * L'appel sortant se fait ici, en dehors de toute transaction : c'est la
         * raison d'etre du verrou pose plus haut.
         */
        $intent = $this->initiate($payment, $gateway, $callbackUrl);

        $payment->update([
            'provider' => $gateway->provider(),
            'transaction_id' => $intent->transactionId,
            'checkout_url' => $intent->checkoutUrl,
        ]);

        Log::info('Paiement ouvert chez le prestataire.', [
            'payment_reference' => $payment->uuid,
            'order_number' => $order->order_number,
            'provider' => $gateway->provider()->value,
            'transaction_id' => $intent->transactionId,
        ]);

        return $payment;
    }

    /**
     * Ouverture de la tentative chez l'operateur, traduite pour le client.
     *
     * Un operateur en panne est un incident de sauts, pas une erreur de notre
     * serveur : le client doit pouvoir reessayer sans que l'echec de FedaPay
     * ressemble a une erreur de notre API. Aucune reference n'est ecrite dans ce
     * cas — l'appel a echoue, donc rien n'a ete ouvert a encaisser.
     */
    private function initiate(Payment $payment, PaymentGateway $gateway, string $callbackUrl): PaymentIntent
    {
        try {
            return $gateway->initiate($payment, $callbackUrl);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error("Le prestataire de paiement n'a pas repondu.", [
                'payment_reference' => $payment->uuid,
                'order_id' => $payment->order_id,
                'provider' => $gateway->provider()->value,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw new ApiException(
                'Le prestataire de paiement est indisponible, reessayez plus tard.',
                502,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => $gateway->provider()->value],
            );
        }
    }

    /**
     * Une commande peut-elle encore etre payee ?
     *
     * Deux refus distincts, parce qu'ils ne disent pas la meme chose au client :
     * une commande deja reglee n'a plus rien a encaisser, une commande dont la
     * vente est terminee — annulee ou remboursee — ne peut plus l'etre. Le
     * second est le plus grave des deux — l'argent ne rentrera jamais — mais il se
     * deduit du statut de la commande, que le webhook de FedaPay ne pourra pas
     * bypasser.
     */
    private function assertPayable(Order $order): void
    {
        /*
         * Une commande remboursee est verifiee explicitement, et non par
         * l'absence de reglement qu'on en deduirait. Le remboursement laisse la
         * date de reglement en place : c'est un choix delibere, pour que la
         * trace du paiement reste lisible, et une vente remboursee ne peut pas
         * pour autant etre re-payee.
         */
        if ($order->status === OrderStatus::CANCELLED || $order->status === OrderStatus::REFUNDED) {
            throw new ApiException(
                $order->status === OrderStatus::CANCELLED
                    ? 'Cette commande est annulee et ne peut plus etre payee.'
                    : 'Cette commande a ete remboursee et ne peut plus etre payee.',
                409,
                'ORDER_NOT_PAYABLE',
                ['status' => $order->status->value],
            );
        }

        if ($this->money->toAmount($this->payments->settledAmount($order)) >= $this->money->toAmount($order->total)) {
            throw new ApiException(
                'Cette commande est deja entierement reglee.',
                409,
                'ORDER_ALREADY_PAID',
            );
        }
    }

    /**
     * La tentative de paiement a completer, ou une nouvelle a creer.
     *
     * Une tentative en attente est reprise des qu'elle appartient a l'agregateur
     * qu'on est en train d'appeler, et qu'elle n'a pas encore de lien de paiement.
     * Une tentative sans prestataire est aussi reprise : c'est la ligne posee a la
     * creation de commande, avant qu'on sache quel operateur encaisse.
     *
     * Est-ce bien de la meme que le seul montant sur lequel on peut faire confiance
     * est celui de la commande, et que l'operateur se deduit de la configuration ?
     * Reutiliser la ligne d'un autre prestataire encaisserait la commande chez un
     * agregateur que le client n'a pas choisi, ou avec un montant deja encaisse.
     * Est-ce de meme que si elle est deja tranchee ? Non : un essai qui a eu son
     * propre cours — un paiement refuse, une annulation — decrit une histoire
     * terminee, et le client qui recommence doit pouvoir le faire.
     */
    private function reusable(Order $order, PaymentGateway $gateway): Payment
    {
        $existing = $order->payments->first(
            fn (Payment $payment): bool => $payment->status === PaymentStatus::PENDING
                && ($payment->provider === null || $payment->provider === $gateway->provider())
        );

        if ($existing !== null) {
            return $existing;
        }

        /*
         * Une commande payee partiellement n'a pas de tentative en attente a
         * reutiliser : celle qui a produit ce solde porte un montant qui n'est
         * plus celui du solde restant, et la reutiliser ferait encaisser le
         * mauvais montant.
         *
         * Le moyen de paiement est repris de la tentative la plus recente
         * plutot que redemande au client. Il a ete choisi a la commande, et le
         * faire choisir une seconde fois ouvrirait la porte a un encaissement
         * par un moyen different de celui annonce au moment de l'achat.
         */
        $previous = $order->payments->sortByDesc('id')->first();

        return $this->payments->create([
            'order_id' => $order->id,
            'amount' => $this->outstanding($order),
            'method' => $previous?->method ?? PaymentMethod::MOBILE_MONEY,
            'provider' => $gateway->provider(),
            'status' => PaymentStatus::PENDING,
        ]);
    }

    /**
     * Solde restant a encaisser sur la commande.
     */
    private function outstanding(Order $order): int
    {
        $settled = $this->money->toAmount($this->payments->settledAmount($order));

        return max(0, $this->money->toAmount($order->total) - $settled);
    }
}
