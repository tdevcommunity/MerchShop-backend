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
             * Adresse email de l'acheteur, capturee a la commande.
             *
             * Nullable : une commande peut etre passee sans email, et le refuser
             * a la caisse ferait perdre une vente pour un champ de confort. En
             * revanche, quand il est present, il permet d'envoyer le QR de
             * retrait et la confirmation de paiement apres encaissement.
             *
             * Priorite sur `users.email` quand il existe : il est fige au
             * moment de l'achat, comme `customer_name` et
             * `customer_phone_number`, et reste valable meme si le compte est
             * modifie ou supprime apres coup.
             */
            $table->string('customer_email')->nullable()->after('customer_phone_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('customer_email');
        });
    }
};
