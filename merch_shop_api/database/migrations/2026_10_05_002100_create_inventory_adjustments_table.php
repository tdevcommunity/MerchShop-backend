<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des ajustements de stock.
 *
 * Deux besoins distincts, que la seule colonne `stock` des declinaisons ne peut
 * pas servir :
 *
 *  - le guichet doit pouvoir corriger une erreur de saisie ou de reception, et
 *    il doit le faire de facon traçable. Un ecrasement du stock laisse un
 *    article que le stand ne peut pas servir, sans qu'on puisse dire quand ni
 *    pourquoi il en reste si peu ;
 *
 *  - le plan de tracking reconcile le stock reel avec le stock annonce. Cette
 *    reconciliation n'a de sens que si chaque ecart a une cause ecrite, sinon
 *    elle ne fait que deplacer l'ecart d'un document a un autre.
 *
 * Le journal est donc append-only : une ligne par ajustement, jamais modifiee.
 * Corriger une erreur de saisie ne se fait pas en editant une ligne mais en
 * saisissant l'ajustement inverse, ce qui laisse les deux mouvements visibles —
 * c'est la seule facon de savoir combien d'articles ont circule pour de vrai.
 *
 * Le stock avant et apres sont recopies dans la ligne plutot que recalcules :
 * le stock d'aujourd'hui ne dirait rien de la valeur qui a declenche l'alerte,
 * et le premier terme se calcule par difference, le second est constate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id();

            /*
             * Identifiant public, comme partout ailleurs.
             *
             * L'ajustement est expose au back-office sans etre une commande : il
             * n'a donc personne a qui refuser l'acces, mais il apparait dans une
             * URL et dans un tableau, ou un identifiant interne exposerait le
             * volume du catalogue sans rien apprendre de plus.
             */
            $table->uuid('uuid')->unique();

            /*
             * La declinaison ajustee, et l'etat dans lequel elle etait.
             *
             * La cle etrangere ne supprime pas en cascade : une declinaison peut
             * disparaitre du catalogue, et son stock doit rester reconciliable
             * apres. C'est `nullOnDelete` qui exprime cela — l'historique survit,
             * la ligne d'inventaire ne peut plus etre reliee a rien, et le
             * journal dit alors pourquoi.
             */
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            /*
             * Les noms figes au moment du mouvement.
             *
             * Un ajustement doit rester lisible meme apres un renommage, et
             * surtout apres une suppression : le tableau d'inventaire liste des
             * lignes dont le produit n'existe plus, et une ligne qui ne peut plus
             * nommer ce qu'elle compte ne sert a rien. C'est le meme traitement
             * que le nom fige sur les lignes de commande.
             */
            $table->string('sku', 64);
            $table->string('product_name');

            /*
             * Les trois etats du stock, dans l'ordre.
             *
             * `previous_stock` et `next_stock` sont ecrits et non deduits : ils
             * sont la seule trace de la valeur qui a declenche l'alerte, celle
             * d'aujourd'hui ne la reproduirait pas.
             */
            $table->integer('previous_stock');
            $table->integer('delta');
            $table->integer('next_stock');

            /*
             * Motif, en une colonne courte.
             *
             * Les valeurs possibles sont celles de l'enumeration `InventoryReason`
             * : reception, correction, damaged, loss, return, inventory. Le
             * motif `sale` y figure aussi dans le vocabulaire du back-office,
             * mais il n'a pas sa place ici : une vente decremente le stock dans
             * la transaction qui cree la commande, et elle est deja tracee par la
             * ligne de commande. Ecrire aussi un ajustement ferait compter deux
             * fois la meme sortie.
             *
             * Aucune contrainte d'exclusion en base : le vocabulaire appartient au
             * code, et une contrainte ici devrait etre reecrite a chaque ajout
             * d'un motif, sans que la base soit mieux protegee — c'est
             * l'enumeration qui refuse la valeur, avant l'ecriture.
             */
            $table->string('reason', 32);

            /* Free text : « 12 pieces remise par le stand merch nord ». */
            $table->string('note')->default('');

            /*
             * Qui a fait l'ajustement.
             *
             * L'adresse est recopiee comme le nom du produit : le journal doit
             * rester lisible si le compte est supprime ou desactive par la suite.
             * `nullOnDelete` pour la meme raison — la trace survit a l'auteur.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_email');

            $table->timestamps();

            /*
             * Les trois lectures du journal, et elles n'ont pas le meme tri.
             *
             * L'historique d'une declinaison se lit du plus recent au plus
             * ancien — c'est la derniere entree qui explique l'etat actuel.
             * L'historique d'un stand se lit par ordre chronologique inverse
             * egalement. Un index sur `created_at` sert les deux, et un index
             * `(variant, date)` sert le premier sans passer par un tri de table
             * entiere a chaque ouverture d'une fiche.
             */
            $table->index('created_at');
            $table->index(['product_variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};