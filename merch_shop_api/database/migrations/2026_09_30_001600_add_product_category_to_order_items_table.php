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
        Schema::table('order_items', function (Blueprint $table): void {
            /*
             * Categorie du produit, figee au moment de la commande.
             *
             * Meme traitement que `product_name` et `variant_name` : la ligne de
             * commande conserve ce qui a ete vendu, pas l'etat actuel du
             * catalogue. Un produit deplace de « Textiles » vers « Accessoires »
             * ne doit pas faire disparaitre les ventes de textile de l'historique.
             *
             * La spec Data (section 13) liste `product_category` parmi les donnees
             * a conserver par produit commande, ce qui suppose de pouvoir la lire
             * sans remonter au catalogue courant.
             */
            $table->string('product_category', 100)->nullable()->after('product_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('product_category');
        });
    }
};
