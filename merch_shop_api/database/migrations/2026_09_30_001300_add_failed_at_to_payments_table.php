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
             * Date d'echec du paiement.
             *
             * La spec Data du festival (section 14) distingue trois horodatages
             * par transaction : creation, aboutissement, echec. Deux existent
             * deja, `created_at` (creation) et `paid_at` (aboutissement) ; celui
             * qui manquait est l'echec.
             *
             * Sans lui, un abandon de paiement et une transaction jamais tentee
             * produisent la meme ligne, et le taux d'echec par moyen de paiement
             * devient impossible a calculer. C'est une des distinctions que la
             * spec demande explicitement de conserver (section 20 : un tableau de
             * bord qui ne montre que les succes donne une vision incomplete).
             *
             * Nullable : une transaction aboutie n'a pas de date d'echec.
             */
            $table->timestamp('failed_at')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('failed_at');
        });
    }
};
