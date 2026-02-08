<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\DTOs\RAGQuery;
use App\Services\Knowledge\DTOs\RAGResult;
use Illuminate\Support\Collection;

interface RAGInterface
{
    /**
     * Query knowledge base for relevant information
     */
    public function query(RAGQuery $query): RAGResult;

    /**
     * Get relevant knowledge for a specific agent
     */
    public function getRelevantKnowledge(string $agentId, string $query, array $options = []): Collection;

    /**
     * Search knowledge by tags
     */
    public function searchByTags(array $tagIds, string $query, array $options = []): Collection;

    /**
     * Search knowledge by document IDs
     */
    public function searchByDocuments(array $documentIds, string $query, array $options = []): Collection;

    /**
     * Search all available knowledge for a user
     */
    public function searchForUser(int $userId, string $query, array $options = []): Collection;

    /**
     * Check if knowledge is expired based on TTL
     */
    public function isExpired(int $documentId): bool;

    /**
     * Get expired documents
     */
    public function getExpiredDocuments(array $documentIds = []): Collection;

    /**
     * Filter results by TTL status
     */
    public function filterByTTL(Collection $results, bool $includeExpired = false): Collection;

    /**
     * Rank search results by relevance and other factors
     */
    public function rankResults(Collection $results, array $criteria = []): Collection;

    /**
     * Generate context from search results for AI consumption
     */
    public function generateContext(Collection $results, int $maxLength = 4000, ?string $query = null): string;
}
