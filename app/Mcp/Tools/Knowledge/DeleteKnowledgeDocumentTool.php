<?php

namespace App\Mcp\Tools\Knowledge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Delete Knowledge Document
 *
 * Permanently delete a knowledge document and all associated data
 * including embeddings and relationships.
 *
 * Required Scope: knowledge:delete
 */
class DeleteKnowledgeDocumentTool extends Tool
{
    public function description(): string
    {
        return 'Permanently delete a knowledge document by ID';
    }

    public function name(): string
    {
        return 'delete_knowledge_document';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:delete')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:delete');
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
            'DELETE'
        );
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            Log::info('MCP knowledge document deleted', [
                'tool' => 'delete_knowledge_document',
                'user_id' => $user->id,
                'document_id' => $validated['document_id'],
            ]);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'delete_knowledge_document',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to delete document: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('document_id')
            ->description('The ID of the knowledge document to delete (required)')
            ->required();
    }
}
