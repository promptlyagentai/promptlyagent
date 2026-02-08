<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use Illuminate\Support\Facades\Http;

/**
 * Abstract base class for OAuth 2.0 providers
 *
 * Implements the Template Method pattern for OAuth 2.0 authorization code flow.
 * Handles the complete OAuth lifecycle including authorization, token exchange,
 * refresh, and revocation.
 *
 * OAuth Flow:
 * 1. User clicks "Connect" → redirected to provider's authorization URL
 * 2. User grants permissions → provider redirects back with authorization code
 * 3. handleOAuthCallback() exchanges code for access + refresh tokens
 * 4. Tokens stored encrypted in IntegrationToken with expiration timestamp
 * 5. refreshToken() automatically refreshes expired tokens when accessed
 *
 * Security Considerations:
 * - State parameter prevents CSRF attacks on OAuth callback
 * - Tokens stored encrypted via Laravel's encrypted casting
 * - Client secrets managed via environment variables (never in database)
 * - Refresh tokens used to obtain new access tokens without re-authorization
 * - Token expiration checked before each use, auto-refreshed if needed
 *
 * Template Methods (must implement):
 * - getOAuthConfig(): Return OAuth endpoints, client credentials, scopes
 * - handleOAuthCallback(): Exchange auth code for tokens, fetch user info
 */
abstract class OAuthProvider implements IntegrationProvider
{
    public function getSupportedAuthTypes(): array
    {
        return ['oauth2'];
    }

    public function getDefaultAuthType(): string
    {
        return 'oauth2';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return match ($authType) {
            'oauth2' => 'Authorize via OAuth 2.0',
            default => 'Unknown authentication type',
        };
    }

    /**
     * Get OAuth configuration
     *
     * @return array{client_id: string, client_secret: string, redirect_uri: string, authorization_url: string, token_url: string, scope: string|array<string>}
     */
    abstract public function getOAuthConfig(): array;

    /**
     * Generate authorization URL for OAuth flow
     */
    public function getAuthorizationUrl(User $user, string $state): string
    {
        $config = $this->getOAuthConfig();

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
        ];

        if (! empty($config['scope'])) {
            $params['scope'] = is_array($config['scope'])
                ? implode(' ', $config['scope'])
                : $config['scope'];
        }

        // Provider-specific additional parameters
        $params = array_merge($params, $this->getAdditionalAuthParams($user));

        return $config['authorization_url'].'?'.http_build_query($params);
    }

    /**
     * Handle OAuth callback and exchange code for tokens
     */
    abstract public function handleOAuthCallback(User $user, string $code, array $params = []): IntegrationToken;

    /**
     * Refresh an expired access token
     */
    public function refreshToken(IntegrationToken $token): IntegrationToken
    {
        $config = $this->getOAuthConfig();

        if (! $token->refresh_token) {
            throw new \Exception('No refresh token available');
        }

        $response = Http::asForm()->post($config['token_url'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ])->throw();

        $data = $response->json();

        $token->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $token->refresh_token,
            'expires_at' => isset($data['expires_in'])
                ? now()->addSeconds($data['expires_in'])
                : null,
            'last_refresh_at' => now(),
            'status' => 'active',
        ]);

        return $token->fresh();
    }

    /**
     * Revoke access token
     */
    public function revokeToken(IntegrationToken $token): bool
    {
        // Default implementation - override if provider has revocation endpoint
        $token->delete();

        return true;
    }

    /**
     * Get access token, refreshing if expired
     */
    protected function getAccessToken(IntegrationToken $token): string
    {
        if ($token->isExpired() && $this->supportsRefreshToken()) {
            $token = $this->refreshToken($token);
        }

        $token->touchLastUsed();

        return $token->access_token;
    }

    /**
     * Provider-specific additional OAuth parameters
     */
    protected function getAdditionalAuthParams(User $user): array
    {
        return [];
    }

    /**
     * Whether this provider supports refresh tokens
     */
    public function supportsRefreshToken(): bool
    {
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
     * OAuth providers use the Integration creation flow
     */
    public function getSetupRoute(): string
    {
        return route('integrations.create', ['provider' => $this->getProviderId()]);
    }
}
