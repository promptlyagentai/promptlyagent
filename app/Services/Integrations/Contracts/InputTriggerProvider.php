<?php

namespace App\Services\Integrations\Contracts;

use App\Models\InputTrigger;
use Illuminate\Http\Request;

/**
 * Interface for Input Trigger Integration Providers
 *
 * Enables programmatic agent execution through various trigger types (API, webhooks, Slack, etc.)
 * while maintaining full integration with the existing chat interface.
 *
 * All trigger-initiated sessions create the SAME chat_sessions and chat_interactions as the web interface.
 */
interface InputTriggerProvider extends IntegrationProvider
{
    /**
     * Get the trigger type identifier (e.g., 'api', 'webhook', 'slack', 'discord')
     * This is stored in the provider_id field for flexible extensibility.
     */
    public function getTriggerType(): string;

    /**
     * Get human-readable trigger type name for UI display
     *
     * @return string e.g., 'API', 'Webhook', 'Slack', 'Discord'
     */
    public function getTriggerTypeName(): string;

    /**
     * Get icon/emoji for this trigger type (for UI badges)
     *
     * @return string e.g., '🔗', '🪝', '💬', '🎮'
     */
    public function getTriggerIcon(): string;

    /**
     * Get SVG icon markup for this trigger type (optional, falls back to emoji)
     *
     * @return string|null Raw SVG markup or null to use emoji from getTriggerIcon()
     */
    public function getTriggerIconSvg(): ?string;

    /**
     * Get Tailwind badge color for this trigger type
     *
     * @return string e.g., 'blue', 'green', 'purple', 'indigo'
     */
    public function getBadgeColor(): string;

    /**
     * Check if this trigger type requires an IntegrationToken
     *
     * @return bool True for OAuth-based triggers (Slack, Discord), false for simple triggers (API, Webhook)
     */
    public function requiresIntegrationToken(): bool;

    /**
     * Validate incoming trigger request
     * Handles authentication, signature validation, rate limiting, etc.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  InputTrigger  $trigger  The trigger being invoked
     * @return array Validation result: ['valid' => bool, 'error' => string|null, 'metadata' => array]
     *
     * @throws \Exception If validation fails critically
     */
    public function validateRequest(Request $request, InputTrigger $trigger): array;

    /**
     * Handle trigger invocation and create chat records
     * This is the core execution method that:
     * 1. Extracts input from the request/payload
     * 2. Resolves or creates a chat session
     * 3. Creates a chat interaction
     * 4. Invokes the agent via TriggerExecutor
     *
     * @param  InputTrigger  $trigger  The trigger being invoked
     * @param  array  $input  Extracted input data ['input' => string, 'options' => array]
     * @param  array  $options  Additional execution options (session_id, workflow, etc.)
     * @return array Execution result with session_id, interaction_id, status, etc.
     */
    public function handleTrigger(InputTrigger $trigger, array $input, array $options = []): array;

    /**
     * Get trigger-specific configuration schema
     * Returns form field definitions for creating/editing triggers of this type
     *
     * @return array Schema definition for trigger configuration fields
     */
    public function getTriggerConfigSchema(): array;

    /**
     * Get webhook endpoint path for this trigger (if applicable)
     * Returns null for non-webhook triggers
     *
     * @param  InputTrigger  $trigger  The trigger instance
     * @return string|null e.g., '/api/webhooks/triggers/{uuid}' or null
     */
    public function getWebhookPath(InputTrigger $trigger): ?string;

    /**
     * Get setup instructions for this trigger type
     * Returns markdown-formatted instructions for users on how to set up and use the trigger
     *
     * @param  mixed  $context  The context (InputTrigger, Integration, OutputAction, or null)
     * @return string Markdown setup instructions
     */
    public function getSetupInstructions(mixed $context = null): string;

    /**
     * Extract input text from the request payload
     * Different providers have different payload structures (JSON, form data, etc.)
     *
     * @param  Request  $request  The incoming request
     * @return string The extracted input text for agent execution
     */
    public function extractInput(Request $request): string;

    /**
     * Get example payload for testing this trigger type
     * Used in UI to help users understand the expected format
     *
     * @param  InputTrigger  $trigger  The trigger instance
     * @return array Example payload structure
     */
    public function getExamplePayload(InputTrigger $trigger): array;

    /**
     * Generate authentication credentials for this trigger
     * For webhooks: generates HMAC secret
     * For OAuth: returns token reference
     * For API: generates or references API token
     *
     * @param  InputTrigger  $trigger  The trigger instance
     * @return array Credentials data ['type' => string, 'secret' => string, 'token' => string, ...]
     */
    public function generateCredentials(InputTrigger $trigger): array;

    /**
     * Check if this trigger type requires a Sanctum API token to be configured
     * before triggers can be created.
     *
     * @return bool True if user must have an API token, false otherwise
     */
    public function requiresApiToken(): bool;

    /**
     * Get the required Sanctum token abilities for this trigger type
     * Only relevant if requiresApiToken() returns true.
     *
     * @return array Array of required abilities (e.g., ['trigger:invoke'])
     */
    public function getRequiredTokenAbilities(): array;

    /**
     * Get the route name where users can set up the required API token
     * Only relevant if requiresApiToken() returns true.
     *
     * @return string Route name (e.g., 'settings.api-tokens')
     */
    public function getApiTokenSetupRoute(): string;

    /**
     * Get the error message to show when API token is missing
     * Only relevant if requiresApiToken() returns true.
     *
     * @return string User-friendly error message
     */
    public function getApiTokenMissingMessage(): string;
}
