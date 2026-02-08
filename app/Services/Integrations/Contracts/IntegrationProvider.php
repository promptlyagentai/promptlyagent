<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Integration;
use App\Models\IntegrationToken;

/**
 * Base interface that all integration providers must implement
 */
interface IntegrationProvider
{
    /**
     * Get the unique provider identifier (e.g., 'notion', 'google_drive', 'gmail')
     */
    public function getProviderId(): string;

    /**
     * Get human-readable provider name
     */
    public function getProviderName(): string;

    /**
     * Get provider description for UI display
     */
    public function getDescription(): string;

    /**
     * Get provider logo/icon URL or SVG path
     */
    public function getLogoUrl(): ?string;

    /**
     * Get supported authentication types for this provider
     *
     * @return array e.g., ['oauth2', 'bearer_token', 'api_key']
     */
    public function getSupportedAuthTypes(): array;

    /**
     * Get the default/recommended auth type
     */
    public function getDefaultAuthType(): string;

    /**
     * Get user-friendly description for each auth type
     */
    public function getAuthTypeDescription(string $authType): string;

    /**
     * Get provider capabilities organized by category
     *
     * @return array Nested array like ['Knowledge' => ['add', 'refresh'], 'Tools' => ['search', 'read']]
     */
    public function getCapabilities(): array;

    /**
     * Get scope requirements for each capability
     * Maps fine-grained capabilities to required scopes/permissions
     *
     * @return array ['Knowledge:add' => ['write'], 'Tools:search' => ['read']]
     */
    public function getCapabilityRequirements(): array;

    /**
     * Get user-friendly descriptions for capabilities
     * Maps capability keys to short, helpful descriptions
     *
     * @return array ['Knowledge:add' => 'Import pages as knowledge documents', ...]
     */
    public function getCapabilityDescriptions(): array;

    /**
     * Detect scopes/permissions available for this token
     * Implementation varies by provider (OAuth tokens, bearer tokens, API keys)
     *
     * @return array Provider-specific scopes like ['read', 'write'] or ['repo', 'admin']
     */
    public function detectTokenScopes(IntegrationToken $token): array;

    /**
     * Evaluate which capabilities are available vs blocked for this token
     * Checks token scopes against capability requirements
     *
     * @return array [
     *               'available' => ['Knowledge:add', ...],
     *               'blocked' => [['capability' => 'Knowledge:refresh', 'missing_scopes' => [...]], ...],
     *               'categories' => ['Knowledge', 'Tools', ...]
     *               ]
     */
    public function evaluateTokenCapabilities(IntegrationToken $token): array;

    /**
     * Get provider configuration schema (for custom config forms)
     *
     * @return array Schema definition for additional configuration
     */
    public function getConfigurationSchema(): array;

    /**
     * Get custom configuration component for provider-specific UI
     * Return Livewire component name if provider needs custom config UI,
     * otherwise return null to use generic form rendering
     *
     * Note: This is legacy. Prefer using getEditFormSections() for integration-level config.
     *
     * @return string|null Livewire component name for token-level configuration
     */
    public function getCustomConfigComponent(): ?string;

    /**
     * Validate provider-specific configuration
     *
     * @return array Empty array if valid, error messages if invalid
     */
    public function validateConfiguration(array $config): array;

    /**
     * Test connection with stored credentials
     */
    public function testConnection(IntegrationToken $token): bool;

    /**
     * Get rate limits for this provider
     *
     * @return array ['requests_per_minute' => 60, 'requests_per_hour' => 1000]
     */
    public function getRateLimits(): array;

    /**
     * Check if provider is enabled in application
     * (based on environment configuration)
     */
    public function isEnabled(): bool;

    /**
     * Check if this integration can be "connected" (has a setup flow)
     * If false, the integration is just always available with no setup needed
     *
     * Examples:
     * - true: Notion, Slack, API Triggers, Webhooks (need setup/configuration)
     * - false: External URL (just always works, no setup)
     */
    public function isConnectable(): bool;

    /**
     * Check if this integration supports multiple connections/instances
     *
     * Examples:
     * - true: Notion (multiple workspaces), API Triggers (multiple triggers)
     * - false: Some integrations might only allow one connection per user
     */
    public function supportsMultipleConnections(): bool;

    /**
     * Get required environment variables/config keys
     *
     * @return array ['ENV_KEY' => 'Description']
     */
    public function getRequiredConfig(): array;

    /**
     * Get the route name for setting up this integration
     * Allows providers to control their own setup flow
     *
     * @return string Route name (e.g., 'integrations.create', 'integrations.create-trigger')
     */
    public function getSetupRoute(): string;

    /**
     * Get custom form sections for integration creation
     * These sections are included in the create/edit forms to add provider-specific fields
     *
     * @return array Array of Blade view paths to include
     */
    public function getCreateFormSections(): array;

    /**
     * Get custom form sections for integration editing
     * These sections are included in the edit form to add provider-specific fields
     *
     * @return array Array of Blade view paths to include
     */
    public function getEditFormSections(): array;

    /**
     * Process provider-specific configuration from integration update form
     * Called when an integration is updated, allowing providers to handle their custom fields
     *
     * @param  Integration  $integration  The integration to update (use-case specific config)
     * @param  array  $requestData  The full request data from the form submission
     */
    public function processIntegrationUpdate(Integration $integration, array $requestData): void;

    /**
     * Get setup instructions markdown for integration creation
     * Displayed alongside the creation form to guide users
     *
     * @param  mixed  $context  The context (Integration, OutputAction, or null)
     * @return string Markdown content (empty string if no instructions)
     */
    public function getSetupInstructions(mixed $context = null): string;

    /**
     * Get mapping of capabilities to tool names for agent creation
     * Maps integration capabilities to their corresponding tool identifiers
     *
     * @return array ['Tools:search' => ['notion_search'], 'Tools:read' => ['notion_retrieve']]
     */
    public function getAgentToolMappings(): array;

    /**
     * Get system prompt for the agent created from this integration
     * Provides instructions on how to use the integration tools
     *
     * @param  Integration  $integration  The integration instance with enabled capabilities
     * @return string System prompt for the agent
     */
    public function getAgentSystemPrompt(Integration $integration): string;

    /**
     * Get description for the agent created from this integration
     * Used for automatic agent selection and UI display
     *
     * @param  Integration  $integration  The integration instance with enabled capabilities
     * @return string Description of what the agent does
     */
    public function getAgentDescription(Integration $integration): string;
}

/**
 * Global capability category definitions
 * Shared across all integration providers
 */
class IntegrationCapabilityCategories
{
    /**
     * Get user-friendly descriptions for capability categories
     *
     * @return array<string, string>
     */
    public static function getDescriptions(): array
    {
        return [
            'Knowledge' => 'Import and manage content as searchable knowledge documents for AI-powered research',
            'Tools' => 'Expose integration-specific tools to AI agents for performing actions and operations',
            'Agent' => 'Enable specialized AI agents to interact with this integration using natural language',
            'Artifact' => 'Store and synchronize artifacts with external integrations for backup and collaboration',
        ];
    }

    /**
     * Get description for a specific category
     */
    public static function getDescription(string $category): ?string
    {
        return self::getDescriptions()[$category] ?? null;
    }
}
