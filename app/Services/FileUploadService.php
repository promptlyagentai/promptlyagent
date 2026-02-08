<?php

namespace App\Services;

use App\DTO\FileUploadResult;
use App\Exceptions\FileValidationException;
use App\Services\InputTrigger\SecureFileValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Centralized file upload service with consistent validation and storage.
 *
 * Consolidates duplicate file upload logic across multiple controllers:
 * - InputTriggerController
 * - ApiChatController
 * - KnowledgeApiController
 *
 * Features:
 * - Security validation via SecureFileValidator
 * - Consistent error handling with cleanup callbacks
 * - Type determination from MIME types
 * - S3 storage with configurable paths
 * - Comprehensive logging for audit trail
 *
 * SECURITY: All uploads go through SecureFileValidator to prevent:
 * - RCE via executable files
 * - XSS via malicious HTML/SVG
 * - Path traversal attacks
 * - ZIP bombs and excessive file sizes
 */
class FileUploadService
{
    public function __construct(
        protected SecureFileValidator $validator
    ) {}

    /**
     * Validate file without storing it.
     *
     * Use this when you need to validate a file but defer storage to later processing.
     *
     * @param  UploadedFile  $file  File to validate
     * @param  array  $context  Logging context for audit trail
     * @return array Validation result data from SecureFileValidator
     *
     * @throws FileValidationException If file fails security validation
     */
    public function validateOnly(UploadedFile $file, array $context = []): array
    {
        $validationResult = $this->validator->validate($file);

        if (! $validationResult->valid) {
            Log::warning('File validation failed', array_merge([
                'filename' => $file->getClientOriginalName(),
                'error' => $validationResult->error,
            ], $context));

            throw new FileValidationException($validationResult->error);
        }

        return $validationResult->data;
    }

    /**
     * Validate and upload file with consistent error handling.
     *
     * @param  UploadedFile  $file  File to upload
     * @param  string  $storagePath  Base storage path (e.g., 'trigger-attachments')
     * @param  array  $context  Logging context for audit trail
     * @param  callable|null  $onFailure  Cleanup callback executed on validation failure
     * @return FileUploadResult Immutable result containing file metadata
     *
     * @throws FileValidationException If file fails security validation
     */
    public function uploadAndValidate(
        UploadedFile $file,
        string $storagePath = 'uploads',
        array $context = [],
        ?callable $onFailure = null
    ): FileUploadResult {
        // SECURITY: Validate file using SecureFileValidator
        // Prevents RCE, XSS, path traversal, ZIP bombs, and malicious uploads
        $validationResult = $this->validator->validate($file);

        if (! $validationResult->valid) {
            Log::warning('File upload validation failed', array_merge([
                'filename' => $file->getClientOriginalName(),
                'error' => $validationResult->error,
                'storage_path' => $storagePath,
            ], $context));

            // Execute cleanup callback if provided
            // Used by controllers that create database records before validation
            if ($onFailure) {
                $onFailure($validationResult);
            }

            throw new FileValidationException($validationResult->error);
        }

        // Use validated data from SecureFileValidator
        $safeFilename = $validationResult->data['original_filename'];
        $validatedMimeType = $validationResult->data['mime_type'];
        $fileSize = $file->getSize();

        // Store file to S3 (let Laravel generate safe filename automatically)
        Log::debug('FileUploadService: Starting S3 upload', array_merge([
            'filename' => $safeFilename,
            'storage_path' => $storagePath,
            'file_size' => $fileSize,
        ], $context));

        try {
            // Use store() instead of storeAs() - Laravel generates unique filename
            // This matches the original master branch behavior that was working
            $path = $file->store($storagePath, 's3');

            if (! $path) {
                throw new \RuntimeException('File storage returned false - S3 upload may have failed');
            }
        } catch (\Exception $e) {
            Log::error('File upload to S3 failed', array_merge([
                'filename' => $safeFilename,
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ], $context));

            // Execute cleanup callback if provided
            if ($onFailure) {
                $onFailure($validationResult);
            }

            throw new FileValidationException('File storage failed: '.$e->getMessage());
        }

        // Determine file type from MIME type
        $type = $this->determineFileType($validatedMimeType);

        Log::info('File uploaded successfully', array_merge([
            'filename' => $safeFilename,
            'path' => $path,
            'type' => $type,
            'size' => $fileSize,
            'mime_type' => $validatedMimeType,
        ], $context));

        return new FileUploadResult(
            path: $path,
            filename: $safeFilename,
            mimeType: $validatedMimeType,
            size: $fileSize,
            type: $type,
            metadata: $validationResult->data
        );
    }

    /**
     * Determine file type category from MIME type.
     *
     * @param  string  $mimeType  Content-based MIME type
     * @return string One of: image, video, audio, document
     */
    protected function determineFileType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
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
}
