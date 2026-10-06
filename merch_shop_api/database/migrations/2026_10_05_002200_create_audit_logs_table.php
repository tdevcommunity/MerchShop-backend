<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit du back-office.
 *
 * Ce qui distingue cette table du journal de stock, c'est qu'elle ne tient pas
 * un etat mais une question : « qui a change quoi, et depuis quoi ». Les
 * ajustements de stock ont leur propre table parce que leur cause est une
 * difference de nombre ; ici la cause est une difference de valeur, et elle
 * n'a pas d'autre endroit ou vivre.
 *
 * Deux proprietes non negociables :
 *
 *  - elle est append-only, comme le journal de stock. Corriger une trace ne
 *    se fait pas en la modifiant mais en ecrivant l'action inverse, ce qui laisse
 *    voir le detour ;
 *
 *  - elle est ecrite par le code qui modifie, et non par le code qui lit. Un
 *    journal enregistre apres coup dependrait de ne pas oublier, et son absence
 *    ne se verrait que le jour ou quelqu'un en aurait besoin — c'est-à-dire le
 *    jour ou elle sert.
 *
 * Les valeurs avant et apres sont des chaines, et non des colonnes structurees :
 * ce qui change peut etre un role, une quantite, un libelle, et les comparer
 * demande de lire les deux cotes, pas d'ecrire une colonne par type d'attribut.
 *
 * `resource_id` est une chaine libre pour la meme raison : la ressource concernee
 * est identifiee par son uuid, qui n'a pas le meme format selon l'objet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Auteur.
             *
             * Nullable et `nullOnDelete` : une trace ne disparait jamais parce
             * qu'un compte a ete supprime. C'est tout l'interet d'un journal — un
             * compte qu'on efface pour cacher une trace laisse la trace.
             *
             * L'adresse est recopiee pour la meme raison que le nom du produit
             * dans le journal de stock : elle doit rester lisible sans que le
             * compte existe encore.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_email');

            /*
             * Ce qui a ete fait, et sur quoi.
             *
             * `action` est un verbe au passe simple, tel qu'il se lit au guichet :
             * « role_change », « stock_adjusted », « product_created ». Aucune
             * contrainte en base : la liste s'allonge au fil des chantiers, et
             * l'autorite reste le code.
             *
             * `resource` est le nom du modele, `resource_id` son identifiant
             * public — uuid, donc le meme format partout, contrairement a la
             * cle primaire interne.
             */
            $table->string('action', 64);
            $table->string('resource', 64);
            $table->string('resource_id', 64)->nullable();

            /*
             * La valeur avant et la valeur apres, en JSON quand il y en a une.
             *
             * Nullable parce que la creation et la suppression n'ont pas de
             * « avant » ni d'« apres » : la ligne dit ce qui est cree, ou ce qui
             * n'existe plus, et le tableau doit pouvoir dire « — » plutot que de
             * laisser croire a une valeur vide.
             *
             * `json` et non `text` : le lecteur du journal doit pouvoir
             * distinguer « la valeur etait nulle » de « la valeur etait la
             * chaine vide », et `json_encode(null)` est justement `null` la ou
             * `json_encode('')` est `""`.
             */
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();

            $table->timestamps();

            /* Le journal se lit du plus recent au plus ancien, et par auteur. */
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};