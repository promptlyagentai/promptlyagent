<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for CORS headers to allow Chrome extension and other
    | cross-origin requests to access the API.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_merge(
        [env('APP_URL')],
        env('APP_ENV') === 'local' ? [
            'http://localhost:8000',
            'http://127.0.0.1:8000',
            'http://localhost',
        ] : []
    ),

    'allowed_origins_patterns' => array_merge(
        [
            '/^chrome-extension:\/\/.*/',
            '/^moz-extension:\/\/.*/',
        ],
        env('APP_ENV') === 'local' ? [
            '/^http:\/\/localhost:\d+$/',
            '/^http:\/\/127\.0\.0\.1:\d+$/',
        ] : []
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
