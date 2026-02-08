<?php

namespace App\Models;

use App\Scout\Traits\HasVectorSearch;
use App\Services\EventStreamNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ChatInteractionSource extends Model
{
    use HasVectorSearch;

    protected $fillable = [
        'chat_interaction_id',
        'source_id',
        'relevance_score',
        'initial_relevance_score',
        'content_relevance_score',
        'discovery_method',
        'discovery_tool',
        'relevance_reasoning',
        'relevance_metadata',
        'content_summary',
        'summary_generated_at',
        'was_scraped',
        'recommended_for_scraping',
        'discovered_at',
        'last_relevance_update',
    ];

    protected $casts = [
        'relevance_metadata' => 'array',
        'was_scraped' => 'boolean',
        'recommended_for_scraping' => 'boolean',
        'discovered_at' => 'datetime',
        'last_relevance_update' => 'datetime',
        'summary_generated_at' => 'datetime',
    ];

    // Temporarily disabled to reduce event noise
    // protected $dispatchesEvents = [
    //     'created' => ChatInteractionSourceCreated::class,
    // ];

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'research_summaries';
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        $array = [
            'id' => $this->getScoutKey(),
            'document_id' => $this->id, // For Scout mapping
            'chat_interaction_id' => $this->chat_interaction_id,
            'source_id' => $this->source_id,
            'content_summary' => $this->content_summary,
            'relevance_score' => $this->relevance_score,
            'discovery_method' => $this->discovery_method,
            'discovery_tool' => $this->discovery_tool,
            'was_scraped' => $this->was_scraped,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'summary_generated_at' => $this->summary_generated_at?->timestamp,
        ];

        // Include source information
        if ($this->source) {
            $array['source_url'] = $this->source->url;
            $array['source_domain'] = $this->source->domain;
            $array['source_title'] = $this->source->title;
        }

        // Include embedding if available from trait
        if ($embedding = $this->getEmbedding()) {
            $array['_vectors'] = ['default' => $embedding];
        }

        return $array;
    }

    /**
     * Get content for embedding generation.
     */
    public function getEmbeddingContent(): string
    {
        $content = [];

        if ($this->content_summary) {
            $content[] = $this->content_summary;
        }

        // Include source context for better embeddings
        if ($this->source) {
            if ($this->source->title) {
                $content[] = $this->source->title;
            }
            if ($this->source->description) {
                $content[] = $this->source->description;
            }
        }

        return implode("\n\n", $content);
    }

    public function setEmbedding(array $embedding): void
    {
        // Use transient embedding storage (from HasVectorSearch trait)
        $this->tempEmbedding = $embedding;
    }

    public function getEmbedding(): ?array
    {
        // Use transient embedding storage (from HasVectorSearch trait)
        return $this->tempEmbedding;
    }

    /**
     * Determine if we should be searchable based on summary availability
     */
    public function shouldBeSearchable(): bool
    {
        return ! empty($this->content_summary);
    }

    /**
     * Relationship to chat interaction
     */
    public function chatInteraction(): BelongsTo
    {
        return $this->belongsTo(ChatInteraction::class);
    }

    /**
     * Relationship to source
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Calculate relevance score based on multiple factors
     */
    public static function calculateRelevanceScore(string $userQuery, array $sourceData): float
    {
        $score = 0.0;
        $factors = [];

        // Title relevance (40% weight)
        if (! empty($sourceData['title'])) {
            $titleScore = self::calculateTextRelevance($userQuery, $sourceData['title']);
            $score += $titleScore * 0.4;
            $factors['title_score'] = $titleScore;
        }

        // Description relevance (30% weight)
        if (! empty($sourceData['description'])) {
            $descScore = self::calculateTextRelevance($userQuery, $sourceData['description']);
            $score += $descScore * 0.3;
            $factors['description_score'] = $descScore;
        }

        // Domain authority bonus (10% weight)
        $domainScore = self::calculateDomainAuthority($sourceData['domain'] ?? '');
        $score += $domainScore * 0.1;
        $factors['domain_score'] = $domainScore;

        // Content category bonus (10% weight)
        $categoryScore = self::calculateCategoryBonus($sourceData['content_category'] ?? 'general');
        $score += $categoryScore * 0.1;
        $factors['category_score'] = $categoryScore;

        // HTTP status penalty (10% weight)
        $statusScore = self::calculateStatusScore($sourceData['http_status'] ?? 200);
        $score += $statusScore * 0.1;
        $factors['status_score'] = $statusScore;

        // Normalize to 0-10 scale
        $finalScore = min(10.0, max(0.0, $score * 10));

        Log::debug('Relevance score calculated', [
            'query' => $userQuery,
            'url' => $sourceData['url'] ?? 'unknown',
            'final_score' => $finalScore,
            'factors' => $factors,
        ]);

        return round($finalScore, 3);
    }

    /**
     * Calculate text relevance using keyword matching
     */
    private static function calculateTextRelevance(string $query, string $text): float
    {
        $query = strtolower($query);
        $text = strtolower($text);

        // Extract keywords from query (remove common words)
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'how', 'what', 'when', 'where', 'why', 'who'];
        $queryWords = array_filter(
            str_word_count($query, 1),
            fn ($word) => strlen($word) > 2 && ! in_array($word, $stopWords)
        );

        if (empty($queryWords)) {
            return 0.0;
        }

        $matches = 0;
        $totalWords = count($queryWords);

        foreach ($queryWords as $word) {
            if (str_contains($text, $word)) {
                $matches++;
            }
        }

        // Exact phrase bonus
        $phraseBonus = str_contains($text, $query) ? 0.2 : 0.0;

        return ($matches / $totalWords) + $phraseBonus;
    }

    /**
     * Calculate domain authority score
     */
    private static function calculateDomainAuthority(string $domain): float
    {
        $highAuthority = [
            'wikipedia.org' => 1.0,
            'scholar.google.com' => 0.95,
            'arxiv.org' => 0.9,
            'nature.com' => 0.9,
            'science.org' => 0.9,
            'ieee.org' => 0.85,
            'acm.org' => 0.85,
            'ncbi.nlm.nih.gov' => 0.85,
            'gov' => 0.8, // Government domains
            'edu' => 0.75, // Educational domains
        ];

        foreach ($highAuthority as $authorityDomain => $score) {
            if (str_contains($domain, $authorityDomain)) {
                return $score;
            }
        }

        // Check for TLD authority
        if (str_ends_with($domain, '.gov') || str_ends_with($domain, '.edu')) {
            return 0.7;
        }

        return 0.5; // Default authority
    }

    /**
     * Calculate category bonus
     */
    private static function calculateCategoryBonus(string $category): float
    {
        return match ($category) {
            'research', 'academic' => 0.9,
            'news' => 0.7,
            'documentation' => 0.8,
            'blog' => 0.6,
            'social' => 0.3,
            default => 0.5,
        };
    }

    /**
     * Calculate HTTP status score
     */
    private static function calculateStatusScore(int $status): float
    {
        if ($status < 300) {
            return 1.0;
        }
        if ($status < 400) {
            return 0.8;
        } // Redirects

        return 0.0; // Errors
    }

    /**
     * Create or update a chat interaction source with relevance scoring
     */
    public static function createOrUpdate(
        int $chatInteractionId,
        int $sourceId,
        string $userQuery,
        array $sourceData,
        string $discoveryMethod,
        string $discoveryTool
    ): self {
        // Calculate initial relevance score
        $relevanceScore = self::calculateRelevanceScore($userQuery, $sourceData);
        $recommendScraping = $relevanceScore >= 6.0; // Threshold for scraping recommendation

        // Try to find existing record
        $chatInteractionSource = self::where('chat_interaction_id', $chatInteractionId)
            ->where('source_id', $sourceId)
            ->first();

        $data = [
            'relevance_score' => $relevanceScore,
            'initial_relevance_score' => $relevanceScore,
            'discovery_method' => $discoveryMethod,
            'discovery_tool' => $discoveryTool,
            'relevance_reasoning' => self::generateRelevanceReasoning($userQuery, $sourceData, $relevanceScore),
            'relevance_metadata' => [
                'query' => $userQuery,
                'calculated_at' => now()->toISOString(),
                'score_factors' => self::getScoreFactors($userQuery, $sourceData),
            ],
            'recommended_for_scraping' => $recommendScraping,
            'discovered_at' => now(),
            'last_relevance_update' => now(),
        ];

        if ($chatInteractionSource) {
            // Update existing record with higher relevance score if found
            if ($relevanceScore > $chatInteractionSource->relevance_score) {
                $chatInteractionSource->update($data);
                Log::info('Updated chat interaction source with higher relevance', [
                    'chat_interaction_id' => $chatInteractionId,
                    'source_id' => $sourceId,
                    'old_score' => $chatInteractionSource->relevance_score,
                    'new_score' => $relevanceScore,
                ]);
            }

            return $chatInteractionSource;
        } else {
            // Create new record
            $data['chat_interaction_id'] = $chatInteractionId;
            $data['source_id'] = $sourceId;

            $chatInteractionSource = self::create($data);

            Log::info('Created chat interaction source', [
                'chat_interaction_id' => $chatInteractionId,
                'source_id' => $sourceId,
                'relevance_score' => $relevanceScore,
                'recommended_for_scraping' => $recommendScraping,
                'discovery_method' => $discoveryMethod,
            ]);

            // Send real-time source added event
            EventStreamNotifier::sourceAdded($chatInteractionId, [
                'source_id' => $sourceId,
                'url' => $sourceData['url'] ?? null,
                'title' => $sourceData['title'] ?? null,
                'domain' => $sourceData['domain'] ?? null,
                'discovery_method' => $discoveryMethod,
                'relevance_score' => $relevanceScore,
            ]);

            return $chatInteractionSource;
        }
    }

    /**
     * Generate human-readable relevance reasoning
     */
    private static function generateRelevanceReasoning(string $query, array $sourceData, float $score): string
    {
        $reasons = [];

        if ($score >= 8.0) {
            $reasons[] = 'Highly relevant to query';
        } elseif ($score >= 6.0) {
            $reasons[] = 'Moderately relevant to query';
        } elseif ($score >= 4.0) {
            $reasons[] = 'Some relevance to query';
        } else {
            $reasons[] = 'Limited relevance to query';
        }

        if (! empty($sourceData['title']) && self::calculateTextRelevance($query, $sourceData['title']) > 0.5) {
            $reasons[] = 'title contains relevant keywords';
        }

        $domain = $sourceData['domain'] ?? '';
        if (self::calculateDomainAuthority($domain) >= 0.8) {
            $reasons[] = 'high-authority domain';
        }

        $category = $sourceData['content_category'] ?? 'general';
        if (in_array($category, ['research', 'academic', 'documentation'])) {
            $reasons[] = 'authoritative content type';
        }

        return ucfirst(implode(', ', $reasons)).'.';
    }

    /**
     * Get detailed score factors for debugging
     */
    private static function getScoreFactors(string $query, array $sourceData): array
    {
        return [
            'title_relevance' => ! empty($sourceData['title']) ? self::calculateTextRelevance($query, $sourceData['title']) : 0,
            'description_relevance' => ! empty($sourceData['description']) ? self::calculateTextRelevance($query, $sourceData['description']) : 0,
            'domain_authority' => self::calculateDomainAuthority($sourceData['domain'] ?? ''),
            'category_bonus' => self::calculateCategoryBonus($sourceData['content_category'] ?? 'general'),
            'status_score' => self::calculateStatusScore($sourceData['http_status'] ?? 200),
        ];
    }

    /**
     * Update relevance score after content scraping
     */
    public function updateAfterScraping(float $contentRelevanceScore): void
    {
        $this->update([
            'content_relevance_score' => $contentRelevanceScore,
            'was_scraped' => true,
            'last_relevance_update' => now(),
            'relevance_score' => max($this->initial_relevance_score, $contentRelevanceScore), // Take the higher score
        ]);

        Log::info('Updated relevance score after scraping', [
            'chat_interaction_source_id' => $this->id,
            'initial_score' => $this->initial_relevance_score,
            'content_score' => $contentRelevanceScore,
            'final_score' => $this->relevance_score,
        ]);
    }

    /**
     * Generate and store a summary of the source content focused on the query
     */
    public function generateAndStoreSummary(string $query): string
    {
        // Skip if we already have a summary that's less than 24 hours old
        if ($this->content_summary && $this->summary_generated_at &&
            $this->summary_generated_at->isAfter(now()->subHours(24))) {
            return $this->content_summary;
        }

        // Get the source content
        $source = $this->source;
        if (! $source || ! $source->content_markdown) {
            Log::warning('Cannot generate summary - source has no content', [
                'chat_interaction_source_id' => $this->id,
                'source_id' => $this->source_id,
            ]);

            return '';
        }

        try {
            // Create a LLM client to generate the summary
            $client = app(\App\Services\Agents\LlmClientFactory::class)->create();

            // Create prompt for summary generation
            $prompt = $this->createSummaryPrompt($query, $source->content_markdown, $source->title ?? '');

            // Generate summary
            $response = $client->complete([
                'prompt' => $prompt,
                'max_tokens' => 750,
                'temperature' => 0.3,
            ]);

            $summary = trim($response->completion);

            // Update the model with the generated summary
            $this->update([
                'content_summary' => $summary,
                'summary_generated_at' => now(),
            ]);

            Log::info('Generated content summary for source', [
                'chat_interaction_source_id' => $this->id,
                'source_id' => $this->source_id,
                'summary_length' => strlen($summary),
            ]);

            return $summary;

        } catch (\Throwable $e) {
            Log::error('Failed to generate content summary', [
                'chat_interaction_source_id' => $this->id,
                'source_id' => $this->source_id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Create a prompt for generating a summary focused on the query
     */
    private function createSummaryPrompt(string $query, string $content, string $title = ''): string
    {
        $contentPreview = substr($content, 0, 10000); // Limit content to first 10K characters for LLM processing

        return <<<EOT
You are an expert content summarizer. Your task is to create a focused summary of the provided content that is relevant to the user's query.

USER QUERY:
{$query}

DOCUMENT TITLE:
{$title}

DOCUMENT CONTENT:
{$contentPreview}

INSTRUCTIONS:
1. Create a concise summary (350-500 words) focused specifically on information relevant to answering the user's query
2. Extract only the most relevant facts, data, and insights from the document
3. Maintain objective tone and include specific details (dates, numbers, key terms, etc.) where relevant
4. DO NOT include your own analysis or opinions
5. DO NOT include information that is not present in the original document
6. DO NOT mention that this is a summary - just provide the relevant content

SUMMARY:
EOT;
    }
}
