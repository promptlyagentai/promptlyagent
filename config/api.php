<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Pagination Settings
    |--------------------------------------------------------------------------
    |
    | Controls pagination limits across API endpoints to prevent resource
    | exhaustion attacks. Large page sizes can cause memory exhaustion,
    | database overload, and slow response times.
    |
    */

    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
        'max_per_page_search' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting configuration for API endpoints. Can be overridden
    | per endpoint or scope as needed.
    |
    */

    'rate_limiting' => [
        'default' => 60,
        'authenticated' => 120,
        'scopes' => [
            'knowledge:create' => 30,
            'knowledge:view' => 120,
            'agents:execute' => 20,
        ],
    ],
];
