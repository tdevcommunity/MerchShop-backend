<?php

namespace App\Support\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * Verification de l'origine d'une notification d'agregateur.
 *
 * Un webhook est une ecriture publique : la route n'a ni session, ni jeton, et
 * ne peut pas l'avoir. Elle est donc securisee par une signature calculee sur le
 * corps de la requete avec un secret partage avec l'operateur.
 *
 * Ce service ne fait qu'une chose : dire si la requete vient bien de
 * l'operateur. Il ne touche pas a la base et ne modifie rien, pour que la
 * verification soit appelable avant toute ecriture, y compris dans un test.
 */
final class WebhookSignatureVerifier
{
    /**
     * En-tetes de signature inspectes, dans l'ordre.
     *
     * Chaque agregateur a sa propre convention, et aucun n'est garanti d'en
     * avoir une seule. Tous sont donc essaies, et le premier qui porte une
     * signature verifiee accepte la requete.
     *
     * @var array<int, string>
     */
    private const HEADERS = ['x-payment-signature', 'x-signature', 'x-hub-signature-256'];

    /**
     * Verifie qu'une requete provient bien de l'agregateur attendu.
     *
     * La comparaison est faite en temps constant : une comparaison ordinaire
     * s'arrete des le premier caractere different, ce qui permet de reconstituer
     * une signature valide caractere par caractere en chronometrant les rejets.
     */
    public function verify(Request $request, PaymentProvider $provider): void
    {
        $secret = $this->secretFor($provider);
        $payload = $request->getContent();

        foreach (self::HEADERS as $header) {
            $provided = $request->header($header);

            if (! is_string($provided) || $provided === '') {
                continue;
            }

            if (hash_equals($this->expected($payload, $secret), $this->normalise($provided))) {
                return;
            }
        }

        /*
         * Aucune signature acceptable. Le message ne distingue pas un en-tete
         * absent d'une signature fausse : les deux signifient la meme chose pour
         * l'appelant, qui doit recommencer de la meme facon.
         */
        throw new ApiException(
            'Signature de notification invalide.',
            401,
            'INVALID_WEBHOOK_SIGNATURE',
        );
    }

    /**
     * Le secret de l'agregateur, ou l'echec qui explique son absence.
     *
     * Un secret non renseigne ferme la route plutot que de l'ouvrir a tout le
     * monde : une configuration incomplete doit se voir au premier appel, et non
     * laisser passer des notifications que personne n'a signees.
     */
    private function secretFor(PaymentProvider $provider): string
    {
        $secret = (string) config('orders.webhooks.'.$provider->value.'.secret');

        if ($secret === '') {
            throw new ApiException(
                'Aucun secret configure pour cet agregateur de paiement.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => $provider->value],
            );
        }

        return $secret;
    }

    /**
     * Signature attendue pour un corps donne.
     */
    private function expected(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Ramene une signature fournie a sa seule valeur hexadecimale.
     *
     * Les agregateurs ne sont pas d'accord sur le format envoye : certains
     * prefixent l'algorithme (`sha256=...`), d'autres non. Le prefixe est donc
     * retire, et une signature prefixee reste verifiable.
     */
    private function normalise(string $signature): string
    {
        $value = trim($signature);

        if (str_contains($value, '=')) {
            $value = substr($value, strpos($value, '=') + 1);
        }

        return strtolower(trim($value));
    }
}
