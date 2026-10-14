<?php

namespace App\Support\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP Velto.
 *
 * Velto expose une API REST versionnee sous /v1. L'authentification se fait par
 * cle secrete Bearer (sk_sandbox_... ou sk_live_...), jamais exposee au
 * navigateur.
 */
final class VeltoClient
{
    public function createPayment(array $payload): array
    {
        return $this->post('/payments', $payload);
    }

    public function createWithdrawal(array $payload): array
    {
        return $this->post('/withdrawals', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $transactionId): array
    {
        return $this->request('GET', '/payments/'.rawurlencode($transactionId));
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $secret = $this->secretKey();

        if ($secret === '') {
            throw new ApiException(
                'Aucune cle API Velto configuree.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => PaymentProvider::VELTO->value],
            );
        }

        try {
            $client = $this->httpClient($secret);
            $url = $this->baseUrl().$path;
            $response = $method === 'GET'
                ? $client->get($url)
                : $client->post($url, $payload ?? []);
        } catch (RequestException $exception) {
            Log::warning('Appel Velto echoue.', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            throw new ApiException(
                'Le prestataire de paiement est indisponible. Reessayez dans un instant.',
                502,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::VELTO->value],
                $exception,
            );
        }

        if (! $response->successful()) {
            Log::warning('Appel Velto refuse.', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            if ($method === 'POST' && $this->isInvalidPhoneResponse($response)) {
                throw new ApiException(
                    'Ce numéro n’est pas valide pour Mobile Money. Utilise un mobile togolais de 8 chiffres commençant par 7 ou 9.',
                    422,
                    'INVALID_CUSTOMER_PHONE',
                    ['provider' => PaymentProvider::VELTO->value],
                );
            }

            throw new ApiException(
                'Le prestataire de paiement est indisponible. Reessayez dans un instant.',
                502,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::VELTO->value],
            );
        }

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? false) !== true || ! is_array($body['data'] ?? null)) {
            throw new ApiException(
                'Le prestataire de paiement a renvoye une reponse invalide.',
                502,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::VELTO->value],
            );
        }

        /** @var array<string, mixed> $data */
        $data = $body['data'];

        return $data;
    }

    private function httpClient(string $secret): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::withToken($secret)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        $origin = rtrim((string) config('services.velto.web_origin', ''), '/');
        if ($origin !== '') {
            $client = $client->withHeaders([
                'Origin' => $origin,
                'Referer' => $origin.'/',
            ]);
        }

        return $client;
    }

    private function baseUrl(): string
    {
        $configured = (string) config('services.velto.base_url', 'https://velto-gate.kiiako.io/v1');

        return rtrim($configured, '/');
    }

    private function secretKey(): string
    {
        return (string) config('services.velto.secret_key');
    }

    private function isInvalidPhoneResponse(\Illuminate\Http\Client\Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return false;
        }

        $errors = $body['errors'] ?? $body['details'] ?? null;
        if (! is_array($errors)) {
            return false;
        }

        foreach (array_keys($errors) as $key) {
            if (is_string($key) && str_contains($key, 'phone')) {
                return true;
            }
        }

        return false;
    }
}
