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
        Schema::table('products', function (Blueprint $table): void {
            /*
             * Photo du produit.
             *
             * La boutique affiche une image sur la fiche, dans le panier et sur
             * le recapitulatif de commande : sans elle, le parcours n'est pas
             * utilisable, meme en developpement.
             *
             * La colonne stocke une URL absolue, pas un `public_id` Cloudinary.
             * Ce choix se arbitre :
             *
             *   - un `public_id` verrait l'API generer les URL et donc
             *     dependre d'un fournisseur de stockage image, et interdire le
             *     retour a un CDN classique ou a un stockage objet sans changer
             *     le schema ;
             *   - une URL verrait le front consommer l'image telle quelle, ce
             *     qui autorise n'importe quelle source.
             *
             * Si le festival veut un jour generer des declinaisons (webp, vignette
             * carrée pour le panier), la construction d'URL revient au front ou a
             * une couche dediee, et non au modele : la base conserve ce que
             * l'admin a saisi, comme pour le reste du catalogue.
             */
            $table->string('image_url', 2048)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });
    }
};
