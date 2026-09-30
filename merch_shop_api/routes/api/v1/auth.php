<?php

/*
|--------------------------------------------------------------------------
| Points d'entree de l'authentification
|--------------------------------------------------------------------------
|
| Ce fichier expose le minimum necessaire au front pour ouvrir une session :
| obtenir un jeton CSRF, s'inscrire, se connecter, se deconnecter, lire son
| profil.
|
| Le jeton CSRF passe avant tout, y compris avant la connexion. C'est
| volontaire : toutes ces routes sont en ecriture, donc protegees par
| ValidateCsrfToken, et sans jeton aucun appel ne pourrait aboutir. Le front
| appelle donc GET /auth/csrf-token en premier, puis renvoie le jeton recu
| dans l'en-tete X-XSRF-TOKEN a chaque ecriture ulterieure.
|
| La deconnexion et le profil exigent une session, mais pas de role
| particulier : ce sont les seules operations reservees aux comptes
| authentifies, sans notion d'administration.
|
*/

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    /*
     * Public et sans throttle. Une limitation ici rendrait la premiere
     * connexion d'un visiteur impossible des que le quota de l'API est
     * epuise, alors que cette route ne fait que lire le jeton de la session.
     */
    Route::get('/csrf-token', [AuthController::class, 'csrfToken'])->name('csrf-token');

    /*
     * Limiteurs dedies, plus severes que celui de l'API generale : ce sont les
     * deux seules routes publiques acceptant un mot de passe, donc les deux
     * seules ou une automatisation a un interet. Voir AppServiceProvider.
     */
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register')
        ->name('register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
    });
});
