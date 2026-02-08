<?php

// Scribe API Documentation Configuration (Dynamic/Deployment Version)
// This config generates documentation for live deployments with:
// - Dynamic APP_URL from environment
// - "Try It Out" functionality enabled
// - Authentication using actual API tokens
//
// For static documentation with placeholder URLs (to commit to repository),
// use: php artisan scribe:generate --config scribe-static
//
// Note: This config uses Scribe classes which must be available during config caching.
// If you encounter "Class not found" errors, run: php artisan config:clear

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

use function Knuckles\Scribe\Config\configureStrategy;

// Only the most common configs are shown. See the https://scribe.knuckles.wtf/laravel/reference/config for all.

return [
    // The HTML <title> for the generated documentation.
    'title' => config('app.name').' API Documentation',

    // A short description of your API. Will be included in the docs webpage, Postman collection and OpenAPI spec.
    'description' => 'PromptlyAgent API provides programmatic access to AI-powered research, knowledge management, agent orchestration, and RAG capabilities. Build intelligent applications with multi-agent workflows, semantic search, and real-time streaming.',

    // Text to place in the "Introduction" section, right after the `description`. Markdown and HTML are supported.
    'intro_text' => <<<'INTRO'
        This documentation provides comprehensive information for integrating with the PromptlyAgent API.

        ## Features
        - **Chat & Streaming**: Real-time AI conversations with streaming responses
        - **Knowledge Management**: RAG-powered semantic search and document processing
        - **Agent Orchestration**: Execute and monitor multi-agent workflows
        - **Input Triggers**: Webhook-based automation with IP whitelisting
        - **Output Actions**: Automated integrations with detailed audit logs
        - **PDF Export**: Professional document generation with Pandoc and LaTeX

        ## Additional Documentation
        - **[PDF Export & Document Generation](../docs/08-pdf-export.md)** - Learn how to generate professional PDFs with YAML metadata, custom templates (Eisvogel, Elegant, Academic), and advanced styling options

        ## Rate Limiting
        The API implements tiered rate limiting:
        - 🔴 **Expensive Operations** (10/min): AI operations, streaming, file uploads
        - 🟡 **Moderate Operations** (60/min): Search, reprocessing, updates
        - 🟢 **Read Operations** (300/min): GET requests for viewing/listing

        <aside>Code examples are shown in the right panel. Switch languages using the tabs above.</aside>
    INTRO,

    // The base URL displayed in the docs.
    // If you're using `laravel` type, you can set this to a dynamic string, like '{{ config("app.tenant_url") }}' to get a dynamic base URL.
    'base_url' => config('app.url'),

    // Routes to include in the docs
    'routes' => [
        [
            'match' => [
                // Match only routes whose paths match this pattern (use * as a wildcard to match any characters). Example: 'users/*'.
                'prefixes' => ['api/*', 'webhooks/triggers/*'],

                // Match only routes whose domains match this pattern (use * as a wildcard to match any characters). Example: 'api.*'.
                'domains' => ['*'],
            ],

            // Include these routes even if they match the above patterns.
            'include' => [
                // 'POST /api/user'
            ],

            // Exclude these routes even if they match the above patterns.
            'exclude' => [
                'api/user',
            ],

            // Ignore these controllers completely (methods from these controllers will not be included).
            'excludeControllers' => [
                // 'App\Http\Controllers\Api\Controller',
            ],

            // Ignore these methods from controllers.
            'excludeMethods' => [
                // 'App\Http\Controllers\Api\Controller@method',
            ],
        ],
    ],

    // API type ('laravel' or 'external').
    'type' => 'laravel',

    // Configure how responses are generated when response calls are being made.
    // See https://scribe.knuckles.wtf/laravel/reference/config#database_connections_to_transact
    'database_connections_to_transact' => [config('database.default')],

    // Set this to the name of a Laravel HTTP middleware that delegates authentication of endpoints to them.
    // See https://scribe.knuckles.wtf/laravel/advanced/authentication
    'auth_middleware' => ['auth:sanctum'],

    // If set, Scribe will try to generate responses by making actual HTTP requests to your routes when possible.
    // You can configure response calls at the `ResponseCalls` strategy level.
    'try_it_out' => [
        'enabled' => true,
        'base_url' => config('app.url'),
    ],

    // How is your API authenticated? This information will be used in the displayed docs, generated examples and response calls.
    'auth' => [
        // Set this to true if ANY endpoints in your API use authentication.
        'enabled' => true,

        // Set this to true if your API should be authenticated by default. If so, you must also set `enabled` (above) to true.
        // You can then use @unauthenticated or @authenticated on individual endpoints to change their status from the default.
        'default' => true,

        // Where is the auth value meant to be sent in a request?
        'in' => AuthIn::BEARER->value,

        // The name of the auth parameter (e.g. token, key, apiKey) or header (e.g. Authorization, Api-Key).
        'name' => 'Authorization',

        // The value of the parameter to be used by Scribe to authenticate response calls.
        // This will NOT be included in the generated documentation. If empty, Scribe will use a random value.
        'use_value' => env('SCRIBE_AUTH_KEY'),

        // Placeholder your users will see for the auth parameter in the example requests.
        // Set this to null if you want Scribe to use a random value as placeholder instead.
        'placeholder' => '{YOUR_AUTH_KEY}',

        // Any extra authentication info for your users. Markdown and HTML are supported.
        'extra_info' => 'You can retrieve your API key from your dashboard at <a href="'.config('app.url').'/settings/tokens">Settings > API Tokens</a>.',
    ],

    // How to order groups in the docs.
    'groups_order' => [
        'Chat & Interactions',
        'Agents & Executions',
        'Knowledge Management',
        'Input Triggers & Webhooks',
        'Output Actions',
        '*', // Order other groups by name
    ],

    // Custom Blade template to use for the docs.
    'theme' => 'promptlyagent',

    // Custom CSS file to use for styling the docs.
    'custom_css' => null,

    // Custom JS file for extra interactivity in the docs.
    'custom_js' => null,

    // Should Scribe generate a Postman collection in addition to HTML docs?
    'postman' => [
        'enabled' => true,
        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    // Should Scribe generate an OpenAPI spec in addition to HTML docs?
    'openapi' => [
        'enabled' => true,
        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    // Settings for `php artisan scribe:generate`.
    'generate' => [
        'cleanDestination' => true,
    ],

    // Where to put the generated file.
    // For 'static' type, should be a URL path (eg 'docs'). Will be accessible at <app_url>/docs.
    // For 'laravel' type, should be a route path (excluding the base URL). Will be accessible at <base_url>/docs.
    'docs_path' => 'docs',

    // Whether to automatically create the docs/index.html file (static) or register the routes (laravel).
    // For 'static' type, will create docs/index.html and assets.
    // For 'laravel' type, will register the routes in your app.
    // You can set this to false and handle the routing yourself.
    'router' => 'laravel',

    // Logo to use in the docs. Will be displayed in the left sidebar.
    'logo' => false,

    // Settings for generating example requests.
    'examples' => [
        'languages' => [
            'bash',
            'javascript',
            'php',
            'python',
        ],
        // Output format for bash examples. Can be 'curl' or 'wget'.
        'bash_format' => 'curl',
    ],

    // Set this to an array of database field names (like ['name', 'email']) that should be masked when generating example data in docs
    'faker_seed' => null,

    // Set to null to disable overriding IDs in examples with fake IDs. Set to a number to use as seed.
    'fractal' => null,

    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,
        ],
        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],
        'urlParameters' => [
            ...Defaults::URL_PARAMETERS_STRATEGIES,
        ],
        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],
        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,
        ],
        'responses' => configureStrategy(
            Defaults::RESPONSES_STRATEGIES,
            Strategies\Responses\ResponseCalls::withSettings(
                only: ['GET *'],
                // Recommended: disable debug mode in response calls to avoid error stack traces in responses
                config: [
                    'app.debug' => false,
                ]
            )
        ),
        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    'fractal' => [
        'serializer' => null,
    ],

    'postman' => [
        'enabled' => true,
        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    'openapi' => [
        'enabled' => true,
        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],
];
