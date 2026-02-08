<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * List Chat Sessions
 *
 * Retrieve all chat sessions for the authenticated user with
 * interaction counts and timestamps.
 *
 * Required Scope: chat:view
 */
class ListChatSessionsTool extends Tool
{
    public function description(): string
    {
        return 'List all chat sessions for the authenticated user';
    }

    public function name(): string
    {
        return 'list_chat_sessions';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('chat:view')) {
            Log::warning('MCP unauthorized chat access attempt', [
                'tool' => 'list_chat_sessions',
                'user_id' => $user?->id,
                'required_scope' => 'chat:view',
            ]);

            return ToolResult::error('Insufficient permissions. Required scope: chat:view');
        }

        // Create internal sub-request (no validation needed for no parameters)
        $subRequest = \Illuminate\Http\Request::create('/api/v1/chat/sessions', 'GET');
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'list_chat_sessions',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to list chat sessions: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        // No parameters required - lists all sessions for the authenticated user
        return $schema;
    }
}
