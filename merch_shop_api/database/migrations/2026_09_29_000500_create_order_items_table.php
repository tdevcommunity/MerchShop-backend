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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Ligne supprimee avec sa commande : une commande annulee n'a pas
            // d'historique de lignes a conserver.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // La variante est memorisee pour la reference produit, mais nullable
            // : un article sans declinaison (stickers, agenda) en reste depourvu.
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            // Prix unitaire fige a l'achat. Il ne doit jamais etre relu depuis
            // le catalogue : le client n'est jamais une source de verite, et une
            // promotion ulterieure ne doit pas reecrire le passe.
            // Montants en francs CFA, entier sans subdivision : voir le prix des
            // variantes pour le pourquoi.
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_price');
            // Copies du nom au moment de l'achat : la facture doit rester
            // fidele a ce qui a ete vendu meme apres renommage du produit.
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
