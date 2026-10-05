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
        Schema::table('payments', function (Blueprint $table): void {
            /*
             * Adresse de paiement chez l'operateur.
             *
             * Elle est stockee pour deux raisons qui ne se substituent pas.
             *
             * D'abord parce que l'acheteur doit pouvoir reprendre son paiement
             * apres avoir ferme l'onglet ou change de reseau : sans cette colonne,
             * la seule facon de la retrouver serait de redemander un jeton a
             * l'operateur, depuis une route qui n'a pas lieu de dependre de la
             * disponibilite de son service.
             *
             * Ensuite parce qu'elle rend le checkout idempotent. Une relance de
             * la route de paiement rend le lien deja obtenu plutot que d'ouvrir
             * une seconde transaction : deux transactions pour une meme commande
             * sont deux lignes de paiement en attente, et le rapprochement par
             * le webhook devient ambigu.
             *
             * Nullable : une tentative creee avec la commande n'a pas encore ete
             * presentee a l'operateur, donc n'a pas d'adresse.
             */
            $table->text('checkout_url')->nullable()->after('transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('checkout_url');
        });
    }
};
