<?php

namespace App\Providers;

use App\Models\Agent;
use App\Models\Artifact;
use App\Models\InputTrigger;
use App\Models\IntegrationToken;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Observers\AgentObserver;
use App\Observers\ArtifactObserver;
use App\Observers\InputTriggerObserver;
use App\Observers\KnowledgeDocumentObserver;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Core application service provider
 *
 * Responsibilities:
 * - Registers singleton services (ToolStatusReporter, ProviderRegistry, ContentConverterRegistry, ToolRegistry)
 * - Configures HTTPS URL scheme based on APP_URL
 * - Registers model observers (KnowledgeDocument, Artifact, InputTrigger)
 * - Sets up scoped route model binding for integration tokens (ownership-based)
 * - Configures HTTP client timeouts and retry logic for external API calls
 * - Schedules knowledge document refresh for external URLs
 *
 * Security:
 * - Integration token route binding enforces ownership (404 if not owned by auth user)
 * - Laravel Debugbar only registered in local/dev environments
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local', 'dev', 'development')) {
            if (class_exists(\Barryvdh\Debugbar\ServiceProvider::class)) {
                $this->app->register(\Barryvdh\Debugbar\ServiceProvider::class);
            }
        }

        $this->app->singleton(\App\Services\ToolStatusReporter::class, function ($app) {
            return new \App\Services\ToolStatusReporter;
        });

        $this->app->singleton(\App\Services\Integrations\ProviderRegistry::class);
        $this->app->singleton(\App\Services\Tools\ContentConverterRegistry::class);
        $this->app->singleton(\App\Services\Agents\ToolRegistry::class);
        $this->app->singleton(\App\Services\Chat\SourceTypeRegistry::class);
        $this->app->singleton(\App\Services\ResearchTopicService::class);
    }

    /**
     * Bootstrap application services and configure core behavior.
     *
     * Sets up:
     * - HTTPS URL scheme enforcement
     * - Model observers for lifecycle hooks
     * - Scoped route binding for integration tokens (prevents unauthorized access)
     * - HTTP client timeout macros (longTimeout for extended operations)
     * - Global HTTP defaults (3min timeout, 30s connect timeout)
     * - Scheduled task for external knowledge document refresh
     *
     * @throws \Exception If route binding fails or observer registration fails
     */
    public function boot(): void
    {
        if (str_starts_with(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Gate::policy(User::class, UserPolicy::class);

        Agent::observe(AgentObserver::class);
        KnowledgeDocument::observe(KnowledgeDocumentObserver::class);
        Artifact::observe(ArtifactObserver::class);
        InputTrigger::observe(InputTriggerObserver::class);

        Route::bind('token', function ($value) {
            $token = IntegrationToken::where('id', $value)
                ->where('user_id', Auth::id())
                ->first();

            if (! $token) {
                Log::error('Integration token route binding failed', [
                    'token_id' => $value,
                    'user_id' => Auth::id(),
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                abort(404, 'Integration token not found');
            }

            return $token;
        });

        Http::macro('longTimeout', function () {
            return Http::timeout(300)
                ->connectTimeout(60)
                ->retry(3, 5000);
        });

        Http::globalOptions([
            'timeout' => 180,
            'connect_timeout' => 30,
        ]);

        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            $schedule->command('knowledge:refresh-external')
                ->everyMinute()
                ->evenInMaintenanceMode()
                ->withoutOverlapping()
                ->runInBackground();

            Log::info('Knowledge document refresh schedule registered', [
                'frequency' => 'every_minute',
                'maintenance_mode' => true,
                'overlapping' => false,
            ]);

            $schedule->command('research:generate-topics')
                ->dailyAt('03:00')
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
