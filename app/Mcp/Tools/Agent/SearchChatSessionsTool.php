<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Search Chat Sessions
 *
 * Search chat sessions by query, filters, or date range.
 * Currently returns all sessions - advanced search functionality planned.
 *
 * Required Scope: chat:view
 */
class SearchChatSessionsTool extends Tool
{
    public function description(): string
    {
        return 'Search chat sessions by query or filters (basic implementation - returns all sessions)';
    }

    public function name(): string
    {
        return 'search_chat_sessions';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('chat:view')) {
            return ToolResult::error('Insufficient permissions. Required scope: chat:view');
        }

        // Validate input (for future use)
        $validator = Validator::make($arguments, [
            'query' => 'nullable|string|max:255',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        $subRequest = \Illuminate\Http\Request::create('/api/v1/chat/sessions', 'GET');
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            // Add note about search functionality
            if (isset($data['success']) && $data['success']) {
                $data['note'] = 'Advanced search functionality is planned. Currently returns all sessions.';
                if (! empty($validated['query'])) {
                    $data['note'] .= ' Search query "'.$validated['query'].'" was provided but not yet implemented.';
                }
            }

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'search_chat_sessions',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to search chat sessions: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')
            ->description('Search query to filter sessions (planned feature, not yet implemented)')
            ->optional()

            ->string('from_date')
            ->description('Filter sessions created after this date (ISO 8601 format, planned feature)')
            ->optional()

            ->string('to_date')
            ->description('Filter sessions created before this date (ISO 8601 format, planned feature)')
            ->optional();
    }
}
