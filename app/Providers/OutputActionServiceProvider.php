<?php

namespace App\Providers;

use App\Services\OutputAction\OutputActionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Output Action Service Provider
 *
 * Registers the OutputActionRegistry as the core registry for output action providers.
 * Output action providers are registered by their respective integration packages
 * (e.g., HTTP Webhook, Slack, Discord, Email).
 */
class OutputActionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register OutputActionRegistry as singleton
        $this->app->singleton(OutputActionRegistry::class, function ($app) {
            return new OutputActionRegistry;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Output action providers are now registered by their integration packages
        // See: packages/http-webhook-integration/src/HttpWebhookServiceProvider.php
        // This keeps the core provider-agnostic and follows the integration package pattern
    }
}
