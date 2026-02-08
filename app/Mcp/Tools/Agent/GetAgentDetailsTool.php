<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Get Agent Details
 *
 * Retrieve detailed information about a specific AI agent including
 * its configuration, tools, prompts, and capabilities.
 *
 * Required Scope: agent:view
 */
class GetAgentDetailsTool extends Tool
{
    public function description(): string
    {
        return 'Get detailed configuration and capabilities of a specific AI agent';
    }

    public function name(): string
    {
        return 'get_agent_details';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('agent:view')) {
            return ToolResult::error('Insufficient permissions. Required scope: agent:view');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'agent_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        // Create internal sub-request
        $subRequest = \Illuminate\Http\Request::create(
            '/api/v1/agents/'.$validated['agent_id'],
            'GET'
        );
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'get_agent_details',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to get agent details: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('agent_id')
            ->description('The ID of the agent to retrieve (required)')
            ->required();
    }
}
