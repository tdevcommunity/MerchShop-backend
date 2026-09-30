<?php

/*
|--------------------------------------------------------------------------
| Commandes : achat client et service au guichet
|--------------------------------------------------------------------------
|
| Deux usages sur le meme prefixe, et la frontiere est la session :
|
|   - la creation est publique. Un festival vend a des visiteurs qui n'ont pas
|     forcement de compte, et exiger une inscription avant d'ajouter au panier
|     ferait perdre la vente a une part importante du public ;
|   - tout le reste exige une session, et l'etat de la commande est cloisonne
|     par client dans la couche service.
|
| Une commande invitee ne s'Enregistre que si on lui laisse un moyen de la
| retrouver : les deux routes de lecture sont donc publiques elles aussi, et
| l'autorisation y est decidee dans le controleur. Un compte connecte passe par
| OrderPolicy, un invite par le jeton emis a la creation. Sans ce jeton, une
| commande invitee n'accepterait aucune lecture et son proprietaire ne pourrait
| pas afficher son QR apres paiement.
|
| Le role de guichet n'est pas exprime ici mais dans OrderPolicy : ce qui est
| autorise depend de l'action (servir, rembourser, annuler), donc une policy
| le sait et un alias de middleware non.
|
| Le chemin de scan est pose avant les routes `/orders/{uuid}` : sans cela, le
| segment « scan » serait lu comme un uuid, et le guichetier recueillerait un 404
| trompeur au lieu d'une erreur de scan.
|
*/

use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPickupController;
use Illuminate\Support\Facades\Route;

/*
 | Creation publique, avec un limiteur dedie.
 |
 | La route ecrit en base et consomme du stock : c'est la seule de l'API que
 | quelqu'un peut appeler en boucle sans compte. Le plafond est par IP tant
 | qu'aucune session n'existe, ce qui protege le stock des scripts anonymes.
 */
Route::post('/orders', [OrderController::class, 'store'])
    ->middleware('throttle:checkout')
    ->name('orders.store');

/*
 | Lecture d'une commande, ouverte a l'invite qui detient son jeton.
 |
 | Ces routes ne sont pas sous le middleware `auth` parce que leur autorisation
 | n'est pas uniforme : un compte connecte est juge par la policy, un invite par
 | le jeton qu'il presente. Les mettre derriere `auth` refuserait l'invite avant
 | que son jeton soit regarde.
 |
 | En contrepartie, une requete sans session ni jeton est rejetee par la policy
 | avec un 403, et non un 401 : `auth` ne s'applique tout simplement pas a ces
 | deux cas. Le client est donc invite a s'authentifier, puisque c'est ce que
 | la regle lui demande, plutot que de lire « vous n'etes pas connecte » sur une
 | route ou il peut l'etre autrement.
 |
 | Elles restent plafonnees par le limiteur general, qui s'applique par IP
 | lorsqu'aucun compte n'est identifie : chercher un jeton par force brute se
 | heurte donc a la meme barriere qu'un mot de passe.
 */
Route::get('/orders/{uuid}', [OrderController::class, 'show'])->whereUuid('uuid')->name('orders.show');
Route::get('/orders/{uuid}/qr', [OrderPickupController::class, 'qrCode'])->whereUuid('uuid')->name('orders.qr');

/*
 | Guichet : file d'attente et lecture du QR.
 |
 | Le middleware `auth` est pose ici et non delegue a la policy, parce qu'il
 | regle deux questions distinctes : etre identifie, puis avoir le role. Sans
 | lui, un appel anonyme atteindrait la policy et recevrait un 403, qui dit
 | « identifie-toi mal » au lieu de « il faut etre connecte », et qui laisse a
 | deviner si le compte existe.
 */
Route::prefix('pickup')->middleware('auth')->name('pickup.')->group(function (): void {
    Route::get('/orders', [OrderPickupController::class, 'index'])->name('orders.index');

    Route::post('/scan', [OrderPickupController::class, 'scan'])->name('scan');
});

Route::middleware('auth')->group(function (): void {
    Route::prefix('orders')->name('orders.')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');

        /*
         | Transitions d'ecriture.
         |
         | Elles passent toutes par le service, qui refuse un etat incompatible
         | dans la meme transaction que la restitution du stock. Une route
         | dedicatede a chaque transition evite un PATCH libre, ou le client
         | pourrait demander n'importe quel statut et dont l'effet de bord
         | serait decide ailleurs.
         |
         | Aucune n'accepte d'invite : les etats de commande ne se pilotent pas
          | depuis un jeton de lecture, seule l'affichage du QR l'est. Un client sans
          | compte annule donc par le guichet, qui est la voie prevue pour lui.
         */
        Route::post('/{uuid}/cancel', [OrderController::class, 'cancel'])->whereUuid('uuid')->name('cancel');
        Route::post('/{uuid}/ready', [OrderPickupController::class, 'markReady'])->whereUuid('uuid')->name('ready');
        Route::post('/{uuid}/picked-up', [OrderPickupController::class, 'markPickedUp'])->whereUuid('uuid')->name('picked-up');
    });
});
