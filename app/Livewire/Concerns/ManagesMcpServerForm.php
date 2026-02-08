<?php

namespace App\Livewire\Concerns;

use App\Models\IntegrationToken;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait ManagesMcpServerForm
{
    public string $integrationName = '';

    public string $integrationDescription = '';

    public string $serverName = '';

    public string $relayConfigJson = '';

    public array $credentials = [];

    public array $discoveredTools = [];

    public array $discoveredPrompts = [];

    public ?array $connectionTestResult = null;

    public string $visibility = 'private';

    /**
     * Get base validation rules shared by create and edit forms
     */
    protected function getBaseRules(): array
    {
        return [
            'integrationName' => 'required|string|max:255',
            'integrationDescription' => 'nullable|string|max:1000',
            'relayConfigJson' => 'required|json',
            'credentials.*.key' => 'required|string|max:255',
        ];
    }

    /**
     * Get base validation messages shared by create and edit forms
     */
    protected function getBaseMessages(): array
    {
        return [
            'relayConfigJson.json' => 'Configuration must be valid JSON. Common issues: trailing commas, unquoted keys, single quotes instead of double quotes.',
            'credentials.*.key.required' => 'Credential key is required',
        ];
    }

    /**
     * Update event handler for relay config changes
     */
    public function updatedRelayConfigJson(): void
    {
        // Provide more specific JSON error feedback
        if (! empty($this->relayConfigJson)) {
            json_decode($this->relayConfigJson);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE) {
                $specificError = match ($jsonError) {
                    JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
                    JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
                    JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
                    JSON_ERROR_SYNTAX => 'Syntax error - check for trailing commas, missing quotes, or incorrect brackets',
                    JSON_ERROR_UTF8 => 'Malformed UTF-8 characters',
                    default => json_last_error_msg(),
                };

                $this->addError('relayConfigJson', 'Invalid JSON: '.$specificError);
            } else {
                // Clear error if JSON is now valid
                $this->resetErrorBag('relayConfigJson');
            }
        }

        $this->extractPlaceholders();
    }

    /**
     * Extract ${VARIABLE} placeholders from JSON config
     */
    protected function extractPlaceholders(): void
    {
        // Extract ${VARIABLE} placeholders from JSON
        preg_match_all('/\$\{([A-Z_]+)\}/', $this->relayConfigJson, $matches);

        $placeholders = array_unique($matches[1]);

        // Keep existing credential values, add new placeholders
        $existingKeys = array_column($this->credentials, 'key');

        foreach ($placeholders as $placeholder) {
            if (! in_array($placeholder, $existingKeys)) {
                $this->credentials[] = [
                    'key' => $placeholder,
                    'value' => '',
                    'existing' => false,
                ];
            }
        }

        // Remove credentials that are no longer in the config
        $this->credentials = array_filter($this->credentials, function ($cred) use ($placeholders) {
            return in_array($cred['key'], $placeholders);
        });

        $this->credentials = array_values($this->credentials); // Re-index
    }

    /**
     * Test MCP server connection and discover tools
     */
    public function testConnection(): void
    {
        // Validate only fields needed for connection test
        $this->validate([
            'relayConfigJson' => 'required|json',
        ]);

        // If credentials exist, validate them
        if (! empty($this->credentials)) {
            $this->validate([
                'credentials.*.key' => 'required|string|max:255',
                'credentials.*.value' => 'required|string',
            ]);
        }

        // Parse relay config
        try {
            $relayConfig = json_decode($this->relayConfigJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->connectionTestResult = [
                    'success' => false,
                    'message' => 'Invalid JSON configuration: '.json_last_error_msg(),
                ];

                return;
            }

            // Get provider
            $provider = app(ProviderRegistry::class)->get('mcp_server');

            // Build credentials map (merge with existing for edit mode)
            $credentialsMap = $this->buildCredentialsMap();

            // Create temporary token for testing
            $tempToken = $this->createTemporaryToken($credentialsMap, $relayConfig);

            // Test connection
            $success = $provider->testConnection($tempToken);

            if ($success) {
                $this->handleSuccessfulConnection($provider, $tempToken);
            } else {
                $this->handleFailedConnection('Connection failed. Please check your configuration and credentials.');
            }

        } catch (\Exception $e) {
            Log::error('MCP server connection test failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            $this->handleFailedConnection('Connection failed: '.$e->getMessage());
        }
    }

    /**
     * Build credentials map from form input
     * Override in edit mode to merge with existing credentials
     */
    protected function buildCredentialsMap(): array
    {
        $credentialsMap = [];
        foreach ($this->credentials as $cred) {
            if (! empty($cred['key']) && ! empty($cred['value'])) {
                $credentialsMap[$cred['key']] = $cred['value'];
            }
        }

        return $credentialsMap;
    }

    /**
     * Create temporary IntegrationToken for connection testing
     */
    protected function createTemporaryToken(array $credentialsMap, array $relayConfig): IntegrationToken
    {
        return new IntegrationToken([
            'user_id' => Auth::id(),
            'provider_id' => 'mcp_server',
            'provider_name' => $this->serverName,
            'token_type' => 'none',
            'metadata' => [
                'credentials' => $credentialsMap,
            ],
            'config' => [
                'server_name' => $this->serverName,
                'relay_config' => $relayConfig,
            ],
        ]);
    }

    /**
     * Handle successful connection test
     */
    protected function handleSuccessfulConnection($provider, IntegrationToken $tempToken): void
    {
        // Get runtime config with injected credentials
        $runtimeConfig = $provider->buildRuntimeConfig($tempToken);

        // Use user-specific server name to prevent conflicts
        $serverInstanceName = 'user_'.Auth::id().'_'.$this->serverName;
        config(['relay.servers.'.$serverInstanceName => $runtimeConfig]);

        // Load tools using standard Relay method
        $tools = \Prism\Relay\Facades\Relay::tools($serverInstanceName);

        Log::debug('MCP tools loaded from Relay', [
            'server' => $serverInstanceName,
            'tools_count' => count($tools),
            'tools_type' => gettype($tools),
            'tools_class' => is_object($tools) ? get_class($tools) : 'not_object',
        ]);

        $this->discoveredTools = collect($tools)->map(function ($tool) {
            Log::debug('Mapping tool', [
                'tool_type' => gettype($tool),
                'tool_class' => is_object($tool) ? get_class($tool) : 'not_object',
                'has_name' => method_exists($tool, 'name'),
                'has_description' => method_exists($tool, 'description'),
            ]);

            return [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'enabled' => true, // Auto-enable by default
            ];
        })->toArray();

        // Prompts discovery: Most MCP servers embed usage guidance in tool descriptions
        // Separate prompts/list endpoint is rarely implemented and causes timeouts
        $this->discoveredPrompts = [];

        Log::info('MCP server tools and prompts discovered', [
            'server' => $serverInstanceName,
            'tool_count' => count($this->discoveredTools),
            'prompt_count' => count($this->discoveredPrompts),
        ]);

        $this->connectionTestResult = [
            'success' => true,
            'message' => 'Connection successful! MCP server is responding.',
            'toolCount' => count($this->discoveredTools),
            'promptCount' => count($this->discoveredPrompts),
        ];
    }

    /**
     * Handle failed connection test
     */
    protected function handleFailedConnection(string $message): void
    {
        $this->connectionTestResult = [
            'success' => false,
            'message' => $message,
        ];
        $this->discoveredTools = [];
    }

    /**
     * Toggle tool enabled state
     */
    public function toggleTool(int $index): void
    {
        if (isset($this->discoveredTools[$index])) {
            $this->discoveredTools[$index]['enabled'] = ! ($this->discoveredTools[$index]['enabled'] ?? true);
        }
    }

    /**
     * Validate that connection test passed before saving
     */
    protected function ensureConnectionTestPassed(): bool
    {
        if (empty($this->connectionTestResult['success'])) {
            session()->flash('error', 'Please test the connection successfully before saving');

            return false;
        }

        return true;
    }
}
