<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Models\Order;

/**
 * Un operateur capable de restituer de l'argent, vu depuis l'API.
 *
 * C'est le pendant de `PaymentGateway` dans l'autre sens de la monnaie. Ce
 * contrat existe parce que le retrait d'argent n'est pas symetrique de son
 * encaissement : l'argent sort vers un numero de telephone, ce qui oblige a
 * connaitre l'identite de l'acheteur et interdit de traiter les deux sens
 * comme deux operations de la meme nature.
 *
 * Une implementation doit respecter les memes trois regles que
 * `PaymentGateway` : elle appelle un service tiers hors de toute transaction
 * base de donnees, elle leve une `ApiException`, et elle n'invente aucun
 * montant.
 *
 * Elle ne peut pas non plus choisir la destination de l'argent. Le numero
 * vient de la commande et de nulle part ailleurs : un depot est irreversible,
 * et une destination choisie par l'appelant serait un moyen de detourner un
 * remboursement.
 */
interface PayoutGateway
{
    /**
     * L'operateur implemente.
     */
    public function provider(): PaymentProvider;

    /**
     * Demande le depot de l'argent d'une commande.
     *
     * La commande porte l'identite et le numero de l'acheteur : ce sont eux, et
     * eux seuls, qui determinent ou part l'argent. Le montant est celui du
     * paiement encaisse.
     *
     * L'appel sort de la base : c'est une operation distante, et sa latence ne
     * doit pas immobiliser une transaction ouverte chez nous.
     */
    public function payOut(Order $order): PayoutResult;
}
