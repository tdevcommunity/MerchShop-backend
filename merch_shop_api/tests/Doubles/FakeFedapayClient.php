<?php

namespace Tests\Doubles;

use FedaPay\HttpClient\ClientInterface;

/**
 * Client HTTP du SDK FedaPay, remplace en test.
 *
 * Le SDK lit son client dans une variable statique et ne permet pas de lui
 * injecter une instance par le conteneur : sans ce double, tester la passerelle
 * obligerait a appeler le vrai service, donc a payer.
 *
 * Il enregistre les appels et repond par des corps prepares, ce qui permet
 * d'affirmer ce que la passerelle envoie — le seul endroit ou l'on peut encore
 * verifier qu'aucune valeur client n'atteint l'operateur.
 *
 * Note sur l'ordre des arguments : l'interface du SDK annonce
 * `($headers, $params)`, mais son `Requestor` appelle en realite
 * `request($method, $url, $params, $headers)`. Le double suit l'appel reel, sans
 * quoi il verifierait autre chose que ce que FedaPay recoit.
 */
final class FakeFedapayClient implements ClientInterface
{
    /** Un appel par requete, dans l'ordre. */
    public array $calls = [];

    /**
     * @param  array<string, array{body: array<string, mixed>, code?: int}>  $routes  Reponse par motif de chemin.
     */
    public function __construct(
        private array $routes,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $headers
     * @return array{0: string, 1: int, 2: array<string, string>}
     */
    public function request($method, $absUrl, $params, $headers): array
    {
        $path = parse_url($absUrl, PHP_URL_PATH) ?: '';

        $this->calls[] = [
            'method' => $method,
            'url' => $absUrl,
            'path' => $path,
            'params' => $params ?? [],
            'headers' => $headers ?? [],
        ];

        foreach ($this->routes as $pattern => $route) {
            if ($this->matches($pattern, $path)) {
                return [
                    json_encode($route['body'], JSON_THROW_ON_ERROR),
                    $route['code'] ?? 200,
                    ['content-type' => 'application/json'],
                ];
            }
        }

        // Une route non prevue doit se voir plutot que de repondre 200 a vide :
        // un double qui inventerait une reponse ferait passer un appel que
        // l'operateur, lui, aurait refuse.
        return [
            json_encode(['message' => 'Route non simulee : '.$path], JSON_THROW_ON_ERROR),
            404,
            ['content-type' => 'application/json'],
        ];
    }

    /**
     * La derniere requete dont le chemin correspond.
     *
     * @return array<string, mixed>
     */
    public function lastCallTo(string $pattern): array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($this->matches($pattern, $call['path'])) {
                return $call;
            }
        }

        return [];
    }

    private function matches(string $pattern, string $path): bool
    {
        return (bool) preg_match($pattern, $path);
    }
}
