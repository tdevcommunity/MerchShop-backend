<?php

namespace App\Services\Payments;

/**
 * Ce qu'un operateur renvoie apres avoir accepte d'ouvrir un paiement.
 *
 * Les trois identifiants qui le composent ne se ressemblent pas et ne jouent
 * pas le meme role, ce qui explique qu'ils soient nommes plutot que ranges
 * dans un tableau :
 *
 *  - `reference` est la nôtre : l'uuid du paiement. C'est elle que nous avons
 *    envoyee a l'operateur au checkout, et c'est par elle qu'un webhook sera
 *    rapproche, puisque aucun operateur ne garantit d'accepter une reference
 *    choisie par le commercant ;
 *  - `transactionId` est celle de l'operateur, seule connue apres l'appel. Elle
 *    sert de repli lorsqu'un webhook ne porte pas notre reference, et de trace
 *    consultable par le guichet ;
 *  - `checkoutUrl` est l'adresse a laquelle l'acheteur doit etre redirige.
 */
final readonly class PaymentIntent
{
    public function __construct(
        public string $reference,
        public string $transactionId,
        public string $checkoutUrl,
        public ?string $token = null,
    ) {}
}
