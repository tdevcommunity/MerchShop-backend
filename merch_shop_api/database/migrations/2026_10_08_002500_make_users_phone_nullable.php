<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le telephone d'un compte de guichet devient optionnel.
 *
 * Aucun ecran d'invitation ne demande de numero : le back-office invite avec un
 * nom, une adresse et un rôle. Exiger un telephone dans la regle et dans la
 * colonne poussait a en inventer un pour satisfaire la contrainte — un compte
 * joignable a personne, avec en prime le risque d'un doublon sur un forfait
 * reel, puisque la colonne est unique.
 *
 * Le numero reste fourni quand on l'a : le champ garde sa regle de format et son
 * unicite, et un compte qui en a un se lit comme avant. La colonne devient
 * simplement ce qu'elle est deja dans les autres tables : vide quand on ne sait
 * rien, plutot que faux.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Reserrer la colonne ici echouerait des que des comptes sans numero
     * existeraient — la contrainte rejetterait leurs lignes — et inventer un
     * numero pour les satisfaire referait exactement ce que la migration
     * ci-dessus refuse. Le retour arriere se fait donc en effacant les comptes
     * concernes a la main, decision qui appartient a l'exploitation et non a une
     * migration.
     */
    public function down(): void
    {
        //
    }
};
