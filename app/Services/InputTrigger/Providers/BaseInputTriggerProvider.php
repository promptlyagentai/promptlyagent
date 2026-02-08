<?php

namespace App\Services\InputTrigger\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Services\Integrations\Contracts\InputTriggerProvider;

/**
 * Base Input Trigger Provider - Template Method for Built-in Triggers.
 *
 * Provides abstract base class for built-in input trigger providers (API, Webhook)
 * with common IntegrationProvider method implementations. Simplifies provider
 * implementation by providing sensible defaults for unused integration methods.
 *
 * Template Method Pattern:
 * - Subclasses implement trigger-specific methods (getTriggerType, validate, etc.)
 * - Base class provides defaults for IntegrationProvider interface
 * - Triggers don't need complex integration features (OAuth, tokens, etc.)
 *
 * IntegrationProvider Defaults:
 * - Auth type: 'none' (triggers don't require auth setup)
 * - Capabilities: Empty (triggers don't provide tools/knowledge)
 * - Agent tools: Empty mapping (triggers don't map to agent tools)
 * - No token management, OAuth, or API key handling
 *
 * Subclass Responsibilities:
 * - getTriggerType(): Unique identifier (api, webhook, email, etc.)
 * - getTriggerTypeName(): Human-readable name
 * - getDescription(): User-facing description
 * - validateTrigger(): Pre-execution validation
 * - extractInput(): Parse trigger-specific payload format
 * - respond(): Format trigger-specific response
 *
 * @see \App\Services\Integrations\Contracts\InputTriggerProvider
 * @see \App\Services\InputTrigger\Providers\ApiTriggerProvider
 * @see \App\Services\InputTrigger\Providers\WebhookTriggerProvider
 */
abstract class BaseInputTriggerProvider implements InputTriggerProvider
{
    // IntegrationProvider methods with sensible defaults for trigger providers

    public function getProviderId(): string
    {
        return $this->getTriggerType();
    }

    public function getProviderName(): string
    {
        return $this->getTriggerTypeName();
    }

    public function getSupportedAuthTypes(): array
    {
        return ['none']; // Trigger providers don't require authentication
    }

    public function getDefaultAuthType(): string
    {
        return 'none';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return 'Not applicable for trigger providers';
    }

    public function getCapabilities(): array
    {
        return [
            'Input' => ['invoke', 'status'],
        ];
    }

    public function getCapabilityRequirements(): array
    {
        return [
            'Input:invoke' => [],
            'Input:status' => [],
        ];
    }

    public function getCapabilityDescriptions(): array
    {
        return [
            'Input:invoke' => 'Invoke agent execution programmatically',
            'Input:status' => 'Check execution status',
        ];
    }

    public function detectTokenScopes(IntegrationToken $token): array
    {
        return []; // Not used for trigger providers
    }

    public function evaluateTokenCapabilities(IntegrationToken $token): array
    {
        return [
            'available' => ['Input:invoke', 'Input:status'],
            'blocked' => [],
            'categories' => ['Input'],
        ];
    }

    public function getConfigurationSchema(): array
    {
        return $this->getTriggerConfigSchema();
    }

    public function getCustomConfigComponent(): ?string
    {
        return null; // Use generic form rendering
    }

    public function validateConfiguration(array $config): array
    {
        return []; // Override in child classes if needed
    }

    public function testConnection(IntegrationToken $token): bool
    {
        return true; // Not applicable for trigger providers
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => 10,
            'requests_per_hour' => 100,
            'requests_per_day' => 1000,
        ];
    }

    public function isEnabled(): bool
    {
        return true; // Built-in providers are always enabled
    }

    public function isConnectable(): bool
    {
        return true; // Triggers need to be created/configured
    }

    public function supportsMultipleConnections(): bool
    {
        return true; // Users can create multiple triggers
    }

    public function getRequiredConfig(): array
    {
        return []; // No environment config required for built-in triggers
    }

    public function getAgentToolMappings(): array
    {
        return []; // Triggers don't provide agent tools
    }

    public function getAgentSystemPrompt(Integration $integration): string
    {
        return ''; // Not applicable for trigger providers
    }

    public function getAgentDescription(Integration $integration): string
    {
        return ''; // Not applicable for trigger providers
    }

    public function requiresIntegrationToken(): bool
    {
        return false; // Most built-in triggers don't need OAuth tokens
    }

    public function getWebhookPath(\App\Models\InputTrigger $trigger): ?string
    {
        return null; // Override in webhook provider
    }

    public function requiresApiToken(): bool
    {
        return false; // Most triggers don't require API tokens
    }

    public function getRequiredTokenAbilities(): array
    {
        return []; // Override if requiresApiToken() returns true
    }

    public function getApiTokenSetupRoute(): string
    {
        return 'settings.api-tokens'; // Default route for API token setup
    }

    public function getApiTokenMissingMessage(): string
    {
        return 'You need to create an API token first. Please create a token with the required abilities.';
    }

    public function getCreateFormSections(): array
    {
        return []; // Triggers typically don't have custom form sections
    }

    public function getEditFormSections(): array
    {
        return []; // Triggers typically don't have custom edit form sections
    }

    public function processIntegrationUpdate(Integration $integration, array $requestData): void
    {
        // Triggers don't need integration-specific config processing
        // This method exists to satisfy the IntegrationProvider interface
    }

    public function getSetupRoute(): string
    {
        // Input Trigger providers use the trigger creation flow
        return route('integrations.create-trigger', ['provider' => $this->getProviderId()]);
    }
}
