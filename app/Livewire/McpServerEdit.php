<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesMcpServerForm;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class McpServerEdit extends Component
{
    use ManagesMcpServerForm;

    public Integration $integration;

    protected function rules()
    {
        return array_merge($this->getBaseRules(), [
            'credentials.*.value' => 'nullable|string|max:512',
        ]);
    }

    protected function messages()
    {
        return $this->getBaseMessages();
    }

    public function mount(Integration $integration)
    {
        $this->integration = $integration;
        $token = $integration->integrationToken;

        // Load existing data
        $this->integrationName = $integration->name;
        $this->integrationDescription = $integration->description ?? '';
        $this->serverName = $token->config['server_name'] ?? '';
        $this->visibility = $integration->visibility;

        // Load relay config
        $relayConfig = $token->config['relay_config'] ?? [];
        $this->relayConfigJson = json_encode($relayConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Load existing credentials (but mask values)
        $existingCredentials = $token->metadata['credentials'] ?? [];
        foreach ($existingCredentials as $key => $value) {
            $this->credentials[] = [
                'key' => $key,
                'value' => '', // Empty - user can update if needed
                'existing' => true,
            ];
        }

        // Load discovered tools from integration config
        $this->discoveredTools = $integration->config['discovered_tools'] ?? [];

        // Load discovered prompts from integration config
        $this->discoveredPrompts = $integration->config['discovered_prompts'] ?? [];

        // Extract any new placeholders from config
        $this->extractPlaceholders();
    }

    /**
     * Override to merge with existing credentials for edit mode
     */
    protected function buildCredentialsMap(): array
    {
        $token = $this->integration->integrationToken;
        $existingCredentials = $token->metadata['credentials'] ?? [];
        $credentialsMap = $existingCredentials;

        foreach ($this->credentials as $cred) {
            if (! empty($cred['value'])) {
                // Update with new value
                $credentialsMap[$cred['key']] = $cred['value'];
            }
            // If value is empty and key exists in existing, keep existing value
        }

        return $credentialsMap;
    }

    /**
     * Override to preserve existing tool enabled states during rediscovery
     */
    protected function handleSuccessfulConnection($provider, $tempToken): void
    {
        // Get runtime config with injected credentials
        $runtimeConfig = $provider->buildRuntimeConfig($tempToken);

        // Use user-specific server name to prevent conflicts
        $serverInstanceName = 'user_'.Auth::id().'_'.$this->serverName;
        config(['relay.servers.'.$serverInstanceName => $runtimeConfig]);

        // Load tools using standard Relay method
        $tools = \Prism\Relay\Facades\Relay::tools($serverInstanceName);

        // Preserve existing enabled states when rediscovering tools
        $existingTools = collect($this->discoveredTools)->keyBy('name');

        $this->discoveredTools = collect($tools)->map(function ($tool) use ($existingTools) {
            $existingTool = $existingTools->get($tool->name());

            return [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'enabled' => $existingTool['enabled'] ?? true,
            ];
        })->toArray();

        // Prompts discovery: Most MCP servers embed usage guidance in tool descriptions
        $this->discoveredPrompts = [];

        Log::info('MCP server tools and prompts discovered during edit', [
            'server' => $serverInstanceName,
            'integration_id' => $this->integration->id,
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

    public function save()
    {
        // Validate
        $this->validate();

        try {
            // Parse relay config
            $relayConfig = json_decode($this->relayConfigJson, true);

            // Get token
            $token = $this->integration->integrationToken;

            // Build credentials map - merge new values with existing
            $credentialsMap = $this->buildCredentialsMap();

            // Update token
            $token->update([
                'metadata' => array_merge($token->metadata, [
                    'credentials' => $credentialsMap,
                ]),
                'config' => array_merge($token->config, [
                    'relay_config' => $relayConfig,
                ]),
            ]);

            // Build capability list from enabled tools
            $capabilities = collect($this->discoveredTools)
                ->filter(fn ($tool) => $tool['enabled'] ?? true)
                ->map(fn ($tool) => 'Tools:'.$tool['name'])
                ->values()
                ->toArray();

            // Update integration
            $this->integration->update([
                'name' => $this->integrationName,
                'description' => $this->integrationDescription,
                'visibility' => $this->visibility,
                'config' => array_merge($this->integration->config, [
                    'enabled_capabilities' => $capabilities,
                    'discovered_tools' => $this->discoveredTools,
                    'discovered_prompts' => $this->discoveredPrompts,
                ]),
            ]);

            Log::info('MCP server integration updated', [
                'integration_id' => $this->integration->id,
                'token_id' => $token->id,
                'user_id' => Auth::id(),
                'tool_count' => count($this->discoveredTools),
                'prompt_count' => count($this->discoveredPrompts),
                'enabled_tool_count' => count($capabilities),
            ]);

            session()->flash('success', "MCP server '{$this->integrationName}' updated successfully!");

            return redirect()->route('integrations.index');

        } catch (\Exception $e) {
            Log::error('Failed to update MCP server integration', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'integration_id' => $this->integration->id,
            ]);

            session()->flash('error', 'Failed to update integration: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.mcp-server-edit');
    }
}
