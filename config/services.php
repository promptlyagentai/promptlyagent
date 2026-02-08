<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'searxng' => [
        'url' => env('SEARXNG_URL', 'http://searxng-lb:80'),
    ],

    'markitdown' => [
        'url' => env('MARKITDOWN_URL', 'http://markitdown-lb:80'),
    ],

    'mermaid' => [
        'url' => env('MERMAID_URL', 'http://mermaid-lb:80'),
        'timeout' => (int) env('MERMAID_TIMEOUT', 60),
        'retry_times' => (int) env('MERMAID_RETRY_TIMES', 2),
        'retry_delay' => (int) env('MERMAID_RETRY_DELAY', 1000),
        'enabled' => (bool) env('MERMAID_ENABLED', true),
        // PDF export constraints to prevent LaTeX image size errors
        'max_width_pdf' => (int) env('MERMAID_MAX_WIDTH_PDF', 2000),
        'scale_pdf' => (int) env('MERMAID_SCALE_PDF', 4),
    ],

    'pandoc' => config('pandoc.service'),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
