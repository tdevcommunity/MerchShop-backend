<?php

namespace Tests\Feature\Services\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\FedapayGateway;
use App\Services\Payments\PaymentIntent;
use FedaPay\HttpClient\CurlClient;
use FedaPay\Requestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Doubles\FakeFedapayClient;
use Tests\TestCase;

/**
 * Ce que la passerelle FedaPay dit a l'operateur.
 *
 * La passerelle est le seul endroit ou une somme d'argent quitte notre base. Ce
 * qui n'y entre pas ne peut pas etre verifie apres coup, donc les tests
 * affirmants ce qui est envoye valent autant que ceux qui affirment ce qui est
 * refuse — et le rapprochement par metadonnees, sur lequel repose toute la
 * reconciliation des paiements, se verifie ici ou nulle part.
 */
class FedapayGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCE = 'FEDAPAY-9001';

    /** Fiche client creee par l'operateur, telle que la simulee. */
    private const CUSTOMER_ID = 4711;

    private FakeFedapayClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.fedapay.secret_key', 'cle-de-test');
        config()->set('services.fedapay.environment', 'sandbox');

        /*
         * Le double est pose avant tout test, pas appele par chacun d'eux : un
         * test qui oublierait de le poser partirait un vrai appel vers le
         * sandbox, donc creerait une vraie transaction avec la cle du
         * developpement. Une suite de tests ne doit jamais pouvoir payer.
         */
        $this->installClient();
    }

    protected function tearDown(): void
    {
        // Le client du SDK est statique : sans cette remise en place, le double
        // resterait actif pour le reste de la suite.
        Requestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    public function test_it_sends_the_amount_of_the_order_and_our_reference_in_the_metadata(): void
    {
        $client = $this->fakeClient();
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $this->gateway()->initiate($payment, 'https://boutique.exemple.test/retour');

        $call = $client->lastCallTo('#^/v\d+/transactions$#');

        $this->assertSame('post', $call['method']);
        $this->assertSame(2500, $call['params']['amount']);
        $this->assertSame('https://boutique.exemple.test/retour', $call['params']['callback_url']);
        $this->assertSame(['iso' => $order->currency], $call['params']['currency']);

        /*
         * C'est cette valeur, et elle seule, qui ramènera le webhook a la bonne
         * commande : FedaPay genere lui-meme sa reference, et la seule facon d'y
         * glisser un identifiant choisi par notre API est cette table.
         */
        $this->assertSame($payment->uuid, $call['params']['custom_metadata']['payment_uuid']);
        $this->assertSame($order->order_number, $call['params']['custom_metadata']['order_number']);
    }

    public function test_it_sends_nothing_that_the_client_chose(): void
    {
        /*
         * Le client choisit son moyen de paiement, pas la somme. Aucun champ
         * portant un montant, une devise ou une reference ne doit donc lui
         * etre transmis depuis la requete : le seules montants qui partent sont
         * ceux que la commande a etablie.
         */
        $client = $this->fakeClient();
        $payment = $this->pendingOrder()->payments()->firstOrFail();

        $this->gateway()->initiate($payment, 'https://boutique.exemple.test/retour');

        $sent = $client->lastCallTo('#^/v\d+/transactions$#')['params'];

        $this->assertSame(
            ['description', 'amount', 'currency', 'callback_url', 'customer', 'merchant_reference', 'custom_metadata'],
            array_keys($sent)
        );

        /*
         * La reference marchande porte le paiement, pas la commande.
         *
         * C'est elle que FedaPay indexe et rend requetable. Si elle portait le
         * numero de commande, une commande payee en deux fois ne pourrait plus
         * etre retrouvee chez l'operateur : la seconde tentative ecrase la
         * premiere, et le rapprochement ne dit plus laquelle des deux a ete
         * honoree.
         */
        $this->assertSame($payment->uuid, $sent['merchant_reference']);
    }

    public function test_it_returns_the_reference_that_the_notification_will_carry(): void
    {
        /*
         * FedaPay identifie une transaction par son identifiant numerique et par
         * la reference qu'il lui attribue ; la notification porte la reference.
         * Ecrire l'identifiant au checkout laisserait deux valeurs pour la meme
         * transaction, et le repli sur `transaction_id` — qui n'existe que pour
         * rattraper une notification sans metadonnees — echouerait sur la
         * premiere.
         */
        $order = $this->pendingOrder();
        $payment = $order->payments()->firstOrFail();

        $intent = $this->gateway()->initiate($payment, 'https://boutique.exemple.test/retour');

        $this->assertInstanceOf(PaymentIntent::class, $intent);
        $this->assertSame(self::REFERENCE, $intent->transactionId);
        $this->assertSame($payment->uuid, $intent->reference);
    }

    public function test_it_returns_the_address_the_buyer_has_to_pay_at(): void
    {
        $payment = $this->pendingOrder()->payments()->firstOrFail();

        $intent = $this->gateway()->initiate($payment, 'https://boutique.exemple.test/retour');

        $this->assertSame('https://paiement.exemple.test/jeton-9001', $intent->checkoutUrl);
        $this->assertSame('jeton-9001', $intent->token);
    }

    public function test_it_talks_to_the_environment_it_was_told_to_use(): void
    {
        /*
         * Une faute de frappe dans la configuration ne doit pas envoyer les
         * paiements d'un festival a une adresse d'un autre environnement.
         */
        config()->set('services.fedapay.environment', 'sandbox');

        $client = $this->fakeClient();

        $this->gateway()->initiate($this->pendingOrder()->payments()->firstOrFail(), 'https://boutique.exemple.test/retour');

        $this->assertStringStartsWith('https://sandbox-api.fedapay.com/', $client->calls[0]['url']);
    }

    public function test_it_refuses_to_call_the_operator_without_an_api_key(): void
    {
        config()->set('services.fedapay.secret_key', null);

        $client = $this->fakeClient();

        $this->expectExceptionCode(503);

        try {
            $this->gateway()->initiate($this->pendingOrder()->payments()->firstOrFail(), 'https://boutique.exemple.test/retour');
        } finally {
            $this->assertSame([], $client->calls, 'Aucun appel ne doit partir sans cle configuree.');
        }
    }

    public function test_it_refuses_an_environment_the_sdk_does_not_know(): void
    {
        /*
         * Le SDK retombe silencieusement sur une adresse vide pour une valeur
         * qu'il ne connait pas : la faute doit se voir ici, pas en production.
         */
        config()->set('services.fedapay.environment', 'productionn');

        $client = $this->fakeClient();

        $this->expectExceptionCode(503);

        try {
            $this->gateway()->initiate($this->pendingOrder()->payments()->firstOrFail(), 'https://boutique.exemple.test/retour');
        } finally {
            $this->assertSame([], $client->calls);
        }
    }

    public function test_it_reports_an_operational_failure_as_a_provider_outage(): void
    {
        $this->installClient([
            '#^/v\d+/transactions$#' => [
                'body' => ['message' => 'Amount must be an integer', 'code' => 'invalid_request'],
                'code' => 422,
            ],
        ]);

        $this->expectExceptionCode(502);

        $this->gateway()->initiate($this->pendingOrder()->payments()->firstOrFail(), 'https://boutique.exemple.test/retour');
    }

    public function test_it_rejects_a_token_response_without_a_checkout_url_or_token(): void
    {
        $this->installClient([
            '#^/v\d+/customers$#' => [
                'body' => ['customer' => ['klass' => 'v1/customer', 'id' => self::CUSTOMER_ID]],
            ],
            '#^/v\d+/transactions$#' => [
                'body' => [
                    'transaction' => [
                        'klass' => 'v1/transaction',
                        'id' => 9001,
                        'reference' => self::REFERENCE,
                    ],
                ],
            ],
            '#^/v\d+/transactions/9001/token$#' => [
                'body' => ['token' => null, 'url' => null],
            ],
        ]);

        $this->expectExceptionCode(502);

        $this->gateway()->initiate(
            $this->pendingOrder()->payments()->firstOrFail(),
            'https://boutique.exemple.test/retour',
        );
    }

    public function test_it_falls_back_to_the_operator_id_when_the_reference_is_missing(): void
    {
        $this->installClient([
            '#^/v\d+/customers$#' => [
                'body' => ['customer' => ['klass' => 'v1/customer', 'id' => self::CUSTOMER_ID]],
            ],
            '#^/v\d+/transactions$#' => [
                'body' => [
                    'transaction' => [
                        'klass' => 'v1/transaction',
                        'id' => 9001,
                    ],
                ],
            ],
            '#^/v\d+/transactions/9001/token$#' => [
                'body' => [
                    'token' => 'jeton-9001',
                    'url' => 'https://paiement.exemple.test/jeton-9001',
                ],
            ],
        ]);

        $intent = $this->gateway()->initiate(
            $this->pendingOrder()->payments()->firstOrFail(),
            'https://boutique.exemple.test/retour',
        );

        $this->assertSame('9001', $intent->transactionId);
    }

    public function test_checkout_persists_the_reference_used_by_a_metadata_free_webhook(): void
    {
        $order = $this->pendingOrder();

        config()->set('payments.return_url', 'https://boutique.exemple.test/retour');

        $payment = app(CheckoutService::class)->start($order);
        $payment->refresh();

        $this->assertSame(self::REFERENCE, $payment->transaction_id);
        $this->assertSame('https://paiement.exemple.test/jeton-9001', $payment->checkout_url);

        $resolvedOrder = app(PaymentService::class)->handleNotification([
            'reference' => null,
            'transaction_id' => self::REFERENCE,
            'status' => PaymentStatus::SUCCESS,
            'amount' => (string) $payment->amount,
        ]);

        $this->assertSame($order->id, $resolvedOrder->id);
        $this->assertSame(PaymentStatus::SUCCESS, $payment->refresh()->status);
    }

    public function test_it_declares_itself_as_the_provider_that_matched(): void
    {
        $this->assertSame(PaymentProvider::FEDAPAY, $this->gateway()->provider());
    }

    private function gateway(): FedapayGateway
    {
        return app(FedapayGateway::class);
    }

    private function fakeClient(): FakeFedapayClient
    {
        $this->installClient();

        return $this->client;
    }

    /**
     * Pose le double du SDK, avec les reponses d'un parcours reussi.
     */
    private function installClient(?array $routes = null): void
    {
        $this->client = new FakeFedapayClient($routes ?? [
            /*
             * La fiche client est creee avant la transaction, et l'identifiant
             * qu'elle renvoie est celui que la transaction portera.
             */
            '#^/v\d+/customers$#' => [
                'body' => [
                    'customer' => [
                        'klass' => 'v1/customer',
                        'id' => self::CUSTOMER_ID,
                        'firstname' => 'Awa',
                        'lastname' => 'Diallo',
                        'phone_number' => ['number' => '90123456', 'country' => 'tg'],
                    ],
                ],
            ],
            '#^/v\d+/transactions$#' => [
                'body' => [
                    'transaction' => [
                        // `klass` est ce qui permet au SDK de rendre un objet
                        // `Transaction` plutot qu'un objet generique. La reponse
                        // simulee le porte donc, comme le fait l'operateur.
                        'klass' => 'v1/transaction',
                        'id' => 9001,
                        'reference' => self::REFERENCE,
                        'status' => 'pending',
                        'amount' => 2500,
                        'currency_id' => 'XOF',
                    ],
                ],
            ],
            '#^/v\d+/transactions/9001/token$#' => [
                'body' => [
                    'token' => 'jeton-9001',
                    'url' => 'https://paiement.exemple.test/jeton-9001',
                ],
            ],
        ]);

        Requestor::setHttpClient($this->client);
    }

    private function pendingOrder(): Order
    {
        $variant = Variant::factory()->withStock(20)->create(['price' => '2500']);

        return app(OrderService::class)->create([
            'user' => User::factory()->create(),
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'shipping_address' => null,
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
            'participant_id' => null,
        ]);
    }
}
