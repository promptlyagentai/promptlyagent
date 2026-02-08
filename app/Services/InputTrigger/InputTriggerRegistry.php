<?php

namespace App\Services\InputTrigger;

use App\Services\Integrations\Contracts\InputTriggerProvider;
use Illuminate\Support\Facades\Log;

/**
 * Input Trigger Registry - Plugin Architecture for External Triggers.
 *
 * Provides centralized registration and discovery of input trigger providers,
 * enabling plug-and-play integration packages (API, webhooks, email, etc.)
 * to extend trigger functionality without modifying core code.
 *
 * Plugin Architecture:
 * - Providers self-register via service provider boot() methods
 * - Each provider handles one trigger type (api, webhook, email, etc.)
 * - Registry maintains provider instances for routing incoming triggers
 * - Zero core code changes needed for new trigger types
 *
 * Provider Contract:
 * - getTriggerType(): Unique identifier for routing
 * - validateTrigger(): Pre-execution validation
 * - extractInput(): Parse trigger-specific input format
 * - respond(): Format trigger-specific response
 *
 * Built-in Providers:
 * - ApiTriggerProvider: REST API endpoints
 * - WebhookTriggerProvider: Webhook callbacks with HMAC validation
 *
 * @see \App\Services\Integrations\Contracts\InputTriggerProvider
 * @see \App\Services\InputTrigger\Providers\ApiTriggerProvider
 * @see \App\Services\InputTrigger\Providers\WebhookTriggerProvider
 */
class InputTriggerRegistry
{
    /**
     * Registered trigger providers
     *
     * @var array<string, InputTriggerProvider>
     */
    protected array $providers = [];

    /**
     * Register a trigger provider
     *
     * @param  InputTriggerProvider  $provider  The provider to register
     */
    public function register(InputTriggerProvider $provider): void
    {
        $triggerType = $provider->getTriggerType();

        if (isset($this->providers[$triggerType])) {
            Log::warning('InputTriggerRegistry: Overwriting existing trigger provider', [
                'trigger_type' => $triggerType,
                'existing_provider' => get_class($this->providers[$triggerType]),
                'new_provider' => get_class($provider),
            ]);
        }

        $this->providers[$triggerType] = $provider;
    }

    /**
     * Get a registered trigger provider by type
     *
     * @param  string  $triggerType  The trigger type identifier (e.g., 'api', 'webhook', 'slack')
     * @return InputTriggerProvider|null The provider instance or null if not found
     */
    public function getProvider(string $triggerType): ?InputTriggerProvider
    {
        return $this->providers[$triggerType] ?? null;
    }

    /**
     * Check if a trigger type is registered
     *
     * @param  string  $triggerType  The trigger type identifier
     */
    public function hasProvider(string $triggerType): bool
    {
        return isset($this->providers[$triggerType]);
    }

    /**
     * Get all registered trigger types
     *
     * @return array<string> Array of trigger type identifiers
     */
    public function getAvailableTriggerTypes(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get all registered providers
     *
     * @return array<string, InputTriggerProvider>
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get metadata for a specific trigger type
     * Used for dynamic UI generation (badges, filters, forms)
     *
     * @param  string  $triggerType  The trigger type identifier
     * @return array|null Metadata array or null if provider not found
     */
    public function getTriggerMetadata(string $triggerType): ?array
    {
        $provider = $this->getProvider($triggerType);

        if (! $provider) {
            return null;
        }

        return [
            'type' => $provider->getTriggerType(),
            'name' => $provider->getTriggerTypeName(),
            'icon' => $provider->getTriggerIcon(),
            'badge_color' => $provider->getBadgeColor(),
            'description' => $provider->getDescription(),
            'requires_integration_token' => $provider->requiresIntegrationToken(),
            'webhook_enabled' => $provider->getWebhookPath(new \App\Models\InputTrigger) !== null,
        ];
    }

    /**
     * Get metadata for all registered trigger types
     * Used for UI components like filter dropdowns
     *
     * @return array<string, array> Map of trigger type to metadata
     */
    public function getAllTriggerMetadata(): array
    {
        $metadata = [];

        foreach ($this->providers as $triggerType => $provider) {
            $metadata[$triggerType] = $this->getTriggerMetadata($triggerType);
        }

        return $metadata;
    }

    /**
     * Get configuration schema for a trigger type
     *
     * @param  string  $triggerType  The trigger type identifier
     * @return array Configuration schema or empty array if not found
     */
    public function getTriggerConfigSchema(string $triggerType): array
    {
        $provider = $this->getProvider($triggerType);

        return $provider ? $provider->getTriggerConfigSchema() : [];
    }

    /**
     * Get setup instructions for a trigger
     *
     * @param  \App\Models\InputTrigger  $trigger  The trigger instance
     * @return string|null Setup instructions or null if provider not found
     */
    public function getSetupInstructions(\App\Models\InputTrigger $trigger): ?string
    {
        $provider = $this->getProvider($trigger->provider_id);

        return $provider ? $provider->getSetupInstructions($trigger) : null;
    }

    /**
     * Validate a trigger's configuration
     *
     * @param  string  $triggerType  The trigger type identifier
     * @param  array  $config  The configuration to validate
     * @return array Validation errors (empty if valid)
     */
    public function validateTriggerConfig(string $triggerType, array $config): array
    {
        $provider = $this->getProvider($triggerType);

        if (! $provider) {
            return ['trigger_type' => "Unknown trigger type: {$triggerType}"];
        }

        return $provider->validateConfiguration($config);
    }

    /**
     * Get count of registered providers
     */
    public function count(): int
    {
        return count($this->providers);
    }
}
