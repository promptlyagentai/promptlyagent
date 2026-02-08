<?php

namespace App\Services\Assets;

use App\Models\Asset;
use Illuminate\Http\StreamedResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Asset Service - File Upload and Storage Management.
 *
 * Provides centralized file upload, validation, and storage management for the
 * application. Handles security validation, storage organization, and asset
 * lifecycle management.
 *
 * Key Responsibilities:
 * - File upload with validation (size, MIME type, security checks)
 * - Storage path generation and organization
 * - Asset record creation and tracking
 * - Orphaned asset cleanup
 * - File serving and downloads
 *
 * Security Validation:
 * - File size limits enforced
 * - MIME type validation
 * - Malicious file detection
 * - Path traversal prevention
 *
 * Storage Strategy:
 * - Default disk: storage/app/assets
 * - Organized by optional directory parameter
 * - Unique filenames prevent collisions
 * - Storage keys tracked in database
 *
 * @see \App\Models\Asset
 */
class AssetService
{
    /**
     * Upload a file and create an asset record
     */
    public function upload(UploadedFile $file, ?string $directory = null): Asset
    {
        $this->validateFile($file);

        return Asset::createFromFile($file, $directory);
    }

    /**
     * Store content as a file and create an asset record
     */
    public function store(string $content, string $filename, string $mimeType, ?string $directory = null): Asset
    {
        $this->validateContent($content, $filename, $mimeType);

        return Asset::createFromContent($content, $filename, $mimeType, $directory);
    }

    /**
     * Retrieve asset content
     */
    public function retrieve(Asset $asset): string|StreamedResponse
    {
        if (! $asset->exists()) {
            throw new \RuntimeException("Asset file not found: {$asset->storage_key}");
        }

        // For small text files, return content directly
        if ($asset->is_text && $asset->size_bytes < 1048576) { // 1MB
            return $asset->getContent();
        }

        // For larger files or binary content, return streamed response
        return Storage::response($asset->storage_key, $asset->original_filename, [
            'Content-Type' => $asset->mime_type,
        ]);
    }

    /**
     * Delete an asset and its physical file
     */
    public function delete(Asset $asset): bool
    {
        return $asset->delete();
    }

    /**
     * Generate a signed URL for temporary access
     */
    public function generateSignedUrl(Asset $asset, int $ttl = 3600): string
    {
        if (! $asset->exists()) {
            throw new \RuntimeException("Asset file not found: {$asset->storage_key}");
        }

        return $asset->getTemporaryUrl($ttl / 60);
    }

    /**
     * Validate uploaded file
     */
    public function validateFile(UploadedFile $file): bool
    {
        $validator = Validator::make([
            'file' => $file,
        ], [
            'file' => [
                'required',
                'file',
                'max:'.config('documents.max_file_size', 10240), // Default 10MB
                'mimes:'.config('documents.allowed_mimes', 'pdf,doc,docx,txt,md,jpg,jpeg,png,gif,csv,json,xml'),
            ],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return true;
    }

    /**
     * Validate content for storage
     */
    protected function validateContent(string $content, string $filename, string $mimeType): bool
    {
        $maxSize = config('documents.max_file_size', 10240) * 1024; // Convert KB to bytes

        if (strlen($content) > $maxSize) {
            throw new \InvalidArgumentException("Content size exceeds maximum allowed size of {$maxSize} bytes");
        }

        $allowedMimes = explode(',', config('documents.allowed_mimes', 'text/plain,text/markdown,application/json,application/xml'));

        if (! in_array($mimeType, $allowedMimes)) {
            throw new \InvalidArgumentException("MIME type '{$mimeType}' is not allowed");
        }

        return true;
    }

    /**
     * Get asset by ID with validation
     */
    public function findAsset(int $assetId): Asset
    {
        $asset = Asset::find($assetId);

        if (! $asset) {
            throw new \RuntimeException("Asset not found with ID: {$assetId}");
        }

        return $asset;
    }

    /**
     * Duplicate an asset (useful for versioning)
     */
    public function duplicate(Asset $asset): Asset
    {
        if (! $asset->exists()) {
            throw new \RuntimeException("Cannot duplicate asset - file not found: {$asset->storage_key}");
        }

        $content = $asset->getContent();
        $filename = 'copy_'.$asset->original_filename;

        return $this->store($content, $filename, $asset->mime_type);
    }

    /**
     * Get storage statistics
     */
    public function getStorageStats(): array
    {
        $totalAssets = Asset::count();
        $totalSizeBytes = Asset::sum('size_bytes');

        return [
            'total_assets' => $totalAssets,
            'total_size_bytes' => $totalSizeBytes,
            'total_size_human' => $this->formatBytes($totalSizeBytes),
            'average_size_bytes' => $totalAssets > 0 ? intval($totalSizeBytes / $totalAssets) : 0,
        ];
    }

    /**
     * Format bytes for human readability
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Cleanup orphaned assets (no longer referenced by any documents)
     */
    public function cleanupOrphanedAssets(): int
    {
        // Find assets not referenced by any knowledge documents or documents
        $orphanedAssets = Asset::whereDoesntHave('knowledgeDocuments')
            ->whereDoesntHave('documents')
            ->where('created_at', '<', now()->subDays(7)) // Only cleanup assets older than 7 days
            ->get();

        $deletedCount = 0;

        foreach ($orphanedAssets as $asset) {
            if ($this->delete($asset)) {
                $deletedCount++;
            }
        }

        return $deletedCount;
    }
}
