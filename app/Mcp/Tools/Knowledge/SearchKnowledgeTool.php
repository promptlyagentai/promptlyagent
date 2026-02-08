<?php

namespace App\Mcp\Tools\Knowledge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Search Knowledge Documents
 *
 * Performs full-text, semantic, or hybrid search across knowledge documents.
 * Supports filtering by content type, tags, and privacy levels.
 *
 * Required Scope: knowledge:search
 */
class SearchKnowledgeTool extends Tool
{
    public function description(): string
    {
        return 'Search knowledge documents using full-text, semantic, or hybrid search with optional filtering';
    }

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:search')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:search');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'query' => 'required|string|max:500',
            'limit' => 'integer|min:1|max:100',
            'search_type' => 'string|in:fulltext,semantic,hybrid',
            'content_type' => 'string|in:text,file,external',
            'tags' => 'array',
            'tags.*' => 'string',
            'include_expired' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        // Determine which endpoint to call based on search type
        $searchType = $validated['search_type'] ?? 'fulltext';
        $endpoint = match ($searchType) {
            'semantic' => '/api/v1/knowledge/semantic-search',
            'hybrid' => '/api/v1/knowledge/hybrid-search',
            default => '/api/v1/knowledge/search',
        };

        // Create internal sub-request
        $subRequest = \Illuminate\Http\Request::create($endpoint, 'POST', $validated);
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            Log::info('MCP knowledge search performed', [
                'tool' => 'search_knowledge',
                'user_id' => $user->id,
                'search_type' => $searchType,
                'query_length' => strlen($validated['query']),
                'results_count' => $data['results_count'] ?? 0,
            ]);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'search_knowledge',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Search failed: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')
            ->description('Search query text (required, max 500 characters)')
            ->required()

            ->integer('limit')
            ->description('Maximum number of results to return (1-100, default: 10)')
            ->optional()

            ->string('search_type')
            ->description('Type of search: fulltext, semantic, or hybrid (default: fulltext)')
            ->optional()

            ->string('content_type')
            ->description('Filter by document content type: text, file, or external')
            ->optional()

            ->raw('tags', [
                'type' => 'array',
                'description' => 'Filter by document tags (array of tag names)',
                'items' => ['type' => 'string'],
            ])

            ->boolean('include_expired')
            ->description('Include documents past their TTL expiration (default: false)')
            ->optional();
    }
}
