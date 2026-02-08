<?php

namespace App\Services\Knowledge\Contracts;

use App\Models\KnowledgeDocument;
use Illuminate\Http\UploadedFile;

interface KnowledgeManagerInterface
{
    /**
     * Create knowledge from uploaded file
     * Returns KnowledgeDocument for single files or array for archive processing
     */
    public function createFromFile(
        UploadedFile $file,
        string $title,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null
    ): KnowledgeDocument|array;

    /**
     * Create knowledge from text input
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
    ): KnowledgeDocument;

    /**
     * Create knowledge from external source
     */
    public function createFromExternal(
        string $source,
        string $sourceType,
        string $title,
        ?string $description = null,
        array $tags = [],
        string $privacyLevel = 'private',
        ?int $ttlHours = null,
        ?int $userId = null
    ): KnowledgeDocument;

    /**
     * Update existing knowledge document
     */
    public function updateDocument(
        KnowledgeDocument $document,
        array $data
    ): KnowledgeDocument;

    /**
     * Delete knowledge document
     */
    public function deleteDocument(KnowledgeDocument $document): bool;

    /**
     * Process knowledge document (extract text, vectorize, index)
     */
    public function processDocument(KnowledgeDocument $document): bool;

    /**
     * Reprocess document (useful after processor updates)
     */
    public function reprocessDocument(KnowledgeDocument $document): bool;

    /**
     * Assign knowledge to an agent
     */
    public function assignToAgent(int $agentId, int $documentId, array $config = []): bool;

    /**
     * Assign knowledge tag to an agent
     */
    public function assignTagToAgent(int $agentId, int $tagId, array $config = []): bool;

    /**
     * Remove knowledge assignment from agent
     */
    public function removeFromAgent(int $agentId, ?int $documentId = null, ?int $tagId = null): bool;

    /**
     * Get documents accessible by user
     */
    public function getAccessibleDocuments(int $userId, array $filters = []);

    /**
     * Check if user can access document
     */
    public function canAccess(int $userId, KnowledgeDocument $document): bool;

    /**
     * Get expired documents
     */
    public function getExpiredDocuments();

    /**
     * Cleanup expired documents
     */
    public function cleanupExpiredDocuments(bool $dryRun = true): int;
}
