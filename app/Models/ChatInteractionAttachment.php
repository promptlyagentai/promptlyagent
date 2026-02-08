<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatInteractionAttachment extends Model
{
    protected $fillable = [
        'chat_interaction_id',
        'attached_to',
        'filename',
        'storage_path',
        'mime_type',
        'file_size',
        'type',
        'metadata',
        'is_temporary',
        'expires_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_temporary' => 'boolean',
        'expires_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function chatInteraction(): BelongsTo
    {
        return $this->belongsTo(ChatInteraction::class);
    }

    /**
     * Determine which storage disk this attachment uses
     * New attachments use S3, legacy attachments use local
     */
    public function getStorageDisk(): string
    {
        // S3 paths start with 'chat-attachments/'
        // Local paths vary
        return Str::startsWith($this->storage_path, 'chat-attachments/') ? 's3' : 'local';
    }

    /**
     * Get file content - works with both local and S3
     */
    public function getFileContent(): ?string
    {
        try {
            $disk = $this->getStorageDisk();

            if (! Storage::disk($disk)->exists($this->storage_path)) {
                return null;
            }

            return Storage::disk($disk)->get($this->storage_path);
        } catch (\Exception $e) {
            \Log::error('Failed to get file content', [
                'attachment_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the full storage path for the file
     */
    public function getFullStoragePath(): string
    {
        $disk = $this->getStorageDisk();

        if ($disk === 's3') {
            // For S3, download to temp file
            $tempPath = tempnam(sys_get_temp_dir(), 'attachment_');
            $contents = Storage::disk('s3')->get($this->storage_path);
            file_put_contents($tempPath, $contents);

            return $tempPath;
        }

        return Storage::disk('local')->path($this->storage_path);
    }

    /**
     * Get the file URL if it exists
     */
    public function getFileUrl(): ?string
    {
        $disk = $this->getStorageDisk();

        if (Storage::disk($disk)->exists($this->storage_path)) {
            if ($disk === 's3') {
                // Generate temporary signed URL for S3
                return Storage::disk('s3')->temporaryUrl(
                    $this->storage_path,
                    now()->addMinutes(60)
                );
            }

            return Storage::disk('local')->url($this->storage_path);
        }

        return null;
    }

    /**
     * Get presigned S3 URL for backend processing
     *
     * @param  int  $minutes  Validity duration in minutes (default: 60)
     * @return string|null Presigned S3 URL or null if file doesn't exist
     */
    public function getPresignedUrl(int $minutes = 60): ?string
    {
        $disk = $this->getStorageDisk();

        if (! Storage::disk($disk)->exists($this->storage_path)) {
            return null;
        }

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl(
                $this->storage_path,
                now()->addMinutes($minutes)
            );
        }

        // For local disk, return regular URL
        return Storage::disk('local')->url($this->storage_path);
    }

    /**
     * Get authenticated download route for browser display
     *
     * @return string Laravel route URL
     */
    public function getDownloadRoute(): string
    {
        return route('chat.attachment.download', ['attachment' => $this->id]);
    }

    /**
     * Get URL based on context
     *
     * @param  string  $context  'browser' or 'backend'
     * @return string|null Context-appropriate URL
     */
    public function getUrlForContext(string $context = 'browser'): ?string
    {
        return match ($context) {
            'backend' => $this->getPresignedUrl(120), // 2 hours for Pandoc
            'browser' => $this->getDownloadRoute(),
            default => $this->getDownloadRoute(),
        };
    }

    /**
     * Check if the file has expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Delete the associated file from storage
     */
    public function deleteFile(): bool
    {
        $disk = $this->getStorageDisk();

        if (Storage::disk($disk)->exists($this->storage_path)) {
            return Storage::disk($disk)->delete($this->storage_path);
        }

        return true;
    }

    public function toPrismValueObject()
    {
        \Log::info('ChatInteractionAttachment: Creating Prism object', [
            'attachment_id' => $this->id,
            'filename' => $this->filename,
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
        ]);

        $disk = $this->getStorageDisk();

        if (! Storage::disk($disk)->exists($this->storage_path)) {
            \Log::error('ChatInteractionAttachment: File does not exist', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'disk' => $disk,
                'storage_path' => $this->storage_path,
            ]);

            return null;
        }

        // For S3, download to temp file. For local, use direct path.
        $tempPath = null;
        if ($disk === 's3') {
            try {
                // Create temp file with proper extension to avoid Prism filename validation errors
                $extension = pathinfo($this->filename, PATHINFO_EXTENSION);
                $tempPath = sys_get_temp_dir().'/prism_'.uniqid().'_'.$this->filename;

                \Log::info('ChatInteractionAttachment: Downloading S3 file to temp location', [
                    'attachment_id' => $this->id,
                    'filename' => $this->filename,
                    's3_path' => $this->storage_path,
                    'temp_path' => $tempPath,
                    'disk' => $disk,
                ]);

                // Get file contents from S3 and write to temp file
                $contents = Storage::disk('s3')->get($this->storage_path);
                file_put_contents($tempPath, $contents);
                $path = $tempPath;

                \Log::info('ChatInteractionAttachment: S3 file downloaded successfully', [
                    'attachment_id' => $this->id,
                    'temp_path' => $tempPath,
                    'file_exists' => file_exists($tempPath),
                    'file_size' => file_exists($tempPath) ? filesize($tempPath) : 0,
                ]);
            } catch (\Exception $e) {
                \Log::error('ChatInteractionAttachment: Failed to download S3 file', [
                    'attachment_id' => $this->id,
                    'filename' => $this->filename,
                    's3_path' => $this->storage_path,
                    'temp_path' => $tempPath ?? 'not_created',
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return null;
            }
        } else {
            $path = Storage::disk('local')->path($this->storage_path);
        }

        if (! file_exists($path)) {
            \Log::error('ChatInteractionAttachment: File does not exist', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'path' => $path,
            ]);

            return null;
        }

        // Check file size - Different limits for different types
        $sizeLimit = match ($this->type) {
            'document' => 512 * 1024 * 1024, // 512MB for documents (according to OpenAI)
            'image' => 20 * 1024 * 1024,     // 20MB for images
            'audio' => 25 * 1024 * 1024,     // 25MB for audio
            'video' => 25 * 1024 * 1024,     // 25MB for video
            default => 20 * 1024 * 1024
        };

        if ($this->file_size > $sizeLimit) {
            \Log::error('ChatInteractionAttachment: File too large for API', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'file_size' => $this->file_size,
                'type' => $this->type,
                'limit' => $sizeLimit,
            ]);

            return null;
        }

        try {
            \Log::debug('ChatInteractionAttachment: Attempting to create Prism object', [
                'attachment_id' => $this->id,
                'type' => $this->type,
                'mime_type' => $this->mime_type,
                'path' => $path,
                'basename' => basename($path),
                'dirname' => dirname($path),
                'file_exists' => file_exists($path),
                'will_use_createDocumentObject' => $this->type === 'document',
            ]);

            $result = match ($this->type) {
                'image' => \Prism\Prism\ValueObjects\Media\Image::fromLocalPath($path),
                'document' => $this->createDocumentObject($path),
                'audio' => \Prism\Prism\ValueObjects\Media\Audio::fromLocalPath($path),
                'video' => \Prism\Prism\ValueObjects\Media\Video::fromLocalPath($path),
                default => null,
            };

            \Log::info('ChatInteractionAttachment: Successfully created Prism object', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'type' => $this->type,
                'path_used' => $path,
                'result_class' => $result ? get_class($result) : 'null',
            ]);

            return $result;
        } catch (\Exception $e) {
            \Log::error('ChatInteractionAttachment: Error creating Prism object', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'file_size' => $this->file_size,
                'mime_type' => $this->mime_type,
                'type' => $this->type,
                'path_used' => $path ?? 'unknown',
                'basename' => isset($path) ? basename($path) : 'unknown',
            ]);

            // Clean up temp file on error
            if ($tempPath && file_exists($tempPath)) {
                \Log::info('ChatInteractionAttachment: Cleaning up temp file after error', [
                    'temp_path' => $tempPath,
                ]);
                unlink($tempPath);
            }

            return null;
        }
        // Note: We intentionally do NOT clean up $tempPath here for S3 files.
        // The Prism objects hold references to the file paths, not the actual content.
        // PHP will clean up temp files automatically when the script ends.
    }

    /**
     * Create document object with specific handling for different document types
     */
    private function createDocumentObject(string $path)
    {
        \Log::info('ChatInteractionAttachment: Creating document object', [
            'attachment_id' => $this->id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'path' => $path,
            'file_exists' => file_exists($path),
            'file_size' => file_exists($path) ? filesize($path) : 'unknown',
        ]);

        // Validate actual MIME type matches stored MIME type to prevent OpenAI errors
        $actualMimeType = mime_content_type($path);
        if ($actualMimeType && $actualMimeType !== $this->mime_type) {
            \Log::warning('ChatInteractionAttachment: MIME type mismatch detected', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'stored_mime' => $this->mime_type,
                'actual_mime' => $actualMimeType,
            ]);

            // For critical mismatches (especially PDF), return null to prevent API errors
            if ($this->mime_type === 'application/pdf' && $actualMimeType !== 'application/pdf') {
                \Log::error('ChatInteractionAttachment: PDF MIME type mismatch - rejecting file to prevent OpenAI error', [
                    'attachment_id' => $this->id,
                    'filename' => $this->filename,
                    'stored_mime' => $this->mime_type,
                    'actual_mime' => $actualMimeType,
                ]);

                return null;
            }
        }

        // Additional file header validation for PDFs
        if ($this->mime_type === 'application/pdf') {
            if (! $this->validatePdfHeader($path)) {
                \Log::error('ChatInteractionAttachment: PDF header validation failed - rejecting file', [
                    'attachment_id' => $this->id,
                    'filename' => $this->filename,
                ]);

                return null;
            }
        }

        // For PDFs, provide title parameter which helps with processing
        if ($this->mime_type === 'application/pdf') {
            try {
                $title = pathinfo($this->filename, PATHINFO_FILENAME);

                \Log::info('ChatInteractionAttachment: Using title parameter for PDF', [
                    'attachment_id' => $this->id,
                    'title' => $title,
                    'original_filename' => $this->filename,
                ]);

                $result = \Prism\Prism\ValueObjects\Media\Document::fromLocalPath(
                    path: $path,
                    title: $title
                );

                \Log::info('ChatInteractionAttachment: Successfully created PDF document with title', [
                    'attachment_id' => $this->id,
                    'title' => $title,
                ]);

                return $result;
            } catch (\Exception $e) {
                \Log::warning('ChatInteractionAttachment: PDF title creation failed, using fallback', [
                    'attachment_id' => $this->id,
                    'filename' => $this->filename,
                    'error' => $e->getMessage(),
                ]);
                // Fallback to basic creation
            }
        }

        // For text/markdown files, try basic creation first (to test if title is causing the issue)
        if (in_array($this->mime_type, ['text/markdown', 'text/plain'])) {
            \Log::info('ChatInteractionAttachment: Using basic creation for text files (testing without title)', [
                'attachment_id' => $this->id,
                'mime_type' => $this->mime_type,
            ]);
        }

        \Log::info('ChatInteractionAttachment: Using basic document creation', [
            'attachment_id' => $this->id,
            'mime_type' => $this->mime_type,
            'reason' => $this->mime_type === 'application/pdf' ? 'title_creation_failed' : 'using_basic_creation',
        ]);

        // Default document creation without title
        return \Prism\Prism\ValueObjects\Media\Document::fromLocalPath($path);
    }

    /**
     * Validate PDF file header to ensure it's a valid PDF
     */
    private function validatePdfHeader(string $path): bool
    {
        try {
            $handle = fopen($path, 'rb');
            if (! $handle) {
                return false;
            }

            // Read first 5 bytes to check for PDF header
            $header = fread($handle, 5);
            fclose($handle);

            // Valid PDF should start with %PDF-
            $isValid = $header === '%PDF-';

            \Log::info('ChatInteractionAttachment: PDF header validation', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'header_bytes' => bin2hex($header),
                'is_valid' => $isValid,
            ]);

            return $isValid;

        } catch (\Exception $e) {
            \Log::error('ChatInteractionAttachment: PDF header validation failed', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get content as text for injection into message (for text-based files)
     */
    public function getTextContent(): ?string
    {
        // Only handle text-based files
        if (! in_array($this->mime_type, ['text/markdown', 'text/plain', 'text/csv'])) {
            return null;
        }

        $path = $this->getFullStoragePath();

        if (! file_exists($path)) {
            \Log::error('ChatInteractionAttachment: File does not exist for text injection', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'path' => $path,
            ]);

            return null;
        }

        // Check file size limit for text injection (e.g., 1MB)
        $maxSize = 1024 * 1024; // 1MB
        if ($this->file_size > $maxSize) {
            \Log::warning('ChatInteractionAttachment: Text file too large for injection', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'file_size' => $this->file_size,
                'max_size' => $maxSize,
            ]);

            return null;
        }

        try {
            $content = file_get_contents($path);

            if ($content === false) {
                \Log::error('ChatInteractionAttachment: Failed to read file content', [
                    'attachment_id' => $this->id,
                    'filename' => $this->filename,
                ]);

                return null;
            }

            \Log::info('ChatInteractionAttachment: Successfully read text content for injection', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'content_length' => strlen($content),
            ]);

            return $content;

        } catch (\Exception $e) {
            \Log::error('ChatInteractionAttachment: Error reading text content', [
                'attachment_id' => $this->id,
                'filename' => $this->filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if this attachment should be injected as text rather than uploaded as document
     *
     * This method determines if a file should be processed as text content vs. sent as a binary attachment.
     * We inject as text for unsupported binary file types and small text files.
     */
    public function shouldInjectAsText(): bool
    {
        // Always inject text files as text content (regardless of size for better reliability)
        // Note: CSV files are excluded as they should be sent as binary attachments per user requirements
        if (in_array($this->mime_type, ['text/markdown', 'text/plain'])) {
            return true;
        }

        // For non-text files, check if they're supported as binary attachments by OpenAI
        // If not supported, inject as text if possible
        if (! $this->isSupportedForBinaryAttachment()) {
            // Try to inject as text if it's a readable format and not too large
            if ($this->canBeReadAsText() && $this->file_size <= (2 * 1024 * 1024)) { // 2MB limit for text injection
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this file type is supported for binary attachment by OpenAI models
     *
     * Based on OpenAI's supported file types:
     * - Documents: PDF only
     * - Images: PNG, JPG, JPEG, GIF, WebP
     * - Audio: MP3, WAV, M4A, etc.
     * - Video: MP4, MOV, etc.
     */
    public function isSupportedForBinaryAttachment(): bool
    {
        return match ($this->type) {
            'image' => in_array($this->mime_type, [
                'image/png',
                'image/jpeg',
                'image/jpg',
                'image/gif',
                'image/webp',
            ]),
            'document' => in_array($this->mime_type, [
                'application/pdf',
                'text/csv', // CSV files should be sent as binary attachments per user requirements
                // Only PDF and CSV are supported for documents according to user requirements
            ]),
            'audio' => in_array($this->mime_type, [
                'audio/mpeg',
                'audio/mp3',
                'audio/wav',
                'audio/m4a',
                'audio/mp4',
            ]),
            'video' => in_array($this->mime_type, [
                'video/mp4',
                'video/mov',
                'video/avi',
            ]),
            default => false
        };
    }

    /**
     * Check if this file can potentially be read as text content
     *
     * This includes text formats and some structured formats that can be parsed as text
     */
    public function canBeReadAsText(): bool
    {
        $textReadableMimeTypes = [
            // Standard text types
            'text/plain',
            'text/markdown',
            'text/csv',
            'text/html',
            'text/xml',
            // Application text types
            'application/json',
            'application/xml',
            'application/javascript',
            'application/yaml',
            'application/x-yaml',
            // Code files that are often mis-categorized
            'application/octet-stream', // Many text files get this generic type
        ];

        // Check MIME type
        if (in_array($this->mime_type, $textReadableMimeTypes)) {
            return true;
        }

        // Check file extension as fallback for mis-categorized files
        $extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
        $textExtensions = [
            'txt', 'md', 'csv', 'json', 'xml', 'yaml', 'yml',
            'js', 'ts', 'css', 'html', 'htm', 'sql', 'log',
            'ini', 'conf', 'config', 'env', 'py', 'php', 'rb',
            'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs', 'swift',
        ];

        return in_array($extension, $textExtensions);
    }

    /**
     * Determine file type from MIME type
     */
    public static function determineTypeFromMimeType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'video/') => 'video',
            in_array($mimeType, [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]) => 'document',
            default => 'document',
        };
    }

    /**
     * Get original filename from metadata
     */
    public function getOriginalFilenameAttribute(): ?string
    {
        return $this->metadata['original_filename'] ?? null;
    }

    /**
     * Get source URL from metadata
     */
    public function getSourceUrlAttribute(): ?string
    {
        return $this->metadata['source_url'] ?? null;
    }

    /**
     * Get source title from metadata
     */
    public function getSourceTitleAttribute(): ?string
    {
        return $this->metadata['source_title'] ?? null;
    }

    /**
     * Get source author from metadata
     */
    public function getSourceAuthorAttribute(): ?string
    {
        return $this->metadata['source_author'] ?? null;
    }

    /**
     * Get source description from metadata
     */
    public function getSourceDescriptionAttribute(): ?string
    {
        return $this->metadata['source_description'] ?? null;
    }

    /**
     * Set source attribution in metadata
     */
    public function setSourceAttribution(
        ?string $sourceUrl = null,
        ?string $sourceTitle = null,
        ?string $sourceAuthor = null,
        ?string $sourceDescription = null,
        ?string $originalFilename = null
    ): void {
        $metadata = $this->metadata ?? [];

        if ($sourceUrl !== null) {
            $metadata['source_url'] = $sourceUrl;
        }
        if ($sourceTitle !== null) {
            $metadata['source_title'] = $sourceTitle;
        }
        if ($sourceAuthor !== null) {
            $metadata['source_author'] = $sourceAuthor;
        }
        if ($sourceDescription !== null) {
            $metadata['source_description'] = $sourceDescription;
        }
        if ($originalFilename !== null) {
            $metadata['original_filename'] = $originalFilename;
        }

        $this->metadata = $metadata;
    }
}
