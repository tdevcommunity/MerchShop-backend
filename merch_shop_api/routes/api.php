<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes de l'API
|--------------------------------------------------------------------------
|
| Le prefixe `api` est pose par bootstrap/app.php (withRouting). La version
| vit dans un groupe nomme, afin de pouvoir faire cohabiter /api/v1 et
| /api/v2 pendant une transition, conformement a
| docs/NAMING_CONVENTIONS.md section 5.
|
| Chaque groupe de version delegue a un fichier dedie des que le nombre de
| routes le justifie, afin de garder ce point d'entree lisible. C'est le cas
| ici : routes/api/v1.php regroupe a son tour l'authentification et le
| catalogue, chacun dans son propre fichier.
|
| Attention, ces routes heritent du groupe de middleware "api" configure dans
| bootstrap/app.php, qui inclut la session et la protection CSRF (voir
| AuthService pour les consequences sur l'authentification par cookie).
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    require __DIR__.'/api/v1.php';
});
