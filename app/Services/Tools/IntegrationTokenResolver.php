<?php

namespace App\Services\Tools;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Integration Token Resolver - Runtime Integration Resolution for Tools.
 *
 * Provides centralized integration resolution with ownership validation for
 * Prism tools. Ensures users can only access their own integrations when tools
 * invoke external APIs via integration tokens.
 *
 * Execution Context Pattern:
 * - Current user stored in app('current_user_id') during tool execution
 * - AgentExecutor sets context before invoking tools
 * - Tools retrieve context via this resolver
 * - Context cleared after execution
 *
 * Security Model:
 * - Ownership validation: Integration must belong to current user
 * - Capability validation: Integration must have required capabilities
 * - Context enforcement: Throws if no user context available
 * - Prevents cross-user integration access
 *
 * Resolution Flow:
 * 1. Tool provides integration UUID via parameter
 * 2. Resolver retrieves current_user_id from app context
 * 3. Queries Integration with user_id filter
 * 4. Validates capabilities if specified
 * 5. Returns Integration or throws exception
 *
 * Helper Methods:
 * - resolveFromContext(): Resolve with automatic user context
 * - resolveWithUser(): Explicit user parameter (testing)
 * - validateCapabilities(): Check integration has required capabilities
 *
 * @see \App\Services\Agents\AgentExecutor
 * @see \App\Services\Agents\Tools\*
 * @see \App\Models\Integration
 */
class IntegrationTokenResolver
{
    /**
     * Resolve integration from UUID with ownership validation
     *
     * @param  string  $integrationId  Integration UUID
     *
     * @throws \RuntimeException If no user context available
     * @throws ModelNotFoundException If integration not found or access denied
     */
    public function resolveFromContext(string $integrationId): Integration
    {
        $userId = app('current_user_id');

        if (! $userId) {
            throw new \RuntimeException('No user context available');
        }

        $integration = Integration::with('integrationToken')
            ->where('id', $integrationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $integration) {
            throw new ModelNotFoundException(
                "Integration not found or access denied: {$integrationId}"
            );
        }

        // Attempt to refresh token if expired
        $token = $integration->integrationToken;
        if ($token->isExpired() && $token->refresh_token) {
            try {
                $provider = app(\App\Services\Integrations\ProviderRegistry::class)
                    ->get($token->provider_id);

                if ($provider) {
                    $provider->refreshAccessToken($token);
                    $token->refresh(); // Reload from database
                }
            } catch (\Exception $e) {
                Log::warning('Failed to refresh token during resolution', [
                    'integration_id' => $integrationId,
                    'token_id' => $token->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue to validation - will fail with clear error if token still invalid
            }
        }

        // Validate the underlying token is active (after refresh attempt)
        if (! $token->isActive()) {
            throw new ModelNotFoundException(
                "Integration token is not active for integration: {$integrationId}"
            );
        }

        return $integration;
    }

    /**
     * Validate capability or throw exception
     *
     * @param  string  $capability  Format: "Category:action" (e.g., "Pages:search")
     *
     * @throws \Exception
     */
    public function validateCapability(
        Integration $integration,
        string $capability
    ): void {
        // Validate capability through Integration model
        // This checks both enabled capabilities and token scope availability
        // Pass the current user from execution context to avoid auth()->user() returning null in queue jobs
        $integration->validateCapability($capability, $this->getCurrentUser());
    }

    /**
     * Get current user from execution context
     *
     *
     * @throws \RuntimeException If no user context available
     * @throws ModelNotFoundException If user not found
     */
    public function getCurrentUser(): User
    {
        $userId = app('current_user_id');

        if (! $userId) {
            throw new \RuntimeException('No user context available');
        }

        $user = User::find($userId);

        if (! $user) {
            throw new ModelNotFoundException("User not found with ID: {$userId}");
        }

        return $user;
    }

    /**
     * Get current interaction ID from context (if available)
     */
    public function getCurrentInteractionId(): ?int
    {
        return app()->has('current_interaction_id')
            ? app('current_interaction_id')
            : null;
    }

    /**
     * Get current agent ID from context (if available)
     */
    public function getCurrentAgentId(): ?int
    {
        return app()->has('current_agent_id')
            ? app('current_agent_id')
            : null;
    }
}
