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
        Route::put('/{uuid}', [CategoryController::class, 'update'])->whereUuid('uuid')->name('update');
        Route::delete('/{uuid}', [CategoryController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
    });

    Route::prefix('products')->name('products.')->group(function (): void {
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::put('/{uuid}', [ProductController::class, 'update'])->whereUuid('uuid')->name('update');
        Route::delete('/{uuid}', [ProductController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
    });
});

/*
 | Lecture publique du catalogue.
 */
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{uuid}', [CategoryController::class, 'show'])->whereUuid('uuid')->name('categories.show');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');

/*
 | Lecture d'un produit par son slug.
 |
 | La boutique affiche des URL lisibles (`/shop/t-shirt-tdev-2026`) alors que la
 | cle technique reste l'uuid, exposee partout ailleurs par la ressource et le
 | plan de tracking. Sans cette route, une URL lisible se resoudrait en
 | parcourant le catalogue page par page, ce qui casse des le deuxieme
 | chapitre : le slug n'est adressable que si l'API sait le resoudre.
 |
 | Le prefixe `by-slug` n'est pas decoratif. Il ecarte toute collision avec
 | `/products/{uuid}` ci-dessous, qui ne porte qu'un segment : meme si cette
 | route etait declaree apres, `by-slug` ne pourrait pas y etre lu comme un
 | uuid. Les deux formes restent ainsi adresseables sans qu'un slug puisse
 | masquer un identifiant.
 */
Route::get('/products/by-slug/{slug}', [ProductController::class, 'showBySlug'])
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('products.show-by-slug');

/*
 | `whereUuid` sur les routes `{uuid}` : le parametre part ensuite en base, ou
 | PostgreSQL le rejette si la chaine n'est pas un uuid, en renvoyant une erreur
 | 500. Une URL malformee est une requete erronee, pas une panne du service, et
 | doit donc repondre 404 comme n'importe quelle ressource inexistante. La
 | contrainte est posee ici plutot que dans chaque controleur pour que la
 | correction ne puisse pas etre oubliee a la prochaine route ajoutee.
 */
Route::get('/products/{uuid}', [ProductController::class, 'show'])->whereUuid('uuid')->name('products.show');

/*
 | `/products/{uuid}/variants` se declare apres `/products/{uuid}` : sans cela,
 | le segment « variants » serait lu comme un uuid et la requette renverrait
 | un 404 trompeur.
 */
Route::get('/products/{uuid}/variants', [ProductController::class, 'variants'])->whereUuid('uuid')->name('products.variants');
