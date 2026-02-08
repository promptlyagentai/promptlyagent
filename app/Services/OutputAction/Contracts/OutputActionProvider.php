<?php

namespace App\Services\OutputAction\Contracts;

use App\Models\OutputAction;
use App\Services\Integrations\Contracts\IntegrationProvider;

/**
 * Interface for Output Action Integration Providers
 *
 * Enables sending agent execution results to external services (HTTP webhooks, Slack, Discord, Email, etc.)
 * Output actions fire AFTER agent execution completes, sending results based on configured conditions.
 */
interface OutputActionProvider extends IntegrationProvider
{
    /**
     * Get the action type identifier (e.g., 'http', 'slack', 'discord', 'email')
     * This is stored in the provider_id field for flexible extensibility.
     */
    public function getActionType(): string;

    /**
     * Get human-readable action type name for UI display
     *
     * @return string e.g., 'HTTP Webhook', 'Slack', 'Discord', 'Email'
     */
    public function getActionTypeName(): string;

    /**
     * Get icon/emoji for this action type (for UI badges)
     *
     * @return string e.g., '📤', '💬', '🎮', '📧'
     */
    public function getActionIcon(): string;

    /**
     * Get SVG icon markup for this action type (optional, falls back to emoji)
     *
     * @return string|null Raw SVG markup or null to use emoji from getActionIcon()
     */
    public function getActionIconSvg(): ?string;

    /**
     * Get Tailwind badge color for this action type
     *
     * @return string e.g., 'blue', 'green', 'purple', 'indigo'
     */
    public function getBadgeColor(): string;

    /**
     * Get action-specific configuration schema
     * Returns form field definitions for creating/editing actions of this type
     *
     * @return array Schema definition for action configuration fields
     */
    public function getActionConfigSchema(): array;

    /**
     * Execute the output action with the given payload
     * This is the core execution method that:
     * 1. Formats the payload based on provider requirements
     * 2. Sends the data to the external service
     * 3. Returns execution result for logging
     *
     * @param  OutputAction  $action  The action being executed
     * @param  array  $payload  Execution data ['result' => string, 'session_id' => int, 'execution_id' => int, ...]
     * @return array Execution result with status, response data, duration, etc.
     */
    public function execute(OutputAction $action, array $payload): array;

    /**
     * Get setup instructions for this action type
     * Returns markdown-formatted instructions for users on how to set up and use the action
     *
     * @param  mixed  $context  The context (OutputAction, Integration, or null)
     * @return string Markdown setup instructions
     */
    public function getSetupInstructions(mixed $context = null): string;

    /**
     * Get example payload for testing this action type
     * Used in UI to help users understand the expected format
     *
     * @param  mixed  $context  The context (OutputAction, Integration, or null)
     * @return array Example payload structure
     */
    public function getExamplePayload(mixed $context = null): array;

    /**
     * Validate action configuration before saving
     * Checks URL format, credentials, required fields, etc.
     *
     * @param  array  $config  Configuration data from form submission
     * @return array Validation result: ['valid' => bool, 'errors' => array]
     */
    public function validateActionConfig(array $config): array;

    /**
     * Test the action configuration with a sample payload
     * Allows users to verify their action works before saving
     *
     * @param  OutputAction  $action  The action to test
     * @param  array  $testPayload  Optional test payload (uses example if not provided)
     * @return array Test result with success status and details
     */
    public function testAction(OutputAction $action, array $testPayload = []): array;

    /**
     * Get additional form sections to render in create form
     * Returns array of view paths that will be included after shared fields
     *
     * @return array Array of view paths (e.g., ['http-webhook-integration::components.webhook-secret-field'])
     */
    public function getCreateFormSections(): array;

    /**
     * Get additional form sections to render in edit form
     * Returns array of view paths that will be included after shared fields
     *
     * @return array Array of view paths (e.g., ['http-webhook-integration::components.trigger-loader'])
     */
    public function getEditFormSections(): array;
}
