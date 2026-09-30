<?php

namespace Tests\Unit\Support\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use App\Support\Payments\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * La seule defense de la route de notification.
 *
 * Cette route n'a ni session ni jeton : si la signature est mal verifiee, une
 * personne tierce peut marquer n'importe quelle commande comme payee.
 * Les tests ci-dessous couvrent donc ce qui fait tenir la porte, y compris le
 * rejet d'une signature qui n'est pas la bonne.
 */
class WebhookSignatureVerifierTest extends TestCase
{
    private const SECRET = 'secret-partage-avec-l-operateur';

    private WebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('orders.webhooks.fedapay.secret', self::SECRET);

        $this->verifier = new WebhookSignatureVerifier;
    }

    public function test_it_accepts_a_notification_signed_with_the_shared_secret(): void
    {
        $payload = ['status' => 'success'];

        $this->verifier->verify(
            $this->request($payload, ['x-payment-signature' => $this->sign($payload)]),
            PaymentProvider::FEDAPAY,
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_it_reads_the_signature_from_any_of_the_standard_headers(): void
    {
        $payload = ['status' => 'success'];
        $signature = $this->sign($payload);

        foreach (['x-payment-signature', 'x-signature', 'x-hub-signature-256'] as $header) {
            $this->verifier->verify(
                $this->request($payload, [$header => $signature]),
                PaymentProvider::FEDAPAY,
            );
        }

        $this->assertTrue(true, 'Les trois en-tetes doivent etre acceptes.');
    }

    public function test_it_accepts_a_signature_prefixed_with_its_algorithm(): void
    {
        $payload = ['status' => 'success'];

        $this->verifier->verify(
            $this->request($payload, ['x-signature' => 'sha256='.$this->sign($payload)]),
            PaymentProvider::FEDAPAY,
        );

        $this->assertTrue(true);
    }

    public function test_it_refuses_a_notification_with_no_signature(): void
    {
        $this->expectExceptionCode(401);

        $this->verifier->verify($this->request(['status' => 'success']), PaymentProvider::FEDAPAY);
    }

    public function test_it_refuses_a_signature_made_with_another_secret(): void
    {
        $body = json_encode(['status' => 'success']);

        $this->expectExceptionCode(401);

        $this->verifier->verify(
            $this->request(
                ['status' => 'success'],
                ['x-payment-signature' => hash_hmac('sha256', $body, 'mauvais-secret')],
            ),
            PaymentProvider::FEDAPAY,
        );
    }

    public function test_it_refuses_a_signature_of_another_body(): void
    {
        /*
         * Une signature valide d'une ancienne notification, rejouee sur un corps
         * different : c'est la tentative la plus simple de faire passer une commande
         * deja payee.
         */
        $signature = hash_hmac('sha256', '{"reference":"autre","status":"success"}', self::SECRET);

        $this->expectExceptionCode(401);

        $this->verifier->verify(
            $this->request(['reference' => 'cible', 'status' => 'success'], ['x-payment-signature' => $signature]),
            PaymentProvider::FEDAPAY,
        );
    }

    public function test_it_refuses_a_signature_that_is_only_a_prefix_of_the_valid_one(): void
    {
        $body = json_encode(['status' => 'success']);
        $valid = hash_hmac('sha256', $body, self::SECRET);

        $this->expectExceptionCode(401);

        $this->verifier->verify(
            $this->request(['status' => 'success'], ['x-payment-signature' => substr($valid, 0, 40)]),
            PaymentProvider::FEDAPAY,
        );
    }

    public function test_it_refuses_a_signature_made_for_another_provider(): void
    {
        $body = json_encode(['status' => 'success']);

        config()->set('orders.webhooks.kkiapay.secret', 'autre-secret');

        $this->expectExceptionCode(401);

        $this->verifier->verify(
            $this->request(
                ['status' => 'success'],
                ['x-payment-signature' => hash_hmac('sha256', $body, 'autre-secret')],
            ),
            PaymentProvider::FEDAPAY,
        );
    }

    public function test_it_refuses_everything_when_the_secret_is_not_configured(): void
    {
        config()->set('orders.webhooks.fedapay.secret', null);

        $this->expectExceptionCode(503);

        $this->verifier->verify($this->request(['status' => 'success']), PaymentProvider::FEDAPAY);
    }

    public function test_the_error_does_not_say_which_part_failed(): void
    {
        try {
            $this->verifier->verify($this->request(['status' => 'success']), PaymentProvider::FEDAPAY);
        } catch (ApiException $exception) {
            $this->assertStringNotContainsStringIgnoringCase('header', $exception->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('sha256', $exception->getMessage());

            return;
        }

        $this->fail('Une signature absente aurait du etre refusee.');
    }

    /**
     * Signe un corps exactement comme le ferait l'operateur.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), self::SECRET);
    }

    /**
     * Requete webhook brute, avec les en-tetes demandes.
     *
     * Le corps est construit a la main parce que c'est lui qui est signe : le
     * verifier ne doit pas voir une forme reecrite du JSON.
     *
     * @param  array<string, string>  $headers
     */
    private function request(array $payload, array $headers = []): Request
    {
        $body = json_encode($payload);

        return Request::create(
            '/api/v1/payments/webhook/fedhq',
            'POST',
            [],
            [],
            [],
            $this->toServerHeaders($headers),
            $body,
        );
    }

    /**
     * Convertit des en-tetes lisibles en en-tetes de serveur.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function toServerHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }

        return $server;
    }
}
