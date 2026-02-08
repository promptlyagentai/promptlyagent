<?php

namespace App\Mcp\Tools\Knowledge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Get Knowledge Document
 *
 * Retrieve detailed information about a specific knowledge document
 * including content, metadata, tags, and embedding status.
 *
 * Required Scope: knowledge:view
 */
class GetKnowledgeDocumentTool extends Tool
{
    public function description(): string
    {
        return 'Get detailed information about a specific knowledge document by ID';
    }

    public function name(): string
    {
        return 'get_knowledge_document';
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
            'document_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        // Create internal sub-request
        $subRequest = \Illuminate\Http\Request::create(
            '/api/v1/knowledge/'.$validated['document_id'],
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
                'tool' => 'get_knowledge_document',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to get document: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('document_id')
            ->description('The ID of the knowledge document to retrieve (required)')
            ->required();
    }
}
