<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Agents & Tools
 *
 * API endpoints for managing and querying AI agents and their configurations.
 * All endpoints require authentication and the `agent:view` token ability.
 */
class AgentController extends Controller
{
    /**
     * List available agents
     *
     * Returns all agents available to the authenticated user, including public agents
     * and user-created agents. Only active agents are returned.
     *
     * ## Example Usage
     *
     * **Ulauncher Extension** ([github.com/promptlyagentai/ulauncher-promptlyagent](https://github.com/promptlyagentai/ulauncher-promptlyagent)) - Desktop AI integration:
     * - Browse and select from configured agents via keyboard shortcut
     * - Smart caching (1-hour default) to reduce API calls
     * - Agent filtering and quick selection in TUI
     * - Requires `agent:view` token ability
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "agents": [
     *     {
     *       "id": 1,
     *       "name": "Direct Chat Agent",
     *       "description": "General purpose conversational agent",
     *       "agent_type": "chat",
     *       "ai_provider": "openai",
     *       "ai_model": "gpt-4",
     *       "is_active": true,
     *       "max_steps": 10,
     *       "tool_count": 5,
     *       "created_at": "2024-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     * @response 403 scenario="Insufficient permissions" {
     *   "success": false,
     *   "error": "Unauthorized",
     *   "message": "Your API token does not have the agent:view ability"
     * }
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('agent:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the agent:view ability',
                ], 403);
            }

            // Return agents available to the user (public agents + user-created agents)
            $agents = Agent::forUser(Auth::id())
                ->active()
                ->orderBy('name')
                ->get()
                ->map(function ($agent) {
                    return [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'description' => $agent->description,
                        'agent_type' => $agent->agent_type,
                        'ai_provider' => $agent->ai_provider,
                        'ai_model' => $agent->ai_model,
                        'is_active' => $agent->status === 'active',
                        'max_steps' => $agent->max_steps,
                        'tool_count' => $agent->enabledTools->count(),
                        'created_at' => $agent->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'agents' => $agents,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving agents',
            ], 500);
        }
    }

    /**
     * Get agent details
     *
     * Returns detailed information about a specific agent, including its configuration,
     * system prompt, and enabled tools.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/agent-api.js`) - Agent details with caching:
     * - Retrieves specific agent configuration and settings
     * - Uses cached agent list for faster access
     * - Fallback to cache when offline
     * - Cache validity: 1 hour
     *
     * @authenticated
     *
     * @urlParam id integer required The agent ID. Example: 1
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "agent": {
     *     "id": 1,
     *     "name": "Direct Chat Agent",
     *     "description": "General purpose conversational agent",
     *     "agent_type": "chat",
     *     "ai_provider": "openai",
     *     "ai_model": "gpt-4",
     *     "is_active": true,
     *     "max_steps": 10,
     *     "system_prompt": "You are a helpful AI assistant...",
     *     "tools": ["web_search", "calculator", "file_read"],
     *     "created_at": "2024-01-01T00:00:00.000000Z",
     *     "updated_at": "2024-01-01T00:00:00.000000Z"
     *   }
     * }
     * @response 404 scenario="Agent not found" {
     *   "success": false,
     *   "error": "Not Found",
     *   "message": "Agent not found"
     * }
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('agent:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the agent:view ability',
                ], 403);
            }

            // Allow viewing agents available to the user (public + user-created)
            $agent = Agent::forUser(Auth::id())
                ->active()
                ->where('id', $id)
                ->with('enabledTools')
                ->first();

            if (! $agent) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not Found',
                    'message' => 'Agent not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'agent' => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'description' => $agent->description,
                    'agent_type' => $agent->agent_type,
                    'ai_provider' => $agent->ai_provider,
                    'ai_model' => $agent->ai_model,
                    'is_active' => $agent->status === 'active',
                    'max_steps' => $agent->max_steps,
                    'system_prompt' => $agent->system_prompt,
                    'tools' => $agent->enabledTools->pluck('tool_name')->toArray(),
                    'created_at' => $agent->created_at,
                    'updated_at' => $agent->updated_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving agent details',
            ], 500);
        }
    }
}
