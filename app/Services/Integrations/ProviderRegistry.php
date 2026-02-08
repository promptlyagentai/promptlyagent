<?php

namespace App\Services\Integrations;

use App\Services\Integrations\Contracts\IntegrationProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Registry for managing available integration providers
 *
 * Provides centralized provider management with automatic discovery and registration.
 * Scans the Providers directory at boot time to auto-register all concrete provider
 * implementations that implement the IntegrationProvider interface.
 *
 * Features:
 * - Auto-discovery of providers from app/Services/Integrations/Providers/
 * - Provider filtering by capability, auth type, and enabled status
 * - Singleton pattern ensures consistent provider instances across the application
 *
 * Discovery Mechanism:
 * 1. Scans for *Provider.php files in Providers directory
 * 2. Skips abstract base classes (ApiKeyProvider, OAuthProvider, etc.)
 * 3. Instantiates concrete implementations via service container
 * 4. Registers by provider_id for fast lookups
 */
class ProviderRegistry
{
    private array $providers = [];

    public function __construct()
    {
        $this->discoverProviders();
    }

    /**
     * Register a provider
     */
    public function register(IntegrationProvider $provider): void
    {
        $this->providers[$provider->getProviderId()] = $provider;
    }

    /**
     * Get a provider by ID
     */
    public function get(string $providerId): ?IntegrationProvider
    {
        return $this->providers[$providerId] ?? null;
    }

    /**
     * Check if a provider exists
     */
    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    /**
     * Get all registered providers
     */
    public function all(): Collection
    {
        return collect($this->providers);
    }

    /**
     * Get all enabled providers
     */
    public function enabled(): Collection
    {
        return collect($this->providers)
            ->filter(fn ($provider) => $provider->isEnabled());
    }

    /**
     * Get providers by capability
     *
     * Filters providers that support a specific capability (e.g., 'Tools' or 'Knowledge')
     */
    public function withCapability(string $capability): Collection
    {
        return collect($this->providers)
            ->filter(fn ($provider) => in_array($capability, $provider->getCapabilities()));
    }

    /**
     * Get providers supporting a specific auth type
     *
     * Filters by authentication method (e.g., 'oauth2', 'api_key', 'bearer_token')
     */
    public function withAuthType(string $authType): Collection
    {
        return collect($this->providers)
            ->filter(fn ($provider) => in_array($authType, $provider->getSupportedAuthTypes()));
    }

    /**
     * Discover and auto-register providers
     */
    private function discoverProviders(): void
    {
        $providerPath = app_path('Services/Integrations/Providers');

        if (! is_dir($providerPath)) {
            return;
        }

        $providerFiles = glob($providerPath.'/*Provider.php');

        foreach ($providerFiles as $file) {
            $className = 'App\\Services\\Integrations\\Providers\\'.basename($file, '.php');

            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);

                // Skip abstract classes
                if ($reflection->isAbstract()) {
                    continue;
                }

                // Check if implements IntegrationProvider
                if ($reflection->implementsInterface(IntegrationProvider::class)) {
                    try {
                        $provider = app($className);
                        $this->register($provider);
                    } catch (\Exception $e) {
                        Log::error('Failed to register integration provider', [
                            'class' => $className,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }
        }
    }
}
