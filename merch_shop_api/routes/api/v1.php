<?php

/*
|--------------------------------------------------------------------------
| Routes de la version 1 de l'API
|--------------------------------------------------------------------------
|
| Point de regroupement de la version. Le prefixe `v1` est pose ici, jamais
| dans les sous-fichiers, afin qu'une v2 puisse etre ajoutee sans avoir a
| toucher aux routes existantes.
|
| Un fichier par domaine plutot qu'un seul fichier de routes : la lecture
| d'un ajout se fait alors dans le seul fichier du domaine concerne, et
| l'authentification reste separee du catalogue.
|
*/

require __DIR__.'/v1/auth.php';
require __DIR__.'/v1/catalog.php';
require __DIR__.'/v1/orders.php';
require __DIR__.'/v1/webhooks.php';
require __DIR__.'/v1/analytics.php';
