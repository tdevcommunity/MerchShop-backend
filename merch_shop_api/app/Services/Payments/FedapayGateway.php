<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Support\Api\Money;
use App\Support\Payments\FedapayClient;
use App\Support\Payments\FedapayCustomerResolver;
use FedaPay\Error\Base;
use FedaPay\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * FedaPay.
 *
 * FedaPay garde un etat global — cle d'API, environnement — sur sa classe
 * statique : il n'y a donc qu'une instance configuree a la fois. Ce n'est pas
 * une limite de cette classe mais du SDK, et elle est sans consequence ici
 * puisqu'un seul operateur est configure par deploiement. La configuration est
 * posee a chaque appel plutot qu'au demarrage, parce qu'un worker long-lived
 * doit refléter la configuration du moment ou il traite la commande.
 */
final class FedapayGateway implements PaymentGateway
{
    public function __construct(
        private readonly Money $money,
        private readonly FedapayClient $client,
        private readonly FedapayCustomerResolver $customers,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::FEDAPAY;
    }

    /**
     * Ouvre une transaction FedaPay et renvoie l'adresse de paiement.
     *
     * Deux appels distincts sont necessaires et leur ordre compte : la
     * transaction doit exister avant qu'un lien de paiement puisse etre emis.
     * FedaPay ne renvoie pas d'URL a la creation — la transaction commence en
     * attente — c'est la generation du jeton qui produit la page a ouvrir.
     *
     * Notre uuid de paiement voyage dans les metadonnees. FedaPay genere
     * lui-meme une reference, non modifiable a la creation, et elle n'existe
     * qu'apres l'appel : c'est donc le seul endroit ou une valeur choisie par
     * l'API peut etre restituee dans un webhook.
     */
    public function initiate(Payment $payment, string $callbackUrl): PaymentIntent
    {
        $this->client->configure();

        $amount = $this->money->toAmount($payment->amount);

        $order = $payment->order;

        try {
            /*
             * La fiche client est resolue avant la transaction, et non dans le
             * tableau : FedaPay attend un `id` deja cree, et sa creation est un
             * appel distant qui n'a rien a faire au milieu d'un autre. Son echec
             * interrompt le paiement plutot que de produire un règlement sans
             * acheteur rattache — un encaisseour ne peut ni livre ni rembourser
             * ce qu'il ne sait pas a qui remettre.
             */
            $customerId = $this->customers->resolve($order);

            $transaction = Transaction::create([
                'description' => 'Commande '.$order->order_number,
                'amount' => $amount,
                'currency' => ['iso' => $order->currency],
                'callback_url' => $callbackUrl,
                'customer' => ['id' => $customerId],
                'custom_metadata' => [
                    'payment_uuid' => $payment->uuid,
                    'order_number' => $order->order_number,
                ],
            ]);

            /*
             * Le jeton est demande par son identifiant, et non sur l'objet
             * renvoye. Le SDK ne restitue un objet `Transaction` que si la
             * reponse porte son champ `_type` : appeler la methode d'instance
             * ferait dependre notre chemin de paiement d'un detail de format de
             * la reponse, et le transformerait en erreur fatale le jour ou
             * FedaPay y repond autrement. L'appel statique ne depend de rien.
             */
            $token = Transaction::generateTokenFromId((int) $transaction->id);

            if (! is_string($token->url ?? null) || $token->url === ''
                || ! is_string($token->token ?? null) || $token->token === '') {
                throw new ApiException(
                    'Le prestataire de paiement a renvoye un lien de paiement invalide.',
                    502,
                    'PAYMENT_PROVIDER_UNAVAILABLE',
                    ['provider' => PaymentProvider::FEDAPAY->value],
                );
            }
        } catch (Base $error) {
            /*
             * L'echec vient d'un service tiers et non du client : le distinguer
             * evite qu'un front lee « montant invalide » la ou le paiement n'a
             * jamais ete tente. La cause est journalisee avec le code operateur
             * et jamais le payload, qui porte des donnees de commande.
             */
            Log::warning('Appel FedaPay refuse.', [
                'payment_reference' => $payment->uuid,
                'fedapay_code' => $error->getCode(),
                'fedapay_message' => $error->getMessage(),
            ]);

            throw new ApiException(
                'Le prestataire de paiement est indisponible. Reessayez dans un instant.',
                502,
                'PAYMENT_PROVIDER_UNAVAILABLE',
                ['provider' => PaymentProvider::FEDAPAY->value],
                $error,
            );
        }

        return new PaymentIntent(
            reference: $payment->uuid,
            transactionId: $this->transactionReference($transaction),
            checkoutUrl: $token->url,
            token: $token->token,
        );
    }

    /**
     * La valeur que portera la notification sur cette transaction.
     *
     * FedaPay identifie une transaction par deux valeurs : son identifiant
     * numerique et la reference qu'il lui attribue. La notification porte la
     * reference en premier, et l'identifiant n'y sert que de repli.
     *
     * C'est donc la reference qu'il faut ecrire ici. Ecrire l'identifiant
     * laisserait deux valeurs differentes pour la meme transaction : celle du
     * checkout ne correspondrait jamais a celle du webhook, et le repli sur
     * `transaction_id` — qui n'existe que pour rattraper une notification sans
     * metadonnees — echouerait precisement sur la premiere notification.
     */
    private function transactionReference(object $transaction): string
    {
        $reference = (string) ($transaction->reference ?? '');

        return $reference !== '' ? $reference : (string) $transaction->id;
    }
}
