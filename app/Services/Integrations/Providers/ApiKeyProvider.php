<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;

/**
 * Abstract base class for API key providers
 *
 * Implements the Template Method pattern for integrations that authenticate
 * via static API keys (non-expiring tokens). Concrete providers must implement
 * key validation, testing, and metadata fetching.
 *
 * Security Considerations:
 * - API keys are stored encrypted in the database via Laravel's encrypted casting
 * - Keys are validated before storage via validateApiKey()
 * - Keys are tested against provider API via testApiKey() before acceptance
 * - No programmatic revocation - users must revoke keys in provider's dashboard
 *
 * Lifecycle:
 * 1. User provides API key via integration creation form
 * 2. validateApiKey() checks format (e.g., regex, length, prefix)
 * 3. testApiKey() makes live API call to verify key works
 * 4. fetchApiKeyMetadata() retrieves account info, permissions, etc.
 * 5. Key stored as IntegrationToken with no expiration
 */
abstract class ApiKeyProvider implements IntegrationProvider
{
    public function getSupportedAuthTypes(): array
    {
        return ['api_key'];
    }

    public function getDefaultAuthType(): string
    {
        return 'api_key';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return match ($authType) {
            'api_key' => 'Use an API key for authentication',
            default => 'Unknown authentication type',
        };
    }

    /**
     * Validate API key format
     */
    abstract public function validateApiKey(string $apiKey): bool;

    /**
     * Create integration token from API key
     */
    public function createFromApiKey(User $user, string $apiKey, ?string $name = null, array $additionalMetadata = []): IntegrationToken
    {
        if (! $this->validateApiKey($apiKey)) {
            throw new \InvalidArgumentException('Invalid API key format');
        }

        if (! $this->testApiKey($apiKey)) {
            throw new \InvalidArgumentException('API key is invalid or expired');
        }

        // Fetch additional metadata from provider
        $metadata = array_merge(
            $this->fetchApiKeyMetadata($apiKey),
            $additionalMetadata
        );

        return IntegrationToken::create([
            'user_id' => $user->id,
            'provider_id' => $this->getProviderId(),
            'provider_name' => $name ?? $this->getProviderName(),
            'token_type' => 'api_key',
            'access_token' => $apiKey,
            'expires_at' => null, // Most API keys don't expire
            'metadata' => $metadata,
            'status' => 'active',
        ]);
    }

    /**
     * Test API key by making a simple API call
     */
    abstract protected function testApiKey(string $apiKey): bool;

    /**
     * Fetch metadata about the API key (account, permissions, etc.)
     */
    abstract protected function fetchApiKeyMetadata(string $apiKey): array;

    /**
     * API keys typically don't support refresh
     */
    public function refreshToken(IntegrationToken $token): IntegrationToken
    {
        throw new \LogicException('API key providers do not support token refresh');
    }

    /**
     * Revoke API key (if provider supports it)
     */
    public function revokeToken(IntegrationToken $token): bool
    {
        // Most API key providers don't support programmatic revocation
        // Just delete the token locally
        $token->delete();

        return true;
    }

    /**
     * Get custom form sections for integration creation
     */
    public function getCreateFormSections(): array
    {
        return [];
    }

    /**
     * Get custom form sections for integration editing
     */
    public function getEditFormSections(): array
    {
        return [];
    }

    /**
     * Process provider-specific configuration from integration update form
     */
    public function processIntegrationUpdate(Integration $integration, array $requestData): void
    {
        // Default implementation: do nothing
        // Providers can override to handle their specific configuration
    }

    /**
     * Get setup instructions markdown for integration creation
     */
    public function getSetupInstructions(mixed $context = null): string
    {
        return '';
    }

    /**
     * Get the route name for setting up this integration
     * API key providers use the Integration creation flow
     */
    public function getSetupRoute(): string
    {
        return route('integrations.create', ['provider' => $this->getProviderId()]);
    }
}
