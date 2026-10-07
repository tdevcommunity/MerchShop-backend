<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * Un evenement FedaPay, lu et traduit.
 *
 * FedaPay n'envoie pas le paiement : il envoie un evenement decrivant ce qui
 * vient de se produire sur une transaction, transaction comprise. Cette classe
 * est le seul endroit ou ce vocabulaire est lu, afin que le service de paiement
 * ne manipule que des termes internes.
 *
 * Deux tolérances sont volontaires.
 *
 * La transaction peut arriver sous `data` ou sous `object` : l'exemple
 * officiel de la documentation lit `$event->data->reference`, tandis que la
 * classe `Event` du SDK declare une propriete `object`. Les deux decrivent le
 * meme objet, et choisir l'un des deux ferait perdre toute notification des
 * que FedaPay change d'un cote.
 *
 * De meme pour `type` et `name`, que la documentation et le SDK nomment
 * differemment. Aucun des deux n'est lu pour determiner l'etat du paiement :
 * seule la transaction fait foi, une notification portant sur autre chose
 * qu'une transaction etant simplement accluse.
 */
final readonly class FedapayEvent
{
    /** Cle sous laquelle nous deposons notre reference dans les metadonnees. */
    public const PAYMENT_REFERENCE_KEY = 'payment_uuid';

    private function __construct(
        public string $name,
        public string $status,
        public string $transactionReference,
        public ?int $amount,
        public ?string $paymentReference,
        public ?int $id,
    ) {}

    /**
     * Traduit le corps d'une notification en evenement.
     *
     * Le corps a deja ete authentifie par la verification de signature : une
     * erreur de lecture ici ne vient donc pas d'un attaquant, mais d'un
     * changement de protocole chez l'operateur, qu'il faut voir remonter.
     */
    public static function fromPayload(string $payload): self
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new ApiException(
                'Notification FedaPay illisible.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
            );
        }

        $name = self::stringOrNull($decoded['type'] ?? $decoded['name'] ?? null);

        $transaction = $decoded['data'] ?? $decoded['object'] ?? null;

        if (! is_array($transaction)) {
            throw new ApiException(
                'Notification FedaPay sans transaction exploitable.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
                ['event' => $name],
            );
        }

        $status = self::stringOrNull($transaction['status'] ?? null);

        if ($status === null) {
            throw new ApiException(
                'Notification FedaPay sans statut de transaction.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
                ['event' => $name],
            );
        }

        /*
         * La reference FedaPay est une chaine et son identifiant un entier :
         * le second ne sert que de repli, et il est converti en chaine parce
         * que c'est le type de la colonne qui porte les references operateur.
         * Sans aucune des deux, aucun rapprochement n'est possible.
         */
        $reference = self::stringOrNull($transaction['reference'] ?? null)
            ?? (isset($transaction['id']) ? (string) $transaction['id'] : null);

        if ($reference === null || $reference === '') {
            throw new ApiException(
                'Notification FedaPay sans reference de transaction.',
                422,
                'FEDAPAY_MALFORMED_EVENT',
                ['event' => $name],
            );
        }

        return new self(
            name: $name ?? '',
            status: $status,
            transactionReference: $reference,
            amount: self::amount($transaction['amount'] ?? null, $name),
            paymentReference: self::paymentReference($transaction),
            id: isset($decoded['id']) ? (int) $decoded['id'] : null,
        );
    }

    /**
     * Notre reference de paiement, lue dans les metadonnees de la transaction.
     *
     * Elle est lue sous deux noms parce que FedaPay les emploie l'un pour
     * l'autre : la creation accepte `custom_metadata`, et la transaction
     * restituee est decrite avec `metadata`. Le SDK et la documentation ne
     * tranchent pas. Lire un seul des deux ferait perdre le rapprochement
     * principal — non par un echec visible, mais par une degradation silencieuse
     * vers la reference operateur, qui ne fonctionne qu'apres le premier
     * envoi.
     */
    private static function paymentReference(array $transaction): ?string
    {
        foreach (['custom_metadata', 'metadata'] as $key) {
            $metadata = $transaction[$key] ?? null;

            if (is_array($metadata)) {
                $reference = self::stringOrNull($metadata[self::PAYMENT_REFERENCE_KEY] ?? null);

                if ($reference !== null) {
                    return $reference;
                }
            }
        }

        return null;
    }

    /**
     * Le montant annonce par la transaction, en entier.
     *
     * FedaPay traite en franc CFA, qui n'a pas de subdivision : ses montants sont
     * des entiers, ce que la documentation confirme. Un montant decimal ne peut
     * donc pas designer une somme de cette devise — il signale soit une autre
     * devise, soit un changement de protocole.
     *
     * Il ne doit pas etre arrondi. Tronquer « 2500.50 » en 2500 reviendrait a
     * accepter une notification annoncant une somme que personne n'a confirmee,
     * pour le prix d'un test qui passe : c'est exactement le montant que le chemin
     * generique refuse depuis le debut, et il ne doit pas se mettre a l'accepter
     * parce qu'il change de chemin.
     */
    private static function amount(mixed $value, ?string $event): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new ApiException(
            'Montant de transaction FedaPay illisible.',
            422,
            'FEDAPAY_MALFORMED_EVENT',
            ['event' => $event, 'field' => 'amount'],
        );
    }

    /**
     * L'evenement porte-t-il sur une transaction ?
     *
     * FedaPay emet des evènements sur d'autres objets. Les traiter comme des
     * paiements conduirait a les rapprocher d'une commande au hasard : ils sont
     * donc accluses sans effet, ce qui reste une reussite pour l'operateur.
     */
    public function concernsTransaction(): bool
    {
        if (str_starts_with($this->name, 'transaction.')) {
            return true;
        }

        Log::warning('FedaPay: nom d\'événement inattendu', ['name' => $this->name]);

        return false;
    }

    /**
     * Un statut lisible par FedaPay mais inconnu ici est une evolution de
     * l'operateur : il doit etre signale plutot que traite comme un refus.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
