<?php

namespace App\Tools\Concerns;

use App\Models\Integration;
use App\Services\Tools\IntegrationTokenResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

/**
 * Base Integration Tool
 *
 * Abstract base class for all integration tools providing standard
 * execution patterns, error handling, and capability validation.
 *
 * Child classes must implement:
 * - getRequiredCapability(): string - The capability required to use this tool
 * - executeOperation(Integration, array): array - The tool-specific logic
 */
abstract class BaseIntegrationTool
{
    use SafeJsonResponse;

    /**
     * Get the capability required for this tool
     *
     * @return string Format: "Category:action" (e.g., "Pages:search")
     */
    abstract protected function getRequiredCapability(): string;

    /**
     * Execute the tool-specific operation
     *
     * @param  Integration  $integration  The validated integration
     * @param  array  $params  Tool parameters
     * @return array Result data to be included in the JSON response
     */
    abstract protected function executeOperation(
        Integration $integration,
        array $params
    ): array;

    /**
     * Standard execution wrapper with error handling
     *
     * This method provides:
     * - Token resolution and ownership validation
     * - Capability validation
     * - Standard error handling
     * - Usage tracking
     * - Consistent JSON responses
     *
     * @param  array  $arguments  Tool arguments from Prism
     * @return string JSON response
     */
    protected static function execute(array $arguments): string
    {
        try {
            $instance = new static;
            $resolver = app(IntegrationTokenResolver::class);

            $integrationId = $arguments['integration_id'] ?? $arguments['integration_token_id'] ?? null;

            if (! $integrationId) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Missing required parameter: integration_id',
                ], static::class);
            }

            // Resolve and validate integration
            $integration = $resolver->resolveFromContext($integrationId);

            // Validate capability
            $resolver->validateCapability(
                $integration,
                $instance->getRequiredCapability()
            );

            // Execute operation
            $result = $instance->executeOperation($integration, $arguments);

            // Track usage
            $instance->trackUsage($integration, $arguments);

            // Log success
            Log::info(static::class.': Operation completed successfully', [
                'integration_id' => $integration->id,
                'user_id' => $integration->user_id,
                'provider' => $integration->integrationToken->provider_id,
            ]);

            return static::safeJsonEncode([
                'success' => true,
                ...$result,
            ], static::class);

        } catch (AuthorizationException $e) {
            Log::warning(static::class.': Authorization failed', [
                'error' => $e->getMessage(),
                'integration_id' => $integrationId ?? null,
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'authorization_error',
            ], static::class);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning(static::class.': Integration not found or access denied', [
                'error' => $e->getMessage(),
                'integration_id' => $integrationId ?? null,
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Integration not found or access denied',
                'error_type' => 'integration_not_found',
            ], static::class);

        } catch (\RuntimeException $e) {
            Log::error(static::class.': Runtime error', [
                'error' => $e->getMessage(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'runtime_error',
            ], static::class);

        } catch (\Exception $e) {
            Log::error(static::class.': Operation failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Operation failed: '.$e->getMessage(),
                'error_type' => 'execution_error',
            ], static::class);
        }
    }

    /**
     * Track tool usage in the current interaction
     */
    protected function trackUsage(
        Integration $integration,
        array $arguments
    ): void {
        if (! app()->has('current_interaction_id')) {
            return;
        }

        $interactionId = app('current_interaction_id');
        $token = $integration->integrationToken;

        Log::info('Integration tool used', [
            'tool' => static::class,
            'interaction_id' => $interactionId,
            'integration_id' => $integration->id,
            'token_id' => $token->id,
            'provider' => $token->provider_id,
            'provider_name' => $token->provider_name,
        ]);
    }

    /**
     * Extract a parameter from arguments with optional default
     */
    protected function getParam(array $arguments, string $key, mixed $default = null): mixed
    {
        return $arguments[$key] ?? $default;
    }
}
