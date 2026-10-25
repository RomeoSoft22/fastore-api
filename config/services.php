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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Proveedor de identidad central (identidad.juarezcampos.net). FASTORE es un cliente OAuth
    // confidencial: la administración de la tienda exige un JWT de identidad con aud=FASTORE y un rol admitido.
    'identidad' => [
        // identidad-web (Angular): /authorize y /logout, en el navegador.
        'url' => rtrim((string) env('IDENTIDAD_URL', 'https://identidad.juarezcampos.net'), '/'),
        // API de identidad (Laravel): token y JWKS, servidor a servidor.
        'api_url' => rtrim((string) env('IDENTIDAD_API_URL', 'https://identidad.juarezcampos.net/api'), '/'),
        'issuer' => rtrim((string) env('IDENTIDAD_ISSUER', env('IDENTIDAD_URL', 'https://identidad.juarezcampos.net')), '/'),
        'client_id' => env('IDENTIDAD_CLIENT_ID'),
        'client_secret' => env('IDENTIDAD_CLIENT_SECRET'),
        'redirect_uri' => env('IDENTIDAD_REDIRECT_URI'),
        'post_logout_redirect_uri' => env('IDENTIDAD_POST_LOGOUT_URI'),
        // Código del sistema registrado en identidad (claim "aud" del JWT).
        'audience' => env('IDENTIDAD_AUDIENCE', 'FASTORE'),
        // Roles que pueden administrar la tienda ("*" = cualquier rol de FASTORE).
        'roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('IDENTIDAD_ROLES', 'ADMIN'))))),
        'refresh_cookie' => 'fastore_refresh',
        'refresh_minutes' => (int) env('IDENTIDAD_REFRESH_MINUTOS', 480),
        // Ruta pública de /api/acceso vista por el navegador (vacío = se deduce de la petición).
        'cookie_path' => env('IDENTIDAD_COOKIE_PATH'),
    ],

];
