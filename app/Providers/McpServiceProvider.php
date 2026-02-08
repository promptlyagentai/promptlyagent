<?php

namespace App\Providers;

use App\Config\Schema\McpServerConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * MCP Service Provider
 *
 * Validates and bootstraps MCP (Model Context Protocol) server configurations.
 * Ensures all MCP server configurations in config/relay.php are valid at boot time.
 *
 * Validation Strategy:
 * - Development: Throws exceptions for invalid configurations (fail fast)
 * - Production: Logs errors and skips invalid servers (graceful degradation)
 *
 * Benefits:
 * - Early detection of configuration errors
 * - Type-safe MCP server definitions
 * - Clear error messages for debugging
 * - Graceful handling in production
 */
class McpServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * Validates MCP server configurations and replaces the config
     * with validated instances.
     */
    public function boot(): void
    {
        // Only validate if relay config exists
        if (! config()->has('relay.servers')) {
            return;
        }

        $this->validateMcpConfiguration();
    }

    /**
     * Validate all MCP server configurations
     *
     * Iterates through relay.servers config and validates each server.
     * In development: throws exceptions for invalid configs
     * In production: logs errors and skips invalid servers
     */
    protected function validateMcpConfiguration(): void
    {
        $servers = config('relay.servers', []);

        // No servers configured - nothing to validate
        if (empty($servers)) {
            return;
        }

        $validated = [];
        $errors = [];

        foreach ($servers as $name => $config) {
            try {
                // Validate and create typed configuration
                $serverConfig = McpServerConfig::fromArray($name, $config);

                // Store validated configuration
                $validated[$name] = $serverConfig->toArray();

                Log::debug('MCP server configuration validated', [
                    'server' => $name,
                    'command' => implode(' ', $serverConfig->command),
                    'timeout' => $serverConfig->timeout,
                ]);
            } catch (InvalidArgumentException $e) {
                $errors[] = [
                    'server' => $name,
                    'error' => $e->getMessage(),
                ];

                // In development: fail fast with detailed error
                if (config('app.debug')) {
                    throw new InvalidArgumentException(
                        "Invalid MCP server configuration: {$e->getMessage()}",
                        previous: $e
                    );
                }

                // In production: log error and continue
                Log::error('Invalid MCP server configuration', [
                    'server' => $name,
                    'error' => $e->getMessage(),
                    'config' => $config,
                ]);
            }
        }

        // Replace config with validated servers only
        config(['relay.servers' => $validated]);

        // Log summary
        if (! empty($validated)) {
            Log::info('MCP server configurations validated', [
                'valid_count' => count($validated),
                'invalid_count' => count($errors),
                'servers' => array_keys($validated),
            ]);
        }

        if (! empty($errors)) {
            Log::warning('Some MCP servers were excluded due to invalid configuration', [
                'excluded_count' => count($errors),
                'errors' => $errors,
            ]);
        }
    }
}
