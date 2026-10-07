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
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();

            /*
             * Journal d'evenements bruts, en ajout seul.
             *
             * Cette table materialise le principe directeur de la spec Data du
             * festival (section 2) : on conserve d'abord les donnees brutes, les
             * KPI se calculent ensuite. Aucune ligne n'est donc mise a jour ni
             * ecrasee (section 26) : corriger une propriete passe par un nouvel
             * evenement, jamais par une reecriture, sinon l'historique de
             * comportement dispararait au premier nettoyage.
             *
             * `properties` porte tout ce qui n'a pas de colonne dediee. La
             * section 2 demande explicitement de garder les proprietes d'un
             * evenement meme lorsqu'une donnee parait peu utile isolement, parce
             * qu'elle devient interessante une fois croisee : un modele
             * fige, lui, fait perdre ces donnees a la volee.
             */
            $table->uuid('event_id')->unique();
            $table->string('event_name', 100);
            $table->json('properties')->nullable();

            /*
             * `event_time` est l'heure reelle de l'action, envoyee par le client.
             * `created_at` est l'heure de reception par le serveur : les deux sont
             * distinctes des que la page a ete ouverte avant l'action, et la
             * section 19 demande de pouvoir les differencier.
             */
            $table->timestamp('event_time');
            $table->timestamp('received_at');

            /*
             * Identifiants de recoupement (section 3). `session_id` relie les
             * evenements d'un meme visiteur, `participant_id` ceux d'une meme
             * personne a travers la billetterie, la boutique et le scan.
             * `ticket_id` n'est pas alimente par la boutique : il est laisse
             * present pour que le meme evenement puisse porter les deux, le jour
             * ou le Shop reference un billet.
             */
            $table->string('session_id', 64);
            $table->string('participant_id', 100)->nullable();
            $table->string('ticket_id', 64)->nullable();
            $table->string('page', 500)->nullable();
            $table->string('product_id', 64)->nullable();

            /*
             * Contexte technique (section 18) et acquisition (section 15). Ces
             * colonnes restent des proprietes de l'evenement et non un profil :
             * un visiteur peut legitimately changer de terminal, et figer son
             * appareil au niveau de la session perdrait cette nuance.
             */
            $table->string('device_type', 50)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('campaign', 100)->nullable();

            $table->timestamps();

            /*
             * Les index portent les trois lectures reelles du festival : le
             * parcours d'un participant, la conversion d'un produit, et le
             * compte d'un evenement sur une plage de dates.
             */
            $table->index(['participant_id', 'event_time'], 'analytics_events_participant_time_idx');
            $table->index(['product_id', 'event_time'], 'analytics_events_product_time_idx');
            $table->index(['event_name', 'event_time'], 'analytics_events_name_time_idx');
            $table->index(['session_id', 'event_time'], 'analytics_events_session_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
