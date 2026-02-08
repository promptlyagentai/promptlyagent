<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;

/**
 * Knowledge Document Injection Service
 *
 * Converts KnowledgeDocument models to Prism Document objects for AI provider consumption.
 * Handles provider compatibility, content type detection, MIME type mapping, and document batch processing.
 *
 * Architecture:
 * - Provider Compatibility: Validates document types against provider capabilities (OpenAI, Anthropic, etc.)
 * - Content Strategy: Determines optimal encoding (base64 vs raw text) based on file type
 * - Multi-source Support: Handles uploaded files (via Asset), external URLs, and direct text input
 * - Auto-refresh: Manages TTL-based content refresh for external sources
 *
 * Document Source Types:
 * - file_upload/uploaded_file: Files stored via Asset model with storage_key
 * - external_source/external: External URLs with auto-refresh and caching
 * - text/manual/direct_input: Plain text content stored directly
 *
 * Provider Compatibility Matrix:
 * - OpenAI: PDF, text formats, JSON, HTML, CSV, Markdown
 * - Anthropic: All OpenAI formats + PNG, JPEG, GIF, WebP images
 * - Fallback: Incompatible documents converted to plain text
 *
 * @see \App\Models\KnowledgeDocument
 * @see \Prism\Prism\ValueObjects\Media\Document
 */
class DocumentInjectionService
{
    private array $textExtensions;

    private array $textMimeTypes;

    private array $providerSupportedTypes;

    public function __construct()
    {
        $this->textExtensions = config('knowledge.injection.text_extensions', ['txt', 'md', 'csv']);
        $this->textMimeTypes = config('knowledge.injection.text_mime_types', [
            'text/plain',
            'text/markdown',
            'text/csv',
            'text/html',
            'application/json',
        ]);
        $this->providerSupportedTypes = config('knowledge.injection.provider_supported_types', [
            'openai' => [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/csv',
                'text/html',
                'application/json',
            ],
            'anthropic' => [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/csv',
                'text/html',
                'application/json',
                'image/png',
                'image/jpeg',
                'image/gif',
                'image/webp',
            ],
        ]);
    }

    public function convertKnowledgeDocumentToPrismDocument(KnowledgeDocument $document, ?\Prism\Prism\Enums\Provider $provider = null): ?Document
    {
        try {
            // Get the actual content based on source type
            $content = $this->getDocumentContent($document);

            if (! $content) {
                Log::warning('No content available for knowledge document', [
                    'document_id' => $document->id,
                    'source_type' => $document->source_type,
                    'asset_id' => $document->asset_id,
                    'external_identifier' => $document->external_source_identifier,
                ]);

                return null;
            }

            // Check provider compatibility - if not compatible, fall back to text content
            if ($provider && ! $this->isDocumentCompatibleWithProvider($document, $provider)) {
                Log::info('Document not natively supported by provider, falling back to text content', [
                    'document_id' => $document->id,
                    'document_title' => $document->title,
                    'provider' => $provider->value,
                    'mime_type' => $this->getMimeType($document),
                ]);

                // Fall back to text content instead of returning null
                return Document::fromRawContent(
                    rawContent: $document->content ?? $content,
                    mimeType: 'text/plain',
                    title: $document->title
                );
            }

            if ($this->shouldUseBase64($document)) {
                $base64Content = base64_encode($content);
                $mimeType = $this->getMimeType($document);

                return Document::fromBase64(
                    document: $base64Content,
                    mimeType: $mimeType,
                    title: $document->title
                );
            }

            return Document::fromRawContent(
                rawContent: $content,
                mimeType: 'text/plain',
                title: $document->title
            );
        } catch (\Exception $e) {
            Log::error('Failed to convert knowledge document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function getDocumentContent(KnowledgeDocument $document): ?string
    {
        // Check for Asset relationship for file documents
        if ($document->asset && $document->asset->exists()) {
            return $document->asset->getContent();
        }

        // Handle different source types
        switch ($document->source_type) {
            case 'file_upload':
            case 'uploaded_file':
                // Fallback to content field if asset not found
                Log::warning('File asset not found, falling back to content field', [
                    'document_id' => $document->id,
                    'asset_id' => $document->asset_id,
                ]);

                return $document->content;

            case 'external_source':
            case 'external':
                // For external sources, check if we need to refresh
                if ($this->shouldRefreshExternalContent($document)) {
                    $content = $this->fetchExternalContent($document);
                    if ($content) {
                        // Update the document with fresh content
                        $document->update([
                            'content' => $content,
                            'last_fetched_at' => now(),
                            'next_refresh_at' => $document->refresh_interval_minutes
                                ? now()->addMinutes($document->refresh_interval_minutes)
                                : null,
                        ]);

                        return $content;
                    }
                }

                // Use cached content
                return $document->content;

            case 'text':
            case 'manual':
            case 'knowledge_manager':
            case 'direct_input':
            default:
                // Use content field directly
                return $document->content;
        }
    }

    private function shouldRefreshExternalContent(KnowledgeDocument $document): bool
    {
        // Check if auto-refresh is enabled
        if (! $document->auto_refresh_enabled) {
            return false;
        }

        // Check if it's time to refresh
        if ($document->next_refresh_at && $document->next_refresh_at->isFuture()) {
            return false;
        }

        // Check if we have a last_fetched_at timestamp
        if (! $document->last_fetched_at) {
            return true; // Never fetched, should refresh
        }

        // Check if content is older than refresh interval
        if ($document->refresh_interval_minutes) {
            $refreshThreshold = $document->last_fetched_at->addMinutes($document->refresh_interval_minutes);

            return now()->isAfter($refreshThreshold);
        }

        return false;
    }

    private function fetchExternalContent(KnowledgeDocument $document): ?string
    {
        // Use the external source class if available
        if ($document->external_source_class && class_exists($document->external_source_class)) {
            try {
                $sourceClass = app($document->external_source_class);
                if (method_exists($sourceClass, 'fetchContent')) {
                    Log::info('Fetching content from external source', [
                        'document_id' => $document->id,
                        'source_class' => $document->external_source_class,
                        'identifier' => $document->external_source_identifier,
                    ]);

                    return $sourceClass->fetchContent($document->external_source_identifier);
                }
            } catch (\Exception $e) {
                Log::error('Failed to fetch external content', [
                    'document_id' => $document->id,
                    'source_class' => $document->external_source_class,
                    'identifier' => $document->external_source_identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: if we have an external_source_identifier that looks like a URL, try to fetch it
        if ($document->external_source_identifier && filter_var($document->external_source_identifier, FILTER_VALIDATE_URL)) {
            try {
                Log::info('Attempting direct URL fetch for external source', [
                    'document_id' => $document->id,
                    'url' => $document->external_source_identifier,
                ]);

                $response = file_get_contents($document->external_source_identifier);

                return $response !== false ? $response : null;
            } catch (\Exception $e) {
                Log::error('Failed to fetch content from URL', [
                    'document_id' => $document->id,
                    'url' => $document->external_source_identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function shouldUseBase64(KnowledgeDocument $document): bool
    {
        // Check file extension first
        if ($document->asset && $document->asset->original_filename) {
            $extension = strtolower(pathinfo($document->asset->original_filename, PATHINFO_EXTENSION));
            if (in_array($extension, $this->textExtensions)) {
                return false;
            }
        }

        // Check MIME type
        $mimeType = $this->getMimeType($document);
        if (in_array($mimeType, $this->textMimeTypes)) {
            return false;
        }

        // Check content type field
        if ($document->content_type === 'text') {
            return false;
        }

        // If it's a manual/direct input document, likely text
        if (in_array($document->source_type, ['text', 'manual', 'knowledge_manager', 'direct_input'])) {
            return false;
        }

        // Default to base64 for binary/unknown formats
        return true;
    }

    public function getMimeType(KnowledgeDocument $document): string
    {
        // First check Asset MIME type if available
        if ($document->asset && $document->asset->mime_type) {
            return $document->asset->mime_type;
        }

        // Determine from file extension if Asset has filename
        if ($document->asset && $document->asset->original_filename) {
            $extension = pathinfo($document->asset->original_filename, PATHINFO_EXTENSION);

            return match (strtolower($extension)) {
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'md' => 'text/markdown',
                'csv' => 'text/csv',
                'html' => 'text/html',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'odt' => 'application/vnd.oasis.opendocument.text',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
                'rtf' => 'application/rtf',
                'xml' => 'application/xml',
                default => 'application/octet-stream'
            };
        }

        // Check content_type field for text documents
        if ($document->content_type === 'text') {
            return 'text/plain';
        }

        // Check source type for hint
        if (in_array($document->source_type, ['text', 'manual', 'knowledge_manager'])) {
            return 'text/plain';
        }

        return 'application/octet-stream';
    }

    public function createDocumentBatch(Collection $documents, ?\Prism\Prism\Enums\Provider $provider = null): array
    {
        $maxDocuments = config('knowledge.injection.max_documents', 10);
        $prismDocuments = [];
        $skippedCount = 0;

        foreach ($documents->take($maxDocuments) as $doc) {
            $prismDoc = $this->convertKnowledgeDocumentToPrismDocument($doc, $provider);
            if ($prismDoc) {
                $prismDocuments[] = $prismDoc;
            } else {
                $skippedCount++;
            }
        }

        Log::info('Document batch created', [
            'total_input' => $documents->count(),
            'successful_conversions' => count($prismDocuments),
            'skipped_documents' => $skippedCount,
            'provider' => $provider?->value,
        ]);

        return $prismDocuments;
    }

    /**
     * Validate if a document can be properly converted
     */
    public function validateDocument(KnowledgeDocument $document): array
    {
        $issues = [];

        // Check if content is accessible
        $content = $this->getDocumentContent($document);
        if (! $content) {
            $issues[] = 'No content available';
        }

        // Check file path existence for uploads
        if (in_array($document->source_type, ['file_upload', 'uploaded_file'])) {
            if (! $document->asset) {
                $issues[] = 'File upload missing asset';
            } elseif (! $document->asset->exists()) {
                $issues[] = 'File asset not found: '.$document->asset->storage_key;
            }
        }

        // Check external source configuration
        if (in_array($document->source_type, ['external_source', 'external'])) {
            if (! $document->external_source_identifier) {
                $issues[] = 'External source missing identifier';
            }

            if (! $document->external_source_class && ! filter_var($document->external_source_identifier, FILTER_VALIDATE_URL)) {
                $issues[] = 'External source missing class and identifier is not a valid URL';
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
        ];
    }

    /**
     * Check if a document is compatible with the specified AI provider
     */
    public function isDocumentCompatibleWithProvider(KnowledgeDocument $document, \Prism\Prism\Enums\Provider $provider): bool
    {
        $mimeType = $this->getMimeType($document);
        $providerKey = strtolower($provider->value);

        // Get supported types for this provider
        $supportedTypes = $this->providerSupportedTypes[$providerKey] ?? [];

        // If no restrictions defined, allow all
        if (empty($supportedTypes)) {
            return true;
        }

        // Check if mime type is supported
        return in_array($mimeType, $supportedTypes);
    }

    /**
     * Get compatible documents for a specific provider
     */
    public function filterDocumentsForProvider(Collection $documents, \Prism\Prism\Enums\Provider $provider): Collection
    {
        return $documents->filter(function (KnowledgeDocument $document) use ($provider) {
            return $this->isDocumentCompatibleWithProvider($document, $provider);
        });
    }
}
