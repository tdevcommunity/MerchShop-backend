<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoi de la confirmation de commande apres paiement.
 *
 * Le job existe pour deux raisons qui tiennent a l'endroit d'ou il part :
 *
 *  1. il part du webhook de paiement. Une transaction SMTP lente ou en echec
 *     ne doit pas prolonger la reponse a l'operateur, qui interprete un
 *     delai comme un defaut de traitement et rejoue la notification. En file,
 *     le webhook repond en quelques millisecondes ;
 *
 *  2. il part dans la transaction de confirmation. `afterCommit` le met en
 *     file une seule fois l'ecriture etablie : une transaction annulee ne
 *     laisse donc pas derriere un email qui annonce un paiement n'ayant pas
 *     eu lieu.
 *
 * L'adresse destinataire est resolue au dispatch, pas ici : ne pas mettre
 * en file une commande sans email evite une ligne de file qui ne fera rien.
 * Le job ne connait donc que le resultat de cette resolution, deja valide,
 * et n'a pas a la refaire.
 */
final class SendOrderConfirmationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Attente avant nouvelle tentative.
     *
     * Un prestataire mail en defaut revient souvent plus vite que cette
     * borne ; au-dela, trois tentatives echouees designent un probleme de
     * configuration plutot qu'un incident passager.
     */
    public int $backoff = 30;

    /** Nombre de tentatives avant abandon. */
    public int $tries = 3;

    public function __construct(
        public readonly Order $order,
        public readonly string $recipient,
    ) {
        /*
         * Mise en file apres commit de la transaction appelante.
         *
         * Sans cette borne, la file recevrait le job avant que la
         * confirmation de paiement ne soit etablie : une transaction
         * annulee laisserait partir un email annoncant un reglement qui
         * n'a pas eu lieu. Le webhook qui declenche ce job s'ecrit dans
         * une transaction, et c'est elle qui doit preceder l'envoi.
         */
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        /*
         * Les lignes ne sont pas chargees par le deserialiseur : il restaure
         * la commande seule. Le chargement ici, dans le worker, evite une
         * requete par ligne dans le rendu du gabarit sans faire peser ce
         * chargement sur la transaction du webhook.
         */
        $this->order->loadMissing('items');

        Mail::to($this->recipient)->send(new OrderConfirmationMail($this->order));

        Log::info('Confirmation de commande envoyee par email.', [
            'order_uuid' => $this->order->uuid,
            'order_number' => $this->order->order_number,
            'recipient' => $this->recipient,
        ]);
    }
}
