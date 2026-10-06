<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a servi la commande.
 *
 * Le retrait enregistre l'heure et le QR consomme, mais pas la personne. Au
 * moment du festival, c'est pourtant la seule question a laquelle on ne peut pas
 * repondre apres coup : deux guichetiers se relaient, une file se Melange, et un
 * article Manque, « qui l'a servi ? » n'a alors aucune reponse. La commande dit
 * qu'elle est sortie, pas par qui.
 *
 * La colonne est donc nullable pour une raison qui n'est pas laTolerance a
 * l'absence : elle est vide pour toute commande qui n'a pas encore ete servie,
 * et elle le resterait pour les commandes livrees, qu'aucun guichetier ne sert.
 * C'est le meme traitement que `pickup_time`, dont elle est l'auteur.
 *
 * `nullOnDelete` et non cascade : une commande servie doit continuer de dire par
 * qui elle a ete servie meme si le compte du guichetier disparait apres le
 * festival. C'est le meme choix que le journal d'audit, pour la meme raison —
 * une trace ne disparait pas avec son auteur.
 *
 * Rien n'est rempli au moment ou la commande est creee, et c'est volontaire :
 * l'ordre de service n'est pas connu a la vente, et le deviner serait
 * fabriquer une reponse a une question qui ne l'etait pas encore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('picked_up_by_user_id')
                ->nullable()
                ->after('pickup_time')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('picked_up_by_user_id');
        });
    }
};