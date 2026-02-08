<?php

namespace App\Mcp\Tools\Knowledge;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Update Knowledge Document
 *
 * Update metadata, tags, privacy level, or TTL for an existing
 * knowledge document. Content cannot be modified.
 *
 * Required Scope: knowledge:update
 */
class UpdateKnowledgeDocumentTool extends Tool
{
    public function description(): string
    {
        return 'Update knowledge document metadata, tags, privacy level, or TTL';
    }

    public function name(): string
    {
        return 'update_knowledge_document';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:update')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:update');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'document_id' => 'required|integer',
            'title' => 'string|max:255',
            'description' => 'nullable|string|max:1000',
            'tags' => 'array',
            'tags.*' => 'string|max:50',
            'privacy_level' => 'in:private,public',
            'ttl_hours' => 'nullable|integer|min:1|max:8760',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        try {
            // Find document and verify ownership
            $document = KnowledgeDocument::where('created_by', $user->id)
                ->findOrFail($validated['document_id']);

            // Use KnowledgeManager to update the document
            $knowledgeManager = app(KnowledgeManager::class);

            $updateData = array_filter([
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'privacy_level' => $validated['privacy_level'] ?? null,
                'ttl_hours' => $validated['ttl_hours'] ?? null,
            ], fn ($value) => $value !== null);

            $updatedDocument = $knowledgeManager->updateDocument($document, $updateData);

            // Load relationships for complete response
            $updatedDocument->load(['creator', 'tags']);

            Log::info('MCP knowledge document updated', [
                'tool' => 'update_knowledge_document',
                'user_id' => $user->id,
                'document_id' => $updatedDocument->id,
                'updated_fields' => array_keys($updateData),
            ]);

            return ToolResult::json([
                'success' => true,
                'message' => 'Knowledge document updated successfully',
                'document' => [
                    'id' => $updatedDocument->id,
                    'title' => $updatedDocument->title,
                    'description' => $updatedDocument->description,
                    'privacy_level' => $updatedDocument->privacy_level,
                    'ttl_expires_at' => $updatedDocument->ttl_expires_at?->toISOString(),
                    'tags' => $updatedDocument->tags->map(fn ($tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                    ])->toArray(),
                    'updated_at' => $updatedDocument->updated_at->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('MCP knowledge document not found or unauthorized', [
                'tool' => 'update_knowledge_document',
                'user_id' => $user->id,
                'document_id' => $validated['document_id'],
            ]);

            return ToolResult::error('Document not found or you do not have permission to update it');
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'update_knowledge_document',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to update document: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('document_id')
            ->description('The ID of the document to update (required)')
            ->required()

            ->string('title')
            ->description('Updated document title (max 255 characters)')
            ->optional()

            ->string('description')
            ->description('Updated document description (max 1000 characters, nullable)')
            ->optional()

            ->raw('tags', [
                'type' => 'array',
                'description' => 'Updated array of tag names',
                'items' => ['type' => 'string', 'maxLength' => 50],
            ])

            ->string('privacy_level')
            ->description('Updated document visibility level: private or public')
            ->optional()

            ->integer('ttl_hours')
            ->description('Updated time-to-live in hours (1-8760, null to remove expiration)')
            ->optional();
    }
}
