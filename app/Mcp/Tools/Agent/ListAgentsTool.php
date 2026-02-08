<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * List Agents
 *
 * Retrieve a list of available AI agents with their configurations
 * and capabilities.
 *
 * Required Scope: agent:view
 */
class ListAgentsTool extends Tool
{
    public function description(): string
    {
        return 'List all available AI agents and their configurations';
    }

    public function name(): string
    {
        return 'list_agents';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('agent:view')) {
            Log::warning('MCP unauthorized agent access attempt', [
                'tool' => 'list_agents',
                'user_id' => $user?->id,
                'required_scope' => 'agent:view',
            ]);

            return ToolResult::error('Insufficient permissions. Required scope: agent:view');
        }

        // Create internal sub-request (no validation needed for no parameters)
        $subRequest = \Illuminate\Http\Request::create('/api/v1/agents', 'GET');
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'list_agents',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to list agents: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        // No parameters required - lists all agents
        return $schema;
    }
}
