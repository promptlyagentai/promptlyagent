<?php

namespace App\Mcp\Tools\Knowledge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * List Knowledge Documents
 *
 * Retrieve a paginated list of knowledge documents with optional filtering
 * by content type, tags, status, and privacy level.
 *
 * Required Scope: knowledge:view
 */
class ListKnowledgeDocumentsTool extends Tool
{
    public function description(): string
    {
        return 'List knowledge documents with pagination and filtering options';
    }

    public function name(): string
    {
        return 'list_knowledge_documents';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:view')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:view');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'search' => 'string|max:255',
            'content_type' => 'string|in:text,file,external',
            'privacy_level' => 'string|in:private,public',
            'status' => 'string|in:pending,processing,completed,failed',
            'tags' => 'array',
            'tags.*' => 'string',
            'only_my_documents' => 'boolean',
            'include_expired' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        // Create internal sub-request
        $subRequest = \Illuminate\Http\Request::create('/api/v1/knowledge', 'GET', $validated);
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'list_knowledge_documents',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to list documents: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('page')
            ->description('Page number for pagination (default: 1)')
            ->optional()

            ->integer('per_page')
            ->description('Number of items per page (1-100, default: 20)')
            ->optional()

            ->string('search')
            ->description('Search query to filter documents by title, description, or content')
            ->optional()

            ->string('content_type')
            ->description('Filter by document content type: text, file, or external')
            ->optional()

            ->string('privacy_level')
            ->description('Filter by document privacy level: private or public')
            ->optional()

            ->string('status')
            ->description('Filter by document processing status: pending, processing, completed, or failed')
            ->optional()

            ->raw('tags', [
                'type' => 'array',
                'description' => 'Filter by document tags (array of tag names)',
                'items' => ['type' => 'string'],
            ])

            ->boolean('only_my_documents')
            ->description('Only show documents created by the authenticated user (default: false)')
            ->optional()

            ->boolean('include_expired')
            ->description('Include documents past their TTL expiration (default: false)')
            ->optional();
    }
}
