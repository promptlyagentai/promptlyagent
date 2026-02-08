<?php

namespace App\Tools;

use App\Models\KnowledgeDocument;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * ListKnowledgeDocumentsTool - Knowledge Document Discovery and Browsing.
 *
 * Prism tool for listing and browsing knowledge documents by metadata filtering.
 * Use this to discover available knowledge or list documents within specific
 * categories rather than searching for specific content (use knowledge_search for that).
 *
 * Filtering Options:
 * - tags: Filter by tag names (AND logic - all tags must match)
 * - document_type: Filter by type (file, text, external)
 * - status: Filter by processing status (pending, processing, completed, failed)
 * - privacy_level: Filter by privacy (private, public)
 * - agent_id: Filter by agent association
 * - limit: Maximum results to return (1-50, default: 20)
 *
 * Response Data:
 * - Document metadata (id, title, type, privacy)
 * - Tag list
 * - Processing status
 * - TTL information (expiration dates for external docs)
 * - Source information (file paths, URLs)
 *
 * Use Cases:
 * - Discovering available knowledge
 * - Listing documents by category/tag
 * - Checking document processing status
 * - Browsing agent-specific knowledge
 *
 * @see \App\Models\KnowledgeDocument
 * @see \App\Tools\KnowledgeRAGTool
 */
class ListKnowledgeDocumentsTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('list_knowledge_documents')
            ->for('List and browse knowledge documents by filtering on metadata like tags, document type, or status. Use this tool to see what documents are available rather than searching for specific content. Perfect for discovering available knowledge or listing documents within a specific category.')
            ->withNumberParameter('limit', 'Maximum number of documents to return (1-50, default: 20)')
            ->withArrayParameter('tags', 'Filter by specific tags (optional)', new StringSchema('tag', 'Tag name'), false)
            ->withStringParameter('source_type', 'Filter by document source type: file_upload, url, manual, api, external_source (optional)')
            ->withBooleanParameter('include_expired', 'Whether to include documents that have passed their TTL expiration date (default: false)')
            ->withStringParameter('sort_by', 'Sort order: recent (default), title, type')
            ->using(function (
                int $limit = 20,
                array $tags = [],
                ?string $source_type = null,
                bool $include_expired = false,
                string $sort_by = 'recent'
            ) {
                return static::executeListDocuments([
                    'limit' => $limit,
                    'tags' => $tags,
                    'source_type' => $source_type,
                    'include_expired' => $include_expired,
                    'sort_by' => $sort_by,
                ]);
            });
    }

    protected static function executeListDocuments(array $arguments = []): string
    {
        try {
            // Check if there's a status reporter available
            $statusReporter = null;
            $interactionId = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            // Report start
            if ($statusReporter) {
                $filterDesc = ! empty($arguments['tags']) ? 'with tags: '.implode(', ', $arguments['tags']) : 'all documents';
                $statusReporter->report('list_knowledge', "Listing knowledge documents ({$filterDesc})", true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'limit' => 'integer|min:1|max:50',
                'tags' => 'array',
                'tags.*' => 'string',
                'source_type' => 'nullable|in:file_upload,url,manual,api,external_source',
                'include_expired' => 'boolean',
                'sort_by' => 'in:recent,title,type',
            ]);

            if ($validator->fails()) {
                Log::warning('ListKnowledgeDocumentsTool: Validation failed', [
                    'interaction_id' => $interactionId,
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'ListKnowledgeDocumentsTool');
            }

            $validated = $validator->validated();

            // Start building query
            $query = KnowledgeDocument::query();

            // Knowledge scope filtering (same as search tool)
            // LEVEL 1: Scope tag restriction - documents must have ALL scope tags (AND logic)
            $scopeTags = [];
            if (app()->has('knowledge_scope_tags')) {
                $scopeTags = app('knowledge_scope_tags');
                if (is_array($scopeTags) && ! empty($scopeTags)) {
                    Log::info('ListKnowledgeDocumentsTool: Applying scope tag filtering', [
                        'interaction_id' => $interactionId,
                        'scope_tags' => $scopeTags,
                    ]);

                    // Filter to documents that have ALL of the scope tags (AND logic)
                    // This matches KnowledgeRAG's getDocumentIdsByTagNames($scopeTags, requireAll: true)
                    foreach ($scopeTags as $tagName) {
                        $query->whereHas('tags', function ($q) use ($tagName) {
                            $q->where('name', $tagName);
                        });
                    }
                }
            }

            // LEVEL 2: User-provided tag refinement - documents must have ALL user tags (AND logic)
            // This allows users to further filter within the scope-restricted universe
            if (! empty($validated['tags'])) {
                foreach ($validated['tags'] as $tagName) {
                    $query->whereHas('tags', function ($q) use ($tagName) {
                        $q->where('name', $tagName);
                    });
                }
            }

            // Filter by source type
            if (! empty($validated['source_type'])) {
                $query->where('source_type', $validated['source_type']);
            }

            // Handle expired documents
            if (! ($validated['include_expired'] ?? false)) {
                $query->where(function ($q) {
                    $q->whereNull('ttl_expires_at')
                        ->orWhere('ttl_expires_at', '>', now());
                });
            }

            // Apply sorting
            switch ($validated['sort_by'] ?? 'recent') {
                case 'title':
                    $query->orderBy('title');
                    break;
                case 'type':
                    $query->orderBy('source_type')->orderBy('created_at', 'desc');
                    break;
                case 'recent':
                default:
                    $query->orderBy('created_at', 'desc');
                    break;
            }

            // Apply limit
            $limit = $validated['limit'] ?? 20;
            $query->limit($limit);

            // Execute query with relationships
            $documents = $query->with('tags')->get();

            // Check if there are more documents available
            $totalCount = KnowledgeDocument::query()
                ->when(! empty($scopeTags), function ($q) use ($scopeTags) {
                    // Apply same AND logic for scope tags - documents must have ALL scope tags
                    foreach ($scopeTags as $tagName) {
                        $q->whereHas('tags', function ($tagQ) use ($tagName) {
                            $tagQ->where('name', $tagName);
                        });
                    }
                })
                ->when(! empty($validated['tags']), function ($q) use ($validated) {
                    foreach ($validated['tags'] as $tagName) {
                        $q->whereHas('tags', function ($tagQ) use ($tagName) {
                            $tagQ->where('name', $tagName);
                        });
                    }
                })
                ->when(! empty($validated['source_type']), function ($q) use ($validated) {
                    $q->where('source_type', $validated['source_type']);
                })
                ->when(! ($validated['include_expired'] ?? false), function ($q) {
                    $q->where(function ($subQ) {
                        $subQ->whereNull('ttl_expires_at')->orWhere('ttl_expires_at', '>', now());
                    });
                })
                ->count();

            // Report completion
            if ($statusReporter) {
                $statusReporter->report('list_knowledge', "Found {$documents->count()} knowledge documents", true, false);
            }

            // Format response
            $response = [
                'total_documents' => $totalCount,
                'returned_documents' => $documents->count(),
                'has_more' => $totalCount > $documents->count(),
                'filters_applied' => array_filter([
                    'tags' => $validated['tags'] ?? null,
                    'source_type' => $validated['source_type'] ?? null,
                    'include_expired' => $validated['include_expired'] ?? false,
                    'scope_tags' => ! empty($scopeTags) ? $scopeTags : null,
                ]),
                'documents' => $documents->map(function ($doc) {
                    $isExpired = $doc->ttl_expires_at && $doc->ttl_expires_at->isPast();

                    return [
                        'id' => $doc->id,
                        'title' => $doc->title,
                        'source_type' => $doc->source_type,
                        'description' => $doc->description,
                        'tags' => $doc->tags->pluck('name')->toArray(),
                        'is_expired' => $isExpired,
                        'expires_at' => $doc->ttl_expires_at?->toISOString(),
                        'created_at' => $doc->created_at->toISOString(),
                        'updated_at' => $doc->updated_at->toISOString(),
                    ];
                })->toArray(),
            ];

            return static::safeJsonEncode([
                'success' => true,
                'data' => $response,
            ], 'ListKnowledgeDocumentsTool');

        } catch (\Exception $e) {
            Log::error('ListKnowledgeDocumentsTool: Exception during execution', [
                'interaction_id' => $interactionId ?? null,
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to list documents: '.$e->getMessage(),
            ], 'ListKnowledgeDocumentsTool');
        }
    }
}
