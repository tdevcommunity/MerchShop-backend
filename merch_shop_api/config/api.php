<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version courante de l'API
    |--------------------------------------------------------------------------
    |
    | La version vit dans les groupes de routes (routes/api.php) afin de
    | pouvoir faire cohabiter /api/v1 et /api/v2 pendant une transition.
    | Cette valeur n'est informative : elle est exposée par l'endpoint
    | de health et sert de référence à la documentation.
    |
    */

    'version' => env('API_VERSION', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Valeurs par défaut de la pagination des endpoints de liste.
    | Les controleurs doivent lire ces valeurs plutot que d'ecrire des
    | nombres en dur, afin que le comportement reste homogene sur toute l'API.
    |
    */

    'pagination' => [
        'default_per_page' => (int) env('API_DEFAULT_PER_PAGE', 15),
        'max_per_page' => (int) env('API_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limitation de debit
    |--------------------------------------------------------------------------
    |
    | Plafonds appliques par le middleware throttle:api sur le groupe de
    | middleware "api", et par les limiteurs dedies a l'authentification.
    |
    | L'authentification repose sur une session, pas sur un jeton porteur : un
    | client qui perd son cookie n'est pas distinguishable d'un attaquant, donc
    | le reequilibrage des quotas par IP devient indispensable. Les valeurs de
    | login et register sont plus severes que celle de l'API generale car ces
    | deux routes concentrent les tentatives automatisables.
    |
    */

    'throttle' => [
        'per_minute' => (int) env('API_THROTTLE_PER_MINUTE', 60),
        'login_per_minute' => (int) env('API_THROTTLE_LOGIN_PER_MINUTE', 5),
        'login_per_hour' => (int) env('API_THROTTLE_LOGIN_PER_HOUR', 60),
        'register_per_hour' => (int) env('API_THROTTLE_REGISTER_PER_HOUR', 10),

        /*
         * Passage de commande : plus bas que le plafond general, parce que la
         * route est publique et reserve du stock. Vingt commandes par minute
         * suffisent largement a un festivalier qui commande pour son groupe, et
         * reste tres en deca de ce qu'un script de reservation de stock
         * tenterait.
         */
        'checkout_per_minute' => (int) env('API_THROTTLE_CHECKOUT_PER_MINUTE', 20),

        /*
         * Ouverture d'un paiement : route publique elle aussi, puisqu'un invite
         * paie avec le jeton qu'il a recu. Elle est en revanche bien plus
         * sensible que le passage de commande, car chaque appel consomme un
         * credits chez l'operateur. Le seau reste large pour un client qui
         * change de reseau mobile et recommence, et bas pour qu'un script ne
         * puisse pas ouvrir des transactions a la place des festivaliers.
         */
        'payment_per_minute' => (int) env('API_THROTTLE_PAYMENT_PER_MINUTE', 10),

        /*
         * Notifications d'operateur : large, parce qu'un agregateur rejoue en
         * rafale apres une coupure. Ce plafond protege d'un envoi massif
         * depuis une seule adresse, pas d'un fonctionnement normal.
         */
        'webhook_per_minute' => (int) env('API_THROTTLE_WEBHOOK_PER_MINUTE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enveloppe de reponse
    |--------------------------------------------------------------------------
    |
    | Cle de premier niveau des reponses succes. Les erreurs utilisent
    | toujours la cle "error" (voir App\Exceptions\ApiException et le rendu
    | des exceptions enregistre dans bootstrap/app.php).
    |
    */

    'envelope' => [
        'data_key' => 'data',
        'error_key' => 'error',
    ],

];
