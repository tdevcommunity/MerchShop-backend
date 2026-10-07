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
        Schema::create('visitor_acquisitions', function (Blueprint $table): void {
            $table->id();

            /*
             * Origine d'une session de visite (section 15 de la spec Data).
             *
             * Cette table est la seule exception a la regle « on n'ecrase pas »,
             * et l'exception est volontaire : la section 15 demande de conserver
             * `first_visit_at` ET `last_visit_at` pour un meme visiteur, ce qui est
             * impossible si chaque visite cree une ligne. Une ligne par session,
             * avec le premier et le dernier passage mis a jour, est la seule
             * facon de tenir les deux.
             *
             * L'historique n'est pas perdu pour autant : `analytics_events`
             * conserve chaque passage, y compris les pages vues, donc le
             * nombre de visites et leur chronologie restent reconstituables.
             */
            $table->string('session_id', 64)->unique();

            // Les cinq parametres UTM, plus la source brute du referrer.
            $table->string('source', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->string('campaign', 100)->nullable();
            $table->string('content', 200)->nullable();
            $table->string('term', 200)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('landing_page', 500)->nullable();

            $table->timestamp('first_visit_at');
            $table->timestamp('last_visit_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_acquisitions');
    }
};
