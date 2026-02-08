<?php

namespace App\Mcp\Tools\Knowledge;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Manage Document Tags
 *
 * Add or sync tags to a knowledge document for better organization
 * and categorization. Tags help with filtering and searching.
 *
 * Required Scope: knowledge:update (via policy check)
 */
class ManageDocumentTagsTool extends Tool
{
    public function description(): string
    {
        return 'Add tags to a knowledge document for organization and filtering';
    }

    public function name(): string
    {
        return 'manage_document_tags';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization via token scope
        if (! $user || ! $user->tokenCan('knowledge:update')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:update');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'document_id' => 'required|integer',
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'integer|exists:knowledge_tags,id',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        try {
            // Find document and verify ownership
            $document = KnowledgeDocument::where('created_by', $user->id)
                ->findOrFail($validated['document_id']);

            // Sync tags (without detaching existing ones - additive behavior)
            $document->tags()->syncWithoutDetaching($validated['tag_ids']);

            // Load tags for response
            $document->load('tags');

            Log::info('MCP document tags updated', [
                'tool' => 'manage_document_tags',
                'user_id' => $user->id,
                'document_id' => $document->id,
                'tag_ids' => $validated['tag_ids'],
            ]);

            return ToolResult::json([
                'success' => true,
                'message' => 'Tags added to document successfully',
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'tags' => $document->tags->map(fn ($tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ])->toArray(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('MCP document not found or unauthorized for tagging', [
                'tool' => 'manage_document_tags',
                'user_id' => $user->id,
                'document_id' => $validated['document_id'],
            ]);

            return ToolResult::error('Document not found or you do not have permission to modify it');
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'manage_document_tags',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to manage document tags: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('document_id')
            ->description('The ID of the document to add tags to (required)')
            ->required()

            ->raw('tag_ids', [
                'type' => 'array',
                'description' => 'Array of tag IDs to add to the document (required). Use ListKnowledgeTagsTool to get available tag IDs.',
                'items' => ['type' => 'integer'],
            ]);
    }
}
