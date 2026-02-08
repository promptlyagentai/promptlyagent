<?php

namespace App\Models\Concerns;

use App\Services\Integrations\ProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Trait HasCapabilities
 *
 * Provides Sanctum-style ability validation for integration tokens.
 * Capabilities must be both available (provider supports + has scopes) AND enabled by user.
 */
trait HasCapabilities
{
    /**
     * Check if token can perform capability (has permission AND enabled)
     *
     * @param  string  $capability  Format: "Category:action" (e.g., "Knowledge:add")
     */
    public function tokenCan(string $capability): bool
    {
        // Get provider to check if capability is available
        $providerRegistry = app(ProviderRegistry::class);

        try {
            $provider = $providerRegistry->get($this->provider_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('HasCapabilities: Failed to get provider', [
                'provider_id' => $this->provider_id,
                'token_id' => $this->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $provider) {
            \Illuminate\Support\Facades\Log::error('HasCapabilities: Provider not found', [
                'provider_id' => $this->provider_id,
                'token_id' => $this->id ?? 'unknown',
            ]);

            return false;
        }

        $evaluation = $provider->evaluateTokenCapabilities($this);
        $isAvailable = in_array($capability, $evaluation['available']);
        $isEnabled = $this->isCapabilityEnabled($capability);

        // Must be available (has scopes) AND enabled by user
        return $isAvailable && $isEnabled;
    }

    /**
     * Check if token cannot perform capability
     *
     * @param  string  $capability  Format: "Category:action" (e.g., "Knowledge:add")
     */
    public function tokenCannot(string $capability): bool
    {
        return ! $this->tokenCan($capability);
    }

    /**
     * Validate capability or throw authorization exception
     *
     * @param  string  $capability  Format: "Category:action" (e.g., "Knowledge:add")
     *
     * @throws AuthorizationException
     */
    public function validateCapability(string $capability): void
    {
        if ($this->tokenCannot($capability)) {
            throw new AuthorizationException(
                "The capability '{$capability}' is not available or not enabled for this integration."
            );
        }
    }

    /**
     * Check if multiple capabilities are all available and enabled
     */
    public function tokenCanAll(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->tokenCannot($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any of the capabilities are available and enabled
     */
    public function tokenCanAny(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->tokenCan($capability)) {
                return true;
            }
        }

        return false;
    }
}
