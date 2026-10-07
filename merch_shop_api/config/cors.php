<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | L'API est consommee par le front Next.js (merch-shop-frontend) et par
    | l'app mobile de scan. Les origines autorisees sont declarees dans le
    | fichier .env, jamais "*" : une origine large en production autoriserait
    | n'importe quel site a appeler l'API avec le cookie/token d'un visiteur.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ))),

    'allowed_origins_patterns' => [],

    /*
    | X-XSRF-TOKEN est obligatoire : l'authentification repose sur un cookie de
    | session, donc toute ecriture doit prouver qu'elle vient bien du front
    | declare dans CORS_ALLOWED_ORIGINS et non d'un site tiers (protection
    | CSRF). Le front lit le cookie XSRF-TOKEN et le renvoie dans ce header.
    */
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-Request-Id',
        'X-XSRF-TOKEN',
        'X-CSRF-TOKEN',
        'X-Order-Token',
    ],

    'exposed_headers' => ['X-Request-Id', 'XSRF-TOKEN'],

    'max_age' => (int) env('CORS_MAX_AGE', 3600),

    /*
    | Le cookie de session ne circule que si le navigateur est explicitement
    | autorisé a l'envoyer avec credentials: 'include' (côté front). Cette
    | bascule est donc le pendant serveur de ce réglage.
    |
    | Elle rend la liste d'origines ci-dessus critique : le protocole CORS
    | interdit "*" des que supports_credentials vaut true, et une origine trop
    | large permettrait a n'importe quel site d'appeler l'API en sessions.
    */
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
