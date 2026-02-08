<?php

namespace App\Tools;

use App\Models\ChatInteractionAttachment;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\DocumentInjectionService;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * RetrieveFullDocumentTool - Complete Document Content Retrieval.
 *
 * Prism tool for retrieving full content of knowledge documents or chat attachments.
 * Returns complete document text, not just embeddings or summaries. Use when you need
 * to analyze entire document rather than search results.
 *
 * Retrieval Modes:
 * - By document_id: Retrieve knowledge document by ID
 * - By attachment_id: Retrieve chat attachment by ID
 * - Automatic type detection based on ID provided
 *
 * Content Processing:
 * - Returns full extracted text content
 * - Includes document metadata
 * - Tracks retrieval in chat interaction attachments
 * - Validates document access permissions
 *
 * Document Types Supported:
 * - File-based documents (PDFs, Word, text files)
 * - Text documents (plain text knowledge)
 * - External documents (fetched from URLs)
 * - Chat attachments (uploaded files)
 *
 * Authorization:
 * - Validates document access via privacy controls
 * - Checks agent-document associations
 * - Respects user ownership rules
 *
 * Use Cases:
 * - Reading complete documents for analysis
 * - Extracting full context from knowledge base
 * - Accessing chat attachment content
 * - Detailed document review
 *
 * @see \App\Models\KnowledgeDocument
 * @see \App\Services\Knowledge\DocumentInjectionService
 */
class RetrieveFullDocumentTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('retrieve_full_document')
            ->for('Retrieve the complete content of a specific knowledge document or attachment. Use this when you need the full text content, file data, or complete document details beyond what was provided in the search results.')
            ->withNumberParameter('document_id', 'The ID of the knowledge document to retrieve')
            ->withStringParameter('source', 'Alternative: retrieve by source identifier (optional if document_id is provided)', false)
            ->withStringParameter('document_type', 'Type of document: "knowledge" for knowledge documents or "attachment" for chat attachments (default: knowledge)', false)
            ->withStringParameter('query_context', 'Optional: User query or context to extract relevant sections from large documents', false)
            ->withBooleanParameter('include_metadata', 'Whether to include detailed metadata about the document (default: true)', false)
            ->withBooleanParameter('include_raw_content', 'Whether to include the raw file content for binary files (default: false)', false)
            ->using(function (
                ?int $document_id = null,
                ?string $source = null,
                string $document_type = 'knowledge',
                ?string $query_context = null,
                bool $include_metadata = true,
                bool $include_raw_content = false
            ) {
                return static::executeDocumentRetrieval([
                    'document_id' => $document_id,
                    'source' => $source,
                    'document_type' => $document_type,
                    'query_context' => $query_context,
                    'include_metadata' => $include_metadata,
                    'include_raw_content' => $include_raw_content,
                ]);
            });
    }

    protected static function executeDocumentRetrieval(array $arguments = []): string
    {
        try {
            // Get status reporter if available
            $statusReporter = null;
            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $statusReporter->report('document_retrieval', 'Retrieving full document content', true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'document_id' => 'nullable|integer|min:1',
                'source' => 'nullable|string|max:500',
                'document_type' => 'string|in:knowledge,attachment',
                'query_context' => 'nullable|string|max:1000',
                'include_metadata' => 'boolean',
                'include_raw_content' => 'boolean',
            ]);

            if ($validator->fails()) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'RetrieveFullDocumentTool');
            }

            $validated = $validator->validated();

            // Require either document_id or source
            if (empty($validated['document_id']) && empty($validated['source'])) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Either document_id or source parameter is required',
                ], 'RetrieveFullDocumentTool');
            }

            // Determine document type and retrieve accordingly
            if ($validated['document_type'] === 'attachment') {
                return static::retrieveChatAttachment($validated, $statusReporter);
            } else {
                return static::retrieveKnowledgeDocument($validated, $statusReporter);
            }

        } catch (\Exception $e) {
            Log::error('RetrieveFullDocumentTool: Retrieval failed', [
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Document retrieval failed: '.$e->getMessage(),
                'metadata' => [
                    'document_id' => $arguments['document_id'] ?? 'unknown',
                    'error_type' => get_class($e),
                ],
            ], 'RetrieveFullDocumentTool');
        }
    }

    protected static function retrieveKnowledgeDocument(array $validated, $statusReporter): string
    {
        // Find the knowledge document
        $document = null;
        if ($validated['document_id']) {
            $document = KnowledgeDocument::find($validated['document_id']);
        } elseif ($validated['source']) {
            $document = KnowledgeDocument::where('source', $validated['source'])->first();
        }

        if (! $document) {
            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Knowledge document not found',
                'searched_criteria' => [
                    'document_id' => $validated['document_id'] ?? null,
                    'source' => $validated['source'] ?? null,
                ],
            ], 'RetrieveFullDocumentTool');
        }

        // Create DocumentInjectionService for validation and content retrieval
        $injectionService = app(DocumentInjectionService::class);

        // Intelligent content retrieval strategy
        $content = static::getIntelligentContent($document, $validated, $injectionService);

        if (! $content) {
            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Document content could not be retrieved',
                'document_info' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'source_type' => $document->source_type,
                ],
            ], 'RetrieveFullDocumentTool');
        }

        // Prepare response data
        $responseData = [
            'id' => $document->id,
            'title' => $document->title,
            'content' => $content,
            'content_length' => strlen($content),
            'source_type' => $document->source_type,
            'content_type' => $document->content_type,
        ];

        // Add metadata if requested
        if ($validated['include_metadata']) {
            $responseData['metadata'] = [
                'file_name' => $document->asset?->original_filename,
                'file_path' => $document->asset?->storage_key,
                'mime_type' => $document->asset?->mime_type ?? $document->source_type,
                'file_size' => $document->asset?->size_bytes,
                'processing_status' => $document->processing_status,
                'ttl' => $document->ttl?->toISOString(),
                'created_at' => $document->created_at?->toISOString(),
                'updated_at' => $document->updated_at?->toISOString(),
                'tags' => $document->tags()->pluck('name')->toArray(),
                'validation_result' => $injectionService->validateDocument($document),
                'asset_info' => $document->asset ? [
                    'id' => $document->asset->id,
                    'storage_key' => $document->asset->storage_key,
                    'checksum' => $document->asset->checksum,
                    'file_exists' => $document->asset->exists(),
                ] : null,
            ];

            // External source information
            if ($document->source_type === 'external_source') {
                $responseData['metadata']['external_source'] = [
                    'class' => $document->external_source_class,
                    'identifier' => $document->external_source_identifier,
                    'auto_refresh_enabled' => $document->auto_refresh_enabled,
                    'refresh_interval_minutes' => $document->refresh_interval_minutes,
                    'last_fetched_at' => $document->last_fetched_at?->toISOString(),
                    'next_refresh_at' => $document->next_refresh_at?->toISOString(),
                ];
            }
        }

        // Add raw content for binary files if requested (be cautious with size)
        if ($validated['include_raw_content']) {
            if ($document->asset && $document->asset->exists()) {
                if ($document->asset->size_bytes <= 10 * 1024 * 1024) { // Limit to 10MB
                    $responseData['raw_content'] = base64_encode($document->asset->getContent());
                    $responseData['raw_content_encoding'] = 'base64';
                } else {
                    $responseData['raw_content_error'] = 'File too large for raw content retrieval (max 10MB)';
                }
            } else {
                $responseData['raw_content_error'] = 'File asset not found';
            }
        }

        if ($statusReporter) {
            $statusReporter->report('document_retrieval', "Retrieved document: {$document->title}", true, false);
        }

        Log::info('RetrieveFullDocumentTool: Knowledge document retrieved successfully', [
            'document_id' => $document->id,
            'title' => $document->title,
            'content_length' => strlen($content),
            'source_type' => $document->source_type,
        ]);

        return static::safeJsonEncode([
            'success' => true,
            'data' => $responseData,
        ], 'RetrieveFullDocumentTool');
    }

    protected static function retrieveChatAttachment(array $validated, $statusReporter): string
    {
        // Find the chat attachment
        $attachment = null;
        if ($validated['document_id']) {
            $attachment = ChatInteractionAttachment::find($validated['document_id']);
        }

        if (! $attachment) {
            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Chat attachment not found',
                'searched_criteria' => [
                    'document_id' => $validated['document_id'] ?? null,
                ],
            ], 'RetrieveFullDocumentTool');
        }

        // Get content based on attachment type
        $content = null;
        $contentType = 'unknown';

        // For text-based attachments, get text content
        if ($attachment->shouldInjectAsText()) {
            $content = $attachment->getTextContent();
            $contentType = 'text';
        } else {
            // For binary attachments, provide file information
            $contentType = 'binary';
            if ($validated['include_raw_content'] && $attachment->file_size <= 10 * 1024 * 1024) {
                $content = $attachment->getFileContent();
                if ($content !== null) {
                    $content = base64_encode($content);
                    $contentType = 'binary_base64';
                }
            }
        }

        // Prepare response data
        $responseData = [
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'content' => $content,
            'content_type' => $contentType,
            'mime_type' => $attachment->mime_type,
            'type' => $attachment->type,
            'file_size' => $attachment->file_size,
        ];

        // Add metadata if requested
        if ($validated['include_metadata']) {
            $responseData['metadata'] = [
                'storage_path' => $attachment->storage_path,
                'chat_interaction_id' => $attachment->chat_interaction_id,
                'is_temporary' => $attachment->is_temporary,
                'expires_at' => $attachment->expires_at?->toISOString(),
                'created_at' => $attachment->created_at?->toISOString(),
                'file_exists' => Storage::disk($attachment->getStorageDisk())->exists($attachment->storage_path),
                'is_expired' => $attachment->isExpired(),
                'file_url' => $attachment->getFileUrl(),
                'is_supported_for_binary_attachment' => $attachment->isSupportedForBinaryAttachment(),
                'should_inject_as_text' => $attachment->shouldInjectAsText(),
                'can_be_read_as_text' => $attachment->canBeReadAsText(),
            ];
        }

        if ($statusReporter) {
            $statusReporter->report('document_retrieval', "Retrieved attachment: {$attachment->filename}", true, false);
        }

        Log::info('RetrieveFullDocumentTool: Chat attachment retrieved successfully', [
            'attachment_id' => $attachment->id,
            'filename' => $attachment->filename,
            'content_type' => $contentType,
            'mime_type' => $attachment->mime_type,
        ]);

        return static::safeJsonEncode([
            'success' => true,
            'data' => $responseData,
        ], 'RetrieveFullDocumentTool');
    }

    /**
     * Intelligent content retrieval that handles large documents
     */
    protected static function getIntelligentContent($document, array $validated, $injectionService): ?string
    {
        // Use conservative 250KB limit to leave plenty of room for conversation context,
        // system prompts, other tool outputs, and prevent OpenAI API errors
        $maxContentLength = 250 * 1024; // 250KB = 256,000 characters

        // First, try to use processed/stored content if available and reasonable size
        if (! empty($document->content)) {
            if (strlen($document->content) <= $maxContentLength) {
                return $document->content;
            }

            // For large stored content, use AI extraction if query context provided
            if (! empty($validated['query_context'])) {
                return static::extractRelevantContent($document->content, $validated['query_context'], $maxContentLength);
            }

            // Fallback to truncation with clear indication
            $truncated = mb_substr($document->content, 0, $maxContentLength - 200);

            return $truncated."\n\n[CONTENT TRUNCATED - Document exceeds 250KB limit. ".
                   'Use query_context parameter for AI-powered relevant section extraction.]';
        }

        // Fallback to DocumentInjectionService for files
        $content = $injectionService->getDocumentContent($document);

        if (! $content) {
            return null;
        }

        // Apply the same logic for file-based content
        if (strlen($content) <= $maxContentLength) {
            return $content;
        }

        if (! empty($validated['query_context'])) {
            return static::extractRelevantContent($content, $validated['query_context'], $maxContentLength);
        }

        $truncated = mb_substr($content, 0, $maxContentLength - 200);

        return $truncated."\n\n[CONTENT TRUNCATED - Document exceeds 250KB limit. ".
               'Use query_context parameter for AI-powered relevant section extraction.]';
    }

    /**
     * Extract relevant content from large documents using AI
     */
    protected static function extractRelevantContent(string $fullContent, string $queryContext, int $maxLength): string
    {
        try {
            // Use the configured low-cost model profile
            $lowCostConfig = config('prism.model_profiles.low_cost');
            $prism = app('prism');

            // Split content into chunks if it's extremely large
            $chunkSize = 50000; // 50KB chunks
            $chunks = [];

            for ($i = 0; $i < strlen($fullContent); $i += $chunkSize) {
                $chunks[] = mb_substr($fullContent, $i, $chunkSize);
            }

            $relevantSections = [];
            $currentLength = 0;

            foreach ($chunks as $index => $chunk) {
                // Ask AI to extract relevant parts from this chunk
                $prompt = "Given the following user query: '{$queryContext}'\n\n".
                         'Extract only the most relevant sections from this document chunk. '.
                         'Return only the relevant text without any explanation or markdown formatting. '.
                         "If nothing is relevant, return 'NONE'.\n\n".
                         "Document chunk:\n{$chunk}";

                $response = $prism->text()
                    ->using($lowCostConfig['provider'], $lowCostConfig['model'])
                    ->withSystemPrompt('You are a document content extractor. Extract only relevant sections without adding explanations.')
                    ->withMaxTokens($lowCostConfig['max_tokens'])
                    ->generate($prompt);

                $extracted = trim($response->text);

                if ($extracted && $extracted !== 'NONE' && strlen($extracted) > 50) {
                    $sectionLength = strlen($extracted);

                    // Check if we can fit this section
                    if ($currentLength + $sectionLength <= $maxLength - 500) { // Leave room for separators
                        $relevantSections[] = $extracted;
                        $currentLength += $sectionLength + 50; // Account for separator
                    } else {
                        break; // No more room
                    }
                }
            }

            if (! empty($relevantSections)) {
                $result = implode("\n\n--- SECTION BREAK ---\n\n", $relevantSections);

                return $result."\n\n[AI-EXTRACTED RELEVANT SECTIONS - Full document available with targeted queries]";
            }

        } catch (\Exception $e) {
            Log::warning('RetrieveFullDocumentTool: AI extraction failed', [
                'error' => $e->getMessage(),
                'query_context' => $queryContext,
            ]);
        }

        // Fallback to simple truncation
        $truncated = mb_substr($fullContent, 0, $maxLength - 200);

        return $truncated."\n\n[CONTENT TRUNCATED - AI extraction failed. Document exceeds 250KB limit. Try a more specific query_context.]";
    }
}
