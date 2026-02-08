<?php

namespace App\Services\OutputAction\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\OutputAction;
use App\Services\OutputAction\Contracts\OutputActionProvider;

/**
 * Base Output Action Provider - Template Method for Built-in Actions.
 *
 * Provides abstract base class for built-in output action providers (HTTP webhook,
 * Slack, email, etc.) with common IntegrationProvider method implementations.
 * Simplifies provider implementation by providing sensible defaults for unused
 * integration methods.
 *
 * Template Method Pattern:
 * - Subclasses implement action-specific methods (getActionType, execute, etc.)
 * - Base class provides defaults for IntegrationProvider interface
 * - Actions don't need complex integration features (OAuth, tokens, etc.)
 *
 * IntegrationProvider Defaults:
 * - Auth type: 'none' (actions don't require auth setup)
 * - Capabilities: Empty (actions don't provide tools/knowledge)
 * - Agent tools: Empty mapping (actions don't map to agent tools)
 * - No token management, OAuth, or API key handling
 *
 * Subclass Responsibilities:
 * - getActionType(): Unique identifier (http_webhook, slack, email, etc.)
 * - getActionTypeName(): Human-readable name
 * - getDescription(): User-facing description
 * - execute(): Perform the action with OutputAction configuration
 * - validate(): Pre-execution validation
 * - getConfigSchema(): JSON schema for UI configuration form
 *
 * Execution Context:
 * - OutputAction model with configuration
 * - Resolved template variables
 * - Execution data (agent response, session, user, etc.)
 *
 * @see \App\Services\OutputAction\Contracts\OutputActionProvider
 * @see \App\Jobs\ExecuteOutputActionJob
 * @see \App\Models\OutputAction
 */
abstract class BaseOutputActionProvider implements OutputActionProvider
{
    // IntegrationProvider methods with sensible defaults for action providers

    public function getProviderId(): string
    {
        return $this->getActionType();
    }

    public function getProviderName(): string
    {
        return $this->getActionTypeName();
    }

    public function getSupportedAuthTypes(): array
    {
        return ['none']; // Most action providers handle auth via custom config
    }

    public function getDefaultAuthType(): string
    {
        return 'none';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return 'Authentication configured in action settings';
    }

    public function getCapabilities(): array
    {
        return [
            'Output' => ['send_result', 'send_error'],
        ];
    }

    public function getCapabilityRequirements(): array
    {
        return [
            'Output:send_result' => [],
            'Output:send_error' => [],
        ];
    }

    public function getCapabilityDescriptions(): array
    {
        return [
            'Output:send_result' => 'Send agent execution results',
            'Output:send_error' => 'Send execution error notifications',
        ];
    }

    public function detectTokenScopes(IntegrationToken $token): array
    {
        return []; // Not used for action providers
    }

    public function evaluateTokenCapabilities(IntegrationToken $token): array
    {
        return [
            'available' => ['Output:send_result', 'Output:send_error'],
            'blocked' => [],
            'categories' => ['Output'],
        ];
    }

    public function getConfigurationSchema(): array
    {
        return []; // Use getActionConfigSchema() instead
    }

    public function getConfigurationComponent(): ?string
    {
        return null;
    }

    public function getCustomConfiguration(IntegrationToken $token): ?array
    {
        return null;
    }

    public function getLogoUrl(): ?string
    {
        return null; // Use icon instead
    }

    public function requiresIntegrationToken(): bool
    {
        return false; // Output actions don't use IntegrationToken
    }

    public function getDescription(): string
    {
        return 'Send execution results to external services';
    }

    public function getCustomConfigComponent(): ?string
    {
        return null;
    }

    public function validateConfiguration(array $config): array
    {
        return []; // Use validateActionConfig() instead
    }

    public function testConnection(IntegrationToken $token): bool
    {
        return true; // Not used for action providers
    }

    public function getRateLimits(): array
    {
        return []; // No rate limits on output actions
    }

    public function isEnabled(): bool
    {
        return true; // Always enabled
    }

    public function isConnectable(): bool
    {
        return true; // Output actions can be set up (created)
    }

    public function supportsMultipleConnections(): bool
    {
        return true; // Users can create multiple output actions
    }

    public function getRequiredConfig(): array
    {
        return []; // No environment config required
    }

    public function getAgentToolMappings(): array
    {
        return []; // Output actions don't provide tools
    }

    public function getAgentSystemPrompt(Integration $integration): string
    {
        return ''; // Not used for output actions
    }

    public function getAgentDescription(Integration $integration): string
    {
        return ''; // Not used for output actions
    }

    /**
     * Default test action implementation
     * Can be overridden by specific providers
     */
    public function testAction(OutputAction $action, array $testPayload = []): array
    {
        if (empty($testPayload)) {
            $testPayload = $this->getExamplePayload($action);
        }

        try {
            $result = $this->execute($action, $testPayload);

            return [
                'success' => true,
                'result' => $result,
                'message' => 'Test execution successful',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Test execution failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Default validation implementation
     * Can be overridden by specific providers for custom validation
     */
    public function validateActionConfig(array $config): array
    {
        $errors = [];

        // Get schema to validate required fields
        $schema = $this->getActionConfigSchema();

        foreach ($schema as $field => $definition) {
            $isRequired = $definition['required'] ?? false;
            $value = $config[$field] ?? null;

            if ($isRequired && empty($value)) {
                $label = $definition['label'] ?? $field;
                $errors[$field] = "{$label} is required";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get additional form sections for create form
     * Override to register provider-specific form components
     */
    public function getCreateFormSections(): array
    {
        return [];
    }

    /**
     * Get additional form sections for edit form
     * Override to register provider-specific form components
     */
    public function getEditFormSections(): array
    {
        return [];
    }

    public function getSetupRoute(): string
    {
        // Output Action providers use the action creation flow
        return route('integrations.create-action', ['provider' => $this->getProviderId()]);
    }
}
