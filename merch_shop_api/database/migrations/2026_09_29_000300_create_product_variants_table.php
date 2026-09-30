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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Reference commerciale : le guichet vend par SKU, pas par nom.
            $table->string('sku')->unique();
            // Porte la taille et la couleur (produits textile) ; un accesoire
            // n'en aura qu'une.
            $table->string('name');
            /*
             * Prix en francs CFA, la seule devise du festival.
             *
             * Le franc CFA (XOF) n'a pas de subdivision : un montant payable est
             * toujours un nombre entier de francs. La colonne est donc un entier,
             * et non un decimal a deux decimales : un prix de 2500,50 FCFA n'a
             * aucun sens ici, il ne serait ni encaisse par un mobile money ni
             * lisible sur un ticket. Stocker un entier rend cette impossibilite
             * structurelle plutot que relies sur une validation.
             */
            $table->unsignedBigInteger('price');
            // unsignedInteger rend la colonne non signee dans le schema, mais
            // ni PostgreSQL ni SQLite n'appliquent cette contrainte au niveau
            // du stockage. La vraie garantie vient de la contrainte CHECK
            // ajoutee juste apres (voir plus bas).
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'status']);
        });

        // Contrainte de non-negativite reelle : sans elle, une decrementation
        // qui se contredit enregistre un stock negatif et acte une survente
        // silencieuse. Ajoutee apres creation, car le schema builder de
        // Laravel n'expose pas les contraintes CHECK.
        //
        // MySQL 8.0.16+ et PostgreSQL supportent ADD CONSTRAINT ; SQLite non,
        // la suite de tests tournant sur SQLite ne verifie donc pas cette
        // contrainte (le service de commande reste la garantie applicative).
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            Schema::getConnection()->statement(
                'ALTER TABLE product_variants ADD CONSTRAINT product_variants_stock_non_negative CHECK (stock >= 0)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
