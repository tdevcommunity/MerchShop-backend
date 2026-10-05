<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identifiants Cloudinary
    |--------------------------------------------------------------------------
    |
    | Lus depuis les variables d'environnement. Laisser vides pour désactiver
    | l'upload automatique : le service retournera null et le contrôleur
    | utilisera l'image_url fournie directement, ou ne changera pas la photo
    | existante.
    |
    */

    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),

    'api_key'    => env('CLOUDINARY_API_KEY'),

    'api_secret' => env('CLOUDINARY_API_SECRET'),

    'url'        => env('CLOUDINARY_URL'),

    /*
    |--------------------------------------------------------------------------
    | Dossier de destination
    |--------------------------------------------------------------------------
    |
    | Préfixe du public_id : toutes les photos de produits seront rangées sous
    | ce dossier dans la médiathèque Cloudinary.
    |
    */

    'folder' => env('CLOUDINARY_FOLDER', 'merch-shop/products'),

];
