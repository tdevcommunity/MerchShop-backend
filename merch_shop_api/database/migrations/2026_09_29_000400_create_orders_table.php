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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->unique();
            // Nullable : le diagramme impose une commande a un utilisateur, mais
            // le tunnel d'achat du festival sert aussi un acheteur invite. La
            // commande reste rattachable a un compte plus tard sans perdre son
            // historique ; nullOnDelete evite qu'une suppression de compte
            // emporte la commande et ses pieces comptables.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Montants en francs CFA, entier sans subdivision : voir le prix des
            // variantes pour le pourquoi.
            $table->unsignedBigInteger('sub_total');
            $table->text('shipping_address')->nullable();
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedTinyInteger('status')->default(1);
            // Champs du plan de tracking, absents du diagramme de classes.
            // Retrait Jour J ou livraison : seule la premiere genere un QR Code.
            $table->string('fulfillment_method')->nullable();
            // Droit (pending) et usage (picked_up) restent distincts.
            $table->string('pickup_status')->nullable();
            $table->timestamp('pickup_time')->nullable();
            // Rapprochement billetterie <-> shop : identifiant du participant
            // porte par le pass, a l'origine du QR de retrait.
            $table->string('participant_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('participant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
