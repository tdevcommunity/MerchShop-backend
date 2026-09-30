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
| Elle ne vaut que parce que PaymentWebhookController verifie la signature du
| corps avant toute autre chose.
|
*/

use App\Enums\PaymentProvider;
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
            Route::post('/'.$provider->value, [PaymentWebhookController::class, 'handle'])
                /*
                 * Le chemin est litteral, mais l'action a besoin de savoir de
                 * quel operateur il s'agit. La valeur est donc injectee comme
                 * parametre de route : le controleur ne la devine pas du chemin,
                 * et un operateur inconnu ne peut pas designer le nom d'un autre
                 * operateur en l'envoyant dans le corps.
                 */
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
    });
