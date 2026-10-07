<?php

namespace App\Support\Payments;

use App\Exceptions\ApiException;

/**
 * Une notification de sortie d'argent FedaPay.
 *
 * Elle est analysee a part de la notification d'encaissement parce que les deux
 * ne parlent pas de la meme chose : l'une dit « le client a paye », l'autre
 * dit « l'argent est parti ». Les confondre ferait passer une commande en
 * payee quand son remboursement echoue — l'inverse exact du risque.
 *
 * Aucun montant n'est lu ici. Le depot a ete demande sur une commande
 * precise, dont le montant est deja connu et verifie a la demande ; le control
 * porte sur le statut, et le rapprochement se fait par la reference du depot
 * que nous avions notee. Un montant annonce par la notification ne ferait que
 * dupliquer une donnee deja connue, avec le risque de lui voir diverger.
 */
final readonly class FedapayPayoutEvent
{
    /**
     * @param  string  $payoutReference  Reference du depot chez l'operateur.
     * @param  string  $status  Statut brut, tel que l'operateur l'ecrit.
     */
    private function __construct(
        public string $payoutReference,
        public string $status,
    ) {}

    /**
     * Analyse le corps recu.
     *
     * @param  array<string, mixed>  $decoded
     *
     * @throws ApiException 422 si l'evenement est illisible ou sans reference.
     */
    public static function fromPayload(array $decoded): self
    {
        /*
         * L'enveloppe se lit sous `data` comme sous `object` : FedaPay a deja
         * employe les deux noms pour la meme information sur ses transactions,
         * et exiger le bon reviendrait a perdre des notifications sur un detail
         * de formatage de l'autre cote.
         */
        $payout = $decoded['data'] ?? $decoded['object'] ?? null;

        if (! is_array($payout)) {
            throw new ApiException(
                'Notification de dépôt FedaPay illisible.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
            );
        }

        $reference = $payout['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw new ApiException(
                'Notification de dépôt FedaPay sans référence de dépôt.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
            );
        }

        $status = $payout['status'] ?? null;

        if (! is_string($status) || $status === '') {
            throw new ApiException(
                'Notification de dépôt FedaPay sans statut.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
            );
        }

        return new self($reference, $status);
    }

    /**
     * L'argent est-il sorti ?
     *
     * Trois libelles pour un seul fait : l'operatoire de l'operateur, dont
     * nous ne controlons pas le vocabulaire. Refuser une restitution reellement
     * effectuee parce qu'il l'a ecrite autrement serait pire que d'accepter un
     * libelle inconnu.
     */
    public function hasSettled(): bool
    {
        return in_array(strtolower($this->status), ['processed', 'succeeded', 'success'], true);
    }

    /**
     * L'operateur a-t-il refuse le depot ?
     *
     * `pending` n'en fait pas partie : il signifie que l'argent est en route, et
     * le traiter comme un echec remettrait la commande en payee pendant que le
     * depot est en cours de traitement.
     */
    public function hasFailed(): bool
    {
        return in_array(strtolower($this->status), ['failed', 'rejected', 'cancelled', 'canceled'], true);
    }

    /**
     * Le statut est-il connu ?
     */
    public function isKnown(): bool
    {
        return $this->hasSettled() || $this->hasFailed() || in_array(strtolower($this->status), ['pending', 'processing'], true);
    }

    /**
     * Le motif d'echec, s'il y en a un.
     *
     * @param  array<string, mixed>  $decoded
     */
    public function failureReason(array $decoded): ?string
    {
        $payout = $decoded['data'] ?? $decoded['object'] ?? [];

        if (! is_array($payout)) {
            return null;
        }

        $reason = $payout['last_error_message'] ?? $payout['last_error_code'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
