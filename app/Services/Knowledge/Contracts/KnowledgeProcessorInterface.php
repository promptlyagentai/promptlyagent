<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\DTOs\KnowledgeSource;
use App\Services\Knowledge\DTOs\ProcessedKnowledge;

interface KnowledgeProcessorInterface
{
    /**
     * Process a knowledge source into structured content
     */
    public function process(KnowledgeSource $source): ProcessedKnowledge;

    /**
     * Check if this processor supports the given content type
     */
    public function supports(string $contentType): bool;

    /**
     * Get the priority of this processor (higher = more preferred)
     */
    public function getPriority(): int;

    /**
     * Get supported file extensions or content types
     */
    public function getSupportedTypes(): array;

    /**
     * Validate the source before processing
     */
    public function validate(KnowledgeSource $source): bool;

    /**
     * Get processor name for identification
     */
    public function getName(): string;
}
