<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Log;

/**
 * Tool Override Service - Dynamic Tool Loading with Override Support.
 *
 * Provides centralized tool loading logic with override support for InputTrigger
 * scenarios. Enables external triggers (API, webhooks) to specify exactly which
 * tools an agent should use, overriding the agent's default configuration.
 *
 * Override Precedence:
 * 1. Tool overrides (if override_enabled = true)
 * 2. Agent's default enabled tools (fallback)
 *
 * Security Model:
 * - Tool access controlled by agent_tool relationships
 * - MCP servers must be in user's available servers
 * - Invalid tools logged as warnings but don't fail execution
 * - Returns both successful and failed tool names for transparency
 *
 * Return Structure:
 * - tools: Array of loaded Tool instances
 * - available_names: Successfully loaded tool names
 * - failed_names: Tools that couldn't be loaded
 * - requested_names: All tools requested via overrides
 *
 * @see \App\Services\InputTrigger\ToolOverrideValidator
 * @see \App\Services\Agents\ToolRegistry
 */
class ToolOverrideService
{
    protected ToolRegistry $toolRegistry;

    public function __construct(ToolRegistry $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
    }

    /**
     * Load tools with override support
     *
     * @param  array|null  $toolOverrides  Override configuration with enabled_tools and enabled_servers
     * @param  mixed  $agentTools  Agent's default enabled tools (for fallback)
     * @param  string  $context  Context identifier for logging (e.g., 'execution_123', 'interaction_456')
     * @return array Array with keys: tools, available_names, failed_names, requested_names
     */
    public function loadToolsWithOverrides(?array $toolOverrides, $agentTools, string $context = 'unknown'): array
    {
        // Check if overrides are enabled and valid
        if ($toolOverrides && ($toolOverrides['override_enabled'] ?? false)) {
            Log::info('ToolOverrideService: Using enabled tool overrides', [
                'context' => $context,
                'enabled_tools' => $toolOverrides['enabled_tools'] ?? [],
                'enabled_servers' => $toolOverrides['enabled_servers'] ?? [],
            ]);

            return $this->loadToolsFromOverrides($toolOverrides, $context);
        }

        // Fall back to agent's default enabled tools
        $requestedToolNames = is_object($agentTools) && method_exists($agentTools, 'pluck')
            ? $agentTools->pluck('tool_name')->toArray()
            : [];
        $tools = $this->toolRegistry->getToolsForAgent($agentTools, false);

        $availableToolNames = array_map(function ($tool) {
            return method_exists($tool, 'name') ? $tool->name() : get_class($tool);
        }, $tools);

        $failedToolNames = array_diff($requestedToolNames, $availableToolNames);

        Log::info('ToolOverrideService: Loaded tools from agent defaults', [
            'context' => $context,
            'requested_tools' => $requestedToolNames,
            'available_tools' => $availableToolNames,
            'failed_tools' => $failedToolNames,
            'tools_count' => count($tools),
        ]);

        return [
            'tools' => $tools,
            'available_names' => $availableToolNames,
            'failed_names' => $failedToolNames,
            'requested_names' => $requestedToolNames,
        ];
    }

    /**
     * Load tools from override configuration
     *
     * @param  array  $toolOverrides  Override configuration
     * @param  string  $context  Context identifier for logging
     * @return array Array with keys: tools, available_names, failed_names, requested_names
     */
    protected function loadToolsFromOverrides(array $toolOverrides, string $context): array
    {
        $tools = [];
        $availableToolNames = [];
        $failedToolNames = [];
        $requestedToolNames = [];

        // Get enabled tools from override
        $enabledTools = $toolOverrides['enabled_tools'] ?? [];
        $enabledServers = $toolOverrides['enabled_servers'] ?? [];

        // Load individual tools
        foreach ($enabledTools as $toolName) {
            $requestedToolNames[] = $toolName;

            try {
                $tool = $this->toolRegistry->getToolInstance($toolName);
                $tools[] = $tool;

                $displayName = method_exists($tool, 'name') ? $tool->name() : get_class($tool);
                $availableToolNames[] = $displayName;

                Log::debug('ToolOverrideService: Loaded override tool', [
                    'context' => $context,
                    'tool_name' => $toolName,
                    'display_name' => $displayName,
                ]);

            } catch (\Exception $e) {
                $failedToolNames[] = $toolName;
                Log::warning('ToolOverrideService: Failed to load override tool', [
                    'context' => $context,
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Load MCP server tools
        foreach ($enabledServers as $serverName) {
            try {
                $serverTools = $this->loadMcpServerTools($serverName, $context);
                foreach ($serverTools as $serverTool) {
                    $tools[] = $serverTool['tool'];
                    $availableToolNames[] = $serverTool['name'];
                    $requestedToolNames[] = $serverTool['key'];
                }

                Log::info('ToolOverrideService: Loaded MCP server tools from override', [
                    'context' => $context,
                    'server_name' => $serverName,
                    'tools_count' => count($serverTools),
                ]);

            } catch (\Exception $e) {
                $failedToolNames[] = "mcp_server_{$serverName}";
                Log::warning('ToolOverrideService: Failed to load MCP server from override', [
                    'context' => $context,
                    'server_name' => $serverName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ToolOverrideService: Loaded tools from overrides', [
            'context' => $context,
            'requested_tools' => $requestedToolNames,
            'available_tools' => $availableToolNames,
            'failed_tools' => $failedToolNames,
            'tools_count' => count($tools),
        ]);

        return [
            'tools' => $tools,
            'available_names' => $availableToolNames,
            'failed_names' => $failedToolNames,
            'requested_names' => $requestedToolNames,
        ];
    }

    /**
     * Load all tools from a specific MCP server
     *
     * @param  string  $serverName  MCP server name
     * @param  string  $context  Context identifier for logging
     * @return array Array of tool arrays with keys: key, name, tool
     */
    protected function loadMcpServerTools(string $serverName, string $context): array
    {
        $serverTools = [];
        $availableTools = $this->toolRegistry->getAvailableTools();

        // Find all tools that belong to this MCP server
        foreach ($availableTools as $toolKey => $toolConfig) {
            if ($toolConfig['category'] === 'mcp' && ($toolConfig['server'] ?? '') === $serverName) {
                try {
                    $tool = $this->toolRegistry->getToolInstance($toolKey);
                    $serverTools[] = [
                        'key' => $toolKey,
                        'name' => $toolConfig['name'],
                        'tool' => $tool,
                    ];
                } catch (\Exception $e) {
                    Log::warning('ToolOverrideService: Failed to load MCP tool from server', [
                        'context' => $context,
                        'server_name' => $serverName,
                        'tool_key' => $toolKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $serverTools;
    }
}
