<?php

namespace App\Traits;

use App\Models\Asset;
use App\Services\Assets\AssetService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * HasFileStorage Trait - Asset-Based File Storage with Lifecycle Management.
 *
 * Provides asset-based file storage capabilities with automatic lifecycle management.
 * Models using this trait can attach uploaded files or raw content as Asset records,
 * with automatic cleanup when the model is deleted.
 *
 * Requirements:
 * - Model must have `asset_id` column (nullable foreign key to assets table)
 * - Model must have BelongsTo relationship configured (handled by trait)
 *
 * Features:
 * - Upload file handling (UploadedFile → Asset)
 * - Raw content storage (string → Asset)
 * - Automatic asset deletion on model deletion
 * - File type detection (image, document, text)
 * - URL generation (public and temporary signed URLs)
 *
 * Lifecycle:
 * 1. Attach file/content → Creates Asset record → Updates model's asset_id
 * 2. Model deleted → Boot hook deletes associated Asset automatically
 *
 * Usage Example:
 * ```php
 * class Document extends Model {
 *     use HasFileStorage;
 * }
 *
 * $document = Document::create(['title' => 'Report']);
 * $document->attachFile($request->file('upload'), 'documents');
 * $document->hasFile(); // true
 * $url = $document->getFileUrl();
 * ```
 *
 * @see \App\Models\Asset
 * @see \App\Services\Assets\AssetService
 */
trait HasFileStorage
{
    /**
     * Boot the trait - Register automatic asset cleanup on model deletion
     */
    protected static function bootHasFileStorage(): void
    {
        // Clean up asset when model is deleted
        static::deleting(function ($model) {
            if ($model->asset_id && $model->asset) {
                try {
                    app(AssetService::class)->delete($model->asset);
                } catch (\Exception $e) {
                    Log::error('Failed to delete asset during model deletion', [
                        'model' => static::class,
                        'model_id' => $model->id,
                        'asset_id' => $model->asset_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    throw $e;
                }
            } elseif ($model->asset_id) {
                Log::warning('Model has asset_id but asset relationship returned null', [
                    'model' => static::class,
                    'model_id' => $model->id,
                    'asset_id' => $model->asset_id,
                ]);
            }
        });
    }

    /**
     * Relationship to asset
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Attach an uploaded file to this model
     *
     * If the model already has an attached file, it will be deleted and replaced.
     * The new Asset is automatically associated with this model via asset_id.
     *
     * @param  UploadedFile  $file  The uploaded file from request
     * @param  string|null  $directory  Storage subdirectory (optional)
     * @return Asset The created Asset record
     *
     * @throws \Exception If file upload fails or asset creation fails
     */
    public function attachFile(UploadedFile $file, ?string $directory = null): Asset
    {
        $assetService = app(AssetService::class);

        try {
            // Delete existing asset if any
            if ($this->asset_id && $this->asset) {
                $assetService->delete($this->asset);
            }

            // Create new asset
            $asset = $assetService->upload($file, $directory);

            // Update model with new asset
            $this->update(['asset_id' => $asset->id]);

            return $asset;
        } catch (\Exception $e) {
            Log::error('Failed to attach file to model', [
                'model' => static::class,
                'model_id' => $this->id,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Attach raw content as a file to this model
     *
     * Creates an Asset from raw string content with specified filename and MIME type.
     * If the model already has an attached file, it will be deleted and replaced.
     *
     * @param  string  $content  Raw file content
     * @param  string  $filename  Desired filename
     * @param  string  $mimeType  Content MIME type
     * @param  string|null  $directory  Storage subdirectory (optional)
     * @return Asset The created Asset record
     *
     * @throws \Exception If content storage fails or asset creation fails
     */
    public function attachContent(string $content, string $filename, string $mimeType, ?string $directory = null): Asset
    {
        $assetService = app(AssetService::class);

        try {
            // Delete existing asset if any
            if ($this->asset_id && $this->asset) {
                $assetService->delete($this->asset);
            }

            // Create new asset from content
            $asset = $assetService->store($content, $filename, $mimeType, $directory);

            // Update model with new asset
            $this->update(['asset_id' => $asset->id]);

            return $asset;
        } catch (\Exception $e) {
            Log::error('Failed to attach content to model', [
                'model' => static::class,
                'model_id' => $this->id,
                'filename' => $filename,
                'content_length' => strlen($content),
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Get file content
     */
    public function getFileContent(): ?string
    {
        if (! $this->asset) {
            return null;
        }

        return $this->asset->getContent();
    }

    /**
     * Get file URL
     */
    public function getFileUrl(): ?string
    {
        if (! $this->asset) {
            return null;
        }

        return $this->asset->getUrl();
    }

    /**
     * Get temporary file URL
     */
    public function getTemporaryFileUrl(int $minutes = 60): ?string
    {
        if (! $this->asset) {
            return null;
        }

        return $this->asset->getTemporaryUrl($minutes);
    }

    /**
     * Check if model has a file attached
     */
    public function hasFile(): bool
    {
        return $this->asset_id && $this->asset && $this->asset->exists();
    }

    /**
     * Get file size in human readable format
     */
    public function getFileSizeHuman(): ?string
    {
        if (! $this->asset) {
            return null;
        }

        return $this->asset->file_size_human;
    }

    /**
     * Check if attached file is an image
     */
    public function isImage(): bool
    {
        return $this->asset && $this->asset->is_image;
    }

    /**
     * Check if attached file is a document
     */
    public function isDocument(): bool
    {
        return $this->asset && $this->asset->is_document;
    }

    /**
     * Check if attached file is text
     */
    public function isText(): bool
    {
        return $this->asset && $this->asset->is_text;
    }

    /**
     * Detach and delete the current file
     *
     * Removes the asset association and deletes the underlying Asset record.
     * Returns true if no asset was attached or deletion succeeded.
     *
     * @return bool True if successful or no file attached, false otherwise
     *
     * @throws \Exception If asset deletion fails
     */
    public function detachFile(): bool
    {
        if (! $this->asset_id || ! $this->asset) {
            return true;
        }

        $assetService = app(AssetService::class);

        try {
            $success = $assetService->delete($this->asset);

            if ($success) {
                $this->update(['asset_id' => null]);
            } else {
                Log::warning('Asset deletion returned false but did not throw exception', [
                    'model' => static::class,
                    'model_id' => $this->id,
                    'asset_id' => $this->asset_id,
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Failed to detach file from model', [
                'model' => static::class,
                'model_id' => $this->id,
                'asset_id' => $this->asset_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
