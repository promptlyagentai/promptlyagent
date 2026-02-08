<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Asset Model
 *
 * Centralized file storage and management system with support for both local
 * and S3-based storage backends. Handles file uploads, checksum validation,
 * MIME type detection, and temporary file generation for remote storage.
 *
 * **Storage Strategy:**
 * - Local: Direct filesystem access via Laravel Storage
 * - S3: Downloads to temporary files on-demand for compatibility
 * - Automatic checksum generation and validation for integrity
 *
 * **Use Cases:**
 * - Knowledge document file attachments
 * - Chat interaction file uploads
 * - Artifact file storage
 * - Any user-uploaded content requiring persistence
 *
 * @property int $id
 * @property string $storage_key Path/key in the storage system
 * @property string $original_filename Original filename from upload
 * @property string $mime_type Detected MIME type
 * @property int $size_bytes File size in bytes
 * @property string $checksum SHA-256 checksum for integrity validation
 */
class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'storage_key',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    // Relationships
    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    // Accessors & Mutators
    public function getFileSizeHumanAttribute(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function getIsDocumentAttribute(): bool
    {
        return in_array($this->mime_type, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'text/markdown',
        ]);
    }

    public function getIsTextAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'text/');
    }

    // Helper methods
    public function exists(): bool
    {
        return Storage::exists($this->storage_key);
    }

    public function getContent(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        return Storage::get($this->storage_key);
    }

    public function getPath(): string
    {
        // Check if using S3 or local storage
        $disk = config('filesystems.default');

        if ($disk === 's3') {
            try {
                // For S3, download to temp file
                $tempPath = sys_get_temp_dir().'/asset_'.uniqid().'_'.basename($this->storage_key);
                $contents = Storage::get($this->storage_key);
                file_put_contents($tempPath, $contents);

                return $tempPath;
            } catch (\Exception $e) {
                Log::error('Failed to download asset from S3 to temporary file', [
                    'asset_id' => $this->id,
                    'storage_key' => $this->storage_key,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return Storage::path($this->storage_key);
    }

    public function getUrl(): string
    {
        return Storage::url($this->storage_key);
    }

    public function getTemporaryUrl(int $minutes = 60): string
    {
        return Storage::temporaryUrl($this->storage_key, now()->addMinutes($minutes));
    }

    /**
     * Get presigned S3 URL for backend processing
     *
     * @param  int  $minutes  Validity duration in minutes (default: 60)
     * @return string Presigned S3 URL
     */
    public function getPresignedUrl(int $minutes = 60): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $this->storage_key,
            now()->addMinutes($minutes)
        );
    }

    /**
     * Get authenticated download route for browser display
     *
     * @return string Laravel route URL
     */
    public function getDownloadRoute(): string
    {
        return route('assets.download', ['asset' => $this->id]);
    }

    /**
     * Get URL based on context
     *
     * @param  string  $context  'browser' or 'backend'
     * @return string Context-appropriate URL
     */
    public function getUrlForContext(string $context = 'browser'): string
    {
        return match ($context) {
            'backend' => $this->getPresignedUrl(120), // 2 hours for Pandoc
            'browser' => $this->getDownloadRoute(),
            default => $this->getDownloadRoute(),
        };
    }

    public function delete(): ?bool
    {
        // Delete the physical file first
        if ($this->exists()) {
            try {
                Storage::delete($this->storage_key);
            } catch (\Exception $e) {
                Log::error('Failed to delete physical asset file', [
                    'asset_id' => $this->id,
                    'storage_key' => $this->storage_key,
                    'original_filename' => $this->original_filename,
                    'error' => $e->getMessage(),
                ]);

                // Continue with model deletion even if file deletion fails
            }
        }

        // Delete the model record
        return parent::delete();
    }

    /**
     * Create an Asset from an uploaded file
     *
     * @param  UploadedFile  $file  The uploaded file instance
     * @param  string|null  $directory  Storage directory (defaults to 'assets')
     * @return self The created Asset instance
     *
     * @throws \Exception If file storage or checksum generation fails
     */
    public static function createFromFile(UploadedFile $file, ?string $directory = null): self
    {
        $directory = $directory ?? 'assets';
        $filename = $file->getClientOriginalName();
        $fileSize = $file->getSize();

        // Log warning for large file uploads
        if ($fileSize > 50 * 1024 * 1024) { // 50MB
            Log::warning('Large file upload detected', [
                'filename' => $filename,
                'size_bytes' => $fileSize,
                'size_human' => number_format($fileSize / (1024 * 1024), 2).' MB',
            ]);
        }

        // Compute checksum from file contents - works with both local and S3 temporary uploads
        // For S3 uploads, getRealPath() may return a path that doesn't exist locally
        $checksum = hash('sha256', $file->get());

        // Now store the file (may upload to S3 and delete temp file)
        $path = $file->store($directory);

        // Detect MIME type, with special handling for markdown files
        $mimeType = $file->getMimeType();

        // Override MIME type for .md files (often detected as text/plain)
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'md' && $mimeType === 'text/plain') {
            $mimeType = 'text/markdown';
        }

        return self::create([
            'storage_key' => $path,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => $fileSize,
            'checksum' => $checksum,
        ]);
    }

    public static function createFromContent(string $content, string $filename, string $mimeType, ?string $directory = null): self
    {
        $directory = $directory ?? 'assets';
        $path = $directory.'/'.uniqid().'_'.$filename;

        Storage::put($path, $content);

        return self::create([
            'storage_key' => $path,
            'original_filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
        ]);
    }

    public function validateChecksum(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        $currentChecksum = hash_file('sha256', $this->getPath());
        $isValid = $currentChecksum === $this->checksum;

        if (! $isValid) {
            Log::error('Asset checksum validation failed - file may be corrupted', [
                'asset_id' => $this->id,
                'original_filename' => $this->original_filename,
                'storage_key' => $this->storage_key,
                'expected_checksum' => $this->checksum,
                'actual_checksum' => $currentChecksum,
            ]);
        }

        return $isValid;
    }
}
