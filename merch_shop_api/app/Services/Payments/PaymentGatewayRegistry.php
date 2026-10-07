<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;

/**
 * Les passerelles de paiement disponibles.
 *
 * Le routage se fait par agregateur plutot que par heritage de classe : chaque
 * prestataire est une passerelle enregistree dans le conteneur, et l'on cherche
 * laquelle repond a cet agregateur plutot que d'en deviner le type. C'est ce qui
 * permet a un operateur absent de la configuration d'etre signale comme tel,
 * plutot que d'etre remplace silencieusement par un autre.
 */
final class PaymentGatewayRegistry
{
    /**
     * @param  array<string, class-string<PaymentGateway>>  $gateways
     */
    public function __construct(
        private readonly array $gateways,
    ) {}

    /**
     * La passerelle d'un agregateur donne.
     *
     * Un agregateur declare mais non configure n'est pas une panne du client :
     * c'est un defaut de deploiement, qui doit se dire dans les journaux plutot
     * que de se manifester en erreur de paiement sur toutes les commandes.
     */
    public function for(PaymentProvider $provider): PaymentGateway
    {
        $gateway = $this->gateways[$provider->value] ?? null;

        if ($gateway === null) {
            throw new ApiException(
                'Aucun prestataire de paiement ne gere cet agregateur.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => $provider->value],
            );
        }

        return app($gateway);
    }

    /**
     * L'agregateur par defaut, d'apres la configuration.
     *
     * La valeur lue peut etre absente ou inconnue — une faute de frappe dans la
     * configuration devant se voir immediatement, pas se transformer en
     * prestataire au hasard pour le festival suivant.
     */
    public function default(): PaymentGateway
    {
        $configured = config('payments.provider');
        $provider = PaymentProvider::tryFrom(is_string($configured) ? $configured : '');

        if ($provider === null) {
            throw new ApiException(
                'Aucun prestataire de paiement par defaut.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => $configured],
            );
        }

        return $this->for($provider);
    }
}
