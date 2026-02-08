<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\KnowledgeFileController;
use App\Http\Controllers\LinkValidatorController;
use App\Http\Controllers\StreamingController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});

// Public shared session view (no authentication required)
Route::get('/public/sessions/{uuid}', [\App\Http\Controllers\PublicSessionController::class, 'show'])
    ->name('public.sessions.show');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::redirect('dashboard/research-chat', 'dashboard/chat', 301)
    ->middleware(['auth', 'verified']);

Route::get('dashboard/chat', \App\Livewire\ChatResearchInterface::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard.research-chat');

Route::get('dashboard/chat/{sessionId}', \App\Livewire\ChatResearchInterface::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard.research-chat.session');

Route::get('dashboard/agents', \App\Livewire\AgentManager::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard.agents');

Route::get('dashboard/users', \App\Livewire\UserManager::class)
    ->middleware(['auth', 'verified', 'impersonate.protect'])
    ->name('dashboard.users');

Route::get('dashboard/knowledge/{document?}', \App\Livewire\KnowledgeManager::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard.knowledge');

Route::get('knowledge/{document}/download', [KnowledgeFileController::class, 'download'])
    ->middleware(['auth', 'verified'])
    ->name('knowledge.download');

Route::get('knowledge/{document}/preview', [KnowledgeFileController::class, 'preview'])
    ->middleware(['auth', 'verified'])
    ->name('knowledge.preview');

Route::get('documents/{document}/download', [\App\Http\Controllers\ArtifactController::class, 'download'])
    ->middleware(['auth', 'verified'])
    ->name('documents.download');

Route::get('artifacts/{artifact}/download', [\App\Http\Controllers\ArtifactController::class, 'downloadArtifact'])
    ->middleware(['auth', 'verified'])
    ->name('artifacts.download');

Route::get('artifacts/{artifact}/conversion/{conversion}/download', [\App\Http\Controllers\ArtifactController::class, 'downloadConversion'])
    ->middleware(['auth', 'verified'])
    ->name('artifacts.conversion.download');

Route::get('artifacts/{artifact}/download-docx', [\App\Http\Controllers\ArtifactController::class, 'downloadAsDocx'])
    ->middleware(['auth', 'verified'])
    ->name('artifacts.download-docx');

Route::get('artifacts/{artifact}/download-pdf', [\App\Http\Controllers\ArtifactController::class, 'downloadAsPdf'])
    ->middleware(['auth', 'verified'])
    ->name('artifacts.download-pdf');

Route::get('artifacts/{artifact}/download-odt', [\App\Http\Controllers\ArtifactController::class, 'downloadAsOdt'])
    ->middleware(['auth', 'verified'])
    ->name('artifacts.download-odt');

Route::get('artifacts/{artifact}/download-latex', [\App\Http\Controllers\ArtifactController::class, 'downloadAsLatex'])
    ->middleware(['auth', 'verified'])
    ->name('artifacts.download-latex');

Route::get('chat/attachment/{attachment}/download', [\App\Http\Controllers\ChatAttachmentController::class, 'download'])
    ->middleware(['auth', 'verified'])
    ->name('chat.attachment.download');

Route::get('assets/{asset}/download', [\App\Http\Controllers\AssetController::class, 'download'])
    ->middleware(['auth', 'verified'])
    ->name('assets.download');

Route::post('api/validate-link', [LinkValidatorController::class, 'validate'])
    ->middleware(['auth', 'verified'])
    ->name('validate-link');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/ai-persona', 'settings.ai-persona')->name('settings.ai-persona');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    Volt::route('settings/api-tokens', 'settings.api-tokens')->name('settings.api-tokens');
    Volt::route('settings/help-widget', 'settings.help-widget')->name('settings.help-widget');
    Volt::route('settings/research-suggestions', 'settings.research-suggestions')->name('settings.research-suggestions');

    Route::prefix('settings/integrations')->name('integrations.')->group(function () {
        Route::get('/', [\App\Http\Controllers\IntegrationController::class, 'index'])->name('index');
        Route::get('mcp-server/setup', \App\Livewire\McpServerSetup::class)->name('mcp-server-setup');
        Route::get('mcp-server/{integration}/edit', \App\Livewire\McpServerEdit::class)->name('mcp-server-edit');

        Route::get('create/{provider}', [\App\Http\Controllers\IntegrationController::class, 'createIntegration'])
            ->name('create');
        Route::post('create/{provider}', [\App\Http\Controllers\IntegrationController::class, 'storeIntegration'])
            ->name('store');
        Route::get('{integration}/edit', [\App\Http\Controllers\IntegrationController::class, 'editIntegration'])
            ->name('edit');

        Route::post('{integration}/agent/create', [\App\Http\Controllers\IntegrationController::class, 'createAgent'])
            ->name('agent.create');
        Route::delete('{integration}/agent', [\App\Http\Controllers\IntegrationController::class, 'deleteAgent'])
            ->name('agent.delete');

        Route::patch('{integration}', [\App\Http\Controllers\IntegrationController::class, 'updateIntegration'])
            ->name('update');
        Route::delete('{integration}', [\App\Http\Controllers\IntegrationController::class, 'deleteIntegration'])
            ->name('delete');

        // Input Trigger Management
        Route::get('provider/{provider}/triggers', [\App\Http\Controllers\IntegrationController::class, 'showProviderTriggers'])->name('provider-triggers');
        Route::get('triggers', [\App\Http\Controllers\IntegrationController::class, 'listTriggers'])->name('list-triggers');
        Route::get('triggers/create/{provider}', [\App\Http\Controllers\IntegrationController::class, 'showCreateTrigger'])->name('create-trigger');
        Route::post('triggers/create/{provider}', [\App\Http\Controllers\IntegrationController::class, 'storeTrigger'])->name('store-trigger');
        Route::get('triggers/{trigger}', [\App\Http\Controllers\IntegrationController::class, 'showTriggerDetails'])->name('trigger-details');
        Route::patch('triggers/{trigger}', [\App\Http\Controllers\IntegrationController::class, 'updateTrigger'])->name('update-trigger');
        Route::delete('triggers/{trigger}', [\App\Http\Controllers\IntegrationController::class, 'deleteTrigger'])->name('delete-trigger');
        Route::post('triggers/{trigger}/regenerate-secret', [\App\Http\Controllers\IntegrationController::class, 'regenerateTriggerSecret'])->name('regenerate-trigger-secret');

        Route::get('actions', [\App\Http\Controllers\IntegrationController::class, 'listActions'])->name('list-actions');
        Route::get('actions/create/{provider}', [\App\Http\Controllers\IntegrationController::class, 'showCreateAction'])->name('create-action');
        Route::post('actions/create/{provider}', [\App\Http\Controllers\IntegrationController::class, 'storeAction'])->name('store-action');
        Route::get('actions/{action}', [\App\Http\Controllers\IntegrationController::class, 'showActionDetails'])->name('action-details');
        Route::patch('actions/{action}', [\App\Http\Controllers\IntegrationController::class, 'updateAction'])->name('update-action');
        Route::delete('actions/{action}', [\App\Http\Controllers\IntegrationController::class, 'deleteAction'])->name('delete-action');
        Route::post('actions/{action}/test', [\App\Http\Controllers\IntegrationController::class, 'testAction'])->name('test-action');

        Route::get('{provider}/select-auth', [\App\Http\Controllers\IntegrationController::class, 'selectAuthType'])
            ->name('select-auth');
        Route::get('{provider}/auth/{authType}', [\App\Http\Controllers\IntegrationController::class, 'initiateAuth'])
            ->name('initiate-auth');
        Route::get('{provider}/token/{authType}', [\App\Http\Controllers\IntegrationController::class, 'showTokenForm'])
            ->name('token-form');
        Route::post('{provider}/token/{authType}', [\App\Http\Controllers\IntegrationController::class, 'storeToken'])
            ->name('store-token');
        Route::get('oauth/callback', [\App\Http\Controllers\IntegrationController::class, 'handleOAuthCallback'])
            ->name('oauth.callback');

        Route::post('tokens/{token}/test', [\App\Http\Controllers\IntegrationController::class, 'testConnection'])
            ->name('test');

        Route::get('tokens/{token}/update-token', [\App\Http\Controllers\IntegrationController::class, 'showUpdateTokenForm'])
            ->name('update-token-form');

        Route::post('tokens/{token}/update-token', [\App\Http\Controllers\IntegrationController::class, 'updateToken'])
            ->name('update-token');

        Route::delete('tokens/{token}', [\App\Http\Controllers\IntegrationController::class, 'revokeToken'])
            ->name('revoke');
        Route::post('{integration}/clear-cache', [\App\Http\Controllers\IntegrationController::class, 'clearCache'])
            ->name('clear-cache');
        Route::post('tokens/{token}/capabilities/toggle', [\App\Http\Controllers\IntegrationController::class, 'toggleCapability'])
            ->name('capabilities.toggle');
        Route::post('tokens/{token}/rename', [\App\Http\Controllers\IntegrationController::class, 'rename'])
            ->name('rename');
    });
});

Route::get('dashboard/chat/stream-direct', [StreamingController::class, 'streamDirectChat'])
    ->middleware(['auth', 'verified'])
    ->name('chat.stream.direct');

Route::prefix('pwa')->name('pwa.')->group(function () {
    Route::get('/', [\App\Http\Controllers\PwaController::class, 'index'])->name('index');
    Route::get('/manifest.webmanifest', [\App\Http\Controllers\PwaController::class, 'manifest'])->name('manifest');
    Route::get('/chat', [\App\Http\Controllers\PwaController::class, 'chat'])->name('chat');
    Route::get('/chat/{sessionId}', [\App\Http\Controllers\PwaController::class, 'chat'])
        ->where('sessionId', '[0-9]+')
        ->name('chat.session');
    Route::get('/knowledge', [\App\Http\Controllers\PwaController::class, 'knowledge'])->name('knowledge');
    Route::get('/settings', [\App\Http\Controllers\PwaController::class, 'settings'])->name('settings');
    Route::post('/share-target', [\App\Http\Controllers\PwaController::class, 'shareTarget'])
        ->middleware(['auth', 'throttle:10,1'])
        ->name('share-target');
});

require __DIR__.'/auth.php';

/**
 * List webhook trigger configurations
 *
 * Retrieve webhook trigger configurations for UI integration. Returns trigger metadata
 * including webhook URLs and secrets needed for external webhook setup.
 *
 * **Note**: This endpoint is primarily for UI consumption. For API integration, use the
 * v1 API endpoints instead.
 *
 * @group Input Triggers
 *
 * @authenticated
 *
 * @queryParam provider string Optional filter triggers by provider (webhook, schedule, etc.). Example: webhook
 *
 * @response 200 scenario="Success" {"triggers": [{"id": 1, "name": "GitHub Push Webhook", "config": {"provider": "webhook", "description": "Trigger on push events"}, "webhook_url": "https://example.com/webhooks/triggers/1", "webhook_secret": "whs_abc123xyz456"}]}
 * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
 *
 * @responseField triggers array List of trigger configurations
 * @responseField triggers[].id integer Trigger ID
 * @responseField triggers[].name string Trigger name
 * @responseField triggers[].config object Trigger configuration
 * @responseField triggers[].webhook_url string Full webhook URL for external services
 * @responseField triggers[].webhook_secret string HMAC secret key for signature validation (null if not webhook trigger)
 */
Route::middleware('auth')->get('/api/input-triggers', function (\Illuminate\Http\Request $request) {
    $triggers = \App\Models\InputTrigger::where('user_id', auth()->id())
        ->when($request->query('provider'), fn ($q, $provider) => $q->where('provider_id', $provider))
        ->select('id', 'name', 'config')
        ->get()
        ->map(function ($trigger) {
            return [
                'id' => $trigger->id,
                'name' => $trigger->name,
                'config' => $trigger->config,
                'webhook_url' => route('webhooks.trigger', ['trigger' => $trigger->id]),
                'webhook_secret' => $trigger->config['webhook_secret'] ?? null,
            ];
        });

    return ['triggers' => $triggers];
})->name('api.input-triggers.index');

/**
 * Get webhook trigger configuration
 *
 * Retrieve webhook configuration for a specific trigger. Returns webhook URL and secret
 * needed for external webhook setup.
 *
 * **Note**: This endpoint is primarily for UI consumption. For API integration, use the
 * v1 API endpoints instead.
 *
 * @group Input Triggers
 *
 * @authenticated
 *
 * @urlParam trigger integer required The trigger ID. Example: 1
 *
 * @response 200 scenario="Success" {"id": 1, "name": "GitHub Push Webhook", "config": {"provider": "webhook", "description": "Trigger on push events"}, "webhook_url": "https://example.com/webhooks/triggers/1", "webhook_secret": "whs_abc123xyz456"}
 * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
 * @response 404 scenario="Not Found" {"message": "Not found"}
 *
 * @responseField id integer Trigger ID
 * @responseField name string Trigger name
 * @responseField config object Trigger configuration
 * @responseField webhook_url string Full webhook URL for external services
 * @responseField webhook_secret string HMAC secret key for signature validation (null if not webhook trigger)
 */
Route::middleware('auth')->get('/api/input-triggers/{trigger}', function (string $trigger) {
    $triggerModel = \App\Models\InputTrigger::where('id', $trigger)
        ->where('user_id', auth()->id())
        ->firstOrFail();

    return [
        'id' => $triggerModel->id,
        'name' => $triggerModel->name,
        'config' => $triggerModel->config,
        'webhook_url' => route('webhooks.trigger', ['trigger' => $triggerModel->id]),
        'webhook_secret' => $triggerModel->config['webhook_secret'] ?? null,
    ];
})->name('api.input-triggers.show');

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::get('auth/google/redirect', [SocialiteController::class, 'redirectToGoogle'])->name('auth.google.redirect');
Route::get('auth/google/callback', [SocialiteController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Impersonation routes
Route::impersonate();

Route::prefix('webhooks/triggers')->name('webhooks.')->group(function () {
    Route::post('/{trigger}', [\App\Http\Controllers\Api\WebhookController::class, 'handle'])
        ->middleware('trigger.ip.whitelist')
        ->name('trigger');
    Route::get('/{trigger}/ping', [\App\Http\Controllers\Api\WebhookController::class, 'ping'])->name('trigger.ping');
});
