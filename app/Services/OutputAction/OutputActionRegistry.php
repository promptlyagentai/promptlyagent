<?php

namespace App\Services\OutputAction;

use App\Services\OutputAction\Contracts\OutputActionProvider;
use Illuminate\Support\Facades\Log;

/**
 * Output Action Registry - Plugin Architecture for External Actions.
 *
 * Provides centralized registration and discovery of output action providers,
 * enabling plug-and-play integration packages (webhooks, Slack, email, etc.)
 * to extend action functionality without modifying core code.
 *
 * Plugin Architecture:
 * - Providers self-register via service provider boot() methods
 * - Each provider handles one action type (http_webhook, slack, email, etc.)
 * - Registry maintains provider instances for routing action execution
 * - Zero core code changes needed for new action types
 *
 * Provider Contract:
 * - getActionType(): Unique identifier for routing
 * - execute(): Perform action with OutputAction configuration
 * - validate(): Pre-execution validation
 * - getConfigSchema(): UI schema for action configuration
 *
 * Built-in Providers:
 * - HTTP Webhook: Generic HTTP POST to any URL
 * - Slack: Post messages to Slack channels
 * - Email: Send notification emails
 *
 * Execution Flow:
 * 1. OutputActionDispatcher dispatches ExecuteOutputActionJob
 * 2. Job resolves provider from registry via action_type
 * 3. Provider executes action with resolved template variables
 * 4. Results logged to OutputActionLog
 *
 * @see \App\Services\OutputAction\Contracts\OutputActionProvider
 * @see \App\Services\OutputAction\OutputActionDispatcher
 * @see \App\Jobs\ExecuteOutputActionJob
 */
class OutputActionRegistry
{
    /**
     * Registered action providers
     *
     * @var array<string, OutputActionProvider>
     */
    protected array $providers = [];

    /**
     * Register an action provider
     *
     * @param  OutputActionProvider  $provider  The provider to register
     */
    public function register(OutputActionProvider $provider): void
    {
        $actionType = $provider->getActionType();

        if (isset($this->providers[$actionType])) {
            Log::warning('OutputActionRegistry: Overwriting existing action provider', [
                'action_type' => $actionType,
                'existing_provider' => get_class($this->providers[$actionType]),
                'new_provider' => get_class($provider),
            ]);
        }

        $this->providers[$actionType] = $provider;
    }

    /**
     * Get a registered action provider by type
     *
     * @param  string  $actionType  The action type identifier (e.g., 'http', 'slack', 'discord')
     * @return OutputActionProvider|null The provider instance or null if not found
     */
    public function getProvider(string $actionType): ?OutputActionProvider
    {
        return $this->providers[$actionType] ?? null;
    }

    /**
     * Check if an action type is registered
     *
     * @param  string  $actionType  The action type identifier
     */
    public function hasProvider(string $actionType): bool
    {
        return isset($this->providers[$actionType]);
    }

    /**
     * Get all registered action types
     *
     * @return array<string> Array of action type identifiers
     */
    public function getAvailableActionTypes(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get all registered providers
     *
     * @return array<string, OutputActionProvider>
     */
    public function getAllProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get metadata for a specific action type
     * Used for dynamic UI generation (badges, filters, forms)
     *
     * @param  string  $actionType  The action type identifier
     * @return array|null Metadata array or null if provider not found
     */
    public function getActionMetadata(string $actionType): ?array
    {
        $provider = $this->getProvider($actionType);

        if (! $provider) {
            return null;
        }

        return [
            'type' => $provider->getActionType(),
            'name' => $provider->getActionTypeName(),
            'icon' => $provider->getActionIcon(),
            'icon_svg' => $provider->getActionIconSvg(),
            'badge_color' => $provider->getBadgeColor(),
            'requires_integration_token' => $provider->requiresIntegrationToken(),
        ];
    }

    /**
     * Get metadata for all registered action types
     * Used for UI components like filter dropdowns
     *
     * @return array<string, array> Map of action type to metadata
     */
    public function getAllActionMetadata(): array
    {
        $metadata = [];

        foreach ($this->providers as $actionType => $provider) {
            $metadata[$actionType] = $this->getActionMetadata($actionType);
        }

        return $metadata;
    }

    /**
     * Get configuration schema for an action type
     *
     * @param  string  $actionType  The action type identifier
     * @return array Configuration schema or empty array if not found
     */
    public function getActionConfigSchema(string $actionType): array
    {
        $provider = $this->getProvider($actionType);

        return $provider ? $provider->getActionConfigSchema() : [];
    }

    /**
     * Get setup instructions for an action
     *
     * @param  \App\Models\OutputAction  $action  The action instance
     * @return string|null Setup instructions or null if provider not found
     */
    public function getSetupInstructions(\App\Models\OutputAction $action): ?string
    {
        $provider = $this->getProvider($action->provider_id);

        return $provider ? $provider->getSetupInstructions($action) : null;
    }

    /**
     * Validate an action's configuration
     *
     * @param  string  $actionType  The action type identifier
     * @param  array  $config  The configuration to validate
     * @return array Validation result with 'valid' and 'errors' keys
     */
    public function validateActionConfig(string $actionType, array $config): array
    {
        $provider = $this->getProvider($actionType);

        if (! $provider) {
            return [
                'valid' => false,
                'errors' => ['action_type' => "Unknown action type: {$actionType}"],
            ];
        }

        return $provider->validateActionConfig($config);
    }

    /**
     * Get example payload for an action
     *
     * @param  \App\Models\OutputAction  $action  The action instance
     * @return array Example payload or empty array if provider not found
     */
    public function getExamplePayload(\App\Models\OutputAction $action): array
    {
        $provider = $this->getProvider($action->provider_id);

        return $provider ? $provider->getExamplePayload($action) : [];
    }

    /**
     * Test an action with a sample payload
     *
     * @param  \App\Models\OutputAction  $action  The action to test
     * @param  array  $testPayload  Optional test payload (uses example if not provided)
     * @return array Test result with success status and details
     */
    public function testAction(\App\Models\OutputAction $action, array $testPayload = []): array
    {
        $provider = $this->getProvider($action->provider_id);

        if (! $provider) {
            return [
                'success' => false,
                'error' => "Unknown action type: {$action->provider_id}",
                'message' => 'Action provider not found',
            ];
        }

        return $provider->testAction($action, $testPayload);
    }

    /**
     * Get count of registered providers
     */
    public function count(): int
    {
        return count($this->providers);
    }
}
