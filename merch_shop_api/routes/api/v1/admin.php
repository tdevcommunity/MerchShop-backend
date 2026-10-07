<?php

/*
|--------------------------------------------------------------------------
| Back-office : le festival vu du guichet
|--------------------------------------------------------------------------
|
| Toutes ces routes partagent un prefixe et une question d'entree : ce compte
| a-t-il le role de gerer le merch ? Elle est posee une fois, par le middleware
| `backoffice`, et jamais route par route — c'est ce qui garantit qu'une route
| d'administration ajoutee plus tard ne puisse pas etre publiee par oubli.
|
| Les permissions individuelles ne sont pas dans ce fichier. Elles dependent de
| l'action (« changer l'etat d'une commande » n'est pas « corriger le stock »),
| donc elles sont decidees dans les controleurs et les services, qui savent ce
| qu'ils couvrent. Un alias de middleware ne voit pas la route qu'il protege,
| donc il ne peut pas distinguer ces deux cas.
|
| La frontiere avec les routes de stand est nette, et elle ne porte pas sur le
| role mais sur ce que l'action affirme. `OrderPickupController` sert une
| commande scannee au comptoir ; `AdminOrderController` annule, prepare et
| enregistre qui a servi. Aucune des deux ne peut declarer un encaissement ni un
| remboursement : l'argent se declare chez FedaPay. Cette separation est
| documentee sur `OrderService::advanceTo()`, qui est le seul aiguillage entre
| les deux et qui refuse explicitement les trois etats d'argent.
|
| Les statuts ne sont pas ecrits directement par une route generique. Il n'y a
| pas de « PUT /orders/{uuid}/status » accepting anything : la route `/status`
| passe par `advanceTo()`, qui refuse un etat d'argent avec la raison plutot que
| de l'ignorer. Une route generique aurait produit une commande « payee » sans
| argent, ce que le service de paiement rend impossible par construction.
|
| Le prefixe `/admin` est sous `/api/v1` comme le reste : une distinction
| d'URL ne doit pas se transformer en distinction de version, parce que le
| contrat est le meme — ce sont les memes ressources, lues autrement.
|
*/

use App\Http\Controllers\Api\V1\Admin\AdminAuditController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminInventoryController;
use App\Http\Controllers\Api\V1\Admin\AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\AdminSearchController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
 | Un groupe unique plutot que sept groupes identiques.
 |
 | Le middleware est pose une fois sur le prefixe, donc aucune route de ce
 | fichier ne peut exister sans lui — pas meme une ajoutee par erreur plus tard,
 | qui heriterait du groupe par construction.
 */
Route::prefix('admin')->middleware('backoffice')->name('admin.')->group(function (): void {
    /*
     | Commandes.
     |
     | `transitions` est exposee avant `{uuid}` : sans cela, le segment serait lu
     | comme un identifiant de commande et la route repondrait « commande
     | introuvable » a une demande parfaitement valide. C'est le meme arbitrage que
     | pour la route de scan cote stand, et pour la meme raison : le chemin
     | doit etre pose avant qu'un segment variable puisse le capturer.
     |
     | `status` est en `PATCH` et non en `POST` : la commande reste la meme
     | ressource, c'est son etat qui change. Et la seule chose que cette route
     | accepte est un etat que le guichet constate — la liste exacte est portee
     | par `OrderService::counterTransitions()`, exposee ici pour que le front
     | construise son menu a partir de la regle et non d'un tableau recopie.
     */
    Route::get('/orders/transitions', [AdminOrderController::class, 'transitions'])->name('orders.transitions');
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{uuid}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{uuid}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');

    /*
     | Paiements, en lecture seule.
     |
     | Aucune route d'ecriture, et c'est le contrat : un back-office qui
     | declarait un encaissement partirait de la declaration d'un tiers, ce que
     | le service de paiement refuse par construction. Le rapprochement se fait
     | par `transactionId`, expose par la ressource.
     */
    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/{uuid}', [AdminPaymentController::class, 'show'])->name('payments.show');

    /*
     | Stock.
     |
     | `inventory/adjustments` est pose avant `inventory/{uuid}`, pour la meme
     | raison que `transitions` : « adjustments » n'est pas un uuid, et sans
     | cet ordre la route du journal serait capturée par celle de l'ajustement et
     | repondrait « declinaison introuvable ».
     |
     | L'ajustement est un `POST` et non un `PATCH` de la declinaison : la
     | declinaison est modifiee, mais ce qui est cree est un mouvement de stock —
     * une ligne de journal datée et signee. Le `POST` dit ce qui existe apres
     | l'appel, ce que ne dit pas une modification de ressource.
     */
    Route::get('/inventory/adjustments', [AdminInventoryController::class, 'logs'])->name('inventory.adjustments');
    Route::get('/inventory', [AdminInventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory/{uuid}/adjust', [AdminInventoryController::class, 'adjust'])->name('inventory.adjust');

    /*
     | Ecran d'accueil, recherche, alertes et journal.
     |
     | Une seule route chacun, en lecture : ce sont des vues, pas des ressources
     | qu'on ecrit. Les alertes n'ont volontairement pas de route de creation ni
     | de route « marquer comme lu » — voir `AdminNotificationController`, qui
     | explique pourquoi une notification derivee n'a pas d'etat de lecture.
     */
    Route::get('/dashboard', [AdminDashboardController::class, 'show'])->name('dashboard');
    Route::get('/search', AdminSearchController::class)->name('search');
    Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('notifications');
    Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit');

    /*
     | Comptes de guichet.
     |
     | Cette liste ne contient que les comptes `admin` et `staff`. Les clients
     | n'en font pas partie, et c'est un choix de perimetre et non un droit : un
     | back-office n'a rien a faire de la liste des acheteurs, et un guichet qui
     | y verrait un client chercherait quel role lui donner.
     |
     | `reset-password` est une action a part entiere et non un champ de `PATCH`.
     | Elle reinitialise le mot de passe de quelqu'un, ce qui se voit et se
     | journalise ; l'integrer dans une correction de nom le ferait passer en
     | sourdine, a la suite d'un formulaire de profil.
     *
     | Le verrou qui empeche un administrateur de se retirer son propre role ou de
     | se desactiver est dans le controleur, sur l'identite de l'appelant : un
     * alias de middleware ne peut pas le poser, puisqu'il ne voit pas le corps de
     | la requete.
     */
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::get('/users/{uuid}', [AdminUserController::class, 'show'])->name('users.show');
    Route::patch('/users/{uuid}', [AdminUserController::class, 'update'])->name('users.update');
    Route::post('/users/{uuid}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');
});