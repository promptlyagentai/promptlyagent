<?php

namespace App\Services\Knowledge;

use App\Models\AgentKnowledgeAssignment;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Knowledge\Contracts\KnowledgeManagerInterface;
use App\Services\Knowledge\Contracts\KnowledgeProcessorInterface;
use App\Services\Knowledge\DTOs\KnowledgeSource;
use App\Services\Knowledge\Embeddings\EmbeddingService;
use App\Services\Knowledge\Processors\ExternalProcessor;
use App\Services\Knowledge\Processors\FileProcessor;
use App\Services\Knowledge\Processors\TextProcessor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Meilisearch\Client as MeilisearchClient;

/**
 * Knowledge Document Lifecycle Manager.
 *
 * Orchestrates the complete lifecycle of knowledge documents from creation through
 * processing, indexing, and deletion. Supports multiple content types with automatic
 * processing and embedding generation.
 *
 * Document Creation Flows:
 * - **Files**: Upload → Asset storage → Processing → Embedding → Indexing
 * - **Archives**: Extract → Multiple documents → Batch processing with AI analysis
 * - **Text**: Direct content → Processing → Embedding → Indexing
 * - **External**: URL fetch → Content extraction → Processing → Auto-refresh scheduling
 *
 * Processing Pipeline:
 * 1. Validate input (file size, type, archive limits)
 * 2. Create document record with pending status
 * 3. Select appropriate processor (TextProcessor, FileProcessor, ExternalProcessor)
 * 4. Extract/transform content via processor
 * 5. Update document with processed content
 * 6. Trigger Scout indexing (automatic via observers)
 * 7. Generate embeddings during indexing (transient, not persisted)
 * 8. Update Meilisearch with content + embeddings
 *
 * Embedding Architecture (Transient):
 * - Embeddings generated during Scout indexing operations
 * - NOT persisted to database (temporary attribute on model)
 * - Regenerated on each reindex operation
 * - Stored only in Meilisearch for search
 *
 * Archive Processing:
 * - Supports ZIP and TAR formats
 * - Configurable limits (max files, max extraction size)
 * - AI-powered analysis for title/description/tags per file
 * - Batch creation with error tracking
 * - Automatic cleanup of extracted temporary files
 *
 * AI-Powered Features:
 * - File analysis for suggested titles, descriptions, tags, TTL
 * - Content analysis for text documents
 * - Cached analysis results to avoid duplication
 *
 * Caching Strategy:
 * - Embedding status cached based on document state:
 *   - Completed/available: 5 minutes (stable)
 *   - Pending/processing: 10 seconds (frequent checks)
 *   - Failed: 1 minute (error state)
 * - Cache keys include timestamp for automatic invalidation
 *
 * @see \App\Models\KnowledgeDocument
 * @see \App\Services\Knowledge\Contracts\KnowledgeProcessorInterface
 * @see \App\Services\Knowledge\Embeddings\EmbeddingService
 * @see \App\Scout\Engines\MeilisearchVectorEngine
 */
class KnowledgeManager implements KnowledgeManagerInterface
{
    protected array $processors = [];

    protected EmbeddingService $embeddingService;

    protected MeilisearchClient $meilisearch;

    public function __construct()
    {
        $this->embeddingService = new EmbeddingService;
        $this->meilisearch = new MeilisearchClient(
            config('scout.meilisearch.host'),
            config('scout.meilisearch.key')
        );
        $this->registerProcessors();
    }

    /**
     * Create knowledge document(s) from an uploaded file.
     *
     * Handles both single files and archive files (ZIP, TAR). For archives,
     * extracts all processable files and creates separate documents for each.
     *
     * Processing Flow:
     * 1. Validate file (size, type)
     * 2. Check if archive → extract and batch process
     * 3. If single file → create document with Asset storage
     * 4. Apply AI suggestions (title, description, tags, TTL)
     * 5. Queue processing and embedding generation
     *
     * @param  UploadedFile  $file  Uploaded file or archive
     * @param  string  $title  Document title (or base title for archives)
     * @param  string|null  $description  Optional description
     * @param  array<string>  $tags  Tag names to attach
     * @param  string  $privacyLevel  'private' or 'public'
     * @param  int|null  $ttlHours  Hours until expiration (null = never expires)
     * @param  int|null  $userId  Document owner (defaults to authenticated user)
     * @return KnowledgeDocument|array Document for single file, array with ['documents', 'errors'] for archives
     *
     * @throws \InvalidArgumentException For validation failures
     */
    public function createFromFile(
        UploadedFile $file,
        string $title,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null
    ): KnowledgeDocument|array {
        $userId = $userId ?? Auth::id();

        // Validate file
        $this->validateFile($file);

        // Check if this is an archive file that should be extracted
        if ($this->isArchiveFile($file)) {
            // Return archive processing result instead of single document
            return $this->createFromArchive(
                $file,
                $title,
                $description,
                $tags,
                $privacyLevel,
                $ttlHours,
                $userId
            );
        }

        // Process as single file (existing logic)
        return $this->createSingleDocument(
            $file,
            $title,
            $description,
            $tags,
            $privacyLevel,
            $ttlHours,
            $userId
        );
    }

    /**
     * Create a single knowledge document from a file (extracted from createFromFile)
     */
    protected function createSingleDocument(
        UploadedFile $file,
        string $title,
        ?string $description,
        array $tags,
        string $privacyLevel,
        ?int $ttlHours,
        int $userId
    ): KnowledgeDocument {
        try {
            // Create Asset record first
            $asset = \App\Models\Asset::createFromFile($file, 'knowledge_documents');
            Log::info('KnowledgeManager: Asset created', ['asset_id' => $asset->id, 'storage_key' => $asset->storage_key]);

            // Create knowledge document record
            $document = KnowledgeDocument::create([
                'asset_id' => $asset->id,
                'title' => $title,
                'description' => $description,
                'content_type' => 'file',
                'source_type' => $asset->mime_type,
                'privacy_level' => $privacyLevel,
                'processing_status' => 'pending',
                'ttl_expires_at' => $ttlHours ? now()->addHours($ttlHours) : null,
                'created_by' => $userId,
            ]);
            Log::info('KnowledgeManager: Document created', ['document_id' => $document->id]);

            // Attach tags
            $this->attachTags($document, $tags, $userId);

            // Queue processing
            $this->queueProcessing($document);

            Log::info('KnowledgeManager: Document creation completed', ['document_id' => $document->id, 'title' => $title]);

            return $document;
        } catch (\Exception $e) {
            Log::error('KnowledgeManager: createSingleDocument failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Create multiple knowledge documents from an archive file
     */
    public function createFromArchive(
        UploadedFile $file,
        string $baseTitle,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null
    ): array {
        $userId = $userId ?? Auth::id();

        // Validate the archive file first
        $this->validateFile($file);

        if (! $this->isArchiveFile($file)) {
            throw new \InvalidArgumentException('File is not a supported archive format');
        }

        // Analyze archive contents
        $archiveInfo = $this->analyzeArchive($file);
        $this->validateArchive($file, $archiveInfo);

        // Extract archive to temporary location
        $extractedFiles = $this->extractArchive($file);

        $createdDocuments = [];
        $errors = [];

        try {
            foreach ($extractedFiles as $extractedFile) {
                try {
                    // Create individual knowledge document for each extracted file
                    $document = $this->createFromExtractedFile(
                        $extractedFile,
                        $baseTitle,
                        $description,
                        $tags,
                        $privacyLevel,
                        $ttlHours,
                        $userId
                    );

                    $createdDocuments[] = $document;

                } catch (\Exception $e) {
                    $errors[] = [
                        'file' => $extractedFile['original_name'],
                        'error' => $e->getMessage(),
                    ];

                    Log::warning('Failed to process file from archive', [
                        'file' => $extractedFile['original_name'],
                        'archive' => $file->getClientOriginalName(),
                        'error' => $e->getMessage(),
                        'user_id' => $userId,
                    ]);
                }
            }
        } finally {
            // Clean up extracted files
            $this->cleanupExtractedFiles($extractedFiles);
        }

        Log::info('Archive processed', [
            'archive' => $file->getClientOriginalName(),
            'total_files' => count($extractedFiles),
            'successful' => count($createdDocuments),
            'failed' => count($errors),
            'user_id' => $userId,
        ]);

        return [
            'documents' => $createdDocuments,
            'errors' => $errors,
            'total_files' => count($extractedFiles),
            'successful_count' => count($createdDocuments),
            'error_count' => count($errors),
        ];
    }

    /**
     * Extract archive to temporary directory and return file information
     */
    protected function extractArchive(UploadedFile $file): array
    {
        $fileName = strtolower($file->getClientOriginalName());

        // For S3 or remote storage, copy to local temp file first
        $tempPath = $file->getRealPath();
        $isRemote = false;

        if (! $tempPath || ! file_exists($tempPath)) {
            // File is likely on S3 - create local temp copy
            $tempPath = tempnam(sys_get_temp_dir(), 'archive_extract_');
            file_put_contents($tempPath, $file->get());
            $isRemote = true;

            Log::info('KnowledgeManager: Created local copy of remote file for extraction', [
                'filename' => $fileName,
                'temp_path' => $tempPath,
                'file_size' => filesize($tempPath),
            ]);
        }

        $extractPath = storage_path('app/'.config('knowledge.archives.extract_path', 'tmp/knowledge_extraction'));
        $sessionId = uniqid('extract_', true);
        $sessionPath = $extractPath.'/'.$sessionId;

        // Create extraction directory
        if (! Storage::exists(config('knowledge.archives.extract_path', 'tmp/knowledge_extraction'))) {
            Storage::makeDirectory(config('knowledge.archives.extract_path', 'tmp/knowledge_extraction'));
        }

        if (! file_exists($sessionPath)) {
            mkdir($sessionPath, 0755, true);
        }

        $extractedFiles = [];

        try {
            if (str_contains($fileName, '.zip')) {
                $extractedFiles = $this->extractZipFile($tempPath, $sessionPath);
            } elseif (str_contains($fileName, '.tar') || str_contains($fileName, '.tgz')) {
                $extractedFiles = $this->extractTarFile($tempPath, $sessionPath);
            } else {
                throw new \InvalidArgumentException('Unsupported archive format');
            }
        } catch (\Exception $e) {
            // Clean up on error
            $this->removeDirectory($sessionPath);

            // Clean up temp file if we created it
            if ($isRemote && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            throw $e;
        }

        // Clean up temp file if we created it
        if ($isRemote && file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return $extractedFiles;
    }

    /**
     * Extract ZIP file
     */
    protected function extractZipFile(string $archivePath, string $extractPath): array
    {
        $zip = new \ZipArchive;
        $extractedFiles = [];

        if ($zip->open($archivePath) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);

                // Skip directories and hidden files
                if (substr($filename, -1) === '/' || str_starts_with(basename($filename), '.')) {
                    continue;
                }

                $extractedPath = $extractPath.'/'.basename($filename);

                if ($zip->extractTo(dirname($extractedPath), $filename)) {
                    $actualPath = dirname($extractedPath).'/'.$filename;

                    if (file_exists($actualPath) && is_file($actualPath)) {
                        $extractedFiles[] = [
                            'path' => $actualPath,
                            'original_name' => $filename,
                            'size' => filesize($actualPath),
                        ];
                    }
                }
            }
            $zip->close();
        } else {
            throw new \InvalidArgumentException('Unable to open ZIP archive');
        }

        return $extractedFiles;
    }

    /**
     * Extract TAR file
     */
    protected function extractTarFile(string $archivePath, string $extractPath): array
    {
        $extractedFiles = [];

        try {
            $phar = new \PharData($archivePath);
            $phar->extractTo($extractPath);

            // Collect extracted files
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && ! str_starts_with($file->getFilename(), '.')) {
                    $extractedFiles[] = [
                        'path' => $file->getPathname(),
                        'original_name' => $file->getFilename(),
                        'size' => $file->getSize(),
                    ];
                }
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Unable to extract TAR archive: '.$e->getMessage());
        }

        return $extractedFiles;
    }

    /**
     * Create knowledge document from extracted file
     */
    protected function createFromExtractedFile(
        array $extractedFile,
        string $baseTitle,
        ?string $description,
        array $tags,
        string $privacyLevel,
        ?int $ttlHours,
        int $userId
    ): KnowledgeDocument {
        $filePath = $extractedFile['path'];
        $originalName = $extractedFile['original_name'];

        // Validate file type
        $mimeType = mime_content_type($filePath) ?: $this->getMimeTypeFromExtension(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedTypes = config('knowledge.allowed_file_types', []);

        if (! in_array($mimeType, $allowedTypes)) {
            throw new \InvalidArgumentException("File type not allowed: {$mimeType}");
        }

        // Create a temporary UploadedFile instance for the FileAnalyzer
        $tempUploadedFile = new \Illuminate\Http\UploadedFile(
            $filePath,
            $originalName,
            $mimeType,
            null,
            true // test mode = true to avoid validation issues
        );

        // Analyze file with FileAnalyzer to get AI suggestions
        $aiSuggestions = [];
        try {
            $analyzer = new \App\Services\Knowledge\FileAnalyzer;
            if ($analyzer->isEnabled()) {
                $aiSuggestions = $analyzer->analyzeFile($tempUploadedFile);
            }
        } catch (\Exception $e) {
            Log::warning('FileAnalyzer failed for extracted file', [
                'file' => $originalName,
                'error' => $e->getMessage(),
            ]);
        }

        // Generate title from AI suggestions or base title + file name
        $documentTitle = ! empty($aiSuggestions['suggested_title'])
            ? $aiSuggestions['suggested_title']
            : $baseTitle.' - '.pathinfo($originalName, PATHINFO_FILENAME);

        // Use AI-suggested description if available, otherwise use provided description
        $finalDescription = ! empty($aiSuggestions['suggested_description'])
            ? $aiSuggestions['suggested_description']
            : $description;

        // Merge AI-suggested tags with provided tags
        $finalTags = $tags;
        if (! empty($aiSuggestions['suggested_tags'])) {
            $finalTags = array_unique(array_merge($tags, $aiSuggestions['suggested_tags']));
        }

        // Use AI-suggested TTL if available and no TTL was provided
        $finalTtlHours = $ttlHours;
        if (! $ttlHours && ! empty($aiSuggestions['suggested_ttl_hours']) && $aiSuggestions['suggested_ttl_hours'] > 0) {
            $finalTtlHours = $aiSuggestions['suggested_ttl_hours'];
        }

        // Use createFromFileWithAnalysis to cache the analysis and avoid duplication
        $document = $this->createFromFileWithAnalysis(
            file: $tempUploadedFile,
            title: $documentTitle,
            description: $finalDescription,
            tags: $finalTags,
            privacyLevel: $privacyLevel,
            ttlHours: $finalTtlHours,
            userId: $userId,
            cachedAnalysis: array_merge($aiSuggestions, [
                'extracted_from_archive' => true,
                'archive_base_title' => $baseTitle,
            ])
        );

        return $document;
    }

    /**
     * Clean up extracted files
     */
    protected function cleanupExtractedFiles(array $extractedFiles): void
    {
        $sessionPaths = [];

        foreach ($extractedFiles as $file) {
            $sessionPath = dirname($file['path']);
            if (! in_array($sessionPath, $sessionPaths)) {
                $sessionPaths[] = $sessionPath;
            }
        }

        foreach ($sessionPaths as $path) {
            $this->removeDirectory($path);
        }
    }

    /**
     * Remove directory and all contents
     */
    protected function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($path);
    }

    public function createFromFileWithAnalysis(
        UploadedFile $file,
        string $title,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null,
        array $cachedAnalysis = []
    ): KnowledgeDocument {
        $userId = $userId ?? Auth::id();

        // Validate file
        $this->validateFile($file);

        // Create Asset record first
        $asset = \App\Models\Asset::createFromFile($file, 'knowledge_documents');

        // Create knowledge document record with cached analysis if available
        $metadata = [];
        if (! empty($cachedAnalysis)) {
            $metadata = array_merge($metadata, [
                'ai_analysis' => $cachedAnalysis,
                'analysis_cached' => true,
                'analysis_timestamp' => now()->toISOString(),
            ]);
        }

        $document = KnowledgeDocument::create([
            'asset_id' => $asset->id,
            'title' => $title,
            'description' => $description,
            'content_type' => 'file',
            'source_type' => $asset->mime_type,
            'privacy_level' => $privacyLevel,
            'processing_status' => 'pending',
            'ttl_expires_at' => $ttlHours ? now()->addHours($ttlHours) : null,
            'metadata' => $metadata,
            'created_by' => $userId,
        ]);

        // Attach tags
        $this->attachTags($document, $tags, $userId);

        // Queue processing with cached analysis
        $this->queueProcessingWithCache($document, $cachedAnalysis);

        return $document;
    }

    /**
     * Create knowledge document from text content.
     *
     * Creates document directly from text with optional AI-powered metadata
     * suggestions (description, tags, TTL). Supports both standalone text and
     * external sources (URLs) with auto-refresh capabilities.
     *
     * AI Enhancement:
     * - Analyzes content for suggested description and tags
     * - Suggests TTL based on content type/freshness indicators
     * - Can be disabled via applyAiSuggestedTtl parameter
     *
     * External Source Features:
     * - Content hash for change detection
     * - Auto-refresh scheduling with configurable intervals
     * - Screenshot and thumbnail support
     * - Author and source tracking
     *
     * @param  string  $content  Document content (max length from config)
     * @param  string  $title  Document title
     * @param  string|null  $description  Optional description (AI-suggested if null)
     * @param  array<string>  $tags  Tag names (merged with AI suggestions)
     * @param  string  $privacyLevel  'private' or 'public'
     * @param  int|null  $ttlHours  Hours until expiration
     * @param  int|null  $userId  Document owner (defaults to authenticated user)
     * @param  string|null  $externalSourceIdentifier  URL or external source ID
     * @param  string|null  $author  Content author name
     * @param  string|null  $thumbnailUrl  Preview image URL
     * @param  string|null  $faviconUrl  Source icon URL
     * @param  bool  $applyAiSuggestedTtl  Whether to use AI-suggested TTL
     * @param  string|null  $notes  User notes about the document
     * @param  bool  $autoRefreshEnabled  Enable auto-refresh for external sources
     * @param  int|null  $refreshIntervalMinutes  Auto-refresh interval
     * @param  string|null  $screenshot  Base64 screenshot data
     * @return KnowledgeDocument Created document with processed content
     *
     * @throws \InvalidArgumentException For empty content or exceeding max length
     */
    public function createFromText(
        string $content,
        string $title,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null,
        ?string $externalSourceIdentifier = null,
        ?string $author = null,
        ?string $thumbnailUrl = null,
        ?string $faviconUrl = null,
        bool $applyAiSuggestedTtl = true,
        ?string $notes = null,
        bool $autoRefreshEnabled = false,
        ?int $refreshIntervalMinutes = null,
        ?string $screenshot = null
    ): KnowledgeDocument {
        $userId = $userId ?? Auth::id();

        // Validate text content
        if (empty(trim($content))) {
            throw new \InvalidArgumentException('Content cannot be empty');
        }

        if (mb_strlen($content) > config('knowledge.max_text_length', 1000000)) {
            throw new \InvalidArgumentException('Content exceeds maximum length');
        }

        // Get AI suggestions for metadata
        $aiSuggestions = [];
        if (config('knowledge.file_analysis.enabled', true)) {
            try {
                $analyzer = new FileAnalyzer;
                $aiSuggestions = $analyzer->analyzeTextContent($content, $title);
            } catch (\Exception $e) {
                Log::warning('KnowledgeManager: Text analysis failed, continuing without AI suggestions', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Use AI-suggested description if none provided
        $finalDescription = $description ?? ($aiSuggestions['suggested_description'] ?? null);

        // Merge AI-suggested tags with provided tags
        $finalTags = $tags;
        if (! empty($aiSuggestions['suggested_tags'])) {
            $finalTags = array_unique(array_merge($tags, $aiSuggestions['suggested_tags']));
        }

        // Use AI-suggested TTL if available, no TTL was provided, and AI suggestions are allowed
        $finalTtlHours = $ttlHours;
        if ($applyAiSuggestedTtl && ! $ttlHours && ! empty($aiSuggestions['suggested_ttl_hours']) && $aiSuggestions['suggested_ttl_hours'] > 0) {
            $finalTtlHours = $aiSuggestions['suggested_ttl_hours'];
        }

        // Build metadata array
        $metadata = [
            'original_length' => mb_strlen($content),
            'created_at' => now()->toISOString(),
            'ai_analysis' => $aiSuggestions,
        ];

        // Add notes to metadata if provided
        if ($notes) {
            $metadata['notes'] = $notes;
        }

        // Add screenshot to metadata if provided
        if ($screenshot) {
            $metadata['screenshot'] = $screenshot;
        }

        // Calculate content hash for refresh detection
        $contentHash = $externalSourceIdentifier ? hash('sha256', $content) : null;

        // Create knowledge document record
        $document = KnowledgeDocument::create([
            'title' => $title,
            'description' => $finalDescription,
            'content_type' => 'text',
            'source_type' => 'text/plain',
            'content' => $content,
            'privacy_level' => $privacyLevel,
            'processing_status' => 'pending',
            'ttl_expires_at' => $finalTtlHours ? now()->addHours($finalTtlHours) : null,
            'external_source_identifier' => $externalSourceIdentifier,
            'author' => $author,
            'thumbnail_url' => $thumbnailUrl,
            'favicon_url' => $faviconUrl,
            'metadata' => $metadata,
            'created_by' => $userId,
            // Refresh settings
            'content_hash' => $contentHash,
            'auto_refresh_enabled' => $autoRefreshEnabled,
            'refresh_interval_minutes' => $refreshIntervalMinutes,
            'next_refresh_at' => $autoRefreshEnabled && $refreshIntervalMinutes ?
                now()->addMinutes($refreshIntervalMinutes) : null,
        ]);

        // Attach tags
        $this->attachTags($document, $finalTags, $userId);

        // Process immediately for text
        $this->processDocument($document);

        return $document;
    }

    public function createFromExternal(
        string $source,
        string $sourceType,
        string $title,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null
    ): KnowledgeDocument {
        // Delegate to ExternalKnowledgeManager for proper handling
        $externalKnowledgeManager = app(ExternalKnowledgeManager::class);

        return $externalKnowledgeManager->addExternalSource(
            sourceIdentifier: $source,
            sourceType: $sourceType,
            title: $title,
            description: $description,
            tags: $tags,
            privacyLevel: $privacyLevel,
            ttlHours: $ttlHours,
            autoRefresh: false,
            refreshIntervalMinutes: null,
            authCredentials: [],
            userId: $userId
        );
    }

    public function updateDocument(KnowledgeDocument $document, array $data): KnowledgeDocument
    {
        $allowedFields = [
            'title', 'description', 'content', 'privacy_level', 'ttl_expires_at',
        ];

        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (isset($data['ttl_hours'])) {
            // Handle TTL: 0 or null = never expire, > 0 = expire in X hours
            $updateData['ttl_expires_at'] = $data['ttl_hours'] > 0 ? now()->addHours($data['ttl_hours']) : null;
        }

        $document->update($updateData);

        // Update tags if provided
        if (isset($data['tags'])) {
            $userId = $document->created_by ?? Auth::id();
            $this->attachTags($document, $data['tags'], $userId);
        }

        // Reprocess if content or title changed (affects embeddings and indexing)
        if ($document->wasChanged(['content', 'title']) && $document->processing_status === 'completed') {
            Log::info('KnowledgeManager: Content or title changed, reprocessing document', [
                'document_id' => $document->id,
                'changed_fields' => array_keys($document->getChanges()),
            ]);
            $this->reprocessDocument($document);
        }

        // Scout will automatically handle search index updates via model observers
        return $document->refresh();
    }

    public function deleteDocument(KnowledgeDocument $document): bool
    {
        try {
            // Delete Asset (which handles physical file deletion)
            if ($document->asset) {
                $document->asset->delete();
            }

            // Delete from database (Scout will automatically handle search index removal via model observers)
            $document->delete();

            Log::info('Knowledge document deleted successfully', [
                'document_id' => $document->id,
                'title' => $document->title,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete knowledge document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Process a knowledge document through the content extraction pipeline.
     *
     * Orchestrates the complete processing flow from raw content to searchable
     * document with embeddings. Selects appropriate processor based on content
     * type and handles automatic Scout indexing with embedding generation.
     *
     * Processing Steps:
     * 1. Create KnowledgeSource from document
     * 2. Find processor (TextProcessor, FileProcessor, ExternalProcessor)
     * 3. Validate source with processor
     * 4. Extract/transform content
     * 5. Update document with processed content
     * 6. Set Meilisearch document ID for Scout consistency
     * 7. Scout observer triggers indexing (with embedding generation)
     *
     * On Failure:
     * - Document marked as 'failed' with error message
     * - Returns false
     *
     * @param  KnowledgeDocument  $document  Document to process
     * @return bool True on success, false on failure
     */
    public function processDocument(KnowledgeDocument $document): bool
    {
        try {
            $document->update(['processing_status' => 'processing']);

            // Create knowledge source
            $source = $this->createKnowledgeSource($document);

            // Find appropriate processor
            $processor = $this->findProcessor($source);
            if (! $processor) {
                throw new \Exception("No suitable processor found for content type: {$source->contentType}");
            }

            // Validate source
            if (! $processor->validate($source)) {
                throw new \Exception("Source validation failed using processor: {$processor->getName()}");
            }

            // Process content
            $processedKnowledge = $processor->process($source);

            // Update document with processed content
            $document->update([
                'content' => $processedKnowledge->content,
                'metadata' => array_merge($document->metadata ?? [], $processedKnowledge->metadata),
                'processing_status' => 'completed',
                'processing_error' => null,
            ]);

            // Set meilisearch_document_id if not set (for Scout key consistency)
            if (! $document->meilisearch_document_id) {
                $document->update(['meilisearch_document_id' => "doc_{$document->id}"]);
            }

            // Scout will automatically update the search index via model observers
            // The HasVectorSearch trait will handle embedding generation during the update process

            return true;

        } catch (\Exception $e) {
            $document->markAsFailedProcessing($e->getMessage());

            return false;
        }
    }

    public function reprocessDocument(KnowledgeDocument $document, bool $contentChanged = true): bool
    {
        // Reset processing status
        $document->update([
            'processing_status' => 'pending',
            'processing_error' => null,
        ]);

        $result = $this->processDocument($document);

        // Scout automatically handles embedding generation and indexing via model observers
        // when content changes, so no additional steps are needed

        return $result;
    }

    public function assignToAgent(int $agentId, int $documentId, array $config = []): bool
    {
        try {
            AgentKnowledgeAssignment::assignDocumentToAgent($agentId, $documentId, $config);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function assignTagToAgent(int $agentId, int $tagId, array $config = []): bool
    {
        try {
            AgentKnowledgeAssignment::assignTagToAgent($agentId, $tagId, $config);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function removeFromAgent(int $agentId, ?int $documentId = null, ?int $tagId = null): bool
    {
        try {
            $query = AgentKnowledgeAssignment::where('agent_id', $agentId);

            if ($documentId) {
                $query->where('knowledge_document_id', $documentId);
            }

            if ($tagId) {
                $query->where('knowledge_tag_id', $tagId);
            }

            $query->delete();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getAccessibleDocuments(int $userId, array $filters = []): Collection
    {
        $query = KnowledgeDocument::forUser($userId)->completed();

        // Apply filters
        if (! empty($filters['tags'])) {
            $query->whereHas('tags', function ($tagQuery) use ($filters) {
                $tagQuery->whereIn('slug', $filters['tags']);
            });
        }

        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }

        if (! empty($filters['privacy_level'])) {
            $query->where('privacy_level', $filters['privacy_level']);
        }

        if (isset($filters['include_expired']) && ! $filters['include_expired']) {
            $query->notExpired();
        }

        return $query->with(['tags', 'creator'])->get();
    }

    public function canAccess(int $userId, KnowledgeDocument $document): bool
    {
        return $document->canAccess(\App\Models\User::find($userId));
    }

    public function getExpiredDocuments(): Collection
    {
        return KnowledgeDocument::expired()->get();
    }

    public function cleanupExpiredDocuments(bool $dryRun = true): int
    {
        $expiredDocuments = $this->getExpiredDocuments();

        if ($dryRun) {
            return $expiredDocuments->count();
        }

        $deletedCount = 0;
        foreach ($expiredDocuments as $document) {
            if ($this->deleteDocument($document)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    protected function registerProcessors(): void
    {
        $this->processors[] = new TextProcessor;
        $this->processors[] = new FileProcessor;
        $this->processors[] = new ExternalProcessor;
        // Additional processors will be registered here
    }

    protected function findProcessor(KnowledgeSource $source): ?KnowledgeProcessorInterface
    {
        // Sort processors by priority
        usort($this->processors, fn ($a, $b) => $b->getPriority() <=> $a->getPriority());

        foreach ($this->processors as $processor) {
            if ($processor->supports($source->contentType)) {
                return $processor;
            }
        }

        return null;
    }

    protected function createKnowledgeSource(KnowledgeDocument $document): KnowledgeSource
    {
        switch ($document->content_type) {
            case 'file':
                if (! $document->asset || ! $document->asset->exists()) {
                    throw new \Exception('File asset not found');
                }

                $filePath = $document->asset->getPath();
                $uploadedFile = new UploadedFile(
                    path: $filePath,
                    originalName: $document->asset->original_filename,
                    mimeType: $document->asset->mime_type,
                    error: null,
                    test: true
                );

                return KnowledgeSource::fromFile($uploadedFile, $document->metadata ?? []);

            case 'text':
                return KnowledgeSource::fromText($document->content, $document->metadata ?? []);

            case 'external':
                // External sources can be either:
                // 1. Pre-processed content (e.g., Notion) - content is already populated and ready
                // 2. URLs that need fetching - content is empty, needs ExternalProcessor

                // If document already has processed content, treat it as text
                if (! empty($document->content) && mb_strlen(trim($document->content)) > 50) {
                    // Pre-processed external source with content already available
                    return KnowledgeSource::fromText($document->content, $document->metadata ?? []);
                }

                // For URLs and sources that need fetching, use ExternalProcessor
                return KnowledgeSource::fromExternal(
                    $document->external_source_identifier ?? $document->content,
                    $document->source_type,
                    $document->metadata ?? []
                );

            default:
                throw new \Exception("Unsupported content type: {$document->content_type}");
        }
    }

    public function attachTags(KnowledgeDocument $document, array $tags, int $userId): void
    {
        if (empty($tags)) {
            return;
        }

        $tagModels = [];
        foreach ($tags as $tagName) {
            if (is_string($tagName)) {
                $tagModels[] = KnowledgeTag::findOrCreateByName($tagName, $userId);
            } elseif (is_array($tagName) && isset($tagName['name'])) {
                $tagModels[] = KnowledgeTag::findOrCreateByName($tagName['name'], $userId);
            }
        }

        $tagIds = collect($tagModels)->pluck('id')->toArray();
        $document->tags()->sync($tagIds);
    }

    public function queueProcessing(KnowledgeDocument $document): void
    {
        // Process document immediately for now (in production, this should be queued)
        try {
            $this->processDocument($document);

            // Scout automatically handles embedding generation and indexing via model observers

        } catch (\Exception $e) {
            Log::error('KnowledgeManager: Document processing failed in queueProcessing', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark document as failed
            $document->update([
                'processing_status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);
        }
    }

    protected function queueProcessingWithCache(KnowledgeDocument $document, array $cachedAnalysis = []): void
    {
        try {
            $this->processDocumentWithCache($document, $cachedAnalysis);
        } catch (\Exception $e) {
            Log::error('KnowledgeManager: Document processing failed in queueProcessingWithCache', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark document as failed
            $document->update([
                'processing_status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);
        }
    }

    public function processDocumentWithCache(KnowledgeDocument $document, array $cachedAnalysis = []): bool
    {
        try {
            $document->update(['processing_status' => 'processing']);

            // Create knowledge source
            $source = $this->createKnowledgeSource($document);

            // Find appropriate processor
            $processor = $this->findProcessor($source);
            if (! $processor) {
                throw new \Exception("No suitable processor found for content type: {$source->contentType}");
            }

            // Validate source
            if (! $processor->validate($source)) {
                throw new \Exception("Source validation failed using processor: {$processor->getName()}");
            }

            // Process content with cache if available and processor supports it
            $processedKnowledge = null;
            if (! empty($cachedAnalysis) && method_exists($processor, 'processWithCache')) {
                $processedKnowledge = $processor->processWithCache($source, $cachedAnalysis);

                Log::info('KnowledgeManager: Processing document with cached analysis', [
                    'document_id' => $document->id,
                    'processor' => $processor->getName(),
                    'cache_keys' => array_keys($cachedAnalysis),
                ]);
            } else {
                $processedKnowledge = $processor->process($source);
            }

            // Update document with processed content
            $document->update([
                'content' => $processedKnowledge->content,
                'metadata' => array_merge($document->metadata ?? [], $processedKnowledge->metadata),
                'processing_status' => 'completed',
                'processing_error' => null,
            ]);

            // Set meilisearch_document_id if not set (for Scout key consistency)
            if (! $document->meilisearch_document_id) {
                $document->update(['meilisearch_document_id' => "doc_{$document->id}"]);
            }

            // Scout will automatically update the search index via model observers
            // The HasVectorSearch trait will handle embedding generation during the update process

            return true;

        } catch (\Exception $e) {
            $document->markAsFailedProcessing($e->getMessage());

            return false;
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        $maxSize = config('knowledge.max_file_size', 10 * 1024 * 1024); // 10MB default
        if ($file->getSize() > $maxSize) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size');
        }

        $allowedTypes = config('knowledge.allowed_file_types', [
            'text/plain',
            'text/markdown',
            'text/html',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/csv',
            'application/json',
        ]);

        if (! in_array($file->getMimeType(), $allowedTypes)) {
            throw new \InvalidArgumentException('File type not allowed');
        }
    }

    /**
     * Check if the uploaded file is an archive that should be extracted
     */
    public function isArchiveFile(UploadedFile $file): bool
    {
        if (! config('knowledge.archives.enabled', true)) {
            return false;
        }

        $supportedTypes = config('knowledge.archives.supported_types', []);
        $mimeType = $file->getMimeType();

        // Check MIME type
        if (in_array($mimeType, $supportedTypes)) {
            return true;
        }

        // Check file extension as fallback
        $extensions = config('knowledge.archives.file_extensions', []);
        $fileName = strtolower($file->getClientOriginalName());

        foreach ($extensions as $ext) {
            if (str_ends_with($fileName, $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get information about files in an archive without extracting
     */
    public function analyzeArchive(UploadedFile $file): array
    {
        $fileName = strtolower($file->getClientOriginalName());
        $files = [];
        $totalSize = 0;

        // For S3 or remote storage, copy to local temp file first
        $tempPath = $file->getRealPath();
        $isRemote = false;

        if (! $tempPath || ! file_exists($tempPath)) {
            // File is likely on S3 - create local temp copy
            $tempPath = tempnam(sys_get_temp_dir(), 'archive_analysis_');
            file_put_contents($tempPath, $file->get());
            $isRemote = true;

            Log::info('KnowledgeManager: Created local copy of remote file', [
                'filename' => $fileName,
                'temp_path' => $tempPath,
                'file_size' => filesize($tempPath),
            ]);
        }

        Log::info('KnowledgeManager: Starting archive analysis', [
            'filename' => $fileName,
            'temp_path' => $tempPath,
            'is_remote' => $isRemote,
            'file_exists' => file_exists($tempPath),
            'file_size' => file_exists($tempPath) ? filesize($tempPath) : 0,
        ]);

        try {
            if (str_contains($fileName, '.zip')) {
                $zip = new \ZipArchive;
                $openResult = $zip->open($tempPath);

                Log::info('KnowledgeManager: Attempting to open ZIP', [
                    'filename' => $fileName,
                    'open_result' => $openResult,
                    'zip_num_files' => $openResult === true ? $zip->numFiles : 0,
                ]);

                if ($openResult === true) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $stat = $zip->statIndex($i);

                        Log::debug('KnowledgeManager: Processing ZIP entry', [
                            'index' => $i,
                            'name' => $stat['name'] ?? 'unknown',
                            'size' => $stat['size'] ?? 0,
                            'is_directory' => $stat && isset($stat['name']) && substr($stat['name'], -1) === '/',
                        ]);

                        if (! $stat || ! $stat['name'] || substr($stat['name'], -1) === '/') {
                            continue; // Skip directories
                        }

                        $files[] = [
                            'name' => $stat['name'],
                            'size' => $stat['size'],
                            'compressed_size' => $stat['comp_size'],
                        ];
                        $totalSize += $stat['size'];
                    }
                    $zip->close();

                    Log::info('KnowledgeManager: ZIP analysis complete', [
                        'filename' => $fileName,
                        'total_files' => count($files),
                        'total_size' => $totalSize,
                    ]);
                } else {
                    Log::error('KnowledgeManager: Failed to open ZIP file', [
                        'filename' => $fileName,
                        'temp_path' => $tempPath,
                        'open_result' => $openResult,
                        'zip_error' => $zip->getStatusString(),
                    ]);
                }
            } elseif (str_contains($fileName, '.tar') || str_contains($fileName, '.tgz')) {
                // Handle tar files using PharData
                try {
                    $phar = new \PharData($tempPath);
                    foreach ($phar as $file) {
                        if (! $file->isFile()) {
                            continue; // Skip directories
                        }

                        $files[] = [
                            'name' => $file->getFilename(),
                            'size' => $file->getSize(),
                            'compressed_size' => $file->getCompressedSize(),
                        ];
                        $totalSize += $file->getSize();
                    }
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException('Unable to read tar archive: '.$e->getMessage());
                }
            }
        } catch (\Exception $e) {
            // Clean up temp file if we created it
            if ($isRemote && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            throw new \InvalidArgumentException('Unable to analyze archive: '.$e->getMessage());
        }

        // Clean up temp file if we created it
        if ($isRemote && file_exists($tempPath)) {
            @unlink($tempPath);
        }

        return [
            'files' => $files,
            'total_files' => count($files),
            'total_size' => $totalSize,
        ];
    }

    /**
     * Validate archive before processing
     */
    protected function validateArchive(UploadedFile $file, array $archiveInfo): void
    {
        $maxFiles = config('knowledge.archives.max_files_per_archive', 50);
        $maxSize = config('knowledge.archives.max_extraction_size', 100 * 1024 * 1024);

        if ($archiveInfo['total_files'] > $maxFiles) {
            throw new \InvalidArgumentException("Archive contains too many files. Maximum allowed: {$maxFiles}");
        }

        if ($archiveInfo['total_size'] > $maxSize) {
            $maxSizeMB = round($maxSize / 1024 / 1024, 1);
            throw new \InvalidArgumentException("Archive extraction size too large. Maximum allowed: {$maxSizeMB}MB");
        }

        // Check if any files in the archive are processable
        $allowedTypes = config('knowledge.allowed_file_types', []);
        $processableFiles = 0;

        foreach ($archiveInfo['files'] as $fileInfo) {
            $extension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
            $mimeType = $this->getMimeTypeFromExtension($extension);

            if (in_array($mimeType, $allowedTypes)) {
                $processableFiles++;
            }
        }

        if ($processableFiles === 0) {
            throw new \InvalidArgumentException('Archive contains no processable files');
        }
    }

    /**
     * Get MIME type from file extension (helper for archive processing)
     */
    protected function getMimeTypeFromExtension(string $extension): string
    {
        $mimeTypes = [
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html' => 'text/html',
            'htm' => 'text/html',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Get embedding status for all documents with intelligent caching.
     *
     * Queries Meilisearch to determine embedding availability for all documents.
     * Implements dynamic caching strategy to minimize API requests while maintaining
     * fresh data for documents in transition states.
     *
     * Caching Strategy:
     * - Cache key based on document count + last updated timestamp
     * - Auto-invalidates when documents are added/modified/removed
     * - Dynamic TTL:
     *   - 30 seconds if any documents are pending/missing (frequent checks)
     *   - 5 minutes if all documents are stable (completed/failed)
     *
     * Status Types:
     * - **available**: Document indexed with embeddings
     * - **missing**: Not in search index or missing Meilisearch ID
     * - **pending**: Processing not complete
     * - **failed**: Processing failed with error
     * - **disabled**: Embedding service not enabled
     *
     * @return array{
     *     total_documents: int,
     *     with_embeddings: int,
     *     without_embeddings: int,
     *     processing_failed: int,
     *     documents: array<array{
     *         id: int,
     *         title: string,
     *         processing_status: string,
     *         embedding_status: string,
     *         embedding_dimensions: int|null,
     *         embedding_model: string|null,
     *         has_vector_field: bool,
     *         tags: array<string>,
     *         created_at: \Carbon\Carbon,
     *         updated_at: \Carbon\Carbon
     *     }>
     * }
     */
    public function getEmbeddingStatus(): array
    {
        // Generate a cache key based on document counts and last updated timestamps
        // This will auto-invalidate when documents are added/modified/removed
        $docsCount = KnowledgeDocument::count();
        $lastUpdated = KnowledgeDocument::max('updated_at');
        $cacheKey = "embedding_status_all_{$docsCount}_{$lastUpdated}";

        // Check if status is cached
        $cachedStats = cache()->get($cacheKey);
        if ($cachedStats) {
            return $cachedStats;
        }

        // Not cached, generate the stats
        $documents = KnowledgeDocument::select('id', 'title', 'processing_status', 'meilisearch_document_id', 'created_at', 'updated_at')
            ->with('tags:id,name')
            ->get();

        $stats = [
            'total_documents' => $documents->count(),
            'with_embeddings' => 0,
            'without_embeddings' => 0,
            'processing_failed' => 0,
            'documents' => [],
        ];

        foreach ($documents as $document) {
            $embeddingStatus = $this->getDocumentEmbeddingStatus($document);

            $stats['documents'][] = [
                'id' => $document->id,
                'title' => $document->title,
                'processing_status' => $document->processing_status,
                'embedding_status' => $embeddingStatus['status'],
                'embedding_dimensions' => $embeddingStatus['dimensions'],
                'embedding_model' => $embeddingStatus['model'],
                'has_vector_field' => $embeddingStatus['has_vector_field'],
                'tags' => $document->tags->pluck('name')->toArray(),
                'created_at' => $document->created_at,
                'updated_at' => $document->updated_at,
            ];

            // Update counters
            switch ($embeddingStatus['status']) {
                case 'available':
                    $stats['with_embeddings']++;
                    break;
                case 'missing':
                    $stats['without_embeddings']++;
                    break;
                case 'failed':
                    $stats['processing_failed']++;
                    break;
            }
        }

        // Dynamic caching based on whether there are pending operations:
        // - If all documents are completed (available/failed), cache for 5 minutes
        // - If there are pending/missing documents, cache for 30 seconds for more frequent updates
        $hasPendingDocuments = collect($stats['documents'])->contains(function ($doc) {
            return in_array($doc['embedding_status'], ['pending', 'missing']);
        });

        $dynamicCacheTtl = $hasPendingDocuments ? 30 : 300; // 30 seconds if pending, 5 minutes if stable
        cache()->put($cacheKey, $stats, $dynamicCacheTtl);

        return $stats;
    }

    /**
     * Get embedding status for a specific document with intelligent caching.
     *
     * Checks document processing status and Meilisearch index to determine
     * embedding availability. Uses transient embedding architecture where
     * embeddings are generated during indexing and stored only in Meilisearch.
     *
     * Status Determination Flow:
     * 1. Check processing_status (failed/pending/completed)
     * 2. Check meilisearch_document_id existence
     * 3. Verify embedding service enabled
     * 4. Query Meilisearch to confirm document indexed
     * 5. Assume embeddings available if indexed (transient architecture)
     *
     * Caching Strategy:
     * - Cache key includes document updated_at timestamp
     * - Dynamic TTL based on status:
     *   - Completed/available: 5 minutes (stable)
     *   - Pending/missing: 10 seconds (frequent checks)
     *   - Failed/errors: 1 minute (error state)
     * - Cache auto-invalidates when document updated
     *
     * @param  KnowledgeDocument  $document  Document to check
     * @return array{
     *     status: string,
     *     dimensions: int|null,
     *     model: string|null,
     *     has_vector_field: bool,
     *     details: array<string>
     * }
     */
    public function getDocumentEmbeddingStatus(KnowledgeDocument $document): array
    {
        // Use document's updated_at timestamp as part of the cache key
        // This ensures cache is invalidated when document is modified
        $cacheKey = "embedding_status_{$document->id}_{$document->updated_at->timestamp}";

        // Dynamic cache TTL based on status:
        // - Completed/available: 5 minutes (long cache)
        // - Pending/processing: 10 seconds (frequent checks)
        // - Errors: 1 minute (handled separately below)

        // Check if status is in cache
        $cachedStatus = cache()->get($cacheKey);
        if ($cachedStatus) {
            // Return cached status without querying Meilisearch
            return $cachedStatus;
        }

        $status = [
            'status' => 'unknown',
            'dimensions' => null,
            'model' => null,
            'has_vector_field' => false,
            'details' => [],
        ];

        // Check if document processing failed
        if ($document->processing_status === 'failed') {
            $status['status'] = 'failed';
            $status['details'][] = 'Document processing failed';
            cache()->put($cacheKey, $status, 60); // Cache failures for 1 minute

            return $status;
        }

        // Check if document is not yet processed
        if ($document->processing_status !== 'completed') {
            $status['status'] = 'pending';
            $status['details'][] = "Processing status: {$document->processing_status}";
            cache()->put($cacheKey, $status, 10); // Cache pending for 10 seconds (frequent checks)

            return $status;
        }

        // Check if document exists in Meilisearch
        if (! $document->meilisearch_document_id) {
            $status['status'] = 'missing';
            $status['details'][] = 'No Meilisearch document ID';
            cache()->put($cacheKey, $status, 10); // Cache missing for 10 seconds (frequent checks)

            return $status;
        }

        // Check if embedding service is enabled
        if (! $this->embeddingService->isEnabled()) {
            $status['status'] = 'disabled';
            $status['details'][] = 'Embedding service is disabled';
            cache()->put($cacheKey, $status, 300); // Cache disabled status for 5 minutes

            return $status;
        }

        // For the new architecture: assume embeddings are available if document is indexed
        // and embedding service is enabled, since embeddings are generated during indexing
        try {
            // Verify document exists in Meilisearch
            $index = $this->meilisearch->index('knowledge_documents');
            $meilisearchDoc = $index->getDocument($document->meilisearch_document_id);

            if (! $meilisearchDoc) {
                $status['status'] = 'missing';
                $status['details'][] = 'Document not found in search index';
                cache()->put($cacheKey, $status, 10); // Cache missing for 10 seconds (frequent checks)

                return $status;
            }

            // In the new architecture, if document is indexed and embedding service is enabled,
            // we can assume embeddings are available (they're generated during indexing)
            $status['status'] = 'available';
            $status['dimensions'] = 3072; // text-embedding-3-large dimensions
            $status['model'] = config('knowledge.embeddings.model', 'openai/text-embedding-3-large');
            $status['has_vector_field'] = true;
            $status['details'][] = 'Embeddings generated during indexing (transient architecture)';

        } catch (\Exception $e) {
            $status['status'] = 'error';
            $status['details'][] = "Error checking search index: {$e->getMessage()}";

            // Cache error status too, but for shorter period
            cache()->put($cacheKey, $status, 60); // Cache errors for 1 minute

            Log::warning('KnowledgeManager: Failed to check document embedding status', [
                'document_id' => $document->id,
                'meilisearch_id' => $document->meilisearch_document_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Cache successful results for longer period
        cache()->put($cacheKey, $status, 300); // Cache available status for 5 minutes

        return $status;
    }

    /**
     * Get summary statistics for embedding status
     */
    public function getEmbeddingStatistics(): array
    {
        $statusData = $this->getEmbeddingStatus();

        return [
            'total_documents' => $statusData['total_documents'],
            'with_embeddings' => $statusData['with_embeddings'],
            'without_embeddings' => $statusData['without_embeddings'],
            'processing_failed' => $statusData['processing_failed'],
            'completion_rate' => $statusData['total_documents'] > 0
                ? round(($statusData['with_embeddings'] / $statusData['total_documents']) * 100, 1)
                : 0,
            'embedding_service_enabled' => $this->embeddingService->isEnabled(),
            'embedding_provider' => $this->embeddingService->isEnabled()
                ? $this->embeddingService->getProvider()->value
                : 'disabled',
            'embedding_model' => $this->embeddingService->isEnabled()
                ? $this->embeddingService->getModel()
                : null,
        ];
    }

    /**
     * Regenerate embeddings for documents that are missing them
     */
    public function regenerateMissingEmbeddings(?int $limit = null): array
    {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (! $this->embeddingService->isEnabled()) {
            $results['errors'][] = 'Embedding service is not enabled';

            return $results;
        }

        $statusData = $this->getEmbeddingStatus();
        $documentsToProcess = collect($statusData['documents'])
            ->where('embedding_status', 'missing')
            ->take($limit ?? PHP_INT_MAX);

        foreach ($documentsToProcess as $docData) {
            $results['processed']++;

            try {
                $document = KnowledgeDocument::find($docData['id']);
                if (! $document) {
                    $results['errors'][] = "Document {$docData['id']} not found";
                    $results['failed']++;

                    continue;
                }

                if ($this->regenerateDocumentEmbedding($document)) {
                    $results['successful']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to regenerate embedding for document {$document->id}: {$document->title}";
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Error processing document {$docData['id']}: {$e->getMessage()}";
            }
        }

        return $results;
    }

    /**
     * Regenerate embedding for a document by triggering Scout reindexing.
     *
     * Forces complete reindexing of the document through Scout which triggers
     * fresh embedding generation via MeilisearchVectorEngine. Uses transient
     * architecture where embeddings are generated at index time.
     *
     * Process:
     * 1. Verify document completed processing
     * 2. Verify embedding service enabled
     * 3. Remove document from search index (unsearchable())
     * 4. Re-add document to search index (searchable())
     * 5. Scout observer triggers MeilisearchVectorEngine update
     * 6. Engine generates fresh embedding during indexing
     *
     * Why Regeneration Needed:
     * - Embedding model changed
     * - Document missing from search index
     * - Index corruption or inconsistency
     * - Migration to new embedding provider
     *
     * @param  KnowledgeDocument  $document  Document to regenerate embeddings for
     * @return bool True on success, false if skipped/failed
     */
    public function regenerateDocumentEmbedding(KnowledgeDocument $document): bool
    {
        try {
            // Check if document is completed
            if ($document->processing_status !== 'completed') {
                Log::info('KnowledgeManager: Skipping embedding regeneration for incomplete document', [
                    'document_id' => $document->id,
                    'processing_status' => $document->processing_status,
                ]);

                return false;
            }

            // Check if embeddings are enabled
            if (! $this->embeddingService->isEnabled()) {
                Log::warning('KnowledgeManager: Embedding service not enabled', [
                    'document_id' => $document->id,
                ]);

                return false;
            }

            // Force Scout to completely re-index this document by removing and re-adding it
            // No need to clear metadata - embeddings are no longer stored there
            $document->unsearchable();
            $document->searchable();

            Log::info('KnowledgeManager: Triggered Scout reindexing for embedding regeneration', [
                'document_id' => $document->id,
                'meilisearch_id' => $document->meilisearch_document_id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('KnowledgeManager: Failed to regenerate document embedding', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Prepare text content for embedding generation
     */
    protected function prepareTextForEmbedding($processedKnowledge): string
    {
        $parts = [];

        // Include title if available
        if (! empty($processedKnowledge->title)) {
            $parts[] = $processedKnowledge->title;
        }

        // Include summary if available
        if (! empty($processedKnowledge->summary)) {
            $parts[] = $processedKnowledge->summary;
        }

        // Include main content
        $parts[] = $processedKnowledge->content;

        // Combine all parts
        $combinedText = implode('\n\n', array_filter($parts));

        // Truncate if too long (keeping within token limits)
        $maxLength = 32000; // Conservative limit for most embedding models
        if (strlen($combinedText) > $maxLength) {
            $combinedText = substr($combinedText, 0, $maxLength);

            // Try to cut at a word boundary
            $lastSpace = strrpos($combinedText, ' ');
            if ($lastSpace !== false && $lastSpace > $maxLength * 0.9) {
                $combinedText = substr($combinedText, 0, $lastSpace);
            }

            $combinedText .= '...';
        }

        return $combinedText;
    }
}
