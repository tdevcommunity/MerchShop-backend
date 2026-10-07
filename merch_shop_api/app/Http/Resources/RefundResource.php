<?php

namespace App\Http\Resources;

use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Http\Request;

/**
 * Une demande de restitution, et son etat reel.
 *
 * @mixin Refund
 *
 * Le statut est expose parce que c'est la seule chose qu'un guichet peut
 * consulter pour repondre a « avez-vous bien rembourse ? » — et que la reponse
 * doit pouvoir etre « demande, l'argent est en route », ce qui n'est ni un oui
 * ni un non.
 */
final class RefundResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,

            /*
             * Le montant vient de la demande, pas du paiement courant : c'est
             * celui qui a ete demande, et le dire evita qu'un guichet compare un
             * numero a un autre qui n'a pas le meme objet.
             */
            'amount' => $this->amount,

            'provider' => $this->provider?->value,
            'payoutReference' => $this->payout_reference,

            'status' => $this->status->value,

            /*
             * Le moment ou l'argent est sorti, et non celui ou la demande a ete
             * faite. Aucun `completedAt` synthetique ne derives de la demande :
             * le dire encore sous un autre nom rendrait l'incertitude
             * invisible.
             */
            'settledAt' => $this->settled_at?->toIso8601String(),

            'failureReason' => $this->failure_reason,
        ];
    }

    /**
     * La demande est-elle encore susceptible d'aboutir ?
     *
     * Expose tel quel plutot que deduit d'une liste de statuts cote client : la
     * liste est deja une regle metier, et la dupliquer dans le front ferait
     * diverger les deux.
     */
    public function isPending(): bool
    {
        return $this->status === RefundStatus::PENDING;
    }
}
