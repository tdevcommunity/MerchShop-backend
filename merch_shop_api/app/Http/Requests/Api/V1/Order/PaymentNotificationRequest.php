<?php

namespace App\Http\Requests\Api\V1\Order;

use App\Http\Requests\ApiRequest;
use App\Support\Rules\PriceInXof;

/**
 * Corps de la notification de paiement envoyee par l'agregateur.
 *
 * Cette route n'a ni session ni jeton : sa seule defense est la signature,
 * verifiee par WebhookSignatureVerifier avant que cette classe ne soit
 * seulement chargee. Les regles ci-dessous ne servent donc qu'a ecarter les
 * notifications mal formees d'un operateur legitime, pas a la securiser.
 *
 * `reference` est l'uuid de la tentative de paiement, envoye a l'operateur au
 * moment du checkout : c'est par lui que la notification est rapprochee, et
 * jamais par un identifiant interne devinable.
 */
final class PaymentNotificationRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'uuid'],

            'transaction_id' => ['nullable', 'string', 'max:190'],

            /*
             * La liste n'est qu'un garde-fou : elle n'autorise que le vocabulaire
             * que cet API documente, et la correspondance reelle vers l'etat
             * interne est faite par PaymentStatus::fromOperator(), qui tolere
             * aussi les libelles d'autres operateurs.
             */
            'status' => ['required', 'string', 'in:pending,success,successful,failed,refused'],

            /*
             * Le montant est verifie contre la commande, et non accepte tel quel
             * : c'est cette comparaison qui empeche une notification portant le
             * bon montant d'etre rejouee sur une autre commande.
             *
             * Il est en francs CFA, donc entier, comme le prix d'une variante.
             * Un operateur peut l'ecrire « 2500.00 » : la regle lit les deux
             * formes, mais refuse « 2500.50 », qui ne correspond a aucun montant
             * payable et signalerait une divergence entre la notification et la
             * commande.
             */
            'amount' => ['required', new PriceInXof],

            'failure_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference' => 'reference de la tentative de paiement',
            'transaction_id' => 'identifiant de la transaction',
            'status' => 'statut du paiement',
            'amount' => 'montant',
            'failure_reason' => 'motif de l’echec',
        ];
    }
}
