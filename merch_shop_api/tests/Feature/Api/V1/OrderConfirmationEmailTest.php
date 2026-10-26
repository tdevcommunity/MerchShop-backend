<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La confirmation de commande envoyee par email apres paiement.
 *
 * Le paiement est le seul moment ou l'API sait que la commande est reelle :
 * c'est la seule occasion ou une confirmation a du sens, et le seul moment ou
 * l'adresse du client compte vraiment. Trois points meritent d'etre verifies
 * hors du tunnel complet :
 *
 *  - l'adresse : celle saisie a la commande prime, celle du compte sert de
 *    repli, et une commande invitee sans aucun canal ne doit rien envoyer
 *    sans empecher le paiement de passer ;
 *
 *  - la piece jointe : un QR pour un retrait, rien pour une livraison — un QR
 *    ne designe aucun droit sur une commande qui n'a pas de retrait ;
 *
 *  - le declenchement : le job part du webhook, dans sa transaction, et une
 *    notification rejouee ne doit pas produire un second email.
 *
 * La file tourne en sync sous PHPUnit et la boite est faxee par le TestCase de
 * base : le job s'execute donc dans le test mais n'envoie et ne rend rien —
 * l'envoi est enregistre et relu par `Mail::assertSent()`.
 */
class OrderConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    /*
     * Destinataire.
     */

    public function test_it_addresses_the_confirmation_to_the_order_email(): void
    {
        $order = $this->confirm($this->createOrder(customerEmail: 'awa@example.com'));

        Mail::assertSent(
            OrderConfirmationMail::class,
            fn (OrderConfirmationMail $mail): bool => $mail->hasTo('awa@example.com')
                && str_contains($mail->envelope()->subject, $order->order_number),
        );
    }

    public function test_it_falls_back_to_the_account_email_when_the_order_carries_none(): void
    {
        /*
         * L'adresse saisie a la commande est une copie figee : c'est elle qui
         * doit partir. Celle du compte reste un canal valable quand la
         * commande n'en porte pas — le cas d'un client qui a donne son email
         * a l'inscription puis qui commande sans le ressaisir.
         */
        $customer = User::factory()->create(['email' => 'client@exemple.td']);
        $order = $this->confirm($this->createOrder(user: $customer));

        $this->assertNull($order->customer_email);

        Mail::assertSent(
            OrderConfirmationMail::class,
            fn (OrderConfirmationMail $mail): bool => $mail->hasTo($customer->email),
        );
    }

    public function test_it_sends_nothing_when_no_address_exists_anywhere(): void
    {
        /*
         * Une commande invitee sans email n'a aucun canal : rien n'est du, et
         * surtout rien ne doit echouer. Le paiement doit passer quand meme —
         * le client a paye, seul l'envoi est impossible.
         */
        $order = $this->confirm($this->createOrder());

        $this->assertSame(OrderStatus::PAID, $order->refresh()->status);
        Mail::assertNothingSent();
    }

    /*
     * Piece jointe.
     */

    public function test_it_confirms_a_delivery_order_without_a_qr(): void
    {
        $order = $this->confirm(
            $this->createOrder(customerEmail: 'awa@example.com', fulfillmentMethod: 'delivery'),
        );

        Mail::assertSent(
            OrderConfirmationMail::class,
            fn (OrderConfirmationMail $mail): bool => $mail->hasTo('awa@example.com'),
        );

        $mail = Mail::sent(OrderConfirmationMail::class)->firstOrFail();

        /*
         * Les deux chemins qui aboutissent au QR — la piece jointe et l'image
         * embarquee — doivent se terminer sur rien : un QR de retrait au sein
         * d'une commande livree designerait un droit qui n'existe pas. Le
         * rendu est epreuve du gabarit lui-meme : une erreur de Blade ne se
         * verrait sinon que sur un envoi reel.
         */
        $this->assertSame([], $mail->attachments());
        $this->assertNull($mail->content()->with['qrInline']);

        $html = $mail->render();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Livraison', $html);
        $this->assertStringContainsString('Cocody', $html);
        $this->assertStringNotContainsString('QR Code de retrait', $html);
    }

    public function test_it_attaches_the_pickup_qr_to_the_confirmation(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped("L'extension GD est requise pour rendre le QR en PNG.");
        }

        $order = $this->confirm($this->createOrder(customerEmail: 'awa@example.com'));

        Mail::assertSent(OrderConfirmationMail::class);

        $mail = Mail::sent(OrderConfirmationMail::class)->firstOrFail();

        $this->assertCount(1, $mail->attachments());

        /*
         * Le meme QR figure aussi dans le corps : la piece jointe sert a
         * l'enregistrer, l'embarque permet de le presenter au stand sans ouvrir
         * une piece jointe sur un telephone.
         */
        $html = $mail->render();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('QR Code de retrait', $html);
    }

    /*
     * Saisie de l'adresse.
     */

    public function test_it_accepts_a_customer_email_on_order_creation(): void
    {
        $variant = Variant::factory()->withStock(5)->create();

        $this->postJson('/api/v1/orders', $this->payload($variant, 'awa@example.com'))
            ->assertCreated()
            ->assertJsonPath('data.customerEmail', 'awa@example.com');

        $this->assertSame(
            'awa@example.com',
            Order::query()->latest('id')->firstOrFail()->customer_email,
        );
    }

    public function test_it_refuses_an_invalid_customer_email(): void
    {
        /*
         * Une adresse erronee donnerait un envoi en echec apres paiement —
         * le moment ou il n'est plus possible de la corriger. Le refus a la
         * creation est le seul endroit ou il est encore temps.
         */
        $variant = Variant::factory()->withStock(5)->create();

        $this->postJson('/api/v1/orders', $this->payload($variant, 'awa@'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['customer_email']]]]);
    }

    /*
     * Outillage.
     */

    /**
     * Commande, avec les accessoires de la mise en paiement.
     */
    private function createOrder(
        ?User $user = null,
        ?string $customerEmail = null,
        string $fulfillmentMethod = 'pickup',
    ): Order {
        $variant = Variant::factory()->withStock(10)->create(['price' => 2500]);

        return app(OrderService::class)->create([
            'user' => $user,
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => $fulfillmentMethod,
            'shipping_address' => $fulfillmentMethod === 'delivery' ? 'Cocody, Abidjan' : null,
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
            'customer_email' => $customerEmail,
            'participant_id' => null,
        ]);
    }

    /**
     * Regle la commande comme payee, par le chemin reel du service.
     *
     * C'est la meme methode que suit le webhook apres la signature, a une
     * difference pres : ici l'enveloppe HTTP saute. Toute la suite — facture,
     * QR, confirmation — reste donc le code reel, et non une reproduction.
     */
    private function confirm(Order $order): Order
    {
        return app(PaymentService::class)->handleNotification([
            'reference' => $order->payments()->firstOrFail()->uuid,
            'transaction_id' => 'FED-TEST-1',
            'status' => PaymentStatus::SUCCESS,
            'amount' => $order->total,
        ]);
    }

    /**
     * Charge utile minimale d'une commande de retrait.
     *
     * @return array<string, mixed>
     */
    private function payload(Variant $variant, ?string $customerEmail = null): array
    {
        return [
            'items' => [['uuid' => $variant->uuid, 'quantity' => 1]],
            'fulfillment_method' => 'pickup',
            'payment_method' => 'mobile_money',
            'customer_name' => 'Awa Diallo',
            'customer_phone_number' => '90123456',
            'customer_email' => $customerEmail,
        ];
    }
}
