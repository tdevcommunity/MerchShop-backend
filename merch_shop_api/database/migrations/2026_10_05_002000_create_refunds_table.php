<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Le refund porte sur un paiement precis, pas sur une commande.
             *
             * Une commande porte plusieurs lignes de paiement au fil du temps —
             * une premiere ouverture, un second essai apres un echec, un depot
             * different — et seul celui qui a ete encaisse donne lieu a
             * restitution. Rattacher le refund a la commande obligerait a
             * reparcourir ces lignes pour savoir laquelle est concernee, et
             * ferait dependre le choix d'un simple `latestPayment`, c'est-a-dire
             * d'un ordre de tri qui n'a rien a voir avec le reglement effectif.
             */
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             * Tentative de depot chez l'operateur, en cascade de celle du refund.
             *
             * Nullable parce qu'une ligne de refund existe avant l'appel distant :
             * c'est ce qui permet de laisser une trace de la demande si l'appel
             * echoue, et de ne pas rejouer un depot deja parti.
             */
            $table->string('provider')->nullable();

            /*
             * Reference du depot chez l'operateur. C'est elle que la notification
             * de sortie d'argent ramene, et le seul moyen de relier un evenement
             * recu a une demande interne. Unique : deux refunds ne peuvent pas
             * designer le meme depot sans que l'un des deux soit un doublon.
             */
            $table->string('payout_reference')->nullable()->unique();

            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            /*
             * Etat de la demande : demandee, confirmee, refusee.
             *
             * Distinguer « demande » de « confirmee » est l'objet de cette table.
             * Un seul booleen `rembourse` ne peut pas dire si l'argent est parti,
             * et cette incertitude est justement ce qu'un suivi de remboursement
             * doit rendre visible au guichet.
             */
            $table->smallInteger('status');

            /*
             * Motif de l'echec, conserve tel que l'operateur l'a decrit.
             *
             * Il est stocke et non reformule : « numero inconnu », « solde
             * insuffisant » et « operateur indisponible » n'appellent pas la meme
             * relance. Traduire cet echec en un message unique le reduirait a du
             * bruit.
             */
            $table->string('failure_reason')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            /*
             * Etat que la commande avait quand la demande a ete faite.
             *
             * Un depot refuse doit rendre la commande a son etat anterieur et non a
             * un etat unique : une commande deja prete ou deja retiree n'a pas
             * perdu son paiement, et la ramener a « payee » lui retirerait son
             * droit de retrait — le stand la servirait une seconde fois. Sans
             * cette colonne, l'echec d'un depot serait irreversible.
             */
            $table->smallInteger('order_status_before')->nullable();

            /*
             * Un refund par demande, cherche a chaque confirmation : sans cet
             * index chaque notification declencherait un balayage complet de la
             * table, et l'index unique garantit qu'une seule ligne correspond a
             * un depot donne.
             */
            $table->index(['order_id', 'status']);

            /*
             * Suppression logique, comme les commandes et les paiements.
             *
             * Une demande de restitution est une ecriture comptable : la retirer
             * de la base parce qu'on s'est trompe de bouton ne doit pas effacer la
             * trace d'une somme sortie. Le guichet peut se tromper, l'historique
             * doit rester lisible.
             */
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
