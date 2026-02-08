<?php

namespace App\Providers;

use App\Services\InputTrigger\InputTriggerRegistry;
use App\Services\InputTrigger\Providers\ApiTriggerProvider;
use App\Services\InputTrigger\Providers\WebhookTriggerProvider;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Input Trigger Service Provider
 *
 * Registers the InputTriggerRegistry and auto-registers built-in trigger providers.
 * Integration packages can register additional providers in their own service providers.
 */
class InputTriggerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register InputTriggerRegistry as singleton
        $this->app->singleton(InputTriggerRegistry::class, function ($app) {
            return new InputTriggerRegistry;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $triggerRegistry = $this->app->make(InputTriggerRegistry::class);
        $integrationRegistry = $this->app->make(ProviderRegistry::class);

        // Register built-in trigger providers in both registries
        $this->registerBuiltInProviders($triggerRegistry, $integrationRegistry);
    }

    /**
     * Register built-in trigger providers
     */
    protected function registerBuiltInProviders(
        InputTriggerRegistry $triggerRegistry,
        ProviderRegistry $integrationRegistry
    ): void {
        $apiProvider = new ApiTriggerProvider;
        $webhookProvider = new WebhookTriggerProvider;

        // Register in InputTriggerRegistry (for trigger execution)
        $triggerRegistry->register($apiProvider);
        $triggerRegistry->register($webhookProvider);

        // Register in main ProviderRegistry (for integrations page display)
        $integrationRegistry->register($apiProvider);
        $integrationRegistry->register($webhookProvider);

        // Future built-in providers can be added here
        // Note: Integration packages (Slack, Discord, etc.) register their own providers
        // in their own service providers
    }
}
