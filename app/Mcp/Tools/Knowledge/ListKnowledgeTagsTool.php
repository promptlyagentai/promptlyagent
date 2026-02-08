<?php

namespace App\Mcp\Tools\Knowledge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * List Knowledge Tags
 *
 * Retrieve all available knowledge tags for organizing documents.
 * Tags can be used for filtering and categorization.
 *
 * Required Scope: knowledge:view
 */
class ListKnowledgeTagsTool extends Tool
{
    public function description(): string
    {
        return 'List all available knowledge tags for document organization';
    }

    public function name(): string
    {
        return 'list_knowledge_tags';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:view')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:view');
        }

        // Create internal sub-request (no validation needed for no parameters)
        $subRequest = \Illuminate\Http\Request::create('/api/v1/knowledge/tags', 'GET');
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'list_knowledge_tags',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to list tags: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        // No parameters required - lists all tags
        return $schema;
    }
}
