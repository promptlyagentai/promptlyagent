<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;

/**
 * Abstract base class for bearer token providers
 *
 * Implements the Template Method pattern for integrations that use bearer tokens
 * (static tokens similar to API keys but using Bearer authentication scheme).
 * Common for service-to-service integrations and personal access tokens.
 *
 * Security Considerations:
 * - Tokens stored encrypted in database via Laravel's encrypted casting
 * - Tokens validated via validateBearerToken() before storage
 * - Live API test via testBearerToken() ensures token is active
 * - Supports token rotation via updateBearerToken() for zero-downtime updates
 * - No programmatic revocation - users revoke via provider dashboard
 *
 * Lifecycle:
 * 1. User provides bearer token from provider's token management UI
 * 2. validateBearerToken() checks format (length, prefix, structure)
 * 3. testBearerToken() makes test API call to verify token works
 * 4. fetchTokenMetadata() retrieves workspace, account, scope info
 * 5. Token stored as IntegrationToken (typically no expiration)
 */
abstract class BearerTokenProvider implements IntegrationProvider
{
    public function requiresAuthentication(): bool
    {
        return true;
    }

    public function getSupportedAuthTypes(): array
    {
        return ['bearer_token'];
    }

    public function getDefaultAuthType(): string
    {
        return 'bearer_token';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return match ($authType) {
            'bearer_token' => 'Use an API token or integration secret',
            default => 'Unknown authentication type',
        };
    }

    /**
     * Validate bearer token format
     */
    abstract public function validateBearerToken(string $token): bool;

    /**
     * Create integration token from bearer token
     */
    public function createFromBearerToken(User $user, string $token, ?string $name = null, array $additionalMetadata = []): IntegrationToken
    {
        if (! $this->validateBearerToken($token)) {
            throw new \InvalidArgumentException('Invalid bearer token format');
        }

        if (! $this->testBearerToken($token)) {
            throw new \InvalidArgumentException('Bearer token is invalid or expired');
        }

        // Fetch additional metadata from provider
        $metadata = array_merge(
            $this->fetchTokenMetadata($token),
            $additionalMetadata
        );

        return IntegrationToken::create([
            'user_id' => $user->id,
            'provider_id' => $this->getProviderId(),
            'provider_name' => $name ?? $this->getProviderName(),
            'token_type' => 'bearer_token',
            'access_token' => $token,
            'expires_at' => null, // Most bearer tokens don't expire
            'metadata' => $metadata,
            'status' => 'active',
        ]);
    }

    /**
     * Test bearer token by making a simple API call
     */
    abstract protected function testBearerToken(string $token): bool;

    /**
     * Fetch metadata about the token (workspace, account, etc.)
     */
    abstract protected function fetchTokenMetadata(string $token): array;

    /**
     * Bearer tokens typically don't support refresh
     */
    public function refreshToken(IntegrationToken $token): IntegrationToken
    {
        throw new \LogicException('Bearer token providers do not support token refresh');
    }

    /**
     * Revoke bearer token (if provider supports it)
     */
    public function revokeToken(IntegrationToken $token): bool
    {
        // Most bearer token providers don't support programmatic revocation
        // Just delete the token locally
        $token->delete();

        return true;
    }

    /**
     * Update bearer token (for token rotation)
     */
    public function updateBearerToken(IntegrationToken $token, string $newToken): IntegrationToken
    {
        if (! $this->validateBearerToken($newToken)) {
            throw new \InvalidArgumentException('Invalid bearer token format');
        }

        if (! $this->testBearerToken($newToken)) {
            throw new \InvalidArgumentException('Bearer token is invalid or expired');
        }

        // Fetch metadata for the new token
        $newMetadata = $this->fetchTokenMetadata($newToken);

        // Update the token while preserving relationships
        $token->access_token = $newToken;
        $token->metadata = array_merge($token->metadata ?? [], $newMetadata);
        $token->status = 'active';
        $token->last_error = null;
        $token->save();

        return $token;
    }

    /**
     * Get custom form sections for integration creation
     * Override this method to provide provider-specific form fields
     */
    public function getCreateFormSections(): array
    {
        return [];
    }

    /**
     * Get custom form sections for integration editing
     * Override this method to provide provider-specific form fields for edit form
     */
    public function getEditFormSections(): array
    {
        return [];
    }

    /**
     * Process provider-specific configuration from integration update form
     * Override this method to handle provider-specific form fields during integration updates
     */
    public function processIntegrationUpdate(Integration $integration, array $requestData): void
    {
        // Default implementation: do nothing
        // Providers can override to handle their specific configuration
    }

    /**
     * Get setup instructions markdown for integration creation
     * Override this method to provide guidance for users
     */
    public function getSetupInstructions(mixed $context = null): string
    {
        return '';
    }

    /**
     * Get the route name for setting up this integration
     * Bearer token providers use the Integration creation flow
     */
    public function getSetupRoute(): string
    {
        return route('integrations.create', ['provider' => $this->getProviderId()]);
    }
}
