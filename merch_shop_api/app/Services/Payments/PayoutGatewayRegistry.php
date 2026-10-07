<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;

/**
 * Les passerelles capables de restituer de l'argent.
 *
 * Distincte de `PaymentGatewayRegistry` volontairement : encaisser et deverser
 * ne sont pas la meme operation, et un agregateur n'offre pas toujours les
 * deux. Les confondre ferait porter a une passerelle d'encaissement une
 * demande de depot — au mieux une erreur, au pire un depot emis par un
 * operateur qui ne sait pas le faire.
 */
final class PayoutGatewayRegistry
{
    /**
     * @param  array<string, class-string<PayoutGateway>>  $gateways
     */
    public function __construct(
        private readonly array $gateways,
    ) {}

    /**
     * La passerelle de depot d'un agregateur donne.
     */
    public function for(PaymentProvider $provider): PayoutGateway
    {
        $gateway = $this->gateways[$provider->value] ?? null;

        if ($gateway === null) {
            throw new ApiException(
                'Aucun prestataire de paiement ne gère les dépôts.',
                503,
                'PAYOUT_PROVIDER_NOT_CONFIGURED',
                ['provider' => $provider->value],
            );
        }

        return app($gateway);
    }

    /**
     * L'agregateur de depot par defaut, d'apres la configuration.
     *
     * L'encaissement et le depot partagent volontairement le meme agregateur :
     * les deux font appel a notre compte chez lui, et une commande remboursee
     * par un autre operateur que celui qui l'a encaissée se heurterait a des
     * regles de provisionnement distinctes que nous ne maitrisons pas.
     */
    public function default(): PayoutGateway
    {
        $configured = config('payments.provider');
        $provider = PaymentProvider::tryFrom(is_string($configured) ? $configured : '');

        if ($provider === null) {
            throw new ApiException(
                'Aucun prestataire de paiement par défaut.',
                503,
                'PAYOUT_PROVIDER_NOT_CONFIGURED',
                ['provider' => $configured],
            );
        }

        return $this->for($provider);
    }
}
