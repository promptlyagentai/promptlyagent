<?php

// Scribe Static API Documentation Configuration
// This config generates static documentation with placeholder URLs that can be committed to the repository.
// Use: php artisan scribe:generate --config scribe-static
//
// Inherits from config/scribe.php and only overrides deployment-specific settings.

// Load the base configuration
$baseConfig = require __DIR__.'/scribe.php';

// Override only the static-specific settings
return array_merge($baseConfig, [
    // STATIC: Generate standalone HTML instead of Laravel routes
    'type' => 'static',

    // STATIC: Output directory for static HTML (not committed - we sync to docs repo)
    'static' => [
        'output_path' => 'public/docs-export',
    ],

    // STATIC: Hardcoded title (not dependent on config('app.name'))
    'title' => 'PromptlyAgent API Documentation',

    // STATIC: Add note about placeholder URL to intro text
    'intro_text' => $baseConfig['intro_text']."\n\n> **Note:** This is static documentation. Replace `https://your-instance.example.com` with your actual PromptlyAgent deployment URL.",

    // STATIC: Placeholder base URL instead of dynamic config('app.url')
    'base_url' => 'https://your-instance.example.com',

    // STATIC: Disabled "Try It Out" functionality
    'try_it_out' => [
        'enabled' => false,
    ],

    // STATIC: Authentication overrides
    'auth' => array_merge($baseConfig['auth'], [
        // No auth value for static docs
        'use_value' => null,
        // Relative path instead of absolute URL
        'extra_info' => 'You can retrieve your API key from your dashboard at <a href="/settings/tokens">Settings > API Tokens</a>.',
    ]),
]);
