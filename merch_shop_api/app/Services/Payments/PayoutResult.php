<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;

/**
 * Ce qu'un operateur renvoie apres avoir accepte de deposer de l'argent.
 *
 * Le statut est conserve tel quel, non traduit en succes ou echec. Un depot
 * d'argent n'est pas une operation instantanee : l'appel d'acceptation
 * repond le plus souvent `pending`, et la sortie d'argent effective n'arrive
 * qu'ensoin, par notification. Traduire ici reviendrait a annoncer une sortie
 * d'argent que personne n'a encore constatee.
 */
final readonly class PayoutResult
{
    /**
     * @param  string  $reference  Reference du depot chez l'operateur, vide si l'appel n'a pas abouti.
     * @param  string  $status  Statut brut, tel que l'operateur l'ecrit.
     * @param  string|null  $failureReason  Motif de l'echec, s'il y en a un.
     */
    public function __construct(
        public string $reference,
        public string $status,
        public ?string $failureReason = null,
    ) {}

    /**
     * Le depot a-t-il ete accepte ?
     *
     * @throws ApiException si l'appel n'a pas abouti.
     */
    public function ensureAccepted(): void
    {
        if ($this->reference === '') {
            throw new ApiException(
                'Le prestataire de paiement n’a pas accepte le depot.',
                502,
                'PAYOUT_NOT_ACCEPTED',
                $this->failureReason === null ? [] : ['reason' => $this->failureReason],
            );
        }
    }
}
