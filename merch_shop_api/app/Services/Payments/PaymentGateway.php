<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Models\Payment;

/**
 * Un agregateur de paiement, vu depuis l'API.
 *
 * Les operateurs ne se parlent pas : chacun a son langage d'echange, son
 * vocabulaire de statuts et sa maniere de signer ses notifications. Ce contrat
 * est le seul vocabulaire que le reste du code doit connaitre, afin qu'ajouter un
 * operateur ne se traduise jamais par une modification du service de commande.
 *
 * Une implementation doit respecter trois regles :
 *
 *  - elle appelle un service tiers, donc hors de toute transaction base de
 *    donnees qui l'attende. Un appel distant bloque une transaction ouverte et
 *    transforme une latence de l'operateur en indisponibilite de l'API ;
 *  - elle leve une `ApiException` et non une exception de transport, pour que
 *    l'echec remonte au client avec un code d'erreur stable ;
 *  - elle n'invente aucun montant : celui qu'elle recoit vient de la commande.
 */
interface PaymentGateway
{
    /**
     * L'operateur implemente.
     *
     * Il est declare plutot que deduit de la classe, parce que la resolution
     * se fait par agregateur : le webhook et le checkout convergent vers la
     * meme instance.
     */
    public function provider(): PaymentProvider;

    /**
     * Ouvre une tentative de paiement chez l'operateur.
     *
     * La ligne de paiement existe deja en base : elle porte le montant, le moyen
     * choisi par l'acheteur et l'uuid qui servira de reference. L'appel sort
     * donc de la base, et son resultat est ecrit apres coup.
     *
     * L'operateur doit pouvoir retrouver cette tentative : l'uuid de paiement
     * est donc transmis dans ses metadonnees, et non laisse a son seul
     * discernement.
     */
    public function initiate(Payment $payment, string $callbackUrl): PaymentIntent;
}
