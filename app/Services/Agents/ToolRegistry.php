<?php

namespace App\Services\Agents;

use App\Tools\AppendArtifactContentTool;
use App\Tools\AssignKnowledgeToAgentTool;
use App\Tools\BulkLinkValidatorTool;
use App\Tools\ChatInteractionLookupTool;
use App\Tools\CodeSearchTool;
use App\Tools\CommentOnGitHubIssueTool;
use App\Tools\CreateAgentTool;
use App\Tools\CreateArtifactTool;
use App\Tools\CreateChatAttachmentTool;
use App\Tools\CreateGitHubIssueTool;
use App\Tools\DatabaseSchemaInspectorTool;
use App\Tools\DeleteArtifactTool;
use App\Tools\DirectoryListingTool;
use App\Tools\GenerateMermaidDiagramTool;
use App\Tools\GetChatInteractionTool;
use App\Tools\HttpRequestTool;
use App\Tools\InsertArtifactContentTool;
use App\Tools\KnowledgeRAGTool;
use App\Tools\LinkValidatorTool;
use App\Tools\ListArtifactsTool;
use App\Tools\ListAvailableToolsTool;
use App\Tools\ListChatAttachmentsTool;
use App\Tools\ListGitHubLabelsTool;
use App\Tools\ListGitHubMilestonesTool;
use App\Tools\ListKnowledgeDocumentsTool;
use App\Tools\MarkItDownTool;
use App\Tools\PatchArtifactContentTool;
use App\Tools\PromptlyAgentPrismTool;
use App\Tools\ReadArtifactTool;
use App\Tools\ResearchSourcesTool;
use App\Tools\RetrieveFullDocumentTool;
use App\Tools\RouteInspectorTool;
use App\Tools\SafeDatabaseQueryTool;
use App\Tools\SearchGitHubIssuesTool;
use App\Tools\SearXNGTool;
use App\Tools\SecureFileReaderTool;
use App\Tools\SourceContentTool;
use App\Tools\UpdateArtifactContentTool;
use App\Tools\UpdateArtifactMetadataTool;
use App\Tools\UpdateGitHubIssueTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Relay\Facades\Relay;

/**
 * Dynamic Tool Registry with Multi-Scope MCP Integration.
 *
 * Manages tool registration and availability for AI agents with support for:
 * - Built-in application tools (artifacts, knowledge, system utilities)
 * - Global MCP servers (configured in relay.php)
 * - Shared MCP servers (user-owned, shared with all users)
 * - User-specific MCP servers (private integrations)
 *
 * Tool Scoping Architecture:
 * - **Global Tools**: Built-in app tools + config-based MCP servers (available to all)
 * - **Shared Tools**: User-owned MCP servers with 'shared' visibility (available to all)
 * - **User Tools**: Private MCP integrations per user (only owner has access)
 *
 * MCP Integration Flow:
 * 1. Global MCP servers loaded from relay.php config during construction
 * 2. Shared MCP servers loaded from Integration model (visibility='shared')
 * 3. User MCP servers loaded on-demand when agent execution requests tools
 * 4. Runtime config injection with credentials from IntegrationToken
 * 5. Relay facade provides tool instances with proper authentication
 *
 * Tool Loading Strategies:
 * - **Eager**: Global and shared tools loaded during registry construction
 * - **Lazy**: User-specific tools loaded when getAvailableTools() called with userId
 * - **Cached**: Tool instances stored in availableTools array to avoid re-creation
 * - **Refresh**: Dynamic reload when integrations change via refresh methods
 *
 * Tool Categories:
 * - **search**: Web search, code search
 * - **knowledge**: RAG, document retrieval, knowledge management
 * - **content**: Content conversion, extraction, validation
 * - **artifacts**: Artifact CRUD operations
 * - **context**: Chat history, interaction lookup
 * - **system**: Database, file system, route inspection
 * - **mcp**: MCP server tools (dynamically loaded)
 * - **github**: GitHub integration tools (conditional)
 *
 * Integration Security:
 * - User tools isolated by user_id (no cross-user access)
 * - Shared tools use owner's credentials (shared functionality)
 * - Active status enforcement (inactive integrations ignored)
 * - Token validation via IntegrationToken model
 *
 * @see \App\Models\Integration
 * @see \App\Models\IntegrationToken
 * @see \App\Services\Integrations\ProviderRegistry
 * @see \Prism\Relay\Facades\Relay
 */
class ToolRegistry
{
    protected array $availableTools = [];

    protected array $loadedUserTools = [];

    public function __construct()
    {
        $this->loadAvailableTools();
    }

    protected function loadAvailableTools(): void
    {
        // Local tools
        $this->availableTools = [
            'searxng_search' => [
                'class' => SearXNGTool::class,
                'name' => 'SearXNG Search',
                'description' => 'Search the web using SearXNG',
                'category' => 'search',
                'instance' => null,
            ],
            'markitdown' => [
                'class' => MarkItDownTool::class,
                'name' => 'MarkItDown',
                'description' => 'Convert web pages to markdown',
                'category' => 'content',
                'instance' => null,
            ],
            'link_validator' => [
                'class' => LinkValidatorTool::class,
                'name' => 'Link Validator',
                'description' => 'Validates URLs and extracts metadata to determine relevance for further processing',
                'category' => 'validation',
                'instance' => null,
            ],
            'bulk_link_validator' => [
                'class' => BulkLinkValidatorTool::class,
                'name' => 'Bulk Link Validator',
                'description' => 'Validates multiple URLs in parallel for improved performance. Perfect for validating search results.',
                'category' => 'validation',
                'instance' => null,
            ],
            'promptlyagent_prism' => [
                'class' => PromptlyAgentPrismTool::class,
                'name' => 'PromptlyAgent Prism',
                'description' => 'PromptlyAgent-specific Prism tool',
                'category' => 'promptlyagent',
                'instance' => null,
            ],
            'knowledge_search' => [
                'class' => KnowledgeRAGTool::class,
                'name' => 'Knowledge Search',
                'description' => 'Search the assigned knowledge base for relevant information using semantic search',
                'category' => 'knowledge',
                'instance' => null,
            ],
            'list_knowledge_documents' => [
                'class' => ListKnowledgeDocumentsTool::class,
                'name' => 'List Knowledge Documents',
                'description' => 'List and browse knowledge documents by filtering on metadata like tags, document type, or status',
                'category' => 'knowledge',
                'instance' => null,
            ],
            'source_content' => [
                'class' => SourceContentTool::class,
                'name' => 'Source Content',
                'description' => 'Retrieve full or summarized content from sources identified during research',
                'category' => 'content',
                'instance' => null,
            ],
            'research_sources' => [
                'class' => ResearchSourcesTool::class,
                'name' => 'Research Sources',
                'description' => 'List all sources discovered during research for the current interaction',
                'category' => 'content',
                'instance' => null,
            ],
            'get_chat_interaction' => [
                'class' => GetChatInteractionTool::class,
                'name' => 'Get Chat Interaction',
                'description' => 'Retrieve previous chat interactions from the current conversation for context. Only returns interactions that belong to the current user for security.',
                'category' => 'context',
                'instance' => null,
            ],
            'chat_interaction_lookup' => [
                'class' => ChatInteractionLookupTool::class,
                'name' => 'Chat Interaction Lookup',
                'description' => 'Search and retrieve previous chat interactions from current session or user history with semantic search',
                'category' => 'context',
                'instance' => null,
            ],
            'retrieve_full_document' => [
                'class' => RetrieveFullDocumentTool::class,
                'name' => 'Retrieve Full Document',
                'description' => 'Retrieve the complete content of a specific knowledge document or chat attachment',
                'category' => 'knowledge',
                'instance' => null,
            ],
            'create_artifact' => [
                'class' => CreateArtifactTool::class,
                'name' => 'Create Artifact',
                'description' => 'Creates new artifacts in the Artifacts system for saving AI-generated content, reports, and analysis',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'read_artifact' => [
                'class' => ReadArtifactTool::class,
                'name' => 'Read Artifact',
                'description' => 'Retrieves a artifact by ID with full content and metadata, including content_hash for concurrency control',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'append_artifact_content' => [
                'class' => AppendArtifactContentTool::class,
                'name' => 'Append Artifact Content',
                'description' => 'Appends content to the end of a artifact with hash-based concurrency control',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'insert_artifact_content' => [
                'class' => InsertArtifactContentTool::class,
                'name' => 'Insert Artifact Content',
                'description' => 'Inserts content at a specific position in a artifact with hash-based concurrency control',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'patch_artifact_content' => [
                'class' => PatchArtifactContentTool::class,
                'name' => 'Patch Artifact Content',
                'description' => 'Applies patches to replace specific sections of artifact content with hash-based validation',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'update_artifact_content' => [
                'class' => UpdateArtifactContentTool::class,
                'name' => 'Update Artifact Content',
                'description' => 'Replaces entire artifact content with new content - simpler than patching for broader changes or small artifacts',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'update_artifact_metadata' => [
                'class' => UpdateArtifactMetadataTool::class,
                'name' => 'Update Artifact Metadata',
                'description' => 'Updates artifact metadata (title, description, tags, filetype, privacy_level) without content changes',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'delete_artifact' => [
                'class' => DeleteArtifactTool::class,
                'name' => 'Delete Artifact',
                'description' => 'Deletes artifacts permanently (requires confirmation)',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'list_artifacts' => [
                'class' => ListArtifactsTool::class,
                'name' => 'List Artifacts',
                'description' => 'Lists and searches artifacts with filtering options',
                'category' => 'artifacts',
                'instance' => null,
            ],
            'list_chat_attachments' => [
                'class' => ListChatAttachmentsTool::class,
                'name' => 'List Chat Attachments',
                'description' => 'Lists chat attachments (images, documents, audio, video) from conversations for use in multi-media artifacts',
                'category' => 'attachments',
                'instance' => null,
            ],
            'create_chat_attachment' => [
                'class' => CreateChatAttachmentTool::class,
                'name' => 'Create Chat Attachment',
                'description' => 'Downloads media from external URLs and creates chat attachments with proper source attribution for crediting',
                'category' => 'attachments',
                'instance' => null,
            ],
            'generate_mermaid_diagram' => [
                'class' => GenerateMermaidDiagramTool::class,
                'name' => 'Generate Mermaid Diagram',
                'description' => 'Generate diagrams using Mermaid.js syntax (flowcharts, sequence diagrams, class diagrams, ER diagrams, Gantt charts, etc.)',
                'category' => 'visualization',
                'instance' => null,
            ],
            'database_schema_inspector' => [
                'class' => DatabaseSchemaInspectorTool::class,
                'name' => 'Database Schema Inspector',
                'description' => 'Inspect database schema, tables, columns, and relationships',
                'category' => 'system',
                'instance' => null,
            ],
            'safe_database_query' => [
                'class' => SafeDatabaseQueryTool::class,
                'name' => 'Safe Database Query',
                'description' => 'Execute read-only SQL SELECT queries',
                'category' => 'system',
                'instance' => null,
            ],
            'secure_file_reader' => [
                'class' => SecureFileReaderTool::class,
                'name' => 'Secure File Reader',
                'description' => 'Read project files with security filtering',
                'category' => 'system',
                'instance' => null,
            ],
            'directory_listing' => [
                'class' => DirectoryListingTool::class,
                'name' => 'Directory Listing',
                'description' => 'List directory contents',
                'category' => 'system',
                'instance' => null,
            ],
            'code_search' => [
                'class' => CodeSearchTool::class,
                'name' => 'Code Search',
                'description' => 'Search for code patterns using grep',
                'category' => 'system',
                'instance' => null,
            ],
            'route_inspector' => [
                'class' => RouteInspectorTool::class,
                'name' => 'Route Inspector',
                'description' => 'Inspect Laravel routes and map to handlers',
                'category' => 'system',
                'instance' => null,
            ],
            'http_request' => [
                'class' => HttpRequestTool::class,
                'name' => 'HTTP Request',
                'description' => 'Make HTTP requests to any URL with custom methods, headers, and body. Perfect for testing APIs and web scraping',
                'category' => 'integration',
                'instance' => null,
            ],
            'list_available_tools' => [
                'class' => ListAvailableToolsTool::class,
                'name' => 'List Available Tools',
                'description' => 'Discover all available tools that can be assigned to agents',
                'category' => 'admin',
                'instance' => null,
            ],
            'create_agent' => [
                'class' => CreateAgentTool::class,
                'name' => 'Create Agent',
                'description' => 'Create new AI agents with specified configuration',
                'category' => 'admin',
                'instance' => null,
            ],
            'assign_knowledge_to_agent' => [
                'class' => AssignKnowledgeToAgentTool::class,
                'name' => 'Assign Knowledge to Agent',
                'description' => 'Assign knowledge documents to agents for RAG-enhanced responses',
                'category' => 'admin',
                'instance' => null,
            ],
        ];

        // Conditionally register GitHub tools if enabled
        if (config('github.bug_report.enabled', false)) {
            $this->availableTools['create_github_issue'] = [
                'class' => CreateGitHubIssueTool::class,
                'name' => 'Create GitHub Issue',
                'description' => 'Create a new GitHub issue for bug reports from the help widget',
                'category' => 'github',
                'instance' => null,
            ];

            $this->availableTools['search_github_issues'] = [
                'class' => SearchGitHubIssuesTool::class,
                'name' => 'Search GitHub Issues',
                'description' => 'Search for existing GitHub issues to check for duplicates before creating new bug reports',
                'category' => 'github',
                'instance' => null,
            ];

            $this->availableTools['update_github_issue'] = [
                'class' => UpdateGitHubIssueTool::class,
                'name' => 'Update GitHub Issue',
                'description' => 'Update an existing GitHub issue (title, description, labels, state)',
                'category' => 'github',
                'instance' => null,
            ];

            $this->availableTools['comment_on_github_issue'] = [
                'class' => CommentOnGitHubIssueTool::class,
                'name' => 'Comment on GitHub Issue',
                'description' => 'Add a comment to an existing GitHub issue for follow-up information or status updates',
                'category' => 'github',
                'instance' => null,
            ];

            $this->availableTools['list_github_labels'] = [
                'class' => ListGitHubLabelsTool::class,
                'name' => 'List GitHub Labels',
                'description' => 'Get all available labels from the GitHub repository (cached for 1 hour)',
                'category' => 'github',
                'instance' => null,
            ];

            $this->availableTools['list_github_milestones'] = [
                'class' => ListGitHubMilestonesTool::class,
                'name' => 'List GitHub Milestones',
                'description' => 'Get all available milestones from the GitHub repository with progress (cached for 1 hour)',
                'category' => 'github',
                'instance' => null,
            ];
        }

        // Load MCP tools from Relay
        $this->loadMcpTools();
    }

    /**
     * Load MCP tools from global and user-specific servers
     *
     * @param  int|null  $userId  Load user-specific MCP servers for this user
     */
    public function loadMcpTools(?int $userId = null): void
    {
        // Load global MCP servers (from config)
        $this->loadGlobalMcpTools();

        // Load shared MCP servers (available to all users)
        $this->loadSharedMcpTools();

        // Load user-specific MCP servers (from database)
        if ($userId) {
            $this->loadUserMcpTools($userId);
        }
    }

    /**
     * Load MCP tools from global relay.php configuration
     */
    protected function loadGlobalMcpTools(): void
    {
        try {
            $mcpServers = config('relay.servers', []);

            foreach ($mcpServers as $serverName => $serverConfig) {
                try {
                    $tools = Relay::tools($serverName);

                    foreach ($tools as $tool) {
                        // Use tool name directly (Relay already prefixes as relay__{server}__{tool})
                        $toolName = $tool->name();

                        $this->availableTools[$toolName] = [
                            'class' => get_class($tool),
                            'name' => $toolName,
                            'description' => $tool->description() ?? "MCP tool from {$serverName}",
                            'category' => 'mcp',
                            'server' => $serverName,
                            'instance' => $tool,
                            'scope' => 'global',  // Available to all users
                        ];
                    }

                    // Log successful tool loading
                    Log::info('Loaded MCP tools successfully', [
                        'server_name' => $serverName,
                        'server_type' => 'global',
                        'tool_count' => count($tools),
                        'tool_names' => array_map(fn ($t) => $t->name(), $tools),
                    ]);
                } catch (\Exception $e) {
                    // Enhanced diagnostics for MCP tool loading failures
                    Log::warning("Failed to load global MCP tools from server: {$serverName}", [
                        'server_name' => $serverName,
                        'server_type' => 'global',
                        'server_config' => array_diff_key(
                            $serverConfig ?? [],
                            ['env' => '', 'args' => ''] // Exclude potentially sensitive data
                        ),
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                        'relay_available' => class_exists(\EchoLabs\Prism\Relay\Facades\Relay::class),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to load global MCP tools', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load MCP tools from user-specific integrations
     *
     * @param  int  $userId  User ID to load integrations for
     */
    protected function loadUserMcpTools(int $userId): void
    {
        try {
            $integrations = $this->getIntegrationsForScope('user', $userId);

            foreach ($integrations as $integration) {
                $token = $integration->integrationToken;
                $serverName = $token->config['server_name'] ?? 'unknown';

                try {
                    $toolCount = $this->loadToolsFromIntegration($integration, 'user', $userId);

                    // Log successful tool loading
                    Log::info('Loaded MCP tools successfully', [
                        'server_name' => $serverName,
                        'server_type' => 'user',
                        'user_id' => $userId,
                        'integration_id' => $integration->id,
                        'tool_count' => $toolCount,
                    ]);
                } catch (\Exception $e) {
                    // Enhanced diagnostics for MCP tool loading failures
                    Log::warning('Failed to load user MCP tools from integration', [
                        'server_name' => $serverName,
                        'server_type' => 'user',
                        'user_id' => $userId,
                        'integration_id' => $integration->id,
                        'server_config' => array_diff_key(
                            $token->config ?? [],
                            ['env' => '', 'args' => '', 'credentials' => ''] // Exclude potentially sensitive data
                        ),
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                        'relay_available' => class_exists(\EchoLabs\Prism\Relay\Facades\Relay::class),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to load user MCP tools', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load MCP tools from shared integrations
     * These are available to all users but use the owner's credentials
     */
    protected function loadSharedMcpTools(): void
    {
        try {
            $integrations = $this->getIntegrationsForScope('shared');

            foreach ($integrations as $integration) {
                $token = $integration->integrationToken;
                $serverName = $token->config['server_name'] ?? 'unknown';

                try {
                    $toolCount = $this->loadToolsFromIntegration($integration, 'shared');

                    // Log successful tool loading
                    Log::info('Loaded MCP tools successfully', [
                        'server_name' => $serverName,
                        'server_type' => 'shared',
                        'integration_id' => $integration->id,
                        'tool_count' => $toolCount,
                    ]);
                } catch (\Exception $e) {
                    // Enhanced diagnostics for MCP tool loading failures
                    Log::warning('Failed to load shared MCP tools from integration', [
                        'server_name' => $serverName,
                        'server_type' => 'shared',
                        'integration_id' => $integration->id,
                        'server_config' => array_diff_key(
                            $token->config ?? [],
                            ['env' => '', 'args' => '', 'credentials' => ''] // Exclude potentially sensitive data
                        ),
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                        'relay_available' => class_exists(\EchoLabs\Prism\Relay\Facades\Relay::class),
                    ]);
                }
            }
        } catch (\PDOException $e) {
            // Database connection issues during startup - log info only, don't fail
            if (strpos($e->getMessage(), '[2002]') !== false || strpos($e->getMessage(), 'Connection refused') !== false) {
                Log::info('Shared MCP tools not loaded - database not ready', [
                    'reason' => 'Database connection unavailable',
                ]);
            } else {
                Log::warning('Failed to load shared MCP tools - database error', [
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load shared MCP tools', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get integrations for a specific scope (user or shared).
     * Returns empty collection for global scope (loaded from config instead).
     *
     * @param  string  $scope  One of: 'global', 'user', 'shared'
     * @param  int|null  $userId  Required for 'user' scope
     * @return \Illuminate\Support\Collection<\App\Models\Integration>
     */
    protected function getIntegrationsForScope(string $scope, ?int $userId = null): Collection
    {
        if ($scope === 'global') {
            return collect();  // Global tools loaded from config, not database
        }

        $query = \App\Models\Integration::query()
            ->whereHas('integrationToken', function ($q) {
                $q->where('provider_id', 'mcp_server')
                    ->where('status', 'active');
            })
            ->where('status', 'active')
            ->with('integrationToken');

        if ($scope === 'user') {
            $query->where('user_id', $userId)
                ->where('visibility', 'private');
        } elseif ($scope === 'shared') {
            $query->where('visibility', 'shared')
                ->with('user');  // Include owner info for shared tools
        }

        return $query->get();
    }

    /**
     * Load tools from a single integration and register them in availableTools.
     *
     * Handles the complete flow:
     * - Builds runtime config with credentials
     * - Validates server name for security
     * - Registers server with Laravel config
     * - Loads tools via Relay
     * - Adds tools to registry with appropriate metadata
     *
     * @param  \App\Models\Integration  $integration  Integration to load tools from
     * @param  string  $scope  One of: 'user', 'shared'
     * @param  int|null  $userId  Current user ID (for user scope)
     * @return int Number of tools loaded
     *
     * @throws \Exception On critical failures
     */
    protected function loadToolsFromIntegration(\App\Models\Integration $integration, string $scope, ?int $userId = null): int
    {
        $token = $integration->integrationToken;

        // Build runtime config with injected credentials
        $provider = app(\App\Services\Integrations\ProviderRegistry::class)->get('mcp_server');

        if (! $provider) {
            Log::warning('ToolRegistry: MCP provider not found', [
                'integration_id' => $integration->id,
                'scope' => $scope,
            ]);

            return 0;
        }

        $runtimeConfig = $provider->buildRuntimeConfig($token);

        // SECURITY: Validate server_name to prevent config overwrite and command injection
        // Only allow alphanumeric characters, hyphens, and underscores
        $rawServerName = $token->config['server_name'] ?? 'unknown';
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $rawServerName)) {
            Log::warning('ToolRegistry: Invalid MCP server name format, skipping integration', [
                'integration_id' => $integration->id,
                'scope' => $scope,
                'user_id' => $userId,
                'owner_id' => $integration->user_id,
                'server_name' => $rawServerName,
                'reason' => 'Contains unsafe characters (only alphanumeric, hyphens, and underscores allowed)',
            ]);

            return 0;
        }

        // Generate unique server name based on scope
        $serverName = $this->generateServerName($scope, $rawServerName, $integration, $userId);

        // Temporarily register this MCP server
        config(['relay.servers.'.$serverName => $runtimeConfig]);

        // Load tools from this server
        $tools = Relay::tools($serverName);

        foreach ($tools as $tool) {
            // Use tool name directly (Relay already prefixes as relay__{server}__{tool})
            $toolName = $tool->name();

            // Build base tool metadata
            $toolMetadata = [
                'class' => get_class($tool),
                'name' => $toolName,
                'description' => $tool->description() ?? "Tool from {$integration->name}",
                'category' => 'mcp',
                'server' => $serverName,
                'instance' => $tool,
                'scope' => $scope,
                'integration_id' => $integration->id,
            ];

            // Add scope-specific metadata
            if ($scope === 'user') {
                $toolMetadata['user_id'] = $userId;
            } elseif ($scope === 'shared') {
                $toolMetadata['owner_user_id'] = $integration->user_id;
                $toolMetadata['description'] = $tool->description()." (Shared by {$integration->user->name})" ??
                    "Shared tool from {$integration->name}";
            }

            $this->availableTools[$toolName] = $toolMetadata;
        }

        Log::info('Loaded MCP tools from integration', [
            'integration_id' => $integration->id,
            'scope' => $scope,
            'user_id' => $scope === 'user' ? $userId : null,
            'owner_user_id' => $scope === 'shared' ? $integration->user_id : null,
            'server_name' => $serverName,
            'tool_count' => count($tools),
        ]);

        return count($tools);
    }

    /**
     * Generate unique server name based on scope and context.
     *
     * @param  string  $scope  Tool scope (user or shared)
     * @param  string  $rawServerName  Base server name from config
     * @param  \App\Models\Integration  $integration  Integration instance
     * @param  int|null  $userId  User ID for user scope
     * @return string Unique server identifier
     */
    protected function generateServerName(string $scope, string $rawServerName, \App\Models\Integration $integration, ?int $userId = null): string
    {
        if ($scope === 'user') {
            return $integration->config['server_instance_name'] ??
                "user_{$userId}_{$rawServerName}";
        }

        if ($scope === 'shared') {
            // Use short hash of integration ID to keep tool names under 64 chars
            $shortId = \App\Support\Str::shortHash($integration->id);

            return "shared_{$shortId}_{$rawServerName}";
        }

        return $rawServerName;
    }

    /**
     * Ensure user-specific MCP tools are loaded for the given user
     *
     * @param  int  $userId  User ID to load tools for
     */
    protected function ensureUserToolsLoaded(int $userId): void
    {
        // Check if we've already loaded tools for this user
        if (in_array($userId, $this->loadedUserTools)) {
            return;
        }

        // Load user-specific MCP tools
        $this->loadUserMcpTools($userId);

        // Mark as loaded
        $this->loadedUserTools[] = $userId;
    }

    /**
     * Register a tool from an external package
     *
     * Allows packages to register tools dynamically without modifying the core registry.
     * This method is designed to be called from package service providers during boot.
     *
     * @param  string  $name  Unique tool identifier (e.g., 'notion_search')
     * @param  array  $config  Tool configuration array with keys:
     *                         - 'class' (required): Tool class name
     *                         - 'name' (required): Display name
     *                         - 'description' (required): Tool description
     *                         - 'category' (required): Tool category
     *                         - 'instance' (optional): Pre-created tool instance
     *
     * @example Register a tool from a package service provider
     * ```php
     * public function boot()
     * {
     *     $toolRegistry = app(ToolRegistry::class);
     *     $toolRegistry->registerTool('notion_search', [
     *         'class' => NotionSearchTool::class,
     *         'name' => 'Notion Search',
     *         'description' => 'Search Notion workspace for documents and pages',
     *         'category' => 'search',
     *     ]);
     * }
     * ```
     */
    public function registerTool(string $name, array $config): void
    {
        if (isset($this->availableTools[$name])) {
            Log::warning("Tool '{$name}' already registered, skipping", [
                'tool_name' => $name,
                'existing_category' => $this->availableTools[$name]['category'] ?? 'unknown',
            ]);

            return;
        }

        $this->availableTools[$name] = array_merge([
            'instance' => null,
        ], $config);
    }

    /**
     * Get available tools, optionally filtered by user ownership and scope
     *
     * Scoping Rules:
     * - Global tools: Always available to all users (built-in tools + config-based MCP)
     * - Shared tools: Available to all users but use owner's credentials
     * - User tools: Only available to the owning user (private integrations)
     *
     * @param  int|null  $userId  Filter tools by user ID. When provided, returns global + shared + user-specific tools.
     *                            When null, returns only global + shared tools (publicly available).
     * @return array Available tools filtered by scope and user ownership
     *
     * @example Get tools for a specific user (includes global, shared, and user-specific)
     * ```php
     * $userTools = $registry->getAvailableTools($userId);
     * ```
     * @example Get publicly available tools only (global and shared, no user-specific)
     * ```php
     * $publicTools = $registry->getAvailableTools(null);
     * ```
     */
    public function getAvailableTools(?int $userId = null): array
    {
        // Load user-specific MCP tools if userId is provided and not already loaded
        if ($userId !== null) {
            $this->ensureUserToolsLoaded($userId);
        }

        // If no user, return global and shared tools
        if ($userId === null) {
            return array_filter($this->availableTools, function ($tool) {
                $scope = $tool['scope'] ?? 'global';

                return in_array($scope, ['global', 'shared']);
            });
        }

        // Filter by user and scope
        return array_filter($this->availableTools, function ($tool) use ($userId) {
            // User-specific tools: only for owning user
            if (isset($tool['scope']) && $tool['scope'] === 'user') {
                return $tool['user_id'] === $userId;
            }

            // Shared tools: available to all users
            if (isset($tool['scope']) && $tool['scope'] === 'shared') {
                return true;
            }

            // Global tools: available to all
            if (($tool['scope'] ?? 'global') === 'global') {
                return true;
            }

            // Existing integration-based filtering
            if (isset($tool['requires_integration']) && $tool['requires_integration']) {
                $provider = $tool['integration_provider'] ?? null;

                if (! $provider) {
                    return false;
                }

                return \App\Models\IntegrationToken::where('user_id', $userId)
                    ->where('provider_id', $provider)
                    ->where('status', 'active')
                    ->exists();
            }

            // All other tools are global
            return true;
        });
    }

    public function getToolsByCategory(string $category): array
    {
        return array_filter($this->availableTools, function ($tool) use ($category) {
            return $tool['category'] === $category;
        });
    }

    public function getToolInstance(string $toolName)
    {
        if (! isset($this->availableTools[$toolName])) {
            throw new \InvalidArgumentException("Tool '{$toolName}' not found");
        }

        $toolConfig = $this->availableTools[$toolName];

        // Return cached instance if available
        if ($toolConfig['instance'] !== null) {
            return $toolConfig['instance'];
        }

        // Create new instance for local tools
        if ($toolConfig['category'] !== 'mcp') {
            $toolClass = $toolConfig['class'];

            // Check if the tool class has a create() method
            if (method_exists($toolClass, 'create')) {
                $instance = $toolClass::create();
            } else {
                // Fallback to direct instantiation
                $instance = new $toolClass;
            }

            $this->availableTools[$toolName]['instance'] = $instance;

            return $instance;
        }

        // For MCP tools, return the already created instance
        return $toolConfig['instance'];
    }

    public function getToolsForAgent(Collection $agentTools, bool $testConnections = true)
    {
        $tools = [];
        $failedTools = [];

        foreach ($agentTools as $agentTool) {
            if (! $agentTool->enabled) {
                continue;
            }

            try {
                $tool = $this->getToolInstance($agentTool->tool_name);

                // Test tool connection/availability if requested
                if ($testConnections && $this->shouldTestTool($agentTool->tool_name)) {
                    if (! $this->testToolConnection($tool, $agentTool->tool_name)) {
                        $failedTools[] = $agentTool->tool_name;
                        Log::warning('Tool connection test failed, excluding from execution', [
                            'tool_name' => $agentTool->tool_name,
                            'agent_id' => $agentTool->agent_id,
                        ]);

                        continue;
                    }
                }

                $tools[] = $tool;
            } catch (\Exception $e) {
                $failedTools[] = $agentTool->tool_name;
                Log::warning('Failed to load tool for agent', [
                    'tool_name' => $agentTool->tool_name,
                    'agent_id' => $agentTool->agent_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Log tool loading summary
        if (! empty($failedTools)) {
            Log::info('Some tools failed to load or connect', [
                'failed_tools' => $failedTools,
                'successful_tools' => count($tools),
                'total_requested' => $agentTools->count(),
            ]);
        }

        return $tools;
    }

    protected function shouldTestTool(string $toolName): bool
    {
        // Only test tools that are known to be flaky or external
        return in_array($toolName, [
            'perplexity_research',
            'markitdown',
        ]);
    }

    protected function testToolConnection($tool, string $toolName): bool
    {
        try {
            // For Perplexity tool, check if user has active integration token
            if ($toolName === 'perplexity_research') {
                // Check if any active Perplexity integration token exists for the current user
                return \App\Models\IntegrationToken::where('provider_id', 'perplexity')
                    ->where('user_id', auth()->id())
                    ->where('status', 'active')
                    ->exists();
            }

            // For MarkItDown, assume service availability
            if ($toolName === 'markitdown') {
                return true;
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Tool connection test exception', [
                'tool_name' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function validateToolName(string $toolName): bool
    {
        return isset($this->availableTools[$toolName]);
    }

    public function getToolInfo(string $toolName): ?array
    {
        return $this->availableTools[$toolName] ?? null;
    }

    public function getResearchTools(): array
    {
        return array_filter($this->availableTools, function ($tool) {
            return in_array($tool['category'], ['search', 'research', 'content', 'validation']);
        });
    }

    /**
     * Refresh MCP tools for a specific user
     * Call this when user integrations change
     *
     * @param  int  $userId  User ID to refresh tools for
     */
    public function refreshUserTools(int $userId): void
    {
        // Remove existing user tools
        $this->availableTools = array_filter($this->availableTools,
            fn ($tool) => ! isset($tool['user_id']) || $tool['user_id'] !== $userId
        );

        // Reload user tools
        $this->loadUserMcpTools($userId);
    }

    /**
     * Refresh all shared MCP tools
     * Call this when shared integrations change
     */
    public function refreshSharedTools(): void
    {
        // Remove existing shared tools
        $this->availableTools = array_filter($this->availableTools,
            fn ($tool) => ($tool['scope'] ?? 'global') !== 'shared'
        );

        // Reload shared tools
        $this->loadSharedMcpTools();
    }
}
