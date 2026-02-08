<?php

namespace App\Console\Commands;

use App\Config\Schema\McpServerConfig;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Validate MCP Configuration Command
 *
 * Validates all MCP server configurations without starting the full application.
 * Useful for CI/CD pipelines and configuration testing.
 *
 * Usage:
 *   ./vendor/bin/sail artisan mcp:validate-config
 *   ./vendor/bin/sail artisan mcp:validate-config --verbose
 *
 * Exit Codes:
 *   0 - All configurations valid
 *   1 - One or more configurations invalid
 *
 * Features:
 * - Validates all servers in config/relay.php
 * - Shows detailed validation errors
 * - Displays configuration summaries in verbose mode
 * - Safe to run in CI/CD without side effects
 */
class ValidateMcpConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcp:validate-config
                            {--verbose : Show detailed configuration information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate MCP server configurations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Validating MCP Server Configurations');
        $this->newLine();

        $servers = config('relay.servers', []);

        if (empty($servers)) {
            $this->warn('No MCP servers configured in config/relay.php');

            return self::SUCCESS;
        }

        $validServers = [];
        $invalidServers = [];

        foreach ($servers as $name => $config) {
            try {
                $serverConfig = McpServerConfig::fromArray($name, $config);

                $validServers[] = $serverConfig;

                $this->components->info("✓ {$name}");

                if ($this->option('verbose')) {
                    $this->line('  Command: '.implode(' ', $serverConfig->command));
                    $this->line("  Timeout: {$serverConfig->timeout}s");
                    $this->line("  Transport: {$serverConfig->transport->value}");

                    if ($serverConfig->description) {
                        $this->line("  Description: {$serverConfig->description}");
                    }

                    if (! empty($serverConfig->env)) {
                        $this->line('  Environment: '.count($serverConfig->env).' variables');
                    }

                    $this->newLine();
                }
            } catch (InvalidArgumentException $e) {
                $invalidServers[] = [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ];

                $this->components->error("✗ {$name}");
                $this->line("  Error: {$e->getMessage()}");
                $this->newLine();
            }
        }

        // Display summary
        $this->newLine();
        $this->info('VALIDATION SUMMARY');

        $totalServers = count($servers);
        $validCount = count($validServers);
        $invalidCount = count($invalidServers);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Servers', $totalServers],
                ['Valid', $validCount],
                ['Invalid', $invalidCount],
            ]
        );

        if ($invalidCount > 0) {
            $this->newLine();
            $this->error("Failed: {$invalidCount} server(s) have invalid configuration");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Success: All {$validCount} MCP server configurations are valid");

        return self::SUCCESS;
    }
}
