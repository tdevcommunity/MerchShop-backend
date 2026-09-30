<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * Representation d'une tentative de paiement.
 *
 * `transactionId` est expose parce que le guichet en a besoin pour rapprocher
 * un versement Mobile Money d'une commande. Il n'est renvoye qu'une fois le
 * paiement confirme : avant, la reference existe dans la base mais n'a encore
 * ete vue par aucun operateur, et l'afficher n'apporterait rien au client.
 *
 * @mixin Payment
 */
final class PaymentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payment $payment */
        $payment = $this->resource;

        return [
            'uuid' => $payment->uuid,

            /*
             * Identifiants de rattachement, demandes par la spec Data du festival
             * (section 14) sur chaque tentative de paiement.
             *
             * `participantId` et `currency` sont lus sur la commande et non
             * recopies sur le paiement : ce sont des proprietes de la vente, pas
             * de la transaction qui la solde. Les dupliquer demanderait de les
             * tenir a jour sur chaque ecriture de paiement, alors qu'une seule
             * lecture les fournit et qu'elles ne peuvent pas diverger de la
             * commande qui les porte.
             *
             * Ils sont absents tant que la commande n'est pas chargee, comme
             * partout ailleurs dans les ressources de l'API.
             */
            'orderId' => $payment->relationLoaded('order') ? $payment->order->uuid : null,
            'participantId' => $payment->relationLoaded('order') ? $payment->order->participant_id : null,
            'currency' => $payment->relationLoaded('order') ? $payment->order->currency : null,

            'amount' => $payment->amount,
            'method' => $payment->method->value,
            'provider' => $payment->provider?->value,
            'status' => $payment->status->value,
            'transactionId' => $payment->transaction_id,
            'failureReason' => $payment->failure_reason,

            /*
             * Les trois horodatages de la transaction demandes par la spec
             * (section 14) : creation, aboutissement, echec.
             *
             * Seul `failedAt` est une colonne propre. Les deux autres sont
             * `created_at` et `paid_at`, qui disent deja ces deux choses ; leur
             * donner un second nom dans la reponse evite au consommateur d'avoir
             * a savoir quelle colonne interne designerait quoi, sans dupliquer
             * une donnee qui pourrait desynchroniser.
             */
            'createdAt' => $payment->created_at?->toIso8601String(),
            'paidAt' => $payment->paid_at?->toIso8601String(),
            'failedAt' => $payment->failed_at?->toIso8601String(),
        ];
    }
}
