<?php

namespace App\Enums;

/**
 * Etat d'une transaction de paiement.
 *
 * Une commande portant plusieurs paiements (paiement partiel puis solde) expose
 * un Payment par tentative : c'est pourquoi chaque ligne garde son propre etat
 * au lieu de porter un statut unique au niveau de la commande.
 *
 * Le plan de tracking exige de conserver tous les resultats, y compris les
 * echecs : FAILED est donc un etat a part entiere, pas une absence de ligne.
 */
enum PaymentStatus: int
{
    /** Transaction envoyee a l'operateur, webhook pas encore recu. */
    case PENDING = 1;

    /** Operateur a confirme le reglement. */
    case SUCCESS = 2;

    /** Reglement refuse ou expire ; failure_reason documente le motif. */
    case FAILED = 3;

    /** Reglement reverse a l'acheteur. */
    case REFUNDED = 4;

    /**
     * Le statut tel qu'un operateur l'ecrit dans sa notification.
     *
     * La table est la seule source de la correspondance, et elle est donc
     * ecrite ici plutot que dans le controleur : un mapping duplique par
     * agregateur divergerait a la premiere evolution, et divergerait en
     * silence — un statut inconnu se traduirait par un paiement traite comme
     * en attente, jamais solde.
     *
     * Une valeur inconnue leve une exception : mieux vaut une notification
     * refusee, que l'operateur rejouera apres correction, qu'un paiement
     * laisse en attente indefiniment.
     */
    public static function fromOperator(string $value): self
    {
        return match (strtolower(trim($value))) {
            'pending', 'en_attente' => self::PENDING,
            'success', 'successful', 'succeeded', 'paye', 'paid' => self::SUCCESS,
            'failed', 'failure', 'refused', 'echec' => self::FAILED,
            default => throw new \InvalidArgumentException('Statut de paiement inconnu : '.$value),
        };
    }

    /**
     * L'etat a-t-il change depuis sa creation ?
     *
     * Un webhook de paiement doit etre idempotent : si l'operateur renvoie
     * deux fois la meme confirmation, le service compare l'etat courant a cet
     * etat-la et n'ecrit rien si rien n'a bouge.
     */
    public function isFinal(): bool
    {
        return $this === self::SUCCESS || $this === self::FAILED;
    }
}
