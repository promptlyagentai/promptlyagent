<?php

namespace App\Services\Knowledge\DTOs;

use Illuminate\Http\UploadedFile;

class KnowledgeSource
{
    public function __construct(
        public readonly string $type, // 'file', 'text', 'external'
        public readonly mixed $content, // UploadedFile, string, or array
        public readonly string $contentType, // MIME type or content identifier
        public readonly array $metadata = [], // Additional metadata
        public readonly ?string $filename = null,
        public readonly ?int $fileSize = null,
    ) {}

    public static function fromFile(UploadedFile $file, array $metadata = []): self
    {
        return new self(
            type: 'file',
            content: $file,
            contentType: 'file', // Use 'file' as contentType for file sources
            metadata: array_merge($metadata, [
                'mimeType' => $file->getMimeType() ?? 'application/octet-stream',
            ]),
            filename: $file->getClientOriginalName(),
            fileSize: $file->getSize(),
        );
    }

    public static function fromText(string $text, array $metadata = []): self
    {
        return new self(
            type: 'text',
            content: $text,
            contentType: 'text/plain',
            metadata: $metadata,
        );
    }

    public static function fromExternal(string $source, string $sourceType, array $metadata = []): self
    {
        return new self(
            type: 'external',
            content: $source,
            contentType: $sourceType,
            metadata: $metadata,
        );
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function isExternal(): bool
    {
        return $this->type === 'external';
    }

    public function getFile(): ?UploadedFile
    {
        return $this->isFile() ? $this->content : null;
    }

    public function getText(): ?string
    {
        return $this->isText() ? $this->content : null;
    }

    public function getExternalSource(): ?string
    {
        return $this->isExternal() ? $this->content : null;
    }
}
