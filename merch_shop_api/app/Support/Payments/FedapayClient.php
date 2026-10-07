<?php

namespace App\Support\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use FedaPay\FedaPay;
use Illuminate\Support\Facades\Log;

/**
 * La configuration du SDK FedaPay, posee avant chaque appel.
 *
 * Le SDK ne prend sa cle et son environnement qu'au niveau de sa classe
 * statique, sans quoi deux objets configurables independamment se
 * s'ecraseraient : c'est pourquoi cette classe est partagee par toutes les
 * passerelles FedaPay plutot que d'etre dupliquee dans chacune.
 *
 * Elle est posee a chaque appel et non au demarrage de l'application, parce
 * qu'un worker long-lived doit reflectir la configuration en cours apres un
 * redemarrage du serveur de configuration, sans que le processus ait besoin
 * d'etre arrete.
 */
final class FedapayClient
{
    /**
     * Les seuls environnements acceptes.
     *
     * Une liste explicite plutot qu'une verification de forme : une valeur
     * inexistante doit etre signalee comme une faute de deploiement, pas
     * transmise au SDK, qui construirait une adresse d'API qui n'existe pas.
     */
    private const ENVIRONMENTS = ['sandbox', 'test', 'development', 'production'];

    /**
     * Verifie la configuration puis la transmet au SDK.
     *
     * @throws ApiException si la configuration est absente ou inconnue.
     */
    public function configure(): void
    {
        $key = config('services.fedapay.secret_key');
        $environment = config('services.fedapay.environment');

        if (! is_string($key) || $key === '') {
            Log::error('Configuration FedaPay incomplete : FEDAPAY_SECRET_KEY est absente.');

            throw new ApiException(
                'Le prestataire de paiement est indisponible.',
                503,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::FEDAPAY->value],
            );
        }

        if (! is_string($environment) || ! in_array($environment, self::ENVIRONMENTS, true)) {
            Log::error('Configuration FedaPay invalide : FEDAPAY_ENVIRONMENT est inconnue.', [
                'fedapay_environment' => $environment,
            ]);

            throw new ApiException(
                'Le prestataire de paiement est indisponible.',
                503,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::FEDAPAY->value],
            );
        }

        FedaPay::setApiKey($key);
        FedaPay::setEnvironment($environment);
    }
}
