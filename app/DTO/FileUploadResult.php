<?php

namespace App\DTO;

/**
 * Data Transfer Object for file upload results.
 *
 * Contains validated and processed file information after successful upload.
 * Immutable readonly class ensures data integrity throughout the application.
 *
 * @property string $path S3 storage path
 * @property string $filename Original client filename (sanitized)
 * @property string $mimeType Content-based MIME type from validator
 * @property int $size File size in bytes
 * @property string $type Determined file type (image, video, audio, document)
 * @property array $metadata Additional validation metadata from SecureFileValidator
 */
readonly class FileUploadResult
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
        public int $size,
        public string $type,
        public array $metadata = []
    ) {}
}
