<?php

declare(strict_types=1);

use Prism\Relay\Enums\Transport;

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Configurations
    |--------------------------------------------------------------------------
    |
    | Define your MCP (Model Context Protocol) server configurations here.
    | Each server is validated at application boot time to ensure correctness.
    |
    | REQUIRED FIELDS:
    | - command: Array of strings for command execution (e.g., ['npx', 'mcp-server'])
    |
    | OPTIONAL FIELDS:
    | - timeout: Timeout in seconds (1-600, default: 30)
    | - transport: Transport::Stdio or Transport::Http (default: Stdio)
    | - description: Human-readable description of the server
    | - env: Array of environment variables (string key-value pairs)
    |
    | VALIDATION:
    | - Command array must not be empty
    | - All command elements must be strings
    | - Timeout must be between 1 and 600 seconds
    | - Environment variables must be string key-value pairs
    | - Invalid configurations throw exceptions in development
    | - Invalid configurations are logged and skipped in production
    |
    | TESTING:
    | Run ./vendor/bin/sail artisan mcp:validate-config to validate without starting the app
    |
    | EXAMPLE:
    | 'my-server' => [
    |     'command' => ['npx', 'mcp-remote', 'https://api.example.com'],
    |     'timeout' => 60,
    |     'transport' => \Prism\Relay\Enums\Transport::Stdio,
    |     'description' => 'My custom MCP server',
    |     'env' => [
    |         'API_KEY' => env('MY_API_KEY'),
    |     ],
    | ],
    |
    */
    'servers' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Definition Cache Duration
    |--------------------------------------------------------------------------
    |
    | This value determines how long (in minutes) the tool definitions fetched
    | from MCP servers will be cached. Set to 0 to disable caching entirely.
    |
    | Caching improves performance by reducing repeated MCP server calls but
    | means tool definition changes won't be reflected until cache expires.
    |
    */
    'cache_duration' => env('RELAY_TOOLS_CACHE_DURATION', 60),
];
