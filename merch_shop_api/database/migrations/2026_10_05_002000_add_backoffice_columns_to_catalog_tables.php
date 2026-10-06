<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes demandees par le back-office sur le catalogue.
 *
 * Le tunnel n'a jamais eu besoin de mettre en avant un article, de classer les
 * rayons a la main, ni de savoir a partir de quand une declinaison doit
 * alerter : ces trois notions n'existaient donc pas en base. Le back-office, lui,
 * les propose — un produit « en vedette » sur la page d'accueil, un ordre
 * d'affichage des rayons saisi a la main, un seuil de stock propre a chaque
 * declinaison.
 *
 * Elles sont ajoutees ici plutot que supprimees du back-office parce que
 * chacune est une question a laquelle le stock a une reponse :
 *
 *  - `featured` et `sort_order` sont des choix de l'organisateur. Les inventer
 *    cote client serait les ecrire dans un etat qui survit a rien : un
 *    rechargement, une autre session, et l'affichage changerait ;
 *
 *  - `low_stock_threshold` est le seul endroit ou la reponse existe. Une alerte
 *    de stock calculee par le front serait un seuil different sur chaque poste
 *    du stand, et deux guichetiers ne verraient pas la meme alerte pour le meme
 *    article.
 *
 * Les valeurs par defaut reprennent ce que le back-office affichait deja :
 * seuil a cinq articles, et tous les produits actifs, tries par ordre de
 * creation. Un catalogue existant ne doit donc pas changer d'apparence du fait
 * de cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            /*
             * Ordre d'affichage saisi au guichet.
             *
             * Defaut a zero plutot qu'a un : la migration ne doit pas
             * se charger elle-meme a classer les rayons, et une colonne a zero
             * se distingue d'une colonne rempli par le back-office. Les rayons
             * existants restent donc dans l'ordre de creation — l'ordre dans
             * lequel ils etaient de toute facon affiches — jusqu'a ce que
             * quelqu'un les range.
             */
            $table->unsignedSmallInteger('sort_order')->default(0)->after('status');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('featured')->default(false)->after('status');

            /*
             * Galerie d'images, au-dela de la photo principale.
             *
             * JSON et non une table : un produit a quelques photos, elles ne sont
             * ni cherchees ni filtrees, et leur ordre est celui de la galerie.
             * Les promotions comme les tailles et les couleurs vivent sur la
             * declinaison ; la galerie, elle, decrit le produit — c'est le meme
             * produit en plusieurs kiosques, donc elle ne change pas d'une
             * declinaison a l'autre.
             */
            $table->json('images')->nullable()->after('image_url');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            /*
             * A partir de combien d'exemplaires le stand doit etre prevenu.
             *
             * Propre a la declinaison et non au produit : un tee-shirt en XS et
             * le meme en XXL n'ont pas la meme rotations, et un seuil unique
             * obligerait a choisir un des deux au detriment de l'autre. Cinq est
             * la valeur qu'affichait le back-office avant que la colonne
             * n'existe — la reprendre evite de faire crier au stock ce qui ne
             * l'etait pas.
             */
            $table->unsignedSmallInteger('low_stock_threshold')->default(5)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('low_stock_threshold');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['featured', 'images']);
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};