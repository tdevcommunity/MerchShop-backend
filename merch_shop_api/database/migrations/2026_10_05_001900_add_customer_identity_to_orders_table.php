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
             * Identite de l'acheteur, capturee a la commande.
             *
             * Ces colonnes ne sont pas une commodite de saisie : elles rendent le
             * remboursement possible. Deposer l'argent chez un operateur mobile
             * money exige un numero, et l'echec de ne pas l'avoir enregistre est
             * irreversible — un remboursement sans numero n'a pas d'issue, et
             * aucune donnee du festival ne permet de le reconstituer ensuite.
             *
             * Elles sont donc recopiées sur la commande, et non lues depuis le
             * compte : une commande invitee n'a pas de compte, et c'est
             * precisement elle qu'il faut pouvoir rembourser. Le compte reste
             * la source de verite quand il existe, la commande en est la copie
             * figee au moment de l'achat.
             */
            $table->string('customer_name')->after('order_number');

            /*
             * Numero au format national, sans l'indicatif : c'est la forme qu'attend
             * l'operateur, qui veut le pays separe. Le champ voisin porte ce pays,
             * de sorte que le couple est complet meme si le numero est saisi par
             * une personne et pas par l'acheteur.
             */
            $table->string('customer_phone_number')->after('customer_name');

            /*
             * Code pays, deux lettres minuscules. Non nul parce qu'un numero sans
             * pays n'est pas interpretable : mieux vaut refuser la commande que
             * d'assumer un depot qui part au mauvais operateur.
             */
            $table->string('customer_phone_country', 2)->default('ci')->after('customer_phone_number');

            /*
             * Identifiant du client chez l'operateur, une fois la fiche creee.
             *
             * Nullable : la fiche n'est creee qu'au moment du paiement, jamais a la
             * commande. La creation d'une commande ne doit pas dependre de la
             * disponibilite d'un tiers — elle se fait dans une transaction et
             * bloquerait la reservation de stock sur une panne de l'operateur.
             *
             * Il est conserve plutot que recree a chaque paiement pour que le
             * guichet retrouve la fiche de l'acheteur, et pour qu'un second
             * paiement de la meme commande ne multiplie pas les fiches.
             */
            $table->unsignedBigInteger('fedapay_customer_id')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_name',
                'customer_phone_number',
                'customer_phone_country',
                'fedapay_customer_id',
            ]);
        });
    }
};
