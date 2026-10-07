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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number')->unique();
            // Relation 1-1 : la contrainte d'unicite est portee par la cle
            // etrangere elle-meme, pas seulement par un index. Sans elle, une
            // seconde facture pour la meme commande passerait la validation
            // applicative et aboutirait a une double facturation.
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            // Montants en francs CFA, entier sans subdivision : voir le prix des
            // variantes pour le pourquoi.
            $table->unsignedBigInteger('sub_total');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            // Distingue l'emission (droit comptable) de la creation technique.
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
