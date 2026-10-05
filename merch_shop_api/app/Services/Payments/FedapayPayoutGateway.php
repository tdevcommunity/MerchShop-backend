<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Support\Api\Money;
use App\Support\Payments\FedapayClient;
use FedaPay\Error\Base;
use FedaPay\Payout;
use Illuminate\Support\Facades\Log;

/**
 * FedaPay, pour la sortie d'argent.
 *
 * Le SDK conserve un etat global sur sa classe statique, ce qui autorise une
 * seule instance configuree a la fois ; c'est une limite du SDK et non de
 * cette classe. La configuration est posee a chaque appel plutot qu'au
 * demarrage, pour qu'un worker long-lived reflectisse la configuration du
 * moment ou il traite la demande.
 *
 * Cette classe vit dans le meme paquet que `FedapayGateway` parce qu'elles
 * partagent cet etat : les separer laisserait deux objets se disputer la meme
 * cle d'API, dont l'un aurait ecrit pour l'autre.
 */
final class FedapayPayoutGateway implements PayoutGateway
{
    public function __construct(
        private readonly Money $money,
        private readonly FedapayClient $client,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::FEDAPAY;
    }

    /**
     * Depose l'argent d'une commande chez FedaPay.
     *
     * Deux appels sont necessaires, et leur ordre compte. La creation enregistre
     * le depot et lui attribue une reference ; c'est `sendNow` qui declenche
     * l'envoi. L'ordre inverse n'a pas de sens : il n'y a rien a envoyer tant que
     * le depot n'existe pas.
     *
     * Le numero de destination n'est pas passe ici. FedaPay lira celui de la
     * fiche client creee a la commande, et la seule facon de viser un autre
     * numero serait de le fournir a cet appel — ce que cette implementation
     * refuse de faire, parce que la destination d'un remboursement ne se
     * discute pas avec la personne qui en demande l'execution.
     */
    public function payOut(Order $order): PayoutResult
    {
        $this->client->configure();

        $paid = $order->payments()
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->first();

        if ($paid === null) {
            /*
             * Sans reglement confirme, il n'y a rien a restituer. Cette
             * verification vit dans la passerelle et non dans le service de
             * commande pour que le contrat tienne quel que soit l'appelant :
             * deposer de l'argent sur une commande qui n'a rien encaisse
             * reviendrait a offrir une somme dont personne n'est comptable.
             */
            throw new ApiException(
                'Cette commande n’a aucun paiement confirmé à rembourser.',
                422,
                'PAYOUT_WITHOUT_PAYMENT',
                ['order' => $order->uuid],
            );
        }

        $amount = $this->money->toAmount($paid->amount);

        Log::info('Demande de depot envoyee a l’operateur.', [
            'order_number' => $order->order_number,
            'payment_reference' => $paid->uuid,
            'provider' => $this->provider()->value,
            'amount' => $amount,
        ]);

        try {
            $payout = Payout::create([
                'amount' => $amount,
                'currency' => ['iso' => $paid->order->currency ?? $order->currency],
                'description' => 'Remboursement commande '.$order->order_number,
                'customer' => ['id' => $order->fedapay_customer_id],
            ]);

            /*
             * Le jeton est demande sans rappel de client : FedaPay en a deja un
             * associe au depot, et lui en fournir un autre reviendrait a
             * demander le depot sur un autre numero — c'est-a-dire a changer la
             * destination de l'argent, sans que personne ne l'ait demande.
             */
            $started = $payout->sendNow();
        } catch (Base $error) {
            Log::error('Depot refuse par l’operateur.', [
                'order_number' => $order->order_number,
                'provider' => $this->provider()->value,
                'reason' => $error->getMessage(),
            ]);

            throw new ApiException(
                'Le prestataire de paiement a refusé le dépôt.',
                502,
                'PAYOUT_REJECTED',
                ['order' => $order->uuid],
            );
        }

        /*
         * La sortie d'argent n'est pas acquise. L'operateur accepte le depot
         * et le traite ensuite : son etat est donc rendu tel quel, et la
         * confirmation viendra par notification. Ecrire « rembourse » ici
         * affirmerait au guichet que l'argent est parti quand il est encore
         * chez nous.
         */
        return new PayoutResult(
            reference: (string) ($started->id ?? $payout->id ?? ''),
            status: (string) ($started->status ?? $payout->status ?? 'pending'),
        );
    }
}
