<?php

namespace App\Services\Agents\Config;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agent Configuration Registry
 *
 * Auto-discovers and registers agent configuration classes from the Config/Agents/ directory.
 * Provides a central registry for accessing agent configurations by identifier or filtering
 * by categories.
 *
 * **Auto-Discovery:**
 * - Scans app/Services/Agents/Config/Agents/ for PHP files
 * - Instantiates classes extending AbstractAgentConfig
 * - Validates each configuration
 * - Caches configurations in memory for performance
 *
 * **Registry API:**
 * ```php
 * $registry = app(AgentConfigRegistry::class);
 *
 * // Get specific config
 * $config = $registry->get('research-assistant');
 *
 * // Check existence
 * if ($registry->has('research-assistant')) { ... }
 *
 * // Get all configs
 * $allConfigs = $registry->all();
 *
 * // Filter configs
 * $userFacing = $registry->getUserFacingAgents();
 * $research = $registry->getResearchAgents();
 * $system = $registry->getSystemAgents();
 * ```
 *
 * **Usage in Seeders:**
 * ```php
 * $registry = app(AgentConfigRegistry::class);
 * foreach ($registry->all() as $config) {
 *     Agent::updateOrCreate(
 *         ['slug' => $config->getIdentifier()],
 *         $config->toArray()
 *     );
 * }
 * ```
 *
 * **Error Handling:**
 * - Invalid configurations are logged but don't halt discovery
 * - Missing files or invalid classes are skipped gracefully
 * - Validation errors are logged for debugging
 *
 * @see \App\Services\Agents\Config\AbstractAgentConfig
 */
class AgentConfigRegistry
{
    /**
     * @var array<string, AbstractAgentConfig> Registered configurations by identifier
     */
    protected array $configs = [];

    /**
     * @var bool Whether configurations have been loaded
     */
    protected bool $loaded = false;

    /**
     * @var string Path to agent configuration directory
     */
    protected string $configPath;

    /**
     * Create a new registry instance
     */
    public function __construct()
    {
        $this->configPath = app_path('Services/Agents/Config/Agents');
    }

    /**
     * Get all registered agent configurations
     *
     * @return array<string, AbstractAgentConfig> Configurations by identifier
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->configs;
    }

    /**
     * Get configuration by identifier
     *
     * @param  string  $identifier  Agent identifier (slug)
     * @return AbstractAgentConfig|null Configuration or null if not found
     */
    public function get(string $identifier): ?AbstractAgentConfig
    {
        $this->ensureLoaded();

        return $this->configs[$identifier] ?? null;
    }

    /**
     * Check if configuration exists
     *
     * @param  string  $identifier  Agent identifier (slug)
     * @return bool True if configuration exists
     */
    public function has(string $identifier): bool
    {
        $this->ensureLoaded();

        return isset($this->configs[$identifier]);
    }

    /**
     * Get count of registered configurations
     *
     * @return int Number of registered configurations
     */
    public function count(): int
    {
        $this->ensureLoaded();

        return count($this->configs);
    }

    /**
     * Get all user-facing agent configurations
     *
     * @return array<string, AbstractAgentConfig> Configurations visible in chat
     */
    public function getUserFacingAgents(): array
    {
        $this->ensureLoaded();

        return array_filter(
            $this->configs,
            fn (AbstractAgentConfig $config) => $config->isUserFacing()
        );
    }

    /**
     * Get all research agent configurations
     *
     * @return array<string, AbstractAgentConfig> Configurations available for research
     */
    public function getResearchAgents(): array
    {
        $this->ensureLoaded();

        return array_filter(
            $this->configs,
            fn (AbstractAgentConfig $config) => $config->isAvailableForResearch()
        );
    }

    /**
     * Get all system/internal agent configurations
     *
     * @return array<string, AbstractAgentConfig> System-only configurations
     */
    public function getSystemAgents(): array
    {
        $this->ensureLoaded();

        return array_filter(
            $this->configs,
            fn (AbstractAgentConfig $config) => $config->isSystemAgent()
        );
    }

    /**
     * Get configurations by agent type
     *
     * @param  string  $type  Agent type (direct, promptly, synthesizer, integration)
     * @return array<string, AbstractAgentConfig> Configurations of specified type
     */
    public function getByType(string $type): array
    {
        $this->ensureLoaded();

        return array_filter(
            $this->configs,
            fn (AbstractAgentConfig $config) => $config->getAgentType() === $type
        );
    }

    /**
     * Get configurations by category
     *
     * @param  string  $category  Category tag
     * @return array<string, AbstractAgentConfig> Configurations with specified category
     */
    public function getByCategory(string $category): array
    {
        $this->ensureLoaded();

        return array_filter(
            $this->configs,
            fn (AbstractAgentConfig $config) => in_array($category, $config->getCategories())
        );
    }

    /**
     * Register a configuration manually (useful for testing)
     *
     * @param  AbstractAgentConfig  $config  Configuration to register
     */
    public function register(AbstractAgentConfig $config): void
    {
        $identifier = $config->getIdentifier();

        // Validate configuration
        $errors = $config->validate();
        if (! empty($errors)) {
            Log::warning('Agent configuration validation failed', [
                'identifier' => $identifier,
                'errors' => $errors,
            ]);

            return;
        }

        $this->configs[$identifier] = $config;

        Log::debug('Agent configuration registered', [
            'identifier' => $identifier,
            'name' => $config->getName(),
            'version' => $config->getVersion(),
        ]);
    }

    /**
     * Clear all registered configurations (useful for testing)
     */
    public function clear(): void
    {
        $this->configs = [];
        $this->loaded = false;
    }

    /**
     * Reload configurations from disk
     */
    public function reload(): void
    {
        $this->clear();
        $this->load();
    }

    /**
     * Ensure configurations are loaded
     */
    protected function ensureLoaded(): void
    {
        if (! $this->loaded) {
            $this->load();
        }
    }

    /**
     * Load agent configurations from directory
     */
    protected function load(): void
    {
        if (! File::isDirectory($this->configPath)) {
            Log::warning('Agent configuration directory not found', [
                'path' => $this->configPath,
            ]);
            $this->loaded = true;

            return;
        }

        $files = File::files($this->configPath);

        Log::info('Discovering agent configurations', [
            'path' => $this->configPath,
            'files_found' => count($files),
        ]);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $this->loadConfigFile($file->getPathname());
        }

        $this->loaded = true;

        Log::info('Agent configurations loaded', [
            'count' => count($this->configs),
            'identifiers' => array_keys($this->configs),
        ]);
    }

    /**
     * Load configuration from file
     *
     * @param  string  $filePath  Full path to config file
     */
    protected function loadConfigFile(string $filePath): void
    {
        try {
            // Extract class name from file path
            $className = $this->getClassNameFromFile($filePath);

            if (! $className) {
                Log::warning('Could not determine class name from file', [
                    'file' => $filePath,
                ]);

                return;
            }

            // Check if class exists
            if (! class_exists($className)) {
                Log::warning('Agent configuration class not found', [
                    'file' => $filePath,
                    'class' => $className,
                ]);

                return;
            }

            // Instantiate configuration
            $config = new $className;

            // Validate it's an AbstractAgentConfig
            if (! $config instanceof AbstractAgentConfig) {
                Log::warning('Class is not an agent configuration', [
                    'file' => $filePath,
                    'class' => $className,
                ]);

                return;
            }

            // Register configuration
            $this->register($config);
        } catch (\Throwable $e) {
            Log::error('Failed to load agent configuration', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Get fully qualified class name from file path
     *
     * @param  string  $filePath  Full path to PHP file
     * @return string|null Class name or null
     */
    protected function getClassNameFromFile(string $filePath): ?string
    {
        // Get relative path from app directory
        $appPath = app_path();
        if (! Str::startsWith($filePath, $appPath)) {
            return null;
        }

        $relativePath = Str::after($filePath, $appPath.DIRECTORY_SEPARATOR);

        // Convert path to namespace
        $namespace = 'App\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            $relativePath
        );

        return $namespace;
    }
}
