<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Repositories\Contracts\RefundRepositoryInterface;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La restitution d'argent : demande, puis confirmation.
 *
 * Ce service existe parce que rendre de l'argent n'est pas un changement d'etat,
 * c'est un envoi d'argent, et que les deux ne se repondent pas. L'operateur
 * accepte la demande et traite le depot ensuite ; la confirmation arrive par
 * notification, des heures ou des jours plus tard. Ecrire « rembourse » au
 * moment de l'appel affirmerait au guichet et au client une restitution qui
 * n'a pas eu lieu — un echec qui ne se voit nulle part, ce qui est la pire
 * forme d'echec.
 *
 * La commande porte donc un etat `refund_pending` pendant l'attente, et le
 * service tient la trace de chaque tentative dans `refunds`. Cette trace rend
 * la demande reprenable et le guichet capable de repondre a « avez-vous bien
 * rembourse ? » par une donnee, pas par une impression.
 *
 * L'appel a l'operateur sort de toute transaction base de donnees, comme au
 * checkout : c'est une latence que nous ne maitrisons pas, et elle
 * immobiliserait une commande entiere pendant ce temps.
 */
final class RefundService
{
    public function __construct(
        private readonly RefundRepositoryInterface $refunds,
        private readonly PayoutGatewayRegistry $gateways,
        private readonly OrderService $orders,
    ) {}

    /**
     * Demande la restitution d'une commande reglee.
     *
     * La commande est d'abord placee en attente de confirmation, et c'est ce qui
     * la rend protegee : elle cesse d'accepter les transitions du stand pendant
     * que l'argent est en route, ce qui evite qu'un guichetier la serve entre la
     * demande et la sortie effective des fonds.
     *
     * Idempotent : une seconde demande tant que la premiere n'a pas abouti
     * renvoie la meme demande plutot que d'envoyer l'argent une seconde fois.
     */
    public function request(Order $order): Refund
    {
        $order->loadMissing('payments');

        $existing = $this->refunds->openForOrder($order);

        if ($existing !== null) {
            return $existing;
        }

        $payment = $this->paidPayment($order);

        /*
         * L'etat anterieur est capture avant tout ecriture, et pas deduit au
         * moment de l'echec : c'est la seule facon de restituer fidelement une
         * commande qui etait prete ou retiree lorsqu'un depot est refuse.
         */
        $statusBefore = $order->status;

        $refund = DB::transaction(function () use ($order, $payment, $statusBefore): Refund {
            $this->orders->assertCanTransition($order, OrderStatus::REFUND_PENDING);

            return $this->refunds->create([
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'provider' => $this->gateways->default()->provider(),
                'amount' => $payment->amount,
                'currency' => $order->currency,
                'status' => RefundStatus::PENDING,
                'requested_at' => now(),
                'order_status_before' => $statusBefore->value,
            ]);
        });

        /*
         * La commande passe en attente avant l'appel, et non apres : si l'API
         * meurt entre les deux, elle reste bloquee en refund_pending et se
         * retrouve au guichet plutot que d'etre servie alors qu'une restitution
         * est en cours. L'etat separe « demande » de « faite », il doit donc
         * dire « demande » avant meme que la demande soit partie.
         */
        $order->update(['status' => OrderStatus::REFUND_PENDING]);

        $this->send($order, $refund);

        return $refund->refresh();
    }

    /**
     * L'operateur confirme la sortie d'argent.
     *
     * Appelee par la notification de depot. Elle ne fait rien si la demande
     * est deja close : FedaPay notifie une fois, mais un rejeu, une relance ou
     * une double remise ne doit pas rendre deux fois le stock.
     */
    public function settle(Refund $refund): Refund
    {
        if (! $refund->hasLeft()) {
            $refund->update([
                'status' => RefundStatus::SETTLED,
                'settled_at' => now(),
            ]);

            $this->orders->markRefunded($refund->order()->firstOrFail());
        }

        return $refund->refresh();
    }

    /**
     * L'operateur refuse le depot : l'argent n'est pas sorti.
     *
     * La commande redevient payee et le stock reste en rayon. C'est la raison
     * d'etre de l'etat intermediaire : sans lui, un depot refuse se
     * distinguerait d'un depot effectue, et le stand devrait choisir entre
     * garder une marchandise deja livree et l'encasser deux fois.
     */
    public function fail(Refund $refund, ?string $reason = null): Refund
    {
        if ($refund->status->isOpen()) {
            $refund->update([
                'status' => RefundStatus::FAILED,
                'failure_reason' => $reason,
                'failed_at' => now(),
            ]);

            /*
             * Le paiement redevient encaisse : il l'a toujours ete, le depot
             * ayant echoue. Sans cette ecriture, un guichet qui filtre sur les
             * paiements aboutis ne retrouverait plus la commande.
             */
            $refund->payment()->firstOrFail()->update(['status' => PaymentStatus::SUCCESS]);

            $this->orders->markRefundFailed(
                $refund->order()->firstOrFail(),
                $refund->statusToRestore(),
            );
        }

        return $refund->refresh();
    }

    /**
     * Demande le depot a l'operateur, puis note la reference obtenue.
     *
     * L'appel se fait dehors, et sa reference est ecrite apres coup. Si l'appel
     * echoue, la demande reste en base avec son echec : la commande est alors
     * rendue a son etat paye, et le guichet peut reessayer — ce qui serait
     * impossible si la demande n'avait jamais ete enregistree.
     */
    private function send(Order $order, Refund $refund): void
    {
        try {
            $result = $this->gateways->default()->payOut($order);
        } catch (ApiException $exception) {
            /*
             * L'appel a echoue avant d'avoir produit quoi que ce soit. La
             * demande est close en echec et la commande rendue, parce qu'un
             * client dont le remboursement a ete refuse ne doit pas attendre
             * indefiniment un depot qui n'a jamais ete emis.
             */
            $this->fail($refund, $exception->errorCode());

            throw $exception;
        }

        try {
            $result->ensureAccepted();
        } catch (ApiException $exception) {
            /*
             * Meme traitement qu'un appel refuse : une reponse sans reference ne
             * peut pas etre suivie, donc aucune notification ne ramènera jamais
             * cette demande a son terme. La laisser ouverte condamnerait la
             * commande a attendre indefiniment un depot dont personne ne
             * connaîtra l'issue.
             */
            $this->fail($refund, $exception->errorCode());

            throw $exception;
        }

        $refund->update(['payout_reference' => $result->reference]);

        /*
         * L'operateur peut confirmer dans la seconde — certains operateurs
         * traitent un depot immediatement. Le service n'attend pas la
         * notification s'il a deja la reponse : l'appliquer maintenant evite de
         * laisser une commande en attente alors que l'argent est parti.
         */
        if (self::isSettledStatus($result->status)) {
            $this->settle($refund->refresh());
        } elseif (self::isFailedStatus($result->status)) {
            $this->fail($refund->refresh());
        }

        Log::info('Demande de restitution enregistree.', [
            'refund_reference' => $refund->uuid,
            'order_number' => $order->order_number,
            'payout_reference' => $result->reference,
            'operator_status' => $result->status,
        ]);
    }

    /**
     * Le paiement regle qui sera restitue.
     *
     * La ligne de paiement est cherchee par sa date de reglement et non par
     * « la derniere » : c'est la seule preuve bancaire, et un ordre de creation
     * designerait parfois une tentative qui n'a jamais abouti.
     */
    private function paidPayment(Order $order): Payment
    {
        $payment = $order->payments()->whereNotNull('paid_at')->latest('paid_at')->first();

        if ($payment === null) {
            throw new ApiException(
                'Cette commande n’a aucun paiement confirmé à rembourser.',
                422,
                'ORDER_NOT_REFUNDABLE',
                ['order' => $order->uuid],
            );
        }

        return $payment;
    }

    /**
     * L'operateur a-t-il sorti l'argent ?
     *
     * Les trois libelles sont acceptes parce que l'operatoire est le sien et
     * que nous ne le controlons pas : reconnaitre trois facons de dire la meme
     * chose vaut mieux que refuser une restitution reellement effectuee.
     */
    private static function isSettledStatus(string $status): bool
    {
        return in_array(strtolower($status), ['processed', 'succeeded', 'success'], true);
    }

    /**
     * L'operateur a-t-il refuse le depot ?
     */
    private static function isFailedStatus(string $status): bool
    {
        return in_array(strtolower($status), ['failed', 'rejected', 'cancelled', 'canceled'], true);
    }
}
