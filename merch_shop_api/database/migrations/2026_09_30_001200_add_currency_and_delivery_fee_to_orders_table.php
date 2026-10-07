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
             * Devise du montant.
             *
             * La spec Data du festival (section 13) exige `currency` sur chaque
             * commande. Le festival n'encaisse qu'en francs CFA et le franc CFA
             * n'a pas de subdivision, donc la devise est aujourd'hui une
             * constante : elle est malgre tout stockee, parce qu'un montant sans
             * devise n'est pas interpretable, et qu'un jour ou le festival ouvre
             * une autre devise, la modifier sur les lignes existantes ferait
             * dire "XOF" a des montants qui ne l'etaient pas.
             */
            $table->string('currency', 3)->default('XOF')->after('discount');

            /*
             * Frais de livraison, distincts du sous-total et de la remise.
             *
             * La spec Data (section 13) les liste separement du total, parce que
             * le revenu du festival et le panier moyen ne doivent pas confondre ce
             * qui vient de la vente de goodies avec ce qui vient du transport.
             *
             * Ils sont nuls pour un retrait au stand, qui est le cas par defaut.
             * Le montant applicable a une livraison vient de la configuration, pas
             * du client : un montant envoye dans le payload de commande
             * permettrait d'ecrire le chiffre a encaisser.
             */
            $table->unsignedBigInteger('delivery_fee')->default(0)->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'delivery_fee']);
        });
    }
};
