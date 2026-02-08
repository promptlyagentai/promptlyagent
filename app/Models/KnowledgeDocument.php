<?php

namespace App\Models;

use App\Scout\Traits\HasVectorSearch;
use App\Services\Knowledge\DocumentInjectionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Media\Document;

/**
 * Knowledge Document Model
 *
 * Central model for the RAG (Retrieval-Augmented Generation) knowledge system.
 * Supports multiple content types and source strategies with automatic processing,
 * embedding generation, and semantic search capabilities.
 *
 * **Content Types:**
 * - text: Direct text input or markdown content
 * - file: Uploaded documents (PDF, DOCX, MD, TXT, etc.)
 * - external: Web URLs with automatic fetching and refresh
 *
 * **External Source Management:**
 * - Auto-refresh scheduling with configurable intervals
 * - Content hash tracking for change detection
 * - Retry logic with exponential backoff
 * - Integration with external data sources (APIs, webhooks)
 *
 * **RAG Pipeline:**
 * 1. Content ingestion (text/file/external)
 * 2. Text extraction and processing
 * 3. Embedding generation via OpenAI/Anthropic
 * 4. Indexing in Meilisearch with hybrid search
 * 5. Retrieval during agent execution
 *
 * **Prism Integration:**
 * - Converts to Prism Document objects for AI context injection
 * - Supports base64 encoding for binary files
 * - Provider-specific optimization (OpenAI, Anthropic, etc.)
 *
 * **Metadata Structure:**
 *
 * @property array{notes?: string, source_url?: string, ...}|null $metadata General metadata storage
 * @property array{title?: string, description?: string, author?: string, ...}|null $external_metadata Metadata from external sources
 * @property array{url?: string, headers?: array, auth?: array, ...}|null $external_source_config External source configuration
 */
class KnowledgeDocument extends Model
{
    use HasFactory, HasVectorSearch;

    protected $fillable = [
        'asset_id',
        'title',
        'description',
        'content_type',
        'source_type',
        'content',
        'meilisearch_document_id',
        'privacy_level',
        'processing_status',
        'processing_error',
        'ttl_expires_at',
        'metadata',
        'created_by',
        // External knowledge source fields
        'external_source_identifier',
        'external_source_class',
        'last_fetched_at',
        'content_hash',
        'auto_refresh_enabled',
        'next_refresh_at',
        'refresh_interval_minutes',
        'external_metadata',
        'favicon_url',
        'thumbnail_url',
        'author',
        'language',
        'published_at',
        'last_modified_at',
        'word_count',
        'reading_time_minutes',
        // Refresh tracking fields
        'last_refresh_attempted_at',
        'last_refresh_status',
        'last_refresh_error',
        'refresh_attempt_count',
        // Integration fields
        'integration_id',
        'external_source_config',
    ];

    protected $casts = [
        'metadata' => 'array',
        'external_metadata' => 'array',
        'external_source_config' => 'array',
        'ttl_expires_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        'next_refresh_at' => 'datetime',
        'published_at' => 'datetime',
        'last_modified_at' => 'datetime',
        'word_count' => 'integer',
        'reading_time_minutes' => 'integer',
        'refresh_interval_minutes' => 'integer',
        'auto_refresh_enabled' => 'boolean',
        'last_refresh_attempted_at' => 'datetime',
        'refresh_attempt_count' => 'integer',
    ];

    protected $attributes = [
        'content_type' => 'text',
        'privacy_level' => 'private',
        'processing_status' => 'pending',
    ];

    // Relationships
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeTag::class, 'knowledge_document_tags');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(KnowledgeGroup::class);
    }

    public function agentAssignments(): HasMany
    {
        return $this->hasMany(AgentKnowledgeAssignment::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('processing_status', 'completed');
    }

    public function scopePublic($query)
    {
        return $query->where('privacy_level', 'public');
    }

    public function scopePrivate($query)
    {
        return $query->where('privacy_level', 'private');
    }

    public function scopeForUser($query, int $userId)
    {
        $user = User::find($userId);

        // SECURITY: Defense in depth - explicit admin verification
        // Verify user exists AND has admin flag
        if ($user && $user->isAdmin()) {
            // Audit log: Track admin access to all documents for security monitoring
            Log::info('Admin user accessing all knowledge documents via scopeForUser', [
                'user_id' => $userId,
                'user_email' => $user->email,
                'is_admin' => true,
                'query_context' => 'unrestricted_access',
            ]);

            return $query;
        }

        // SECURITY: Non-admin users - strict privacy enforcement
        // Only allow access to:
        // 1. Public documents (privacy_level = 'public')
        // 2. User's own private documents (created_by = user_id)
        return $query->where(function ($q) use ($userId) {
            $q->where('privacy_level', 'public')
                ->orWhere(function ($inner) use ($userId) {
                    // Explicit: private documents owned by this user
                    $inner->where('privacy_level', 'private')
                        ->where('created_by', $userId);
                });
        });
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('ttl_expires_at')
                ->orWhere('ttl_expires_at', '>', now());
        });
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('ttl_expires_at')
            ->where('ttl_expires_at', '<=', now());
    }

    public function scopeRefreshable($query)
    {
        return $query->whereIn('content_type', ['external', 'text'])
            ->whereNotNull('external_source_identifier')
            ->where('auto_refresh_enabled', true);
    }

    // Accessors & Mutators
    public function getIsExpiredAttribute(): bool
    {
        return $this->ttl_expires_at && now()->isAfter($this->ttl_expires_at);
    }

    public function getIsFileAttribute(): bool
    {
        return $this->content_type === 'file';
    }

    public function getIsTextAttribute(): bool
    {
        return $this->content_type === 'text';
    }

    public function getIsExternalAttribute(): bool
    {
        return $this->content_type === 'external';
    }

    public function getIsProcessedAttribute(): bool
    {
        return $this->processing_status === 'completed';
    }

    public function getWordCountAttribute(): ?int
    {
        if (! $this->content) {
            return null;
        }

        return str_word_count(strip_tags($this->content));
    }

    // Backward-compatible accessors for file fields (now handled by Asset relationship)
    public function getFilePathAttribute(): ?string
    {
        return $this->asset?->storage_key;
    }

    public function getFileNameAttribute(): ?string
    {
        return $this->asset?->original_filename;
    }

    public function getFileSizeAttribute(): ?int
    {
        return $this->asset?->size_bytes;
    }

    public function getMimeTypeFromAssetAttribute(): ?string
    {
        return $this->asset?->mime_type;
    }

    public function getNotesAttribute(): ?string
    {
        return $this->attributes['metadata']['notes'] ?? $this->metadata['notes'] ?? null;
    }

    public function setNotesAttribute(?string $value): void
    {
        $metadata = $this->metadata ?? [];
        if ($value !== null) {
            $metadata['notes'] = $value;
        } else {
            unset($metadata['notes']);
        }
        $this->attributes['metadata'] = json_encode($metadata);
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'knowledge_documents';
    }

    // Scout/Meilisearch integration

    public function shouldBeSearchable(): bool
    {
        return $this->processing_status === 'completed';
    }

    public function getScoutKey()
    {
        return $this->meilisearch_document_id ?: "doc_{$this->id}";
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        $array = [
            'document_id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'content' => $this->content,
            'tags' => $this->tags->pluck('name')->toArray(),
            'privacy_level' => $this->privacy_level,
            'content_type' => $this->content_type,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'word_count' => $this->word_count,
            'author' => $this->author,
            'language' => $this->language,
        ];

        // Include embedding if available from trait
        if ($embedding = $this->getEmbedding()) {
            $array['_vectors'] = ['default' => $embedding];
        }

        return $array;
    }

    // Helper methods
    public function canAccess(User $user): bool
    {
        // Admins can access all documents
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->privacy_level === 'public') {
            return true;
        }

        if ($this->privacy_level === 'private') {
            return $this->created_by === $user->id;
        }

        return false;
    }

    public function markAsProcessed(): void
    {
        $this->update(['processing_status' => 'completed']);

        Log::info('Knowledge document processing completed', [
            'document_id' => $this->id,
            'title' => $this->title,
            'content_type' => $this->content_type,
            'word_count' => $this->word_count,
        ]);
    }

    public function markAsFailedProcessing(string $error): void
    {
        $this->update([
            'processing_status' => 'failed',
            'processing_error' => $error,
        ]);

        Log::error('Knowledge document processing failed', [
            'document_id' => $this->id,
            'title' => $this->title,
            'content_type' => $this->content_type,
            'error' => $error,
            'created_by' => $this->created_by,
        ]);
    }

    public function setTTL(int $hours): void
    {
        $this->update([
            'ttl_expires_at' => now()->addHours($hours),
        ]);
    }

    // Prism Document Integration Methods

    /**
     * Convert this knowledge document to a Prism Document object
     */
    public function toPrismDocument(?Provider $provider = null): ?Document
    {
        $injectionService = app(DocumentInjectionService::class);

        try {
            return $injectionService->convertKnowledgeDocumentToPrismDocument($this, $provider);
        } catch (\Exception $e) {
            Log::warning('Failed to convert knowledge document to Prism document', [
                'document_id' => $this->id,
                'title' => $this->title,
                'content_type' => $this->content_type,
                'provider' => $provider?->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the raw content for this document based on its source type
     */
    public function getRawContent(): ?string
    {
        $injectionService = app(DocumentInjectionService::class);

        return $injectionService->getDocumentContent($this);
    }

    /**
     * Get the base64 encoded content of this document
     */
    public function getBase64Content(): string
    {
        $content = $this->getRawContent() ?? '';

        return base64_encode($content);
    }

    /**
     * Check if this document comes from a file upload
     */
    public function isFileUpload(): bool
    {
        return in_array($this->source_type, ['file_upload', 'uploaded_file']) && $this->asset;
    }

    /**
     * Check if this document comes from an external source
     */
    public function isExternalSource(): bool
    {
        return in_array($this->source_type, ['external_source', 'external']) && ! empty($this->external_source_identifier);
    }

    /**
     * Check if this document is a text-based document
     */
    public function isTextDocument(): bool
    {
        return in_array($this->content_type, ['text', 'manual']) ||
               in_array($this->source_type, ['knowledge_manager', 'text', 'manual', 'direct_input']);
    }

    /**
     * Get the MIME type for this document
     */
    public function getMimeType(): string
    {
        $injectionService = app(DocumentInjectionService::class);

        return $injectionService->getMimeType($this);
    }

    /**
     * Validate if this document can be properly converted to a Prism document
     */
    public function validateForPrismConversion(): array
    {
        $injectionService = app(DocumentInjectionService::class);

        return $injectionService->validateDocument($this);
    }

    /**
     * Check if this document should use base64 encoding for Prism
     */
    public function shouldUseBase64Encoding(): bool
    {
        // Text documents and manual entries use raw content
        if ($this->isTextDocument()) {
            return false;
        }

        // Check file extension for text formats
        if ($this->asset && $this->asset->original_filename) {
            $textExtensions = ['txt', 'md', 'csv'];
            $extension = strtolower(pathinfo($this->asset->original_filename, PATHINFO_EXTENSION));
            if (in_array($extension, $textExtensions)) {
                return false;
            }
        }

        // Default to base64 for binary/unknown formats
        return true;
    }

    /**
     * Validate file integrity using Asset model checksum
     */
    public function validateFileIntegrity(): bool
    {
        if (! $this->asset) {
            Log::warning('Knowledge document file integrity check skipped: no asset', [
                'document_id' => $this->id,
                'title' => $this->title,
            ]);

            return false;
        }

        // SECURITY: Validate file integrity using SHA-256 checksum
        $isValid = $this->asset->validateChecksum();

        if (! $isValid) {
            // CRITICAL SECURITY EVENT: File integrity violation detected
            // This indicates potential file tampering, corruption, or malicious modification
            Log::critical('FILE INTEGRITY VIOLATION DETECTED - Possible tampering or corruption', [
                'document_id' => $this->id,
                'title' => $this->title,
                'asset_id' => $this->asset->id,
                'original_filename' => $this->asset->original_filename,
                'storage_key' => $this->asset->storage_key,
                'user_id' => $this->created_by,
                'privacy' => $this->privacy,
                'checksum_algorithm' => 'sha256',
                'action' => 'quarantining_document',
            ]);

            // Quarantine the document to prevent access to potentially compromised file
            // Use updateQuietly to avoid triggering observers that might re-process the file
            $this->updateQuietly([
                'processing_status' => 'failed',
                'status' => 'error',
            ]);

            // Basic incident response: Enhanced logging for security monitoring
            Log::critical('SECURITY INCIDENT: File integrity violation - possible tampering detected', [
                'incident_type' => 'file_integrity_violation',
                'document_id' => $this->id,
                'asset_id' => $this->asset->id,
                'user_id' => $this->created_by,
                'original_filename' => $this->asset->original_filename,
                'storage_key' => $this->asset->storage_key,
                'privacy' => $this->privacy,
                'expected_checksum' => $this->asset->sha256_hash,
                'calculated_checksum' => $calculatedChecksum,
                'checksum_algorithm' => 'sha256',
                'quarantine_timestamp' => now()->toISOString(),
                'requires_investigation' => true,
            ]);

            /**
             * Future Enhancement: Full Security Incident Response Workflow
             *
             * Current: Enhanced logging with CRITICAL level for monitoring systems
             * Future: Complete incident response automation
             *
             * Implementation tasks:
             * 1. Create FileIntegrityViolationEvent for event-driven response
             * 2. Implement admin notification system (email, Slack, PagerDuty)
             * 3. Move compromised file to isolated quarantine storage
             * 4. Add access control layer to block downloads of quarantined documents
             * 5. Create incident investigation dashboard/workflow
             * 6. Implement automated threat analysis (malware scanning, etc.)
             * 7. Add compliance reporting (GDPR, SOC2, ISO27001)
             *
             * @see https://owasp.org/www-community/vulnerabilities/Unrestricted_File_Upload
             *
             * @todo Create App\Events\FileIntegrityViolationEvent with listener workflow
             */

            return false;
        }

        // Successful validation - log for audit trail
        Log::info('Knowledge document file integrity validated successfully', [
            'document_id' => $this->id,
            'asset_id' => $this->asset->id,
            'checksum_algorithm' => 'sha256',
        ]);

        return true;
    }

    /**
     * Check if document has an associated asset file
     */
    public function hasAssetFile(): bool
    {
        return $this->asset && $this->asset->exists();
    }
}
