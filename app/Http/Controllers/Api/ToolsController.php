<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Agents\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Agents & Tools
 *
 * Query available Prism tools and agent-specific tool configurations.
 * Tools enable agents to perform actions like web search, file operations, calculations, and external API calls.
 *
 * ## Authentication
 * Required token abilities:
 * - `tools:view` - View available tools and tool configurations
 * - `agent:view` - View agent-specific tool assignments
 *
 * ## Tool Categories
 * - **research**: Web search, knowledge base queries
 * - **file**: File operations and document processing
 * - **calculation**: Mathematical operations and data analysis
 * - **integration**: External API calls and service integrations
 * - **general**: Miscellaneous utilities
 */
class ToolsController extends Controller
{
    public function __construct(
        private ToolRegistry $toolRegistry
    ) {}

    /**
     * List all available tools
     *
     * Retrieve all tools registered in the ToolRegistry with their descriptions, categories,
     * and authentication requirements. These are the tools that can be assigned to agents.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"success": true, "tools": [{"name": "web_search", "description": "Search the web using SearXNG meta-search engine", "category": "research", "requires_auth": false}, {"name": "search_knowledge", "description": "Query the knowledge base with semantic search", "category": "research", "requires_auth": false}, {"name": "calculator", "description": "Perform mathematical calculations", "category": "calculation", "requires_auth": false}], "total": 15}
     * @response 403 scenario="Missing tools:view ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the tools:view ability"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while retrieving tools"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField tools array Array of available tools
     * @responseField tools[].name string Tool identifier (used when assigning to agents)
     * @responseField tools[].description string Human-readable description of tool functionality
     * @responseField tools[].category string Tool category (research, file, calculation, integration, general)
     * @responseField tools[].requires_auth boolean Whether tool requires user authentication to external services
     * @responseField total integer Total number of available tools
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('tools:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the tools:view ability',
                ], 403);
            }

            $allTools = $this->toolRegistry->getAvailableTools();

            $tools = collect($allTools)->map(function ($tool, $name) {
                return [
                    'name' => $name,
                    'description' => $tool['description'] ?? 'No description available',
                    'category' => $tool['category'] ?? 'general',
                    'requires_auth' => $tool['requires_auth'] ?? false,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'tools' => $tools,
                'total' => $tools->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving tools',
            ], 500);
        }
    }

    /**
     * List tools enabled for a specific agent
     *
     * Retrieve all tools currently assigned to a specific agent. Only tools that have been
     * explicitly enabled for the agent are returned.
     *
     * @authenticated
     *
     * @urlParam agentId integer required The agent ID. Example: 5
     *
     * @response 200 scenario="Success" {"success": true, "agent_id": 5, "agent_name": "Research Agent", "tools": [{"name": "web_search", "description": "Search the web using SearXNG meta-search engine", "category": "research", "enabled": true}, {"name": "search_knowledge", "description": "Query the knowledge base with semantic search", "category": "research", "enabled": true}], "total": 2}
     * @response 403 scenario="Missing tools:view ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the tools:view ability"}
     * @response 403 scenario="Missing agent:view ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the agent:view ability required to access agent tools"}
     * @response 404 scenario="Agent Not Found" {"success": false, "error": "Not Found", "message": "Agent not found"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while retrieving agent tools"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField agent_id integer The agent ID
     * @responseField agent_name string The agent name
     * @responseField tools array Array of tools enabled for this agent
     * @responseField tools[].name string Tool identifier
     * @responseField tools[].description string Tool description
     * @responseField tools[].category string Tool category (research, file, calculation, integration, general)
     * @responseField tools[].enabled boolean Always true for this endpoint (indicates tool is enabled for agent)
     * @responseField total integer Number of tools enabled for this agent
     */
    public function agentTools(Request $request, int $agentId): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('tools:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the tools:view ability',
                ], 403);
            }

            // Also check agent:view permission since we're accessing agent data
            if (! $request->user()->tokenCan('agent:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the agent:view ability required to access agent tools',
                ], 403);
            }

            $agent = Agent::where('id', $agentId)
                ->where('created_by', Auth::id())
                ->with('enabledTools')
                ->first();

            if (! $agent) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not Found',
                    'message' => 'Agent not found',
                ], 404);
            }

            $allTools = $this->toolRegistry->getAvailableTools();
            $enabledToolNames = $agent->enabledTools->pluck('tool_name')->toArray();

            $tools = collect($enabledToolNames)->map(function ($toolName) use ($allTools) {
                $tool = $allTools[$toolName] ?? null;

                return [
                    'name' => $toolName,
                    'description' => $tool['description'] ?? 'No description available',
                    'category' => $tool['category'] ?? 'general',
                    'enabled' => true,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'tools' => $tools,
                'total' => $tools->count(),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving agent tools',
            ], 500);
        }
    }
}
