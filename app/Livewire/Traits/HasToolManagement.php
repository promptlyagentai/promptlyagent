<?php

namespace App\Livewire\Traits;

use Illuminate\Support\Facades\Log;
use Prism\Relay\Facades\Relay;

/**
 * Provides tool management functionality for chat components.
 *
 * Features:
 * - Load Prism tools and MCP servers
 * - Tool enable/disable toggles
 * - User preference persistence (session-based)
 * - Tool override mode for runtime customization
 * - Server-based tool grouping
 *
 * Properties added to using class:
 *
 * @property array<int, array{name: string, description: string, source: string, class?: string}> $availableTools
 * @property array<string> $enabledTools Local tool names that are enabled
 * @property array<int, array{name: string, tools: array<string>, toolCount: int}> $availableServers
 * @property array<string> $enabledServers MCP server names that are enabled
 * @property bool $toolOverrideEnabled Whether user can override tool selection
 */
trait HasToolManagement
{
    public $availableTools = [];

    public $enabledTools = ['hello-world', 'searxng_search', 'link_validator', 'bulk_link_validator', 'markitdown', 'knowledge_search', 'research_sources', 'source_content'];

    public $availableServers = [];

    public $enabledServers = [];

    public $showToolsPanel = false;

    // Tool override properties
    public $toolOverrideEnabled = false;

    public $toolOverrides = [];

    public $serverOverrides = [];

    /**
     * Load user tool preferences from session
     */
    protected function loadUserToolPreferences(): void
    {
        if (! auth()->check()) {
            return;
        }

        $userId = auth()->id();
        $sessionKey = "user_tool_preferences.{$userId}";

        $preferences = session($sessionKey, [
            'enabledTools' => $this->enabledTools,
            'enabledServers' => $this->enabledServers,
        ]);

        $this->enabledTools = $preferences['enabledTools'] ?? $this->enabledTools;
        $this->enabledServers = $preferences['enabledServers'] ?? $this->enabledServers;
    }

    /**
     * Save user tool preferences to session
     */
    protected function saveUserToolPreferences(): void
    {
        if (! auth()->check()) {
            return;
        }

        $userId = auth()->id();
        $sessionKey = "user_tool_preferences.{$userId}";

        $preferences = [
            'enabledTools' => $this->enabledTools,
            'enabledServers' => $this->enabledServers,
        ];

        session([$sessionKey => $preferences]);
    }

    public function loadAvailableTools()
    {
        $this->availableTools = [];
        $this->availableServers = [];

        // Load Prism tools from local config
        $prismTools = [
            \App\Tools\PromptlyAgentPrismTool::class,
            \App\Tools\SearXNGTool::class,
            \App\Tools\MarkItDownTool::class,
            \App\Tools\LinkValidatorTool::class,
            \App\Tools\BulkLinkValidatorTool::class,
            \App\Tools\ResearchSourcesTool::class,
            \App\Tools\SourceContentTool::class,
            \App\Tools\KnowledgeRAGTool::class,
            \App\Tools\CreateDocumentTool::class,
            \App\Tools\ReadDocumentTool::class,
            \App\Tools\UpdateDocumentTool::class,
            \App\Tools\DeleteDocumentTool::class,
            \App\Tools\ListDocumentsTool::class,
        ];

        foreach ($prismTools as $toolClass) {
            try {
                if (class_exists($toolClass) && method_exists($toolClass, 'create')) {
                    $tool = $toolClass::create();
                    $this->availableTools[] = [
                        'class' => $toolClass,
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                        'source' => 'local',
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("Failed to load Prism tool: {$toolClass}", ['error' => $e->getMessage()]);
            }
        }

        // Load MCP servers using Relay facade
        try {
            $relayServers = config('relay.servers', []);
            Log::info('Loading MCP servers', ['servers' => array_keys($relayServers)]);

            foreach ($relayServers as $serverName => $serverConfig) {
                if ($serverName !== 'local') {
                    Log::info("Attempting to load tools from MCP server: {$serverName}", [
                        'config' => array_merge($serverConfig, ['env' => '[REDACTED]']), // Hide sensitive env vars in logs
                    ]);

                    try {
                        // Add more detailed error checking
                        if (! isset($serverConfig['transport'])) {
                            throw new \Exception("No transport specified for server {$serverName}");
                        }

                        // Check for appropriate connection field based on transport type
                        if ($serverConfig['transport'] === \Prism\Relay\Enums\Transport::Http) {
                            if (! isset($serverConfig['url'])) {
                                throw new \Exception("No URL specified for HTTP transport server {$serverName}");
                            }
                        } elseif ($serverConfig['transport'] === \Prism\Relay\Enums\Transport::Stdio) {
                            if (! isset($serverConfig['command'])) {
                                throw new \Exception("No command specified for STDIO transport server {$serverName}");
                            }
                        }

                        $serverTools = Relay::tools($serverName);

                        if (! is_array($serverTools)) {
                            Log::warning("Server {$serverName} returned non-array tools response", [
                                'type' => gettype($serverTools),
                                'response' => $serverTools,
                            ]);

                            continue;
                        }

                        $toolNames = [];
                        foreach ($serverTools as $tool) {
                            if (! is_object($tool) || ! method_exists($tool, 'name')) {
                                Log::warning("Invalid tool object from server {$serverName}", [
                                    'tool_type' => gettype($tool),
                                    'tool_data' => $tool,
                                ]);

                                continue;
                            }

                            $toolName = $tool->name();
                            $toolDescription = method_exists($tool, 'description') ? $tool->description() : 'No description available';

                            $this->availableTools[] = [
                                'name' => $toolName,
                                'description' => $toolDescription,
                                'source' => $serverName,
                            ];
                            $toolNames[] = $toolName;
                        }

                        // Group tools by server
                        if (! empty($toolNames)) {
                            $this->availableServers[] = [
                                'name' => $serverName,
                                'tools' => $toolNames,
                                'toolCount' => count($toolNames),
                            ];
                            Log::info("Successfully loaded tools from MCP server: {$serverName}", [
                                'tools' => $toolNames,
                                'tool_count' => count($toolNames),
                            ]);
                        } else {
                            Log::warning("No valid tools found from MCP server: {$serverName}", [
                                'raw_tools_count' => count($serverTools),
                                'raw_tools' => $serverTools,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error("Failed to load tools from MCP server: {$serverName}", [
                            'error' => $e->getMessage(),
                            'error_code' => $e->getCode(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                            'config' => array_merge($serverConfig, ['env' => '[REDACTED]']),
                        ]);

                        // For debugging, add a visual indicator in the UI when servers fail
                        $this->availableServers[] = [
                            'name' => $serverName.' (ERROR)',
                            'tools' => [],
                            'toolCount' => 0,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load MCP servers', ['error' => $e->getMessage()]);
        }
    }

    public function toggleTool($toolName)
    {
        if ($this->toolOverrideEnabled) {
            // Allow toggling in override mode (user preferences)
            if (in_array($toolName, $this->toolOverrides)) {
                $this->toolOverrides = array_diff($this->toolOverrides, [$toolName]);
            } else {
                $this->toolOverrides[] = $toolName;
            }
        } else {
            // When override is disabled, tools are readonly (showing agent configuration)
            // No action taken - tools should be read-only
            return;
        }
    }

    public function toggleServer($serverName)
    {
        if ($this->toolOverrideEnabled) {
            // Allow toggling in override mode (user preferences)
            if (in_array($serverName, $this->serverOverrides)) {
                $this->serverOverrides = array_diff($this->serverOverrides, [$serverName]);
            } else {
                $this->serverOverrides[] = $serverName;
            }
        } else {
            // When override is disabled, servers are readonly (showing agent configuration)
            // No action taken - servers should be read-only
            return;
        }
    }

    public function toggleToolsPanel()
    {
        $this->showToolsPanel = ! $this->showToolsPanel;
    }

    /**
     * Enable tool override mode - load user preferences for editing
     */
    public function enableToolOverride()
    {
        $this->toolOverrideEnabled = true;

        // Load user preferences (not agent configuration)
        $this->loadUserToolPreferences();

        // Initialize overrides with user preferences
        $this->toolOverrides = $this->enabledTools;
        $this->serverOverrides = $this->enabledServers;
    }

    /**
     * Disable tool override mode - load agent configuration (readonly)
     */
    public function disableToolOverride()
    {
        $this->toolOverrideEnabled = false;
        $this->toolOverrides = [];
        $this->serverOverrides = [];

        // Load agent's configured tools for display (readonly)
        // This will be handled by the component that implements this trait
        $this->loadCurrentAgentToolConfiguration();
    }

    /**
     * Toggle tool override mode
     */
    public function toggleToolOverride()
    {
        if ($this->toolOverrideEnabled) {
            $this->disableToolOverride();
        } else {
            $this->enableToolOverride();
        }
    }

    /**
     * Get effective tool configuration (either overrides or defaults)
     */
    public function getEffectiveToolConfiguration(): array
    {
        if ($this->toolOverrideEnabled) {
            return [
                'enabled_tools' => $this->toolOverrides,
                'enabled_servers' => $this->serverOverrides,
                'is_override' => true,
            ];
        }

        return [
            'enabled_tools' => $this->enabledTools,
            'enabled_servers' => $this->enabledServers,
            'is_override' => false,
        ];
    }

    protected function getEnabledToolNames(): array
    {
        $enabledToolNames = [];

        foreach ($this->availableTools as $tool) {
            if ($tool['source'] === 'local' && in_array($tool['name'], $this->enabledTools)) {
                $enabledToolNames[] = $tool['name'];
            } elseif ($tool['source'] !== 'local' && in_array($tool['source'], $this->enabledServers)) {
                $enabledToolNames[] = $tool['name'];
            }
        }

        return $enabledToolNames;
    }

    protected function getAvailableToolClasses(): array
    {
        $toolClasses = [];

        foreach ($this->availableTools as $tool) {
            if (in_array($tool['name'], $this->enabledTools)) {
                // Only local tools have a class property
                if (isset($tool['class'])) {
                    $toolClasses[] = $tool['class'];
                }
            }
        }

        return $toolClasses;
    }

    protected function getAvailableTools(): array
    {
        $tools = [];

        // Get local tool instances
        foreach ($this->availableTools as $tool) {
            if (in_array($tool['name'], $this->enabledTools)) {
                if (isset($tool['class'])) {
                    // Local tool - create instance
                    try {
                        $toolInstance = $tool['class']::create();
                        $tools[] = $toolInstance;
                    } catch (\Exception $e) {
                        Log::warning("Failed to create tool instance: {$tool['name']}", ['error' => $e->getMessage()]);
                    }
                } else {
                    // MCP server tool - we'll need to get it from the server
                    // This will be handled separately when loading from servers
                }
            }
        }

        // Get MCP server tools
        if (! empty($this->enabledServers)) {
            $relayServers = config('relay.servers', []);
            foreach ($relayServers as $serverName => $serverConfig) {
                if ($serverName !== 'local' && in_array($serverName, $this->enabledServers)) {
                    try {
                        $serverTools = Relay::tools($serverName);
                        Log::info("Loading tools from enabled MCP server: {$serverName}", [
                            'server_tools_count' => count($serverTools),
                        ]);

                        foreach ($serverTools as $serverTool) {
                            if (is_object($serverTool) && method_exists($serverTool, 'name')) {
                                // If server is enabled, include all its tools
                                $tools[] = $serverTool;
                                Log::debug("Added MCP tool from {$serverName}: {$serverTool->name()}");
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to load tools from server: {$serverName}", ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        Log::info('getAvailableTools() completed', [
            'total_tools_count' => count($tools),
            'tool_names' => array_map(function ($tool) {
                return is_object($tool) && method_exists($tool, 'name') ? $tool->name() : 'unknown';
            }, $tools),
        ]);

        return $tools;
    }
}
