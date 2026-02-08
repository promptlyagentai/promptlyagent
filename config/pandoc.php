<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pandoc Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Pandoc document conversion microservice.
    | Supports PDF, DOCX, ODT, and LaTeX output formats with multiple
    | professional templates and customizable styling options.
    |
    */

    'service' => [
        'url' => env('PANDOC_URL', 'http://pandoc-lb:80'),
        'timeout' => env('PANDOC_TIMEOUT', 120),
        'retry_times' => env('PANDOC_RETRY_TIMES', 2),
        'retry_delay' => env('PANDOC_RETRY_DELAY', 1000),
        'enabled' => env('PANDOC_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Available Templates
    |--------------------------------------------------------------------------
    |
    | Professional LaTeX templates for PDF generation. Each template includes
    | metadata for UI display and default styling variables.
    |
    */

    'templates' => [
        'eisvogel' => [
            'name' => 'Eisvogel',
            'description' => 'Professional technical documentation with modern styling',
            'preview' => null,
            'supports' => ['pdf'],
            'variables' => [
                'mainfont' => 'Latin Modern Roman',
                'fontsize' => '11pt',
                'papersize' => 'a4',
            ],
        ],
        'elegant' => [
            'name' => 'Elegant',
            'description' => 'Clean business style with generous margins',
            'preview' => null,
            'supports' => ['pdf'],
            'variables' => [
                'mainfont' => 'Latin Modern Roman',
                'fontsize' => '11pt',
                'papersize' => 'a4',
            ],
        ],
        'academic' => [
            'name' => 'Academic',
            'description' => 'Academic papers with double spacing and citations',
            'preview' => null,
            'supports' => ['pdf'],
            'variables' => [
                'mainfont' => 'Times New Roman',
                'fontsize' => '12pt',
                'papersize' => 'a4',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Template
    |--------------------------------------------------------------------------
    |
    | The default template to use when no user preference is set and no
    | template is specified in the URL.
    |
    */

    'default_template' => env('PANDOC_DEFAULT_TEMPLATE', 'eisvogel'),

    /*
    |--------------------------------------------------------------------------
    | Default Typography
    |--------------------------------------------------------------------------
    |
    | Default font settings applied across all templates unless overridden
    | by user preferences or template-specific settings.
    |
    */

    'default_fonts' => [
        'mainfont' => 'Latin Modern Roman',
        'sansfont' => 'Latin Modern Sans',
        'monofont' => 'Courier New',
        'fontsize' => '11pt',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Colors
    |--------------------------------------------------------------------------
    |
    | Default color scheme for hyperlinks matching the application's tropical
    | teal theme. Colors use LaTeX RGB notation (rgb,1:r,g,b) where values
    | are 0-1. Tropical teal 600: #468e93 = rgb(70,142,147) = 0.275,0.557,0.576
    |
    */

    'default_colors' => [
        'linkcolor' => 'rgb,1:0.275,0.557,0.576',  // Tropical teal 600
        'urlcolor' => 'rgb,1:0.275,0.557,0.576',   // Tropical teal 600
        'toccolor' => 'rgb,1:0.275,0.557,0.576',   // Tropical teal 600
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset Handling
    |--------------------------------------------------------------------------
    |
    | Configuration for handling embedded assets (images, graphs) in documents.
    |
    */

    'assets' => [
        'max_download_size' => 10 * 1024 * 1024, // 10MB per asset
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/svg+xml',
            'image/webp',
        ],
        'timeout' => 30, // seconds for external URL downloads
    ],

];
