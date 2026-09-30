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
        Schema::table('product_variants', function (Blueprint $table): void {
            /*
             * Taille et couleur deviennent des colonnes a part entiere.
             *
             * Elles vivaient jusque-la dans `name`, ce qui est exact pour
             * l'affichage (« Taille unique », « L / Noir ») mais impossible a
             * analyser : la spec Data du festival (section 13) exige `size` et
             * `color` comme proprietes distinctes de chaque ligne de commande,
             * precisely pour etudier la repartition des ventes par taille et par
             * couleur. Un libelle libre ne permet ni de regrouper par valeur, ni
             * de joindre « L / Noir » a une table de reference.
             *
             * `name` est conserve tel quel : il reste le libelle affiche au
             * client et au guichet, et il n'a pas a disparaitre au profit d'une
             * reconstruction taille + couleur qui perdrait les libelles
             * specifiques d'un produit (« Pocket », « Dos imprime »).
             *
             * Les deux colonnes sont nullable parce qu'un accessoire n'a ni
             * taille ni couleur, et qu'un produit textile peut n'avoir qu'une
             * declinaison « Taille unique ».
             */
            $table->string('size', 50)->nullable()->after('name');
            $table->string('color', 50)->nullable()->after('size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['size', 'color']);
        });
    }
};
