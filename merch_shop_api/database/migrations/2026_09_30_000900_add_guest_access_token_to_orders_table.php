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
             * Le passage de commande est public : un visiteur sans compte peut
             * acheter, mais il n'a aucun moyen de retrouver sa commande ensuite,
             * les routes de lecture exigeant une session. Le jeton de cette
             * colonne est la seule voie de retour vers sa commande, et donc
             * vers son QR de retrait.
             *
             * Il est emis une seule fois, a la creation, et n'est pas derivable :
             * il tire 32 octets aleatoires, sa faible entropie n'etant pas un
             * probleme, contrairement a un contenu calcule depuis l'uuid de la
             * commande, que quiconque pourrait recalculer en connaissant cet
             * uuid. Seule l'empreinte est stockee, comme pour le QR lui-meme.
             */
            $table->string('guest_access_token_hash', 64)->nullable()->unique()->after('pickup_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('guest_access_token_hash');
        });
    }
};
