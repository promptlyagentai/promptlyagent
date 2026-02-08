<?php

namespace App\Services\Integrations;

use App\Models\Agent;
use App\Models\Integration;
use App\Models\User;
use App\Services\Agents\AgentService;
use App\Services\Agents\ToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing agents created from integration tokens
 *
 * This service orchestrates the creation, deletion, and synchronization of AI agents
 * that are automatically generated from user integration tokens. It handles the complex
 * mapping between integration capabilities and agent tool configurations.
 *
 * Key Responsibilities:
 * - Agent lifecycle management (create, delete, sync tools)
 * - MCP server tool discovery and registration
 * - Capability-to-tool mapping for different provider types
 * - Dynamic tool configuration generation
 *
 * Special Handling:
 * - MCP servers: Dynamically discovers tools via Relay protocol, injects credentials
 * - Standard providers: Maps static capabilities to predefined tool sets
 * - All agents: Automatically includes common tools (research, artifacts)
 */
class IntegrationAgentService
{
    public function __construct(
        protected AgentService $agentService,
        protected ProviderRegistry $providerRegistry,
        protected ToolRegistry $toolRegistry
    ) {}

    /**
     * Create an agent for an integration
     */
    public function createAgent(Integration $integration, User $user): Agent
    {
        $token = $integration->integrationToken;

        // Get provider instance
        $provider = $this->providerRegistry->get($token->provider_id);

        // For MCP server integrations, ensure user tools are loaded in ToolRegistry
        // This is necessary so that validateToolName() works correctly when creating tool relationships
        if ($token->provider_id === 'mcp_server') {
            $this->toolRegistry->loadMcpTools($user->id);
        }

        // Get enabled capabilities
        $enabledCapabilities = $integration->getEnabledCapabilities();

        // Build tool configs from enabled capabilities
        $integrationToolConfigs = $this->buildToolConfigsFromCapabilities($provider, $enabledCapabilities, $integration);

        if (empty($integrationToolConfigs)) {
            throw new \Exception('Cannot create agent without tools. Enable at least one tool capability first.');
        }

        // Add common tools (research_sources, artifacts, etc.) to integration-specific tools
        $toolConfigs = $this->agentService->addCommonTools($integrationToolConfigs);

        // Get system prompt and description from provider
        $systemPrompt = $provider->getAgentSystemPrompt($integration);
        $description = $provider->getAgentDescription($integration);

        // Prepare agent data
        $agentData = [
            'name' => $integration->name,
            'agent_type' => 'individual',
            'description' => $description,
            'system_prompt' => $systemPrompt,
            'status' => 'active',
            'is_public' => false,
            'show_in_chat' => true,
            'available_for_research' => true,
            'streaming_enabled' => true,
            'thinking_enabled' => false,
            'integration_id' => $integration->id,
        ];

        // Create agent using AgentService with complete tool configs (integration + common tools)
        $agent = $this->agentService->createAgent($agentData, $toolConfigs, $user);

        Log::info('Created integration agent', [
            'agent_id' => $agent->id,
            'integration_id' => $integration->id,
            'token_id' => $token->id,
            'provider' => $token->provider_id,
            'tools_count' => count($toolConfigs),
        ]);

        return $agent;
    }

    /**
     * Delete an agent associated with an integration
     */
    public function deleteAgent(Integration $integration): bool
    {
        $agent = $integration->agent;

        if (! $agent) {
            return false;
        }

        Log::info('Deleting integration agent', [
            'agent_id' => $agent->id,
            'integration_id' => $integration->id,
            'token_id' => $integration->integration_token_id,
            'provider' => $integration->integrationToken->provider_id,
        ]);

        return $agent->delete();
    }

    /**
     * Synchronize agent tools when capabilities change
     */
    public function syncAgentTools(Integration $integration): void
    {
        $agent = $integration->agent;

        if (! $agent) {
            return;
        }

        $token = $integration->integrationToken;

        // Get provider instance
        $provider = $this->providerRegistry->get($token->provider_id);

        // For MCP server integrations, ensure user tools are loaded
        if ($token->provider_id === 'mcp_server') {
            $this->toolRegistry->loadMcpTools($integration->user_id);
        }

        // Get current enabled capabilities
        $enabledCapabilities = $integration->getEnabledCapabilities();

        // Build new tool configs from integration capabilities
        $integrationToolConfigs = $this->buildToolConfigsFromCapabilities($provider, $enabledCapabilities, $integration);

        // If no integration tools are enabled, delete the agent
        if (empty($integrationToolConfigs)) {
            Log::info('No tools enabled, deleting agent', [
                'agent_id' => $agent->id,
                'integration_id' => $integration->id,
            ]);

            $agent->delete();

            return;
        }

        // Add common tools to integration-specific tools
        $toolConfigs = $this->agentService->addCommonTools($integrationToolConfigs);

        // Update system prompt in case it references available tools
        $systemPrompt = $provider->getAgentSystemPrompt($integration);

        // Update agent with new tool configs (integration + common tools) and system prompt
        $this->agentService->updateAgent($agent, [
            'system_prompt' => $systemPrompt,
        ], $toolConfigs);

        Log::info('Synchronized integration agent tools', [
            'agent_id' => $agent->id,
            'integration_id' => $integration->id,
            'tools_count' => count($toolConfigs),
        ]);
    }

    /**
     * Build tool configs from enabled capabilities using provider mappings
     *
     * Maps integration capabilities to agent tool configurations. Handles two distinct patterns:
     * - MCP servers: Dynamic tool discovery from integration config's discovered_tools
     * - Standard providers: Static mapping via provider's getAgentToolMappings()
     *
     * @param  IntegrationProvider  $provider  The integration provider instance
     * @param  array<string>  $enabledCapabilities  Capability strings (e.g., 'Tools:search')
     * @param  Integration  $integration  The integration instance with config data
     * @return array<string, array{enabled: bool, execution_order: int, priority_level: string, execution_strategy: string, min_results_threshold: int, max_execution_time: int, config: array}>
     */
    protected function buildToolConfigsFromCapabilities($provider, array $enabledCapabilities, Integration $integration): array
    {
        // Special handling for MCP server integrations
        if ($provider->getProviderId() === 'mcp_server') {
            return $this->buildMcpServerToolConfigs($integration, $enabledCapabilities);
        }

        // Standard tool mapping for other providers
        $toolMappings = $provider->getAgentToolMappings();
        $toolConfigs = [];
        $executionOrder = 1;

        foreach ($enabledCapabilities as $capability) {
            // Only map Tool capabilities (not Knowledge capabilities)
            if (! str_starts_with($capability, 'Tools:')) {
                continue;
            }

            if (isset($toolMappings[$capability])) {
                foreach ($toolMappings[$capability] as $toolName) {
                    // Build tool config with standard settings
                    $toolConfigs[$toolName] = [
                        'enabled' => true,
                        'execution_order' => $executionOrder++,
                        'priority_level' => 'standard',
                        'execution_strategy' => 'always',
                        'min_results_threshold' => 1,
                        'max_execution_time' => 30000,
                        'config' => [],
                    ];
                }
            }
        }

        return $toolConfigs;
    }

    /**
     * Build MCP server tool configs dynamically from integration config
     *
     * Extracts discovered tools from integration config and creates tool configurations
     * for enabled capabilities. Tool names use Relay format: relay__{server}__{tool}
     *
     * @param  Integration  $integration  Integration with config['discovered_tools']
     * @param  array<string>  $enabledCapabilities  Capability strings like 'Tools:relay__server__tool'
     * @return array<string, array{enabled: bool, execution_order: int, priority_level: string, execution_strategy: string, min_results_threshold: int, max_execution_time: int, config: array}>
     */
    protected function buildMcpServerToolConfigs(Integration $integration, array $enabledCapabilities): array
    {
        $toolConfigs = [];
        $executionOrder = 1;

        // Get discovered tools from integration config
        $discoveredTools = $integration->config['discovered_tools'] ?? [];

        // Build tool configs for enabled MCP tools
        foreach ($discoveredTools as $tool) {
            $toolName = $tool['name'] ?? null;
            if (! $toolName) {
                continue;
            }

            // Check if this tool is enabled via capability
            // The capability format is 'Tools:{full_tool_name_with_relay_prefix}'
            $toolCapability = 'Tools:'.$toolName;

            if (! in_array($toolCapability, $enabledCapabilities)) {
                continue;
            }

            // Use the tool name directly - it already has the Relay format (relay__{server}__{tool})
            $toolKey = $toolName;

            // Build tool config with standard settings
            $toolConfigs[$toolKey] = [
                'enabled' => true,
                'execution_order' => $executionOrder++,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 30000,
                'config' => [],
            ];
        }

        return $toolConfigs;
    }

    /**
     * Check if an integration can create an agent
     */
    public function canCreateAgent(Integration $integration): bool
    {
        // Already has an agent
        if ($integration->agent()->exists()) {
            return false;
        }

        // Check if at least one tool capability is enabled
        $enabledCapabilities = $integration->getEnabledCapabilities();

        foreach ($enabledCapabilities as $capability) {
            if (str_starts_with($capability, 'Tools:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if agent needs to be deleted due to capability changes
     */
    public function shouldDeleteAgent(Integration $integration): bool
    {
        if (! $integration->agent()->exists()) {
            return false;
        }

        // Get enabled capabilities
        $enabledCapabilities = $integration->getEnabledCapabilities();

        // Check if any tool capabilities are still enabled
        foreach ($enabledCapabilities as $capability) {
            if (str_starts_with($capability, 'Tools:')) {
                return false;
            }
        }

        // No tool capabilities enabled, should delete agent
        return true;
    }
}
