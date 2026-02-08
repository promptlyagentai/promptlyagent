<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\InputTrigger;
use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\OutputAction;
use App\Services\InputTrigger\InputTriggerRegistry;
use App\Services\Integrations\IntegrationAgentService;
use App\Services\Integrations\ProviderRegistry;
use App\Services\OutputAction\OutputActionDispatcher;
use App\Services\OutputAction\OutputActionRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manages integration lifecycle: OAuth setup, token management, and capabilities.
 *
 * Handles three integration types:
 * 1. OAuth2 integrations (GitHub, Notion, Slack, etc.)
 * 2. API key/bearer token integrations (Linear, Perplexity, etc.)
 * 3. No-auth integrations (webhooks, HTTP actions, MCP servers)
 *
 * Supports:
 * - Multi-auth type providers (OAuth + API key)
 * - Token capability management with scope detection
 * - Input triggers and output actions
 * - Integration agents (AI agents powered by integration tools)
 *
 * @see \App\Services\Integrations\ProviderRegistry
 * @see \App\Services\InputTrigger\InputTriggerRegistry
 * @see \App\Services\OutputAction\OutputActionRegistry
 */
class IntegrationController extends Controller
{
    public function __construct(
        private ProviderRegistry $registry,
        private IntegrationAgentService $agentService,
        private InputTriggerRegistry $triggerRegistry,
        private OutputActionRegistry $actionRegistry,
        private OutputActionDispatcher $actionDispatcher
    ) {}

    /**
     * Show integrations management page
     */
    public function index()
    {
        $providers = $this->registry->enabled();
        $userIntegrations = Integration::where('user_id', Auth::id())
            ->with('integrationToken')
            ->orderBy('created_at', 'desc')
            ->get();

        $inputTriggers = InputTrigger::where('user_id', Auth::id())
            ->with('agent')
            ->orderBy('created_at', 'desc')
            ->get();

        $outputActions = OutputAction::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        // Get available token counts per provider for credential checking
        $tokenCounts = IntegrationToken::where('user_id', Auth::id())
            ->where('status', 'active')
            ->selectRaw('provider_id, count(*) as token_count')
            ->groupBy('provider_id')
            ->pluck('token_count', 'provider_id');

        return view('settings.integrations.index', [
            'providers' => $providers,
            'userIntegrations' => $userIntegrations,
            'inputTriggers' => $inputTriggers,
            'outputActions' => $outputActions,
            'tokenCounts' => $tokenCounts,
        ]);
    }

    /**
     * Show auth type selection for providers with multiple options
     */
    public function selectAuthType(string $providerId)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        if (! $provider->isEnabled()) {
            return redirect()->route('integrations.index')
                ->with('error', 'This integration is not enabled');
        }

        $authTypes = $provider->getSupportedAuthTypes();

        if (count($authTypes) === 1) {
            // Only one auth method, redirect directly
            return $this->initiateAuth($providerId, $authTypes[0]);
        }

        // Show selection UI
        return view('settings.integrations.select-auth', [
            'provider' => $provider,
            'authTypes' => $authTypes,
        ]);
    }

    /**
     * Initiate authentication based on selected type
     */
    public function initiateAuth(string $providerId, string $authType)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        if (! in_array($authType, $provider->getSupportedAuthTypes())) {
            return redirect()->route('integrations.index')
                ->with('error', 'This authentication type is not supported by this provider');
        }

        return match ($authType) {
            'oauth2' => $this->initiateOAuth($providerId),
            'api_key', 'bearer_token' => redirect()->route('integrations.token-form', [
                'provider' => $providerId,
                'authType' => $authType,
            ]),
            'none' => $this->handleNoAuthSetup($providerId, $provider),
            default => redirect()->back()->with('error', 'Unsupported authentication type'),
        };
    }

    /**
     * Initiate OAuth flow
     */
    protected function initiateOAuth(string $providerId)
    {
        $provider = $this->registry->get($providerId);
        $user = Auth::user();
        $state = Str::random(40);

        // Store state and provider in session for verification
        session([
            'oauth_state' => $state,
            'oauth_provider_id' => $providerId,
            'oauth_user_id' => $user->id,
        ]);

        $authUrl = $provider->getAuthorizationUrl($user, $state);

        return redirect($authUrl);
    }

    /**
     * Handle setup for integrations that don't require authentication
     * Used for input triggers (API, Webhook), output actions (HTTP Webhook), MCP servers, and other core integrations
     */
    protected function handleNoAuthSetup(string $providerId, $provider)
    {
        // Check if this is MCP server provider
        if ($providerId === 'mcp_server') {
            // Redirect to MCP server setup form
            return redirect()->route('integrations.mcp-server-setup');
        }

        // Check if this is an input trigger provider
        if ($provider instanceof \App\Services\Integrations\Contracts\InputTriggerProvider) {
            // Redirect directly to create trigger form
            return redirect()->route('integrations.create-trigger', [
                'provider' => $providerId,
            ]);
        }

        // Check if this is an output action provider
        if ($provider instanceof \App\Services\OutputAction\Contracts\OutputActionProvider) {
            // Redirect directly to create action form
            return redirect()->route('integrations.create-action', [
                'provider' => $providerId,
            ]);
        }

        // For other 'none' auth providers, just mark as available
        return redirect()->route('integrations.index')
            ->with('success', 'This integration is now available');
    }

    /**
     * Handle OAuth callback
     */
    public function handleOAuthCallback(Request $request)
    {
        $code = $request->get('code');
        $state = $request->get('state');
        $error = $request->get('error');
        $errorDescription = $request->get('error_description');

        // Check for OAuth errors
        if ($error) {
            session()->forget(['oauth_state', 'oauth_provider_id', 'oauth_user_id']);

            return redirect()->route('integrations.index')
                ->with('error', 'Authorization failed: '.($errorDescription ?? $error));
        }

        if (! $code || ! $state) {
            return redirect()->route('integrations.index')
                ->with('error', 'Authorization failed: Missing code or state');
        }

        // Verify state
        if ($state !== session('oauth_state')) {
            session()->forget(['oauth_state', 'oauth_provider_id', 'oauth_user_id']);

            return redirect()->route('integrations.index')
                ->with('error', 'Authorization failed: Invalid state parameter (CSRF)');
        }

        $providerId = session('oauth_provider_id');
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            session()->forget(['oauth_state', 'oauth_provider_id', 'oauth_user_id']);

            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        $user = Auth::user();

        try {
            $token = $provider->handleOAuthCallback($user, $code, $request->all());

            session()->forget(['oauth_state', 'oauth_provider_id', 'oauth_user_id']);

            return redirect()->route('integrations.index')
                ->with('success', "{$provider->getProviderName()} connected successfully! You can now create integrations using these credentials.");

        } catch (\Exception $e) {
            Log::error("OAuth callback failed for {$providerId}", [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return redirect()->route('integrations.index')
                ->with('error', 'Failed to connect: '.$e->getMessage());
        }
    }

    /**
     * Show token form for API key / bearer token
     */
    public function showTokenForm(string $providerId, string $authType)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        return view('settings.integrations.token-form', [
            'provider' => $provider,
            'authType' => $authType,
        ]);
    }

    /**
     * Store API key / bearer token
     */
    public function storeToken(Request $request, string $providerId, string $authType)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        $user = Auth::user();

        $request->validate([
            'token' => 'required|string|max:2048',
            'name' => 'nullable|string|max:255',
        ]);

        try {
            $token = match ($authType) {
                'bearer_token' => $provider->createFromBearerToken(
                    $user,
                    $request->input('token'),
                    $request->input('name')
                ),
                'api_key' => $provider->createFromApiKey(
                    $user,
                    $request->input('token'),
                    $request->input('name')
                ),
                default => throw new \Exception('Unsupported authentication type'),
            };

            return redirect()->route('integrations.index')
                ->with('success', "{$provider->getProviderName()} connected successfully!");

        } catch (\Exception $e) {
            Log::error("Token storage failed for {$providerId}", [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'auth_type' => $authType,
            ]);

            return back()
                ->withInput($request->except('token'))
                ->with('error', 'Failed to connect: '.$e->getMessage());
        }
    }

    /**
     * Test connection for a token
     */
    public function testConnection(IntegrationToken $token)
    {
        $this->authorize('update', $token);

        $provider = $this->registry->get($token->provider_id);

        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'Provider not found',
            ], 404);
        }

        try {
            $success = $provider->testConnection($token);

            if ($success) {
                $token->markAsActive();

                return response()->json([
                    'success' => true,
                    'message' => 'Connection successful',
                ]);
            } else {
                $token->markAsError('Connection test failed');

                return response()->json([
                    'success' => false,
                    'message' => 'Connection test failed',
                ], 400);
            }

        } catch (\Exception $e) {
            $token->markAsError($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke a token
     */
    public function revokeToken(IntegrationToken $token)
    {
        $this->authorize('delete', $token);

        $providerName = $token->provider_name ?? 'Integration';
        $provider = $this->registry->get($token->provider_id);

        if ($provider) {
            try {
                $provider->revokeToken($token);
            } catch (\Exception $e) {
                // Log error but continue with local deletion
                Log::warning("Failed to revoke token with provider: {$e->getMessage()}", [
                    'token_id' => $token->id,
                    'provider_id' => $token->provider_id,
                ]);
            }
        }

        // Always delete locally even if provider revocation fails
        $token->delete();

        return redirect()->route('integrations.index')
            ->with('success', "{$providerName} disconnected successfully");
    }

    /**
     * Clear cache for a specific integration
     */
    public function clearCache(Integration $integration)
    {
        $this->authorize('update', $integration);

        $token = $integration->integrationToken;
        $provider = $this->registry->get($token->provider_id);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        try {
            // Check if provider has clearCache method
            if (method_exists($provider, 'clearCache')) {
                $provider->clearCache($integration);

                $connectionName = $integration->name;

                return back()->with('success', "Cache invalidated for {$connectionName}. Old cache entries will be cleaned up automatically.");
            }

            return back()->with('error', 'Cache clearing not supported for this integration');
        } catch (\Exception $e) {
            Log::error('Failed to clear integration cache', [
                'integration_id' => $integration->id,
                'token_id' => $token->id,
                'provider_id' => $token->provider_id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to clear cache: '.$e->getMessage());
        }
    }

    /**
     * Toggle capability for an integration token
     */
    public function toggleCapability(Request $request, IntegrationToken $token)
    {
        $this->authorize('update', $token);

        $request->validate([
            'capability' => 'required|string',
            'enabled' => 'required|boolean',
        ]);

        $provider = $this->registry->get($token->provider_id);
        if (! $provider) {
            return back()->with('error', 'Provider not found');
        }

        $capability = $request->input('capability');
        $enabled = $request->boolean('enabled');

        // Verify capability is available (not blocked by missing scopes)
        $evaluation = $provider->evaluateTokenCapabilities($token);

        if (! in_array($capability, $evaluation['available'])) {
            return back()->with('error', 'This capability is not available due to insufficient permissions');
        }

        $token->toggleCapability($capability, $enabled);

        $action = $enabled ? 'enabled' : 'disabled';

        return back()->with('success', "Capability {$capability} has been {$action} successfully");
    }

    /**
     * Show form to update token
     */
    public function showUpdateTokenForm(IntegrationToken $token)
    {
        $provider = $this->registry->get($token->provider_id);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        return view('settings.integrations.update-token', [
            'provider' => $provider,
            'token' => $token,
            'authType' => $token->token_type,
        ]);
    }

    /**
     * Update integration token (for token rotation)
     */
    public function updateToken(Request $request, IntegrationToken $token)
    {
        $provider = $this->registry->get($token->provider_id);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        $request->validate([
            'token' => 'required|string|max:2048',
        ]);

        $newToken = trim($request->input('token'));

        try {
            // For bearer token providers, use the updateBearerToken method
            if (method_exists($provider, 'updateBearerToken')) {
                // Update the token (validates, tests, and updates metadata)
                $provider->updateBearerToken($token, $newToken);

                // Re-detect scopes
                $scopes = $provider->detectTokenScopes($token);
                $token->setDetectedScopes($scopes);

                // Re-evaluate capabilities
                $evaluation = $provider->evaluateTokenCapabilities($token);

                // Update available/blocked capabilities in metadata
                $metadata = $token->metadata ?? [];
                $previousAvailable = $metadata['available_capabilities'] ?? [];
                $metadata['available_capabilities'] = $evaluation['available'];
                $metadata['blocked_capabilities'] = array_column($evaluation['blocked'], 'capability');
                $token->metadata = $metadata;

                // Keep enabled capabilities as-is, but disable any that are no longer available
                // This preserves user preferences while ensuring security
                $currentEnabled = $token->getEnabledCapabilities();
                $stillEnabled = array_intersect($currentEnabled, $evaluation['available']);

                $config = $token->config ?? [];
                $config['enabled_capabilities'] = array_values($stillEnabled);
                $token->config = $config;

                $token->save();

                // Detect newly available capabilities for user notification
                $newlyAvailable = array_diff($evaluation['available'], $previousAvailable);
                $disabledDueToMissingScope = array_diff($currentEnabled, $stillEnabled);

                $message = 'Integration token updated successfully. Scopes and capabilities have been refreshed.';
                if (! empty($newlyAvailable)) {
                    $message .= ' '.count($newlyAvailable).' new capability(-ies) are now available for you to enable.';
                }
                if (! empty($disabledDueToMissingScope)) {
                    $message .= ' '.count($disabledDueToMissingScope).' capability(-ies) were disabled due to insufficient permissions.';
                }

                Log::info('Integration token updated', [
                    'token_id' => $token->id,
                    'provider_id' => $token->provider_id,
                    'user_id' => Auth::id(),
                    'scopes' => $scopes,
                    'available_capabilities' => count($evaluation['available']),
                    'newly_available' => $newlyAvailable,
                    'disabled_due_to_scope' => $disabledDueToMissingScope,
                ]);

                return redirect()
                    ->route('integrations.index')
                    ->with('success', $message);
            }

            return back()->with('error', 'Token update not supported for this provider');

        } catch (\Exception $e) {
            Log::error('Failed to update integration token', [
                'token_id' => $token->id,
                'provider_id' => $token->provider_id,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Failed to update token: '.$e->getMessage());
        }
    }

    /**
     * Rename an integration token
     */
    public function rename(Request $request, IntegrationToken $token)
    {
        $this->authorize('update', $token);

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $newName = trim($request->input('name'));

        // Update the provider name
        $token->provider_name = $newName;
        $token->save();

        Log::info('Integration token renamed', [
            'token_id' => $token->id,
            'provider_id' => $token->provider_id,
            'user_id' => Auth::id(),
            'new_name' => $newName,
        ]);

        return back()->with('success', 'Integration renamed successfully to "'.$newName.'"');
    }

    /**
     * Create an agent for an integration
     */
    public function createAgent(Integration $integration)
    {
        $this->authorize('update', $integration);

        $token = $integration->integrationToken;
        $provider = $this->registry->get($token->provider_id);

        if (! $provider) {
            return back()->with('error', 'Integration provider not found');
        }

        // Check if agent already exists
        if ($integration->agent()->exists()) {
            return back()->with('error', 'An agent already exists for this integration');
        }

        // Check if provider supports agent creation
        $capabilities = $provider->getCapabilities();
        if (! isset($capabilities['Agent'])) {
            return back()->with('error', 'This integration does not support agent creation.');
        }

        // Check if at least one tool capability is enabled (agents need tools to function)
        $enabledCapabilities = $integration->getEnabledCapabilities();
        $hasToolsEnabled = collect($enabledCapabilities)
            ->filter(fn ($cap) => str_starts_with($cap, 'Tools:'))
            ->isNotEmpty();

        if (! $hasToolsEnabled) {
            return back()->with('error', 'You must enable at least one Tool capability before creating an agent.');
        }

        try {
            $agent = $this->agentService->createAgent($integration, Auth::user());

            Log::info('Created integration agent', [
                'agent_id' => $agent->id,
                'integration_id' => $integration->id,
                'token_id' => $token->id,
                'user_id' => Auth::id(),
                'provider' => $token->provider_id,
            ]);

            return back()
                ->with('success', "Agent \"{$agent->name}\" created successfully");

        } catch (\Exception $e) {
            Log::error('Failed to create integration agent', [
                'integration_id' => $integration->id,
                'token_id' => $token->id,
                'provider_id' => $token->provider_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to create agent: '.$e->getMessage());
        }
    }

    /**
     * Delete the agent associated with an integration
     */
    public function deleteAgent(Integration $integration)
    {
        $this->authorize('update', $integration);

        $agent = $integration->agent;

        if (! $agent) {
            return back()->with('error', 'No agent exists for this integration');
        }

        $agentName = $agent->name;

        try {
            $this->agentService->deleteAgent($integration);

            Log::info('Deleted integration agent', [
                'agent_id' => $agent->id,
                'integration_id' => $integration->id,
                'token_id' => $integration->integration_token_id,
                'user_id' => Auth::id(),
                'provider' => $integration->integrationToken->provider_id,
            ]);

            return back()
                ->with('success', "Agent \"{$agentName}\" deleted successfully");

        } catch (\Exception $e) {
            Log::error('Failed to delete integration agent', [
                'agent_id' => $agent->id,
                'integration_id' => $integration->id,
                'token_id' => $integration->integration_token_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete agent: '.$e->getMessage());
        }
    }

    /**
     * Show triggers for a specific provider (API or Webhook)
     */
    public function showProviderTriggers(string $providerId)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider || ! ($provider instanceof \App\Services\Integrations\Contracts\InputTriggerProvider)) {
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid trigger provider');
        }

        // Check if provider requires an API token and validate if necessary
        if ($provider->requiresApiToken()) {
            $requiredAbilities = $provider->getRequiredTokenAbilities();

            $hasValidToken = Auth::user()->tokens()
                ->get()
                ->filter(function ($token) use ($requiredAbilities) {
                    // Check if token has all required abilities
                    return count(array_intersect($requiredAbilities, $token->abilities)) === count($requiredAbilities);
                })
                ->isNotEmpty();

            if (! $hasValidToken) {
                return redirect()->route($provider->getApiTokenSetupRoute())
                    ->with('error', $provider->getApiTokenMissingMessage());
            }
        }

        // Get user's triggers for this provider
        $triggers = InputTrigger::where('user_id', Auth::id())
            ->where('provider_id', $providerId)
            ->with('agent')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('settings.integrations.provider-triggers', [
            'provider' => $provider,
            'triggers' => $triggers,
        ]);
    }

    /**
     * Show trigger creation form
     */
    public function showCreateTrigger(string $providerId)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider || ! ($provider instanceof \App\Services\Integrations\Contracts\InputTriggerProvider)) {
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid trigger provider');
        }

        // Check if provider requires an API token and validate if necessary
        if ($provider->requiresApiToken()) {
            $requiredAbilities = $provider->getRequiredTokenAbilities();

            $hasValidToken = Auth::user()->tokens()
                ->get()
                ->filter(function ($token) use ($requiredAbilities) {
                    // Check if token has all required abilities
                    return count(array_intersect($requiredAbilities, $token->abilities)) === count($requiredAbilities);
                })
                ->isNotEmpty();

            if (! $hasValidToken) {
                return redirect()->route($provider->getApiTokenSetupRoute())
                    ->with('error', $provider->getApiTokenMissingMessage());
            }
        }

        // Get all active agents (includes user agents, system agents, and integration agents)
        // Sort by agent_type first, then by name
        $agents = Agent::where('status', 'active')
            ->orderBy('agent_type')
            ->orderBy('name')
            ->get();

        // Get all triggerable commands
        $triggerableCommandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
        $triggerableCommands = $triggerableCommandRegistry->getAll();

        return view('settings.integrations.create-trigger', [
            'provider' => $provider,
            'agents' => $agents,
            'triggerableCommands' => $triggerableCommands,
        ]);
    }

    /**
     * Store a new trigger
     */
    public function storeTrigger(Request $request, string $providerId)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider || ! ($provider instanceof \App\Services\Integrations\Contracts\InputTriggerProvider)) {
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid trigger provider');
        }

        $validationRules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger_target_type' => ['required', 'in:agent,command'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'agent_input_template' => ['nullable', 'string', 'max:5000'],
            'command_class' => ['nullable', 'string', 'max:255'],
            'command_parameters' => ['nullable', 'array'],
            'session_strategy' => ['nullable', 'in:new_each,continue_last'],
            'rate_limits' => ['nullable', 'array'],
            'workflow_config' => ['nullable', 'string'], // JSON string for workflow configuration
            'ip_whitelist' => ['nullable', 'array', 'max:50'],
            'ip_whitelist.*' => ['string', 'regex:/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?:\/([0-9]|[1-2][0-9]|3[0-2]))?$|^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}(?:\/([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8]))?$/'],
        ];

        // Allow providers to extend validation rules
        if (method_exists($provider, 'getAdditionalValidationRules')) {
            $validationRules = array_merge($validationRules, $provider->getAdditionalValidationRules());
        }

        $validated = $request->validate($validationRules);

        // Conditional validation based on trigger_target_type
        $triggerTargetType = $validated['trigger_target_type'];

        if ($triggerTargetType === 'agent') {
            // Agent target requires agent_id
            if (empty($validated['agent_id'])) {
                return back()
                    ->withInput()
                    ->with('error', 'Agent selection is required when trigger target type is "agent"');
            }
        } elseif ($triggerTargetType === 'command') {
            // Command target requires command_class
            if (empty($validated['command_class'])) {
                return back()
                    ->withInput()
                    ->with('error', 'Command selection is required when trigger target type is "command"');
            }

            // Validate command exists and is triggerable
            $commandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
            $commandDef = $commandRegistry->getByClass($validated['command_class']);

            if (! $commandDef) {
                return back()
                    ->withInput()
                    ->with('error', 'Selected command is not available for triggering');
            }

            // Note: Parameter validation happens AFTER processing (after line 922)
            // to ensure type conversions (string→array, string→int) are applied first
        }

        try {
            // Parse workflow config if provided
            $config = [];
            if (! empty($validated['workflow_config'])) {
                $workflowConfig = json_decode($validated['workflow_config'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $config['workflow_config'] = $workflowConfig;
                } else {
                    return back()
                        ->withInput()
                        ->with('error', 'Invalid JSON in workflow configuration: '.json_last_error_msg());
                }
            }

            // Add agent input template if provided
            if (! empty($validated['agent_input_template'])) {
                // Validate template syntax
                $templateProcessor = app(\App\Services\InputTrigger\PayloadTemplateProcessor::class);
                $validation = $templateProcessor->validate($validated['agent_input_template']);

                if (! $validation['valid']) {
                    return back()
                        ->withInput()
                        ->withErrors(['agent_input_template' => 'Invalid template syntax: '.implode(', ', $validation['errors'])])
                        ->with('error', 'Please fix the agent input template syntax');
                }

                $config['agent_input_template'] = $validated['agent_input_template'];
            }

            // Allow provider to prepare config from validated data
            if (method_exists($provider, 'prepareConfigFromRequest')) {
                $providerConfig = $provider->prepareConfigFromRequest($validated);
                $config = array_merge($config, $providerConfig);
            }

            // Process command parameters (convert newline-separated strings to arrays for array-type params)
            $commandParameters = $validated['command_parameters'] ?? null;
            if ($commandParameters && $validated['trigger_target_type'] === 'command' && ! empty($validated['command_class'])) {
                $commandParameters = $this->processCommandParameters(
                    $validated['command_class'],
                    $commandParameters
                );

                // Validate processed command parameters
                $commandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
                $validation = $commandRegistry->validatePayload($validated['command_class'], $commandParameters);

                if (! $validation['valid']) {
                    return back()
                        ->withInput()
                        ->with('error', 'Command parameter validation failed: '.implode(', ', $validation['errors']));
                }
            }

            // Determine if provider requires HTTP security features
            $requiresHttpSecurity = ! method_exists($provider, 'requiresHttpSecurity') || $provider->requiresHttpSecurity();

            // Create the trigger
            $trigger = InputTrigger::create([
                'user_id' => Auth::id(),
                'provider_id' => $providerId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'trigger_target_type' => $validated['trigger_target_type'],
                'agent_id' => $validated['agent_id'] ?? null,
                'command_class' => $validated['command_class'] ?? null,
                'command_parameters' => $commandParameters,
                'session_strategy' => $validated['session_strategy'] ?? null,
                'status' => 'active',
                'config' => $config,
                'rate_limits' => $requiresHttpSecurity ? ($validated['rate_limits'] ?? [
                    'per_minute' => 10,
                    'per_hour' => 100,
                ]) : null,
                'ip_whitelist' => $requiresHttpSecurity ? ($validated['ip_whitelist'] ?? []) : null,
            ]);

            // Generate credentials if needed
            if (method_exists($provider, 'generateCredentials')) {
                $provider->generateCredentials($trigger);
                $trigger->refresh(); // Reload to get updated config
            }

            Log::info('Input trigger created', [
                'trigger_id' => $trigger->id,
                'provider_id' => $providerId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('integrations.trigger-details', ['trigger' => $trigger->id])
                ->with('success', 'Trigger created successfully! See setup instructions below.');

        } catch (\Exception $e) {
            Log::error('Failed to create trigger', [
                'provider_id' => $providerId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Failed to create trigger: '.$e->getMessage());
        }
    }

    /**
     * Process command parameters - convert array-type params from newline-separated strings to arrays
     *
     * @param  string  $commandClass  Fully qualified command class name
     * @param  array  $parameters  Raw parameters from form submission
     * @return array Processed parameters with arrays properly formatted
     */
    protected function processCommandParameters(string $commandClass, array $parameters): array
    {
        // Get command definition from registry
        $commandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
        $commandDefinition = $commandRegistry->getByClass($commandClass);

        if (! $commandDefinition || ! isset($commandDefinition['parameters'])) {
            return $parameters;
        }

        // Process each parameter based on its type
        foreach ($commandDefinition['parameters'] as $name => $definition) {
            // Skip user-id parameter - it will be set automatically to Auth::id()
            if ($name === 'user-id') {
                unset($parameters[$name]);

                continue;
            }

            if (! isset($parameters[$name])) {
                continue;
            }

            $value = $parameters[$name];
            $type = $definition['type'] ?? 'string';

            // Convert newline-separated string to array for array-type parameters
            if ($type === 'array' && is_string($value)) {
                // Split by newlines, trim whitespace, remove empty lines
                $array = array_filter(
                    array_map('trim', explode("\n", $value)),
                    fn ($item) => $item !== ''
                );

                $parameters[$name] = array_values($array); // Re-index array
            }
            // Convert string to integer for integer-type parameters
            elseif ($type === 'integer' && is_string($value)) {
                $parameters[$name] = (int) $value;
            }
            // For string parameters, remove empty values
            elseif ($type === 'string' && ($value === null || $value === '')) {
                unset($parameters[$name]);
            }
        }

        return $parameters;
    }

    /**
     * Show trigger details and setup instructions
     */
    public function showTriggerDetails(string $triggerId)
    {
        $trigger = InputTrigger::where('id', $triggerId)
            ->where('user_id', Auth::id())
            ->with('agent')
            ->firstOrFail();

        $provider = $this->triggerRegistry->getProvider($trigger->provider_id);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Trigger provider not found');
        }

        // Get all triggerable commands for command triggers
        $triggerableCommandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
        $triggerableCommands = $triggerableCommandRegistry->getAll();

        return view('settings.integrations.trigger-details', [
            'trigger' => $trigger,
            'provider' => $provider,
            'setupInstructions' => $provider->getSetupInstructions($trigger),
            'triggerableCommands' => $triggerableCommands,
        ]);
    }

    /**
     * List all triggers for the user
     */
    public function listTriggers()
    {
        $triggers = InputTrigger::where('user_id', Auth::id())
            ->with('agent')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('provider_id');

        $providers = [];
        foreach ($triggers as $providerId => $providerTriggers) {
            $provider = $this->triggerRegistry->getProvider($providerId);
            if ($provider) {
                $providers[$providerId] = [
                    'provider' => $provider,
                    'triggers' => $providerTriggers,
                ];
            }
        }

        return view('settings.integrations.list-triggers', [
            'providers' => $providers,
        ]);
    }

    /**
     * Update a trigger
     */
    public function updateTrigger(Request $request, string $triggerId)
    {
        $trigger = InputTrigger::where('id', $triggerId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $provider = $this->triggerRegistry->getProvider($trigger->provider_id);

        $validationRules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'agent_id' => ['nullable', 'exists:agents,id'],
            'session_strategy' => ['nullable', 'in:new_each,continue_last'],
            'status' => ['required', 'in:active,paused,disabled'],
            'workflow_config' => ['nullable', 'string'],
            'command_parameters' => ['nullable', 'array'],
            'rate_limits' => ['nullable', 'array'],
            'rate_limits.per_minute' => ['nullable', 'integer', 'min:1', 'max:100'],
            'rate_limits.per_hour' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'ip_whitelist' => ['nullable', 'array', 'max:50'],
            'ip_whitelist.*' => ['string', 'regex:/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?:\/([0-9]|[1-2][0-9]|3[0-2]))?$|^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}(?:\/([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8]))?$/'],
        ];

        // Allow provider to extend validation rules
        if ($provider && method_exists($provider, 'getAdditionalValidationRules')) {
            $validationRules = array_merge($validationRules, $provider->getAdditionalValidationRules());
        }

        $validated = $request->validate($validationRules);

        // Parse workflow config if provided
        $config = $trigger->config ?? [];
        if (! empty($validated['workflow_config'])) {
            $workflowConfig = json_decode($validated['workflow_config'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $config['workflow_config'] = $workflowConfig;
            } else {
                return back()
                    ->withInput()
                    ->withErrors(['workflow_config' => 'Invalid JSON in workflow configuration: '.json_last_error_msg()]);
            }
        } else {
            // Remove workflow config if empty
            unset($config['workflow_config']);
        }

        // Allow provider to prepare config from validated data
        if ($provider && method_exists($provider, 'prepareConfigFromRequest')) {
            $providerConfig = $provider->prepareConfigFromRequest($validated);
            $config = array_merge($config, $providerConfig);
        }

        // Process command parameters if this is a command trigger
        $commandParameters = $trigger->command_parameters;
        if ($trigger->isCommandTrigger() && ! empty($validated['command_parameters'])) {
            $commandParameters = $this->processCommandParameters(
                $trigger->command_class,
                $validated['command_parameters']
            );

            // Validate processed command parameters
            $commandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
            $validation = $commandRegistry->validatePayload($trigger->command_class, $commandParameters);

            if (! $validation['valid']) {
                return back()
                    ->withInput()
                    ->with('error', 'Command parameter validation failed: '.implode(', ', $validation['errors']));
            }
        }

        // Determine if provider requires HTTP security features
        $requiresHttpSecurity = ! $provider || ! method_exists($provider, 'requiresHttpSecurity') || $provider->requiresHttpSecurity();

        // Update trigger with validated data
        $trigger->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'agent_id' => $validated['agent_id'] ?? null,
            'session_strategy' => $validated['session_strategy'] ?? null,
            'status' => $validated['status'],
            'config' => $config,
            'command_parameters' => $commandParameters,
            'rate_limits' => $requiresHttpSecurity ? ($validated['rate_limits'] ?? [
                'per_minute' => 10,
                'per_hour' => 100,
            ]) : null,
            'ip_whitelist' => $requiresHttpSecurity ? ($validated['ip_whitelist'] ?? []) : null,
        ]);

        Log::info('Input trigger updated', [
            'trigger_id' => $trigger->id,
            'user_id' => Auth::id(),
            'changes' => array_keys($validated),
        ]);

        return redirect()->route('integrations.trigger-details', ['trigger' => $trigger->id])
            ->with('success', 'Trigger updated successfully');
    }

    /**
     * Delete a trigger
     */
    public function deleteTrigger(string $triggerId)
    {
        $trigger = InputTrigger::where('id', $triggerId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $trigger->delete();

        return redirect()->route('integrations.index')
            ->with('success', 'Trigger deleted successfully');
    }

    /**
     * Regenerate webhook secret for a trigger
     */
    public function regenerateTriggerSecret(string $triggerId)
    {
        $trigger = InputTrigger::where('id', $triggerId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        // Verify it's a webhook trigger
        if ($trigger->provider_id !== 'webhook') {
            return back()->with('error', 'Secret regeneration is only available for webhook triggers');
        }

        $provider = $this->triggerRegistry->getProvider('webhook');

        if (! $provider || ! method_exists($provider, 'regenerateSecret')) {
            return back()->with('error', 'Secret regeneration not supported');
        }

        try {
            $result = $provider->regenerateSecret($trigger);

            Log::warning('Webhook secret regenerated via UI', [
                'trigger_id' => $trigger->id,
                'trigger_name' => $trigger->name,
                'user_id' => Auth::id(),
                'rotation_count' => $trigger->secret_rotation_count,
                'ip_address' => request()->ip(),
            ]);

            return back()->with('success', 'Webhook secret regenerated successfully. Update your webhook clients with the new secret immediately. The old secret is now invalid.');

        } catch (\Exception $e) {
            Log::error('Failed to regenerate webhook secret', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to regenerate secret: '.$e->getMessage());
        }
    }

    // ==================== OUTPUT ACTIONS ====================

    /**
     * Show output action creation form
     */
    public function showCreateAction(string $providerId)
    {
        $provider = $this->actionRegistry->getProvider($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid output action provider');
        }

        // Create a temporary action instance for setup instructions
        $tempAction = new \App\Models\OutputAction([
            'provider_id' => $providerId,
            'name' => 'New Action',
        ]);

        return view('settings.integrations.create-action', [
            'provider' => $provider,
            'configSchema' => $provider->getActionConfigSchema(),
            'setupInstructions' => $provider->getSetupInstructions($tempAction),
            'examplePayload' => $provider->getExamplePayload($tempAction),
        ]);
    }

    /**
     * Store a new output action
     */
    public function storeAction(Request $request, string $providerId)
    {
        $provider = $this->actionRegistry->getProvider($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid output action provider');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger_on' => ['required', 'in:success,failure,always'],
            'config' => ['required', 'array'],
            'webhook_secret' => ['nullable', 'string', 'max:1000'],
        ]);

        // Validate provider-specific configuration
        $configValidation = $provider->validateActionConfig($validated['config']);
        if (! $configValidation['valid']) {
            // Prefix config errors with 'config.' for proper field matching
            $configErrors = [];
            foreach ($configValidation['errors'] as $field => $message) {
                $configErrors["config.{$field}"] = $message;
            }

            return back()
                ->withInput()
                ->withErrors($configErrors)
                ->with('error', 'Please fix the configuration errors');
        }

        try {
            $action = OutputAction::create([
                'user_id' => Auth::id(),
                'provider_id' => $providerId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'trigger_on' => $validated['trigger_on'],
                'status' => 'active',
                'config' => $validated['config'],
                'webhook_secret' => $validated['webhook_secret'] ?? null,
            ]);

            Log::info('Output action created', [
                'action_id' => $action->id,
                'provider_id' => $providerId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('integrations.action-details', ['action' => $action->id])
                ->with('success', 'Output action created successfully!');

        } catch (\Exception $e) {
            Log::error('Failed to create output action', [
                'provider_id' => $providerId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Failed to create output action: '.$e->getMessage());
        }
    }

    /**
     * Show output action details
     */
    public function showActionDetails(string $actionId)
    {
        $action = OutputAction::where('id', $actionId)
            ->where('user_id', Auth::id())
            ->with(['agents', 'inputTriggers', 'logs' => function ($query) {
                $query->orderBy('executed_at', 'desc')->limit(10);
            }])
            ->firstOrFail();

        $provider = $this->actionRegistry->getProvider($action->provider_id);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Output action provider not found');
        }

        // Get agents available to the user (public agents + user's own agents)
        $agents = \App\Models\Agent::forUser(Auth::id())
            ->orderBy('name')
            ->get(['id', 'name', 'created_by']);

        // Get only input triggers created by the user
        $inputTriggers = \App\Models\InputTrigger::where('user_id', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('settings.integrations.action-details', [
            'action' => $action,
            'provider' => $provider,
            'setupInstructions' => $provider->getSetupInstructions($action),
            'examplePayload' => $provider->getExamplePayload($action),
            'agents' => $agents,
            'inputTriggers' => $inputTriggers,
        ]);
    }

    /**
     * List all output actions for the user
     */
    public function listActions()
    {
        $actions = OutputAction::where('user_id', Auth::id())
            ->with(['agents', 'inputTriggers'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Get metadata for all action providers
        $providerMetadata = $this->actionRegistry->getAllActionMetadata();

        return view('settings.integrations.list-actions', [
            'actions' => $actions,
            'providerMetadata' => $providerMetadata,
        ]);
    }

    /**
     * Update an output action
     */
    public function updateAction(Request $request, string $actionId)
    {
        $action = OutputAction::where('id', $actionId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $provider = $this->actionRegistry->getProvider($action->provider_id);

        if (! $provider) {
            return back()->with('error', 'Output action provider not found');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger_on' => ['sometimes', 'required', 'in:success,failure,always'],
            'status' => ['sometimes', 'required', 'in:active,paused,disabled'],
            'config' => ['sometimes', 'required', 'array'],
            'webhook_secret' => ['nullable', 'string', 'max:1000'],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer', 'exists:agents,id'],
            'trigger_ids' => ['nullable', 'array'],
            'trigger_ids.*' => ['string', 'exists:input_triggers,id'], // UUIDs are strings, not integers
        ]);

        // Additional authorization checks for agent_ids
        if (! empty($validated['agent_ids'])) {
            $availableAgentIds = \App\Models\Agent::forUser(Auth::id())
                ->pluck('id')
                ->toArray();

            foreach ($validated['agent_ids'] as $agentId) {
                if (! in_array($agentId, $availableAgentIds)) {
                    return back()->withErrors(['agent_ids' => 'One or more selected agents are not accessible to you.']);
                }
            }
        }

        // Additional authorization checks for trigger_ids
        if (! empty($validated['trigger_ids'])) {
            $userTriggerIds = \App\Models\InputTrigger::where('user_id', Auth::id())
                ->pluck('id')
                ->toArray();

            foreach ($validated['trigger_ids'] as $triggerId) {
                if (! in_array($triggerId, $userTriggerIds)) {
                    return back()->withErrors(['trigger_ids' => 'One or more selected triggers do not belong to you.']);
                }
            }
        }

        // Validate provider-specific configuration if config is being updated
        if (isset($validated['config'])) {
            $configValidation = $provider->validateActionConfig($validated['config']);
            if (! $configValidation['valid']) {
                return back()
                    ->withErrors($configValidation['errors'])
                    ->with('error', 'Please fix the configuration errors');
            }
        }

        try {
            $action->update($validated);

            // Always sync agent relationships (even when empty - allows unlinking all)
            // Multi-select fields don't send the key when nothing is selected
            $action->agents()->sync($request->input('agent_ids', []));

            // Always sync input trigger relationships (even when empty - allows unlinking all)
            $action->inputTriggers()->sync($request->input('trigger_ids', []));

            Log::info('Output action updated', [
                'action_id' => $action->id,
                'user_id' => Auth::id(),
                'synced_agents' => count($request->input('agent_ids', [])),
                'synced_triggers' => count($request->input('trigger_ids', [])),
            ]);

            return back()->with('success', 'Output action updated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to update output action', [
                'action_id' => $action->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to update output action: '.$e->getMessage());
        }
    }

    /**
     * Delete an output action
     */
    public function deleteAction(string $actionId)
    {
        $action = OutputAction::where('id', $actionId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        try {
            $action->delete();

            Log::info('Output action deleted', [
                'action_id' => $actionId,
                'user_id' => Auth::id(),
            ]);

            return redirect()->route('integrations.list-actions')
                ->with('success', 'Output action deleted successfully');

        } catch (\Exception $e) {
            Log::error('Failed to delete output action', [
                'action_id' => $actionId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete output action: '.$e->getMessage());
        }
    }

    /**
     * Test an output action
     */
    public function testAction(Request $request, string $actionId)
    {
        $action = OutputAction::where('id', $actionId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $provider = $this->actionRegistry->getProvider($action->provider_id);

        if (! $provider) {
            return back()->with('error', 'Output action provider not found');
        }

        try {
            // Get test payload from request or use example
            $testPayload = $request->input('test_payload', $provider->getExamplePayload($action));

            // Execute test
            $result = $this->actionDispatcher->test($action, $testPayload, Auth::user());

            if ($result['success']) {
                return back()->with('success', 'Test successful! Check the execution log for details.');
            } else {
                return back()->with('error', 'Test failed: '.($result['error'] ?? 'Unknown error'));
            }

        } catch (\Exception $e) {
            Log::error('Failed to test output action', [
                'action_id' => $actionId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Test failed: '.$e->getMessage());
        }
    }

    // ==================== INTEGRATION CRUD ====================

    /**
     * Show integration creation form
     */
    public function createIntegration(string $providerId)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        $tokens = IntegrationToken::where('user_id', Auth::id())
            ->where('provider_id', $providerId)
            ->where('status', 'active')
            ->get();

        return view('settings.integrations.integration-create', [
            'provider' => $provider,
            'tokens' => $tokens,
        ]);
    }

    /**
     * Store new integration
     */
    public function storeIntegration(Request $request, string $providerId)
    {
        $provider = $this->registry->get($providerId);

        if (! $provider) {
            return redirect()->route('integrations.index')
                ->with('error', 'Integration provider not found');
        }

        $validated = $request->validate([
            'integration_token_id' => 'required|exists:integration_tokens,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'enabled_capabilities' => 'array',
        ]);

        // Verify token belongs to user and provider
        $token = IntegrationToken::where('id', $validated['integration_token_id'])
            ->where('user_id', Auth::id())
            ->where('provider_id', $providerId)
            ->firstOrFail();

        $integration = Integration::create([
            'user_id' => Auth::id(),
            'integration_token_id' => $token->id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'config' => [
                'enabled_capabilities' => $validated['enabled_capabilities'] ?? [],
            ],
            'status' => 'active',
        ]);

        return redirect()->route('integrations.edit', $integration)
            ->with('success', "Integration '{$integration->name}' created successfully. Configure additional settings below.");
    }

    /**
     * Show integration edit form
     */
    public function editIntegration(Integration $integration)
    {
        $this->authorize('update', $integration);

        // Check if this is an MCP server integration - use custom edit page
        if ($integration->integrationToken->provider_id === 'mcp_server') {
            return redirect()->route('integrations.mcp-server-edit', ['integration' => $integration]);
        }

        $tokens = IntegrationToken::where('user_id', Auth::id())
            ->where('provider_id', $integration->integrationToken->provider_id)
            ->where('status', 'active')
            ->get();

        return view('settings.integrations.integration-edit', [
            'integration' => $integration,
            'provider' => $this->registry->get($integration->integrationToken->provider_id),
            'tokens' => $tokens,
        ]);
    }

    /**
     * Update integration
     */
    public function updateIntegration(Request $request, Integration $integration)
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'integration_token_id' => 'required|exists:integration_tokens,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'enabled_capabilities' => 'array',
            'config' => 'array',
            'config.agent_id' => 'nullable|exists:agents,id',
            'config.session_strategy' => 'nullable|in:new_each,thread,continue_last',
            'config.enable_live_updates' => 'nullable|boolean',
            'config.respond_in_all_channels' => 'nullable|boolean',
            'config.enable_conversation_continuation' => 'nullable|boolean',
            'config.continuation_timeout_minutes' => 'nullable|integer|min:5|max:1440',
            'config.rate_limit_per_minute' => 'nullable|integer|min:1|max:1000',
            'config.rate_limit_per_hour' => 'nullable|integer|min:1|max:10000',
            'config.knowledge_scope_tags' => 'nullable|string|max:1000',
            'config.use_dynamic_channel_tags' => 'nullable|boolean',
        ]);

        // Verify token belongs to user and matches provider
        $token = IntegrationToken::where('id', $validated['integration_token_id'])
            ->where('user_id', Auth::id())
            ->where('provider_id', $integration->integrationToken->provider_id)
            ->firstOrFail();

        // Check if any Input trigger capabilities are enabled
        $enabledCapabilities = $validated['enabled_capabilities'] ?? [];
        $hasInputTriggers = collect($enabledCapabilities)->filter(function ($cap) {
            return str_starts_with($cap, 'Input:');
        })->isNotEmpty();

        // If input triggers are enabled, require agent_id and session_strategy
        if ($hasInputTriggers) {
            $config = $validated['config'] ?? [];

            if (empty($config['agent_id'])) {
                return back()
                    ->withInput()
                    ->withErrors(['config.agent_id' => 'An agent must be configured when input trigger capabilities are enabled.']);
            }

            if (empty($config['session_strategy'])) {
                return back()
                    ->withInput()
                    ->withErrors(['config.session_strategy' => 'A session strategy must be configured when input trigger capabilities are enabled.']);
            }
        }

        // Build config array
        $config = array_merge($integration->config ?? [], [
            'enabled_capabilities' => $validated['enabled_capabilities'] ?? [],
        ]);

        // Merge in config fields if provided
        if (isset($validated['config'])) {
            foreach ($validated['config'] as $key => $value) {
                // Process knowledge_scope_tags: convert comma-separated string to array
                if ($key === 'knowledge_scope_tags' && is_string($value)) {
                    $tags = array_map('trim', explode(',', $value));
                    $tags = array_filter($tags); // Remove empty values
                    $config[$key] = empty($tags) ? null : $tags;
                } else {
                    $config[$key] = $value;
                }
            }
        }

        // Update integration
        $integration->update([
            'integration_token_id' => $token->id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'config' => $config,
        ]);

        // Allow provider to process any provider-specific configuration
        $provider = $this->registry->get($token->provider_id);
        if ($provider) {
            $provider->processIntegrationUpdate($integration, $request->all());
        }

        return back()
            ->with('success', 'Configuration saved successfully');
    }

    /**
     * Delete integration
     */
    public function deleteIntegration(Integration $integration)
    {
        $this->authorize('delete', $integration);

        $name = $integration->name;
        $token = $integration->integrationToken;

        // Check BEFORE deleting if we should cascade delete the token
        // MCP integrations create their own tokens (1:1 relationship)
        $shouldDeleteToken = $token->isExclusiveMcpToken();

        // Delete the integration
        $integration->delete();

        // Delete the token if it was exclusive to this integration
        if ($shouldDeleteToken) {
            Log::info('Deleting exclusive MCP token with integration', [
                'integration_id' => $integration->id,
                'token_id' => $token->id,
                'provider' => $token->provider_id,
            ]);

            $token->delete();
        }

        return redirect()->route('integrations.index')
            ->with('success', "Integration '{$name}' deleted successfully");
    }
}
