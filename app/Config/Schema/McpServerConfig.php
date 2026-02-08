<?php

namespace App\Config\Schema;

use InvalidArgumentException;
use Prism\Relay\Enums\Transport;

/**
 * MCP Server Configuration Schema
 *
 * Provides type-safe, validated configuration for MCP servers.
 * Used to ensure MCP server configurations are valid at application boot time.
 *
 * Required fields:
 * - name: Server identifier (kebab-case recommended)
 * - command: Array of strings for command execution
 *
 * Optional fields:
 * - timeout: Timeout in seconds (1-600, default: 30)
 * - transport: Transport::Stdio or Transport::Http (default: Stdio)
 * - description: Human-readable description
 * - env: Environment variables to pass to the command
 *
 * Example:
 * ```php
 * $config = new McpServerConfig(
 *     name: 'my-server',
 *     command: ['npx', 'mcp-remote', 'https://api.example.com'],
 *     timeout: 60,
 *     transport: Transport::Stdio,
 *     description: 'My custom MCP server'
 * );
 * ```
 *
 * Validation rules:
 * - Command array must not be empty
 * - All command elements must be strings
 * - Timeout must be between 1 and 600 seconds
 * - Environment variables must be string key-value pairs
 */
readonly class McpServerConfig
{
    /**
     * Create a new MCP server configuration instance
     *
     * @param  string  $name  Server identifier
     * @param  array  $command  Command array (e.g., ['npx', 'mcp-server'])
     * @param  int  $timeout  Timeout in seconds (1-600)
     * @param  Transport  $transport  Transport type
     * @param  string|null  $description  Human-readable description
     * @param  array  $env  Environment variables
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    public function __construct(
        public string $name,
        public array $command,
        public int $timeout = 30,
        public Transport $transport = Transport::Stdio,
        public ?string $description = null,
        public array $env = []
    ) {
        $this->validate();
    }

    /**
     * Create configuration from array
     *
     * @param  string  $name  Server name
     * @param  array  $config  Configuration array from config file
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            command: $config['command'] ?? [],
            timeout: $config['timeout'] ?? 30,
            transport: $config['transport'] ?? Transport::Stdio,
            description: $config['description'] ?? null,
            env: $config['env'] ?? []
        );
    }

    /**
     * Validate configuration
     *
     * @throws InvalidArgumentException If configuration is invalid
     */
    private function validate(): void
    {
        // Validate command is not empty
        if (empty($this->command)) {
            throw new InvalidArgumentException(
                "MCP server '{$this->name}' must have a non-empty command array"
            );
        }

        // Validate all command elements are strings
        foreach ($this->command as $index => $arg) {
            if (! is_string($arg)) {
                throw new InvalidArgumentException(
                    "MCP server '{$this->name}' command must be an array of strings (invalid element at index {$index})"
                );
            }
        }

        // Validate timeout range
        if ($this->timeout < 1 || $this->timeout > 600) {
            throw new InvalidArgumentException(
                "MCP server '{$this->name}' timeout must be between 1 and 600 seconds (got {$this->timeout})"
            );
        }

        // Validate environment variables are string key-value pairs
        foreach ($this->env as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "MCP server '{$this->name}' environment variable keys must be strings"
                );
            }

            if (! is_string($value) && ! is_numeric($value)) {
                throw new InvalidArgumentException(
                    "MCP server '{$this->name}' environment variable '{$key}' must be a string or numeric value"
                );
            }
        }
    }

    /**
     * Convert configuration to array format
     *
     * @return array Configuration array suitable for use with Prism Relay
     */
    public function toArray(): array
    {
        $config = [
            'command' => $this->command,
            'timeout' => $this->timeout,
            'transport' => $this->transport,
        ];

        if (! empty($this->env)) {
            $config['env'] = $this->env;
        }

        return $config;
    }

    /**
     * Get a human-readable summary of this configuration
     *
     * @return string Configuration summary
     */
    public function summary(): string
    {
        $command = implode(' ', $this->command);
        $desc = $this->description ? " - {$this->description}" : '';

        return "{$this->name}: {$command} (timeout: {$this->timeout}s, transport: {$this->transport->value}){$desc}";
    }
}
