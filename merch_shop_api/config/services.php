<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'cloudinary' => [
        'url' => env('CLOUDINARY_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fedapay' => [
        'public_key' => env('FEDAPAY_PUBLIC_KEY'),
        'secret_key' => env('FEDAPAY_SECRET_KEY'),
        'environment' => env('FEDAPAY_ENVIRONMENT', 'sandbox'),
    ],

    'velto' => [
        'public_key' => env('VELTO_PUBLIC_KEY'),
        'secret_key' => env('VELTO_SECRET_KEY'),
        'base_url' => env('VELTO_BASE_URL', 'https://velto-gate.kiiako.io/v1'),
        /*
         * Origine envoyee a Velto (cle pk_*) : doit etre whiteliste dans
         * Applications > Securite & Domaines. Par defaut APP_URL (API).
         */
        'web_origin' => env('VELTO_WEB_ORIGIN', env('APP_URL', 'http://localhost:8000')),
        'default_withdrawal_operator' => env('VELTO_DEFAULT_WITHDRAWAL_OPERATOR', 'mtn_open'),
        /*
         * Pays par defaut sur le checkout MM heberge (ex. bj pour Momo Test sandbox).
         * Sans numero : le contact togolais de la commande n'est pas envoye a Velto.
         */
        'checkout_phone_country' => env('VELTO_CHECKOUT_PHONE_COUNTRY', 'bj'),
        'checkout_phone_country_in_sandbox_only' => env('VELTO_CHECKOUT_PHONE_COUNTRY_SANDBOX_ONLY', true),
        'sandbox_unique_customer_email' => env('VELTO_SANDBOX_UNIQUE_CUSTOMER_EMAIL', false),
    ],

];
