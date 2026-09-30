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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // 1-* : une commande peut enchainer plusieurs paiements (reglement
            // partiel puis solde, ou relance apres un echec). La ligne est
            // supprimee avec la commande.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Montants en francs CFA, entier sans subdivision : voir le prix des
            // variantes pour le pourquoi.
            $table->unsignedBigInteger('amount');
            $table->string('method');
            // Infrastructure ayant traite l'appel, distincte du moyen choisi
            // par l'acheteur.
            $table->string('provider')->nullable();
            // Reference operateur, pour reconcilier un webhook avec la ligne.
            // Nullable avant l'appel ; unique ensuite, car un operateur ne peut
            // pas declarer deux fois la meme transaction. Cette unicite est ce
            // qui rend le traitement du webhook idempotent.
            $table->string('transaction_id')->nullable()->unique();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('paid_at')->nullable();
            // Motif conserve pour tout echec : le plan de tracking exige de
            // garder les echecs, pas seulement les paiements reussis.
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
