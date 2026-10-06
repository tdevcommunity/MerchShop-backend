<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pays par defaut du numero de telephone de l'acheteur.
 *
 * La colonne a ete creee avec la valeur par defaut du pays voisin, `'ci'`, et
 * `PhoneNumber` a suivi la meme faute : la commande etait validee sur un numero
 * ivoirien alors que le festival, l'API et le compte utilisateur sont togolais
 * (`RegisterRequest` valide sur `^\+?228[0-9]{8}$`).
 *
 * Corriger la regle ne suffit pas : `customer_phone_country` porte un `NOT NULL`,
 * et la commande la remplit par defaut a partir du schema. Une commande
 * enregistree sans pays explicite garderait donc `ci`, et son remboursement
 * partirait vers l'operateur du mauvais pays. La valeur de la regle et celle du
 * schema doivent dire la meme chose.
 *
 * Les lignes deja ecrites ne sont pas retouchees. Elles ont ete saisies avec un
 * numero que l'ancienne regle acceptait, donc `ci` est, pour elles, une donnee
 * reelle et non une erreur de saisie : les changer en `tg` enverrait un
 * remboursement togolais vers un numero ivoirien, ce qui serait pire que de
 * garder la trace de ce qui s'est passe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_phone_country', 2)->default('tg')->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_phone_country', 2)->default('ci')->change();
        });
    }
};