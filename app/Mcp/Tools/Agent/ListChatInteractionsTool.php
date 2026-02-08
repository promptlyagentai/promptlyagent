<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * List Chat Interactions
 *
 * Retrieve all interactions (question/answer pairs) for a specific
 * chat session, including sources and metadata.
 *
 * Required Scope: chat:view
 */
class ListChatInteractionsTool extends Tool
{
    public function description(): string
    {
        return 'List all interactions (question/answer pairs) for a specific chat session';
    }

    public function name(): string
    {
        return 'list_chat_interactions';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('chat:view')) {
            return ToolResult::error('Insufficient permissions. Required scope: chat:view');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'session_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        // Use the chat session show endpoint which includes interactions
        $subRequest = \Illuminate\Http\Request::create(
            '/api/v1/chat/sessions/'.$validated['session_id'],
            'GET'
        );
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            // Extract just the interactions if successful
            if (isset($data['success']) && $data['success'] && isset($data['interactions'])) {
                return ToolResult::json([
                    'success' => true,
                    'session_id' => $validated['session_id'],
                    'interactions' => $data['interactions'],
                    'count' => count($data['interactions']),
                ]);
            }

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'list_chat_interactions',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to list chat interactions: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('session_id')
            ->description('The ID of the chat session to retrieve interactions from (required)')
            ->required();
    }
}
