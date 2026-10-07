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
        Schema::table('orders', function (Blueprint $table): void {
            /*
             * Le QR Code de retrait est emis a la confirmation du paiement, quand
             * la commande devient retirable. Une commande livree ou annulee n'en a
             * pas, donc la colonne reste nullable.
             *
             * Seule l'empreinte du jeton est conservee. Une lecture en clair
             * permettrait a quiconque aurait acces a la base de fabriquer un QR
             * valide et de servir une commande qui n'a pas ete payee.
             */
            $table->string('pickup_token_hash', 64)->nullable()->unique()->after('pickup_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('pickup_token_hash');
        });
    }
};
