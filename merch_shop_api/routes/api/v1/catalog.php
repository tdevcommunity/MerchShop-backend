<?php

/*
|--------------------------------------------------------------------------
| Catalogue public et administration du catalogue
|--------------------------------------------------------------------------
|
| Deux usages distincts cohabitent sur ces routes, et la separation se lit
| dans la declaration elle-meme :
|
|   - le groupe public lit le catalogue, sans session. C'est la vitrine du
|     shop : un visiteur qui n'a pas de compte doit pouvoir voir les produits,
|     et c'est la seule raison d'etre d'un GET sans authentification ;
|   - le groupe admin ecrit le catalogue. Il exige une session ET le role
|     administrateur, la seconde condition etant portee par les policies
|     (ProductPolicy, CategoryPolicy) et non par un middleware : le role
|     applicable depend de la ressource, une policy le sait, un alias de
|     middleware non.
|
| Aucun jeton n'est lu ici : l'authentification passe par le cookie de
| session pose par le middleware StartSession (voir bootstrap/app.php).
|
*/

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    /*
     | Ecriture reservee au role administrateur.
     |
     | Le middleware `auth` est en place pour que le rate limiter puisse
     | identifier la requete par compte plutot que par IP, et non comme
     | controle d'acces : c'est la policy qui refuse le role insuffisant, avec
     | un 403 plutot qu'un 401, ce qui distingue « pas connecte » de « pas
     | autorise ».
     */
    Route::prefix('categories')->name('categories.')->group(function (): void {
        Route::post('/', [CategoryController::class, 'store'])->name('store');
        Route::put('/{uuid}', [CategoryController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [CategoryController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('products')->name('products.')->group(function (): void {
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::put('/{uuid}', [ProductController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [ProductController::class, 'destroy'])->name('destroy');
    });
});

/*
 | Lecture publique du catalogue.
 */
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{uuid}', [CategoryController::class, 'show'])->name('categories.show');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{uuid}', [ProductController::class, 'show'])->name('products.show');

/*
 | `/products/{uuid}/variants` se declare apres `/products/{uuid}` : sans cela,
 | le segment « variants » serait lu comme un uuid et la requette renverrait
 | un 404 trompeur.
 */
Route::get('/products/{uuid}/variants', [ProductController::class, 'variants'])->name('products.variants');
