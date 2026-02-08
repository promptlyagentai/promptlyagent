<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesMcpServerForm;
use App\Models\Integration;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class McpServerSetup extends Component
{
    use ManagesMcpServerForm;

    // Default template for relay config
    public string $defaultTemplate = <<<'JSON'
{
  "command": ["npx", "@modelcontextprotocol/server-example"],
  "transport": "Stdio",
  "timeout": 60,
  "env": {
    "API_KEY": "${API_KEY}",
    "BASE_URL": "${BASE_URL}"
  }
}
JSON;

    protected function rules()
    {
        return array_merge($this->getBaseRules(), [
            'serverName' => 'required|string|max:255|regex:/^[a-z0-9\-_]+$/|unique:integration_tokens,config->server_name',
            'credentials.*.value' => 'required|string|max:512',
        ]);
    }

    protected function messages()
    {
        return array_merge($this->getBaseMessages(), [
            'serverName.regex' => 'Server name must be lowercase letters, numbers, hyphens, and underscores only',
            'serverName.unique' => 'This server name is already in use',
            'credentials.*.value.required' => 'Credential value is required',
        ]);
    }

    public function mount()
    {
        // Initialize with default template
        $this->relayConfigJson = $this->defaultTemplate;

        // Parse placeholders from template
        $this->extractPlaceholders();
    }

    public function save()
    {
        // Validate
        $this->validate();

        // Ensure connection test passed
        if (! $this->ensureConnectionTestPassed()) {
            return;
        }

        try {
            // Get provider
            $provider = app(ProviderRegistry::class)->get('mcp_server');

            // Parse relay config
            $relayConfig = json_decode($this->relayConfigJson, true);

            // Prepare credentials map
            $credentialsMap = $this->buildCredentialsMap();

            // Add server_name to relay config
            $relayConfig['server_name'] = $this->serverName;

            // Create token using provider method
            $token = $provider->createToken(
                Auth::user(),
                $this->serverName,
                $credentialsMap,
                $relayConfig
            );

            // Build capability list from enabled tools
            $capabilities = collect($this->discoveredTools)
                ->filter(fn ($tool) => $tool['enabled'] ?? true)
                ->map(fn ($tool) => 'Tools:'.$tool['name'])
                ->values()
                ->toArray();

            // Create integration
            $integration = Integration::create([
                'user_id' => Auth::id(),
                'integration_token_id' => $token->id,
                'name' => $this->integrationName,
                'description' => $this->integrationDescription,
                'visibility' => $this->visibility,
                'config' => [
                    'enabled_capabilities' => $capabilities,
                    'server_instance_name' => $this->visibility === 'shared'
                        ? null // Will be set dynamically by ToolRegistry using integration ID hash
                        : 'user_'.Auth::id().'_'.$this->serverName,
                    'discovered_tools' => $this->discoveredTools,
                    'discovered_prompts' => $this->discoveredPrompts,
                ],
                'status' => 'active',
            ]);

            Log::info('MCP server integration created', [
                'integration_id' => $integration->id,
                'token_id' => $token->id,
                'user_id' => Auth::id(),
                'server_name' => $this->serverName,
                'tool_count' => count($this->discoveredTools),
                'prompt_count' => count($this->discoveredPrompts),
                'enabled_tool_count' => count($capabilities),
            ]);

            // Redirect to integrations index with success message
            $enabledCount = count($capabilities);
            $totalCount = count($this->discoveredTools);

            return redirect()->route('integrations.index')
                ->with('success', "MCP server '{$this->integrationName}' added successfully with {$enabledCount}/{$totalCount} tools enabled!");

        } catch (\Exception $e) {
            Log::error('Failed to create MCP server integration', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            session()->flash('error', 'Failed to create integration: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.mcp-server-setup');
    }
}
