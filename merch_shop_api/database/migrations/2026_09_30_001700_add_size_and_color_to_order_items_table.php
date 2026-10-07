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
             * Taille et couleur vendues, figees comme le nom du produit.
             *
             * La spec Data (section 13) demande `size` et `color` pour chaque
             * produit commande, et `analytics_events` ne pourra pas les produire
             * seul : la ligne de commande est le seul endroit ou elles sont
             * fiables, donc il faut les y conserver au moment de la vente.
             *
             * Elles ne sont pas relues depuis la variante. Une variante qui change
             * de couleur entre deux festivals ne doit pas faire dire a la commande
             * de l'an dernier que le T-shirt vendu etait de l'autre couleur, et le
             * comptage des ventes par taille deviendrait faux.
             *
             * Nullable, comme sur la variante : un accessoire n'a ni taille ni
             * couleur.
             */
            $table->string('size', 50)->nullable()->after('variant_name');
            $table->string('color', 50)->nullable()->after('size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['size', 'color']);
        });
    }
};
