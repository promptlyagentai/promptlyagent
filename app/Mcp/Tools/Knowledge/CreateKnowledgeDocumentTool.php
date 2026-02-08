<?php

namespace App\Mcp\Tools\Knowledge;

use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Create Knowledge Document
 *
 * Create a new knowledge document from text content or external URL.
 * Supports tagging, privacy levels, and TTL expiration.
 *
 * Required Scope: knowledge:create
 */
class CreateKnowledgeDocumentTool extends Tool
{
    public function description(): string
    {
        return 'Create a new knowledge document from text content or external URL';
    }

    public function name(): string
    {
        return 'create_knowledge_document';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:create')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:create');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'content_type' => 'required|in:text,external',
            'content' => 'required_if:content_type,text|string|max:1000000',
            'external_source' => 'required_if:content_type,external|url',
            'tags' => 'array',
            'tags.*' => 'string|max:50',
            'privacy_level' => 'in:private,public',
            'ttl_hours' => 'nullable|integer|min:1|max:8760',
            'async' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        try {
            $knowledgeManager = app(KnowledgeManager::class);

            $document = match ($validated['content_type']) {
                'text' => $knowledgeManager->createFromText(
                    content: $validated['content'],
                    title: $validated['title'],
                    description: $validated['description'] ?? null,
                    tags: $validated['tags'] ?? [],
                    privacyLevel: $validated['privacy_level'] ?? 'private',
                    ttlHours: $validated['ttl_hours'] ?? null,
                    userId: $user->id
                ),
                'external' => $knowledgeManager->createFromExternal(
                    source: $validated['external_source'],
                    title: $validated['title'],
                    description: $validated['description'] ?? null,
                    tags: $validated['tags'] ?? [],
                    privacyLevel: $validated['privacy_level'] ?? 'private',
                    ttlHours: $validated['ttl_hours'] ?? null,
                    userId: $user->id
                ),
                default => throw new \InvalidArgumentException('Invalid content_type'),
            };

            Log::info('MCP knowledge document created', [
                'tool' => 'create_knowledge_document',
                'user_id' => $user->id,
                'document_id' => $document->id,
                'content_type' => $document->content_type,
                'privacy_level' => $document->privacy_level,
            ]);

            return ToolResult::json([
                'success' => true,
                'message' => 'Knowledge document created successfully',
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'content_type' => $document->content_type,
                    'privacy_level' => $document->privacy_level,
                    'created_at' => $document->created_at,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'create_knowledge_document',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to create document: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('title')
            ->description('Document title (required, max 255 characters)')
            ->required()

            ->string('description')
            ->description('Document description (optional, max 1000 characters)')
            ->optional()

            ->string('content_type')
            ->description('Type of content: text (direct content) or external (URL to fetch)')
            ->required()

            ->string('content')
            ->description('Text content of the document (required if content_type is "text", max 1MB)')
            ->optional()

            ->string('external_source')
            ->description('URL to fetch content from (required if content_type is "external")')
            ->optional()

            ->raw('tags', [
                'type' => 'array',
                'description' => 'Array of tag names to organize the document',
                'items' => ['type' => 'string', 'maxLength' => 50],
            ])

            ->string('privacy_level')
            ->description('Document visibility: private (only you) or public (all users)')
            ->optional()

            ->integer('ttl_hours')
            ->description('Time-to-live in hours (1-8760). Document expires after this period.')
            ->optional()

            ->boolean('async')
            ->description('Process document asynchronously (default: false)')
            ->optional();
    }
}
