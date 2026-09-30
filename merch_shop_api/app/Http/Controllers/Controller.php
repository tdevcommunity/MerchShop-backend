<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Base des controleurs.
 *
 * Elle apporte les deux traits standards de Laravel :
 *
 *  - AuthorizesRequests pour `authorize()`, qui delègue aux policies. L'API
 *    s'appuie dessus pour l'ecriture du catalogue, donc l'absence du trait
 *    rendrait cette verification invisible et son oubli silencieux ;
 *  - ValidatesRequests pour la validation inline, que l'API n'utilise pas
 *    (elle passe par ApiRequest) mais que conserver evite une difference
 *    avec le squelette attendu par l'equipe.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
