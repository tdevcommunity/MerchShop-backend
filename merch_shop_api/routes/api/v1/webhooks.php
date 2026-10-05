<?php

/*
|--------------------------------------------------------------------------
| Notifications de paiement
|--------------------------------------------------------------------------
|
| Une seule route, par agregateur, et c'est volontaire : le chemin d'URL est ce
| que l'operateur configure une fois dans son tableau de bord, et il ne doit
| pas pouvoir designer un agregateur par une valeur qu'il choisit lui-meme.
|
| Cette route sort du montage session/CSRF pose dans bootstrap/app.php. Le
| webhook vient d'une machine : elle n'a pas de cookie, donc pas de jeton a
| renvoyer, et la protection contre les requetes forgees par un site tiers
| n'a pas de sens ici — elle ferait echouer toutes les notifications avec un
| 419 avant meme que la signature soit lue.
|
| Retirer la session evite aussi une ecriture de session en base pour chaque
| notification requee, ce qui n'apporterait rien : la signature de la requete,
| et elle seule, dit qui l'a envoyee.
|
| La suppression des middlewares est donc un choix delibere et non un oubli.
| Elle ne vaut que parce que le controleur verifie la signature avant toute
| autre chose : `FedapayWebhookController` pour FedaPay, et
| `PaymentWebhookController` pour les agregateurs qui partagent encore le
| chemin generique.
|
*/

use App\Enums\PaymentProvider;
use App\Http\Controllers\Api\V1\FedapayPayoutWebhookController;
use App\Http\Controllers\Api\V1\FedapayWebhookController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::prefix('payments/webhooks')
    ->name('payments.webhooks.')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        PreventRequestForgery::class,
    ])
    ->group(function (): void {
        foreach (PaymentProvider::cases() as $provider) {
            /*
             * FedaPay a son propre controleur, car sa notification n'a ni la
             * meme forme ni la meme signature que celle des agregateurs restant a
             * integrer. Les faire tous pointers sur le controleur generique
             * echouerait des la verification de signature, et le webhook resterait
             * muet sans lever d'alarme.
             *
             * L'URL, elle, reste identique et lisible : c'est elle que
             * l'operateur saisit une fois dans son tableau de bord, et elle ne
             * doit pas changer parce que la maniere de traiter la notification a
             * evolve.
             */
            $controller = $provider === PaymentProvider::FEDAPAY
                ? FedapayWebhookController::class
                : PaymentWebhookController::class;

            Route::post('/'.$provider->value, [$controller, 'handle'])
                ->defaults('provider', $provider->value)
                /*
                 * Limiteur propre, plus large que celui de l'API generale : un
                 * operateur rattrape des notifications en rafale apres une
                 * coupure, et les refuserait s'il tombait sous le plafond
                 * general. Il n'est pas illimite non plus.
                 */
                ->middleware('throttle:webhook')
                ->name($provider->value);
        }

        /*
         | Depots d'argent.
         |
         | Chemin distinct de celui des encaissements, et non une seconde
         | variante du meme : FedaPay notifie la sortie d'argent sur une adresse
         | separee, qu'il faut donc declarer dans son tableau de bord, et le
         | controleur est different parce que l'evenement dit autre chose. Un
         | depot ne rend pas une commande payee, il la cloture.
         |
         | Aucun autre agregateur n'a de route ici tant que son passerelle de
         | depot n'existe pas : une route qui repondrait 404 laisserait croire a
         | une integration presente.
         */
        Route::post('/'.PaymentProvider::FEDAPAY->value.'/payouts', [FedapayPayoutWebhookController::class, 'handle'])
            ->defaults('provider', PaymentProvider::FEDAPAY->value)
            ->middleware('throttle:webhook')
            ->name('fedapay.payouts');
    });
