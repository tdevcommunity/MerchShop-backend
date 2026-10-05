<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\Payments\FedapayEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Notifications FedaPay.
 *
 * Cette route est la seule porte d'entree qui decide qu'une commande est payee,
 * et elle est appelable sans session. Chaque test porte donc sur une consequence
 * de securite : ce qui ne vient pas de FedaPay n'ecrit rien, ce qui vient de
 * FedaPay deux fois n'ecrit qu'une fois, et ce que FedaPay n'a pas dit n'est
 * jamais devine.
 */
class FedapayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-webhook-fedapay-de-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('orders.webhooks.fedapay.secret', self::SECRET);
    }

    /*
     * Identification de la notification.
     */

    public function test_it_pays_the_order_when_fedapay_approves_the_transaction(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->approved($payment))
            ->assertOk()
            ->assertJsonPath('data.orderUuid', $order->uuid)
            ->assertJsonPath('data.status', OrderStatus::PAID->value);

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentStatus::SUCCESS, $payment->refresh()->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_it_accepts_a_transferred_transaction_as_a_settlement(): void
    {
        /*
         * FedaPay compte `transferred` parmi ses etats payes : c'est le meme
         * paiement, vu apres le transfert bancaire. Le traiter comme un simple
         * changement d'etat ferait dependre l'ouverture du droit de retrait du
         * moment ou l'argent arrive sur le compte de l'operateur.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.updated', $payment, 'transferred'))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_issues_one_invoice_however_many_times_fedapay_repeats_itself(): void
    {
        /*
         * FedaPay rejoue toute notification restee en erreur, et un simple delai de
         * notre reponse en suffit a provoquer une. Deux confirmations doivent
         * donc produire une facture, pas deux : le double comptage se verrait
         * ensuite dans les bilans.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();
        $body = $this->approved($payment);

        $this->send($body)->assertOk();
        $this->send($body)->assertOk();
        $this->send($body)->assertOk();

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        $this->assertSame(PaymentStatus::SUCCESS, $payment->refresh()->status);
    }

    public function test_it_reads_the_transaction_under_the_object_key_as_well(): void
    {
        /*
         * La documentation lit `$event->data`, la classe `Event` du SDK declare
         * une propriete `object`. Ne lire que l'une des deux ferait perdre tout
         * paiement le jour ou FedaPay s'aligne sur l'autre.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->approved($payment, envelopeKey: 'object'))->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_reads_the_reference_from_a_metadata_field_named_the_other_way(): void
    {
        /*
         * FedaPay accepte `custom_metadata` a la creation mais decrit la
         * transaction restituee avec `metadata`. Le SDK et la documentation ne
         * tranchent pas, donc les deux noms sont lus : s'en tenir a un seul ferait
         * perdre le rapprochement principal — silencieusement, en retombant sur la
         * reference operateur, qui n'existe qu'apres le premier envoi.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.approved', $payment, 'approved', metadataKey: 'metadata'))
            ->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_finds_the_payment_by_the_operator_reference_when_metadata_is_lost(): void
    {
        /*
         * Notre reference interne voyage dans les metadonnees. Si l'operateur
         * cesse de les renvoyer, la notification ne doit pas devenir
         * inexploitable : la reference qu'il a attribuee a la transaction est
         * ecrite au checkout et sert alors de repli.
         */
        $order = $this->pendingOrder(checkoutDone: true);
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.approved', $payment, 'approved', withMetadata: false))
            ->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    /*
     * Refus et evenements sans effet.
     */

    public function test_it_records_a_failure_without_paying_the_order(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.updated', $payment, 'declined'))
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::PENDING_PAYMENT->value);

        $payment->refresh();

        $this->assertSame(PaymentStatus::FAILED, $payment->status);
        $this->assertNotNull($payment->failed_at, 'Un abandon et un refus doivent rester distinguables.');
        $this->assertNull($payment->paid_at);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_treats_a_cancellation_as_a_failure(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.updated', $payment, 'canceled'))->assertOk();

        $this->assertSame(PaymentStatus::FAILED, $payment->refresh()->status);
    }

    public function test_it_acknowledges_a_transaction_still_pending_without_writing_anything(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.created', $payment, 'pending'))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(PaymentStatus::PENDING, $payment->refresh()->status);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_acknowledges_a_refund_event_without_writing_anything(): void
    {
        /*
         * Un remboursement n'est pas un paiement : il rend le stock et retire le
         * droit de retrait. Ce sont deux consequences qu'un evenement ne peut pas
         * porter, et le back-office reste seul juge. L'acquitter avec un 200 evite
         * surtout que FedaPay le rejoue sans fin.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.refunded', $payment, 'refunded'))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(PaymentStatus::PENDING, $payment->refresh()->status);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_acknowledges_an_event_that_is_not_about_a_transaction(): void
    {
        /*
         * FedaPay emet des evènements sur d'autres objets. Les traiter comme des
         * paiements les rapprocherait d'une commande au hasard.
         */
        $order = $this->pendingOrder();

        $this->send(json_encode([
            'id' => 987,
            'type' => 'payout.created',
            'data' => ['id' => 55, 'status' => 'approved', 'amount' => 1000],
        ], JSON_THROW_ON_ERROR))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_status_it_does_not_know(): void
    {
        /*
         * Un etat inconnu n'est ni un succes ni un refus. Le traiter comme un
         * refus ferait passer une transaction reussie pour un echec ; l'ignorer
         * laisserait une commande payee en attente. Il est donc refuse, ce qui le
         * rend visible et laisse le temps d'etendre le vocabulaire.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.updated', $payment, 'in_mediation'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNKNOWN_FEDAPAY_STATUS');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_carrying_an_amount_that_does_not_exist(): void
    {
        /*
         * 2500,50 FCFA n'existe pas : le franc CFA n'a pas de subdivision. Ce
         * montant ne decrit donc aucune somme de cette devise — il annonce autre
         * chose. L'ignorer en le tronquant accepterait une notification dont
         * personne n'a confirme le montant, et le proprietaire du chiffre verrait
         * sa commande payee pour une somme qui n'a jamais ete encaissee.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.approved', $payment, 'approved', amount: '2500.50'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEDAPAY_MALFORMED_EVENT');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_whose_amount_is_not_the_one_of_the_order(): void
    {
        /*
         * Un montant entier mais different est un desaccord sur la somme, pas une
         * notification malformee : c'est ce qui distingue « FedaPay parle mal »
         * de « FedaPay parle d'un autre paiement ».
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->event('transaction.approved', $payment, 'approved', amount: 999999))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PAYMENT_AMOUNT_MISMATCH');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_about_a_payment_it_does_not_know(): void
    {
        $this->send(json_encode([
            'id' => 1,
            'type' => 'transaction.approved',
            'data' => [
                'id' => 4242,
                'reference' => 'FEDAPAY-INCONNUE',
                'status' => 'approved',
                'amount' => 2500,
                'custom_metadata' => [FedapayEvent::PAYMENT_REFERENCE_KEY => 'uuid-inconnu'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PAYMENT_NOT_FOUND');
    }

    public function test_it_refuses_a_body_that_is_not_json(): void
    {
        $this->send('ceci n est pas du json')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEDAPAY_MALFORMED_EVENT');
    }

    public function test_it_refuses_an_event_without_a_transaction_status(): void
    {
        $this->send(json_encode([
            'id' => 1,
            'type' => 'transaction.approved',
            'data' => ['id' => 1, 'reference' => 'FED-1'],
        ], JSON_THROW_ON_ERROR))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FEDAPAY_MALFORMED_EVENT');
    }

    /*
     * Origine de la requete.
     */

    public function test_it_refuses_a_notification_without_a_signature(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->sendUnsigned($this->approved($payment))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_signed_with_the_wrong_secret(): void
    {
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->approved($payment), secret: 'mauvais-secret')->assertUnauthorized();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_the_signature_scheme_of_the_other_operators(): void
    {
        /*
         * FedaPay signe `<horodatage>.<corps>`. Le chemin generique, lui, signe
         * le corps seul et attend un autre en-tete. Faire passer l'un pour l'autre
         * viderait la verification de l'un des deux.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();
        $body = $this->approved($payment);

        $this->postJson('/api/v1/payments/webhooks/fedapay', json_decode($body, true), [
            'X-Payment-Signature' => hash_hmac('sha256', $body, self::SECRET),
        ])->assertUnauthorized();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_body_that_does_not_match_its_signature(): void
    {
        /*
         * La signature porte sur le corps. Le changer apres signature — un
         * montant modifie, un intermediaire qui recompose la requete — doit se
         * voir.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->deliver(
            $this->approved($payment),
            header: $this->sign(
                $this->event('transaction.approved', $payment, 'approved', amount: 999999),
            ),
        )->assertUnauthorized();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_refuses_a_notification_replayed_outside_the_tolerance_window(): void
    {
        /*
         * Une capture d'une notification valide reste rejouable tant que sa
         * signature est bonne. C'est l'horodatage, signe lui aussi, qui borne la
         * duree de ce rejeu.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->approved($payment), timestamp: time() - 3600)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    public function test_it_accepts_a_notification_delayed_by_an_ordinary_clock_gap(): void
    {
        /*
         * FedaPay rattrape ses envois manquants pendant plusieurs minutes. Une
         * fenetre trop courte ferait perdre un paiement, et faire payer
         * l'acheteur pour une panne de notre cote.
         */
        config()->set('orders.webhooks.fedapay.tolerance', 3600);

        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->approved($payment), timestamp: time() - 1800)->assertOk();

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
    }

    public function test_it_refuses_everything_when_no_secret_is_configured(): void
    {
        /*
         * Sans secret, la seule facon de repondre 200 serait de cesser de
         * verifier : la route resterait ouverte a quiconque en trouve l'adresse.
         * Elle doit donc se fermer, y compris sur le trafic de l'operateur.
         */
        config()->set('orders.webhooks.fedapay.secret', null);

        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->send($this->approved($payment))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'PAYMENT_PROVIDER_NOT_CONFIGURED');

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->refresh()->status);
    }

    /*
     * Outillage.
     */

    /**
     * Envoie un corps signe comme FedaPay le ferait.
     *
     * La signature porte sur les octets exacts recus. Passer par `postJson()`
     * ferait encoder le tableau une seconde fois, et le corps signe ne
     * correspondrait plus au corps envoye : le test validerait une requete que
     * FedaPay n'enverrait jamais.
     *
     * @param  string|null  $header  Signe le corps si null.
     */
    private function deliver(string $body, ?string $header = null, int $timestamp = 0): TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/payments/webhooks/fedapay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_FEDAPAY_SIGNATURE' => $header ?? $this->sign($body, self::SECRET, $timestamp ?: time()),
            ],
            $body,
        );
    }

    /**
     * Envoie un corps correctement signe.
     */
    private function send(string $body, string $secret = self::SECRET, ?int $timestamp = null): TestResponse
    {
        return $this->deliver($body, $this->sign($body, $secret, $timestamp ?? time()));
    }

    /**
     * Envoie un corps sans aucune signature.
     */
    private function sendUnsigned(string $body): TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/payments/webhooks/fedapay',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body,
        );
    }

    /**
     * En-tete `X-FEDAPAY-SIGNATURE`, signe sur `<horodatage>.<corps>`.
     */
    private function sign(string $payload, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    private function approved(Payment $payment, string $envelopeKey = 'data'): string
    {
        return $this->event('transaction.approved', $payment, 'approved', envelopeKey: $envelopeKey);
    }

    /**
     * Corps d'evenement FedaPay, sous la forme de sa documentation.
     */
    private function event(
        string $type,
        Payment $payment,
        string $status,
        ?string $amount = null,
        bool $withMetadata = true,
        string $envelopeKey = 'data',
        string $metadataKey = 'custom_metadata',
    ): string {
        $transaction = [
            'id' => 9001,
            'reference' => $payment->transaction_id ?? 'FEDAPAY-9001',
            'amount' => $amount ?? $payment->amount,
            'currency_id' => 'XOF',
            'status' => $status,
        ];

        if ($withMetadata) {
            $transaction[$metadataKey] = [FedapayEvent::PAYMENT_REFERENCE_KEY => $payment->uuid];
        }

        return json_encode([
            'id' => 1,
            'type' => $type,
            $envelopeKey => $transaction,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Commande en attente de paiement, dont le checkout a deja ete joue ou non.
     *
     * Le checkout ecrit la reference operateur sur la ligne de paiement : c'est
     * elle qui permet de rattraper une notification sans metadonnees.
     */
    private function pendingOrder(bool $checkoutDone = false): Order
    {
        $variant = Variant::factory()->withStock(20)->create(['price' => '2500']);

        $order = app(OrderService::class)->create([
            'user' => User::factory()->create(),
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'shipping_address' => null,
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '0707070707',
            'participant_id' => null,
        ]);

        if ($checkoutDone) {
            $order->payments()->firstOrFail()->update(['transaction_id' => 'FEDAPAY-9001']);
        }

        return $order;
    }
}
