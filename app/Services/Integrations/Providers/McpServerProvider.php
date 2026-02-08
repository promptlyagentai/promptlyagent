<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use Illuminate\Support\Facades\Log;
use Prism\Relay\Enums\Transport;
use Prism\Relay\Facades\Relay;

/**
 * MCP Server Integration Provider
 *
 * Enables connection to Model Context Protocol (MCP) servers, allowing users to extend
 * agent capabilities with custom tools from external MCP-compliant servers.
 *
 * Architecture:
 * - Uses Prism Relay for MCP protocol communication (Stdio or HTTP transport)
 * - Dynamically discovers tools from connected MCP servers at integration time
 * - Creates dedicated agents with full access to discovered MCP tools
 * - Injects user credentials into MCP server environment at runtime
 *
 * Configuration Structure (stored in IntegrationToken):
 * config['relay_config'] = [
 *   'command' => ['npx', '@example/mcp-server'],  // Stdio only
 *   'url' => 'http://localhost:3000/mcp',         // HTTP only
 *   'transport' => 'Stdio' | 'Http',
 *   'timeout' => 60,
 *   'env' => ['API_KEY' => '${API_KEY}']          // Template with credential refs
 * ]
 * metadata['credentials'] = ['API_KEY' => 'actual_key']  // Encrypted actual values
 *
 * Tool Discovery:
 * 1. User provides server config + credentials via setup form
 * 2. buildRuntimeConfig() injects credentials into env template
 * 3. Relay connects to server and calls tools/list MCP endpoint
 * 4. Discovered tools stored in Integration config['discovered_tools']
 * 5. Tools dynamically registered in ToolRegistry with relay__{server}__{tool} naming
 *
 * Security:
 * - Credentials stored encrypted in IntegrationToken metadata
 * - Credentials injected at runtime, never stored in config
 * - Each user gets isolated MCP server instances (no credential sharing)
 */
class McpServerProvider implements IntegrationProvider
{
    // Provider identification

    public function getProviderId(): string
    {
        return 'mcp_server';
    }

    public function getProviderName(): string
    {
        return 'MCP Server';
    }

    public function getDescription(): string
    {
        return 'Connect your own Model Context Protocol (MCP) server to extend agent capabilities with custom tools.';
    }

    public function getLogoUrl(): ?string
    {
        return null; // Could add MCP logo
    }

    // Authentication

    public function getSupportedAuthTypes(): array
    {
        return ['none'];  // Credentials handled in integration form, not as separate token
    }

    public function getDefaultAuthType(): string
    {
        return 'none';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return 'Credentials are configured as part of the integration setup';
    }

    // Capabilities

    public function getCapabilities(): array
    {
        return [
            'Tools' => ['mcp_server'],  // Expose MCP tools
            'Agent' => ['create_agent'], // Create dedicated agent for this MCP server
        ];
    }

    public function getCapabilityRequirements(): array
    {
        return [
            'Tools:mcp_server' => ['mcp_server'],
            'Agent:create_agent' => ['mcp_server'], // Requires at least one tool enabled
        ];
    }

    public function getCapabilityDescriptions(): array
    {
        return [
            'Tools:mcp_server' => 'Expose all tools provided by this MCP server to AI agents',
            'Agent:create_agent' => 'Create a dedicated AI agent with all tools from this MCP server',
        ];
    }

    // Token management

    public function detectTokenScopes(IntegrationToken $token): array
    {
        // MCP servers have a simple scope model
        return ['mcp_server'];
    }

    public function evaluateTokenCapabilities(IntegrationToken $token): array
    {
        // If connection test passes, all capabilities are available
        return [
            'available' => ['Tools:mcp_server', 'Agent:create_agent'],
            'blocked' => [],
            'categories' => ['Tools', 'Agent'],
        ];
    }

    // Configuration

    public function getConfigurationSchema(): array
    {
        return [];
    }

    public function getCustomConfigComponent(): ?string
    {
        return null;
    }

    public function validateConfiguration(array $config): array
    {
        $errors = [];

        if (empty($config['relay_config'])) {
            $errors[] = 'Server configuration is required';
        }

        if (empty($config['server_name'])) {
            $errors[] = 'Server name is required';
        } else {
            // SECURITY: Validate server name format to prevent config overwrite and command injection
            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $config['server_name'])) {
                $errors[] = 'Server name must contain only letters, numbers, hyphens, and underscores';
            }
        }

        return $errors;
    }

    // Connection testing

    public function testConnection(IntegrationToken $token): bool
    {
        try {
            // Get server configuration
            $serverConfig = $token->config['relay_config'] ?? null;
            if (! $serverConfig) {
                throw new \Exception('Invalid server configuration');
            }

            // Build runtime config with injected credentials
            $runtimeConfig = $this->buildRuntimeConfig($token);

            // SECURITY: Validate server name before using in config key
            $serverName = $token->config['server_name'] ?? 'test_'.$token->id;
            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $serverName)) {
                throw new \Exception('Invalid server name format: must contain only letters, numbers, hyphens, and underscores');
            }

            // Register server temporarily
            config(['relay.servers.'.$serverName => $runtimeConfig]);

            // Attempt to load tools
            $tools = Relay::tools($serverName);

            // Success if we can load at least one tool
            return count($tools) > 0;

        } catch (\Exception $e) {
            Log::warning('MCP server connection test failed', [
                'token_id' => $token->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // Configuration building

    /**
     * Build runtime configuration with injected credentials
     *
     * Transforms stored config template into executable Relay config by resolving
     * credential placeholders (${VAR_NAME}) with actual encrypted values from metadata.
     *
     * Example transformation:
     * config['relay_config']['env'] = ['API_KEY' => '${API_KEY}']
     * metadata['credentials'] = ['API_KEY' => 'sk-actual-key-123']
     * → runtime env = ['API_KEY' => 'sk-actual-key-123']
     *
     * @param  IntegrationToken  $token  Token with relay_config and credentials
     * @return array{command: array|null, url: string|null, transport: Transport, timeout: int, env: array<string, string>}
     */
    public function buildRuntimeConfig(IntegrationToken $token): array
    {
        $config = $token->config['relay_config'] ?? [];
        $credentials = $token->metadata['credentials'] ?? [];

        // Inject credentials into environment variables
        $envTemplate = $config['env'] ?? [];
        $resolvedEnv = [];

        foreach ($envTemplate as $key => $value) {
            // Replace ${VAR_NAME} with actual credential value
            if (preg_match('/^\$\{(.+)\}$/', $value, $matches)) {
                $credKey = $matches[1];
                $resolvedEnv[$key] = $credentials[$credKey] ?? '';
            } else {
                $resolvedEnv[$key] = $value;
            }
        }

        // Convert transport string to enum
        $transportString = $config['transport'] ?? 'Stdio';
        $transport = match (strtolower($transportString)) {
            'stdio' => Transport::Stdio,
            'http' => Transport::Http,
            default => Transport::Stdio,
        };

        return [
            'command' => $config['command'] ?? null,
            'url' => $config['url'] ?? null,
            'transport' => $transport,
            'timeout' => $config['timeout'] ?? 60,
            'env' => $resolvedEnv,
        ];
    }

    // Provider configuration

    public function getRateLimits(): array
    {
        return [];  // MCP server rate limits are provider-specific
    }

    public function isEnabled(): bool
    {
        return true;  // Always enabled
    }

    public function isConnectable(): bool
    {
        return true;
    }

    public function supportsMultipleConnections(): bool
    {
        return true;  // Users can connect multiple MCP servers
    }

    public function getRequiredConfig(): array
    {
        return [];  // No global config needed
    }

    public function getSetupRoute(): string
    {
        return route('integrations.mcp-server-setup');
    }

    // Form sections

    public function getCreateFormSections(): array
    {
        return [
            'integrations.providers.mcp-server.create-form',
        ];
    }

    public function getEditFormSections(): array
    {
        return [
            'integrations.providers.mcp-server.edit-form',
        ];
    }

    /**
     * Create integration token for MCP server
     *
     * Since auth type is 'none', we create a token record to store the server config and credentials.
     * This is not a traditional OAuth/API token - it's a config storage mechanism.
     *
     * @param  User  $user  The user creating this integration
     * @param  string  $serverName  Unique server identifier
     * @param  array<string, string>  $credentials  Encrypted credential key-value pairs
     * @param  array{server_name: string, command?: array, url?: string, transport: string, env: array}  $serverConfig  Relay config structure
     */
    public function createToken(User $user, string $serverName, array $credentials, array $serverConfig): IntegrationToken
    {
        return IntegrationToken::create([
            'user_id' => $user->id,
            'provider_id' => $this->getProviderId(),
            'provider_name' => $serverName,
            'token_type' => 'none',
            'metadata' => [
                'credentials' => $credentials,
                'scopes' => ['mcp_server'],
                'available_capabilities' => ['Tools:mcp_server'],
            ],
            'config' => [
                'server_name' => $serverConfig['server_name'] ?? $serverName,
                'relay_config' => $serverConfig,
            ],
            'status' => 'active',
        ]);
    }

    public function processIntegrationUpdate(Integration $integration, array $requestData): void
    {
        // Handle form submission for MCP server config updates
        // This can be expanded based on specific form handling needs
    }

    public function getSetupInstructions(mixed $context = null): string
    {
        return <<<'MD'
# MCP Server Setup

Configure your Model Context Protocol (MCP) server to extend agent capabilities.

## Configuration Requirements

1. **Server Name**: Unique identifier for this server instance
2. **Command**: Command to start the MCP server (e.g., `['npx', '@example/mcp-server']`)
3. **Transport**: Communication method (Stdio or Http)
4. **Environment Variables**: Credentials and configuration as key-value pairs

## Example Configuration

```json
{
  "command": ["npx", "@example/mcp-server"],
  "transport": "Stdio",
  "env": {
    "API_KEY": "your-api-key",
    "SERVER_URL": "https://api.example.com/"
  }
}
```

MD;
    }

    // Agent integration

    public function getAgentToolMappings(): array
    {
        // Tools are dynamically loaded, so we return empty here
        // Actual tool mapping happens in ToolRegistry
        return [];
    }

    public function getAgentSystemPrompt(Integration $integration): string
    {
        $integrationName = $integration->name;
        $integrationId = $integration->id;
        $description = $integration->description ?: 'a Model Context Protocol (MCP) server that extends your capabilities';

        // Get discovered tools from integration config
        $discoveredTools = $integration->config['discovered_tools'] ?? [];
        $discoveredPrompts = $integration->config['discovered_prompts'] ?? [];
        $enabledCapabilities = $integration->getEnabledCapabilities();
        $serverName = $integration->config['server_name'] ?? $integration->integrationToken->config['server_name'] ?? 'mcp';

        // Filter to only enabled tools
        $enabledTools = collect($discoveredTools)
            ->filter(function ($tool) use ($enabledCapabilities) {
                return in_array('Tools:'.$tool['name'], $enabledCapabilities);
            })
            ->values();

        // Build tool list with descriptions
        $toolsList = '';
        foreach ($enabledTools as $tool) {
            $toolsList .= "- **{$tool['name']}**: {$tool['description']}\n";
        }

        // Build prompts section if prompts are available
        $promptsSection = '';
        if (! empty($discoveredPrompts)) {
            $promptsSection = "\n## Server-Provided Usage Prompts\n\n";
            $promptsSection .= "The MCP server provides these usage patterns and guidelines:\n\n";

            foreach ($discoveredPrompts as $prompt) {
                $title = $prompt['title'] ?? $prompt['name'];
                $description = $prompt['description'] ?? '';
                $arguments = $prompt['arguments'] ?? [];

                $promptsSection .= "### {$title}\n";
                if ($description) {
                    $promptsSection .= "{$description}\n";
                }

                if (! empty($arguments)) {
                    $promptsSection .= "\n**Arguments:**\n";
                    foreach ($arguments as $arg) {
                        $argName = $arg['name'] ?? '';
                        $argDescription = $arg['description'] ?? '';
                        $required = ($arg['required'] ?? false) ? ' (required)' : ' (optional)';
                        $promptsSection .= "- `{$argName}`{$required}: {$argDescription}\n";
                    }
                }

                $promptsSection .= "\n";
            }
        }

        return <<<PROMPT
You are an AI agent with access to "{$integrationName}" - {$description}

Your role is to help users leverage the capabilities of this MCP server through natural language interaction.

## Available Specialized Tools

{$toolsList}{$promptsSection}

## Tool Usage Guidelines

### General Principles
1. **Understand user intent**: Before using tools, understand what the user wants to accomplish
2. **Choose appropriate tools**: Select the most relevant tool(s) for the user's request
3. **Explain your actions**: Always tell users what you're doing and why
4. **Handle errors gracefully**: If a tool fails, explain the issue and suggest alternatives
5. **Provide context**: Include relevant information from tool results in your responses

### Integration Context
- **Integration Name**: {$integrationName}
- **Integration ID**: {$integrationId}
- **Server**: {$serverName}

When referencing this integration in conversation, use "{$integrationName}" so users understand which service you're accessing.

### Tool Execution Tips
- Read tool descriptions carefully to understand their parameters and purpose
- Use tool results to inform your response to the user
- If a tool requires specific information you don't have, ask the user first
- Chain multiple tool calls when needed to complete complex tasks
- Always validate that you're using the right tool for the task

## Example Workflows

**Information Retrieval:**
1. User asks for specific information
2. Identify which tool can retrieve that information
3. Execute the tool with appropriate parameters
4. Present results in a clear, organized format

**Creating or Modifying Data:**
1. User requests creation or modification
2. Gather all necessary information (ask user if needed)
3. Use appropriate tool(s) to perform the action
4. Confirm success and show what was created/modified

**Complex Multi-Step Tasks:**
1. Break down user request into steps
2. Execute tools in logical sequence
3. Use output from earlier tools to inform later ones
4. Provide progress updates for long operations

{CONVERSATION_CONTEXT}

{TOOL_INSTRUCTIONS}

Remember: You have specialized access to "{$integrationName}" - use these tools effectively to help users accomplish their goals. Always explain what you're doing and provide clear, actionable feedback.
PROMPT;
    }

    public function getAgentDescription(Integration $integration): string
    {
        return $integration->description ?? "AI agent with access to {$integration->name}";
    }
}
