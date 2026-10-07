<?php

namespace App\Support\Payments;

use App\Exceptions\ApiException;
use FedaPay\Error\SignatureVerification;
use FedaPay\WebhookSignature;
use Illuminate\Http\Request;

/**
 * Verification de l'origine d'une notification FedaPay.
 *
 * FedaPay ne signe pas le corps seul. L'en-tete `X-FEDAPAY-SIGNATURE` porte
 * deux valeurs, `t=<horodatage>,s=<signature>`, et la signature porte sur
 * `<horodatage>.<corps>` : l'horodatage est donc partie du message signe, ce
 * qui permet de rejeter une notification rejouee alors que son corps reste
 * parfaitement valide.
 *
 * L'algorithme lui-meme est celui du SDK officiel, appele ici plutot que
 * recalcule : c'est la seule facon de ne pas diverger de l'implementation de
 * reference, et le SDK compare en temps constant.
 *
 * Cette classe ne touche ni a la base ni au corps de la requete. Elle repond a
 * une seule question — « cette requete vient-elle de FedaPay ? » — et peut donc
 * etre appelee avant toute lecture metier, ce qui est l'ordre exige : une
 * notification doit etre authentique avant d'etre interpretee, sinon ses
 * messages d'erreur renseigneraient celui qui l'a forgee.
 */
final class FedapaySignatureVerifier
{
    /** En-tete portant la signature, dans la casse documentee par FedaPay. */
    public const HEADER = 'X-FEDAPAY-SIGNATURE';

    /**
     * Fenetre de rejeu, en secondes.
     *
     * Elle couvre le decalage d'horloge ordinaire entre deux machines, et rien
     * de plus. FedaPay rattrape ses envois manquants pendant plusieurs minutes :
     * une fenetre trop courte refuserait des notifications legitimes et ferait
     * payer l'acheteur pour une panne de notre cote.
     */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verifie la signature d'une notification FedaPay.
     *
     * @throws ApiException 401 si la signature est absente, fausse, ou hors fenetre.
     */
    public function verify(Request $request): void
    {
        $secret = $this->secret();

        $header = $request->header(self::HEADER);

        if (! is_string($header) || trim($header) === '') {
            throw new ApiException(
                'Signature de notification invalide.',
                401,
                'INVALID_WEBHOOK_SIGNATURE',
            );
        }

        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $header,
                $secret,
                $this->tolerance(),
            );
        } catch (SignatureVerification $failure) {
            /*
             * La signature a ete reconnue mais refusee, ou son horodatage est
             * hors fenetre. Les deux cas sont indistinguables pour l'appelant :
             * lui dire lequel serait indiquer le nombre d'essais restants, donc
             * aider a forger une signature par recherche successive.
             */
            throw new ApiException(
                'Signature de notification invalide.',
                401,
                'INVALID_WEBHOOK_SIGNATURE',
                previous: $failure,
            );
        }
    }

    /**
     * Le secret de l'endpoint FedaPay configure dans le tableau de bord.
     *
     * Il est distinct de la cle d'API et propre a chaque environnement : un
     * webhook de test signe avec le secret de production ne doit pas passer.
     * Son absence ferme la route plutot que de l'ouvrir a tout le monde.
     */
    private function secret(): string
    {
        $secret = config('orders.webhooks.fedapay.secret');

        if (! is_string($secret) || $secret === '') {
            throw new ApiException(
                'Aucun secret configure pour cet agregateur de paiement.',
                503,
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                ['provider' => 'fedapay'],
            );
        }

        return $secret;
    }

    /**
     * Fenetre de rejeu retenue.
     *
     * Une valeur nulle ou negative laisse le SDK ne verifier aucune fenetre :
     * ce serait accepte par defaut, mais c'est un defaut de configuration qui
     * doit se voir plutot que de desactiver la protection sans le dire.
     */
    private function tolerance(): int
    {
        $configured = config('orders.webhooks.fedapay.tolerance');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_TOLERANCE;
    }
}
