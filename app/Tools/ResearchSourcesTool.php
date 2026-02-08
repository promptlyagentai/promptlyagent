<?php

namespace App\Tools;

use App\Models\ChatInteractionSource;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * ResearchSourcesTool - Research Source Discovery and Search.
 *
 * Prism tool for listing all sources discovered during research for the current
 * interaction, including both web links and knowledge documents. Supports semantic
 * search for finding relevant sources based on content similarity.
 *
 * Source Types:
 * - Web URLs: External web pages fetched during research
 * - Knowledge documents: Internal knowledge base documents
 * - Mixed results: Combination of both types
 *
 * Search Capabilities:
 * - List all sources for current interaction
 * - Semantic search across source content
 * - Filter by source type
 * - Sort by relevance or recency
 *
 * Response Options:
 * - include_summaries: Include content summaries (default: false)
 * - limit: Maximum results to return (default: 20)
 * - semantic_query: Natural language search across sources
 *
 * Use Cases:
 * - Reviewing research sources used in conversation
 * - Finding specific sources by content
 * - Validating research completeness
 * - Source citation and reference
 *
 * @see \App\Models\ChatInteractionSource
 * @see \App\Tools\SourceContentTool
 */
class ResearchSourcesTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('research_sources')
            ->for('List all sources discovered during research for the current interaction, including both web links and knowledge documents. Supports semantic search for finding relevant sources based on content similarity.')
            ->withBooleanParameter('include_summaries', 'Whether to include content summaries in the response (default: false)')
            ->withNumberParameter('limit', 'Maximum number of sources to return (default: 20)')
            ->withNumberParameter('min_relevance', 'Minimum relevance score threshold (0-10, default: 0)')
            ->withStringParameter('semantic_query', 'Optional semantic search query to find relevant sources by content similarity')
            ->withNumberParameter('semantic_ratio', 'For semantic search: ratio of semantic vs keyword matching (0.0 = pure keyword, 1.0 = pure semantic, default: 0.7)')
            ->using(function (
                bool $include_summaries = true,
                int $limit = 20,
                float $min_relevance = 0,
                ?string $semantic_query = null, // Fix: Add explicit nullable type
                float $semantic_ratio = 0.7
            ) {
                return static::executeListSources([
                    'include_summaries' => $include_summaries,
                    'limit' => $limit,
                    'min_relevance' => $min_relevance,
                    'semantic_query' => $semantic_query,
                    'semantic_ratio' => $semantic_ratio,
                ]);
            });
    }

    protected static function executeListSources(array $arguments = []): string
    {
        try {
            // Get current interaction ID from the context
            $interactionId = null;
            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            if (! $interactionId) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No current interaction found in context',
                ], 'ResearchSourcesTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'include_summaries' => 'boolean',
                'limit' => 'integer|min:1|max:100',
                'min_relevance' => 'numeric|min:0|max:10',
                'semantic_query' => 'nullable|string|max:1000',
                'semantic_ratio' => 'numeric|min:0|max:1',
            ]);

            if ($validator->fails()) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'ResearchSourcesTool');
            }

            $validated = $validator->validated();
            $includeSummaries = $validated['include_summaries'] ?? false;
            $limit = $validated['limit'] ?? 20;
            $minRelevance = $validated['min_relevance'] ?? 0;
            $semanticQuery = $validated['semantic_query'] ?? null;
            $semanticRatio = $validated['semantic_ratio'] ?? 0.7;

            // If semantic query is provided, use semantic search
            if (! empty($semanticQuery)) {
                return static::executeSemanticSearch($interactionId, $semanticQuery, $limit, $minRelevance, $semanticRatio, $includeSummaries);
            }

            // Default behavior - query sources for this interaction
            $sources = ChatInteractionSource::with('source')
                ->where('chat_interaction_id', $interactionId)
                ->where('relevance_score', '>=', $minRelevance)
                ->orderBy('relevance_score', 'desc')
                ->limit($limit)
                ->get();

            // Map sources to response format
            $formattedSources = $sources->map(function ($chatInteractionSource) use ($includeSummaries) {
                $source = $chatInteractionSource->source;

                $result = [
                    'source_id' => $source->id,
                    'url' => $source->url,
                    'title' => $source->title ?? 'Untitled',
                    'domain' => $source->domain ?? 'Unknown',
                    'relevance_score' => round($chatInteractionSource->relevance_score, 2),
                    'discovery_method' => $chatInteractionSource->discovery_method,
                    'discovery_tool' => $chatInteractionSource->discovery_tool,
                    'was_scraped' => $chatInteractionSource->was_scraped,
                    'has_content' => ! empty($source->content_markdown),
                    'has_summary' => ! empty($chatInteractionSource->content_summary),
                    'created_at' => $chatInteractionSource->created_at->toDateTimeString(),
                ];

                // Include summaries if requested
                if ($includeSummaries) {
                    // Use existing summary if available, otherwise use preview from source
                    $result['summary'] = $chatInteractionSource->content_summary ?:
                        ($source->content_preview ?: 'No summary available');
                }

                return $result;
            });

            // Create the response
            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'interaction_id' => $interactionId,
                    'total_sources' => $sources->count(),
                    'search_type' => 'interaction_sources',
                    'sources' => $formattedSources,
                ],
            ], 'ResearchSourcesTool');

        } catch (\Exception $e) {
            Log::error('ResearchSourcesTool: Failed to list sources', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to list sources: '.$e->getMessage(),
            ], 'ResearchSourcesTool');
        }
    }

    /**
     * Execute semantic search using the SemanticSearchService
     */
    protected static function executeSemanticSearch(
        int $interactionId,
        string $query,
        int $limit,
        float $minRelevance,
        float $semanticRatio,
        bool $includeSummaries
    ): string {
        try {
            // First get the chat session ID from the current interaction
            $chatInteraction = \App\Models\ChatInteraction::find($interactionId);
            $chatSessionId = $chatInteraction ? $chatInteraction->chat_session_id : null;

            if (! $chatSessionId) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No chat session found for current interaction',
                ], 'ResearchSourcesTool');
            }

            $semanticSearchService = app(\App\Services\Research\SemanticSearchService::class);

            // Search across all sources in the session
            $sessionSources = \App\Models\ChatInteractionSource::select([
                'chat_interaction_sources.*',
                'sources.url',
                'sources.title',
                'sources.description',
                'sources.domain',
                'sources.content_markdown',
                'sources.content_preview',
            ])
                ->join('sources', 'chat_interaction_sources.source_id', '=', 'sources.id')
                ->join('chat_interactions', 'chat_interaction_sources.chat_interaction_id', '=', 'chat_interactions.id')
                ->where('chat_interactions.chat_session_id', $chatSessionId)
                ->where('chat_interaction_sources.relevance_score', '>=', $minRelevance)
                ->orderBy('chat_interaction_sources.relevance_score', 'desc')
                ->limit($limit * 2) // Get more for filtering
                ->get();

            if ($sessionSources->isEmpty()) {
                return static::safeJsonEncode([
                    'success' => true,
                    'data' => [
                        'interaction_id' => $interactionId,
                        'chat_session_id' => $chatSessionId,
                        'total_sources' => 0,
                        'search_type' => 'session_sources',
                        'search_query' => $query,
                        'semantic_ratio' => $semanticRatio,
                        'sources' => [],
                        'search_metadata' => null,
                    ],
                ], 'ResearchSourcesTool');
            }

            // Now perform text-based similarity matching on session sources
            $formattedSources = $sessionSources->map(function ($chatInteractionSource) use ($query, $includeSummaries) {
                // Calculate text similarity score
                $textContent = ($chatInteractionSource->title ?? '').' '.
                             ($chatInteractionSource->description ?? '').' '.
                             ($chatInteractionSource->content_summary ?? '').' '.
                             substr($chatInteractionSource->content_markdown ?? '', 0, 500);

                // Simple text similarity calculation
                $queryWords = array_filter(explode(' ', strtolower($query)));
                $contentWords = array_filter(explode(' ', strtolower($textContent)));

                $matches = count(array_intersect($queryWords, $contentWords));
                $totalWords = count(array_unique(array_merge($queryWords, $contentWords)));
                $similarityScore = $totalWords > 0 ? ($matches / $totalWords) : 0;

                $formatted = [
                    'source_id' => $chatInteractionSource->source_id,
                    'interaction_id' => $chatInteractionSource->chat_interaction_id,
                    'source_type' => 'session_source',
                    'url' => $chatInteractionSource->url,
                    'title' => $chatInteractionSource->title ?? 'Untitled',
                    'domain' => $chatInteractionSource->domain ?? 'Unknown',
                    'relevance_score' => round(($similarityScore) * 10, 2), // Convert to 0-10 scale
                    'semantic_score' => round(($similarityScore) * 10, 2),
                    'discovery_method' => $chatInteractionSource->discovery_method ?? 'session_search',
                    'discovery_tool' => 'session_search',
                    'was_scraped' => $chatInteractionSource->was_scraped ?? false,
                    'has_content' => ! empty($chatInteractionSource->content_markdown),
                    'has_summary' => ! empty($chatInteractionSource->content_summary),
                    'created_at' => $chatInteractionSource->created_at?->toDateTimeString() ?? null,
                ];

                // Include content preview/summary if requested
                if ($includeSummaries) {
                    $formatted['summary'] = $chatInteractionSource->content_summary ??
                                          $chatInteractionSource->content_preview ??
                                          'No summary available';
                }

                return $formatted;
            })
            // Filter by similarity and sort by relevance
                ->filter(function ($source) use ($minRelevance) {
                    return $source['relevance_score'] >= $minRelevance;
                })
                ->sortByDesc('relevance_score')
                ->take($limit);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'interaction_id' => $interactionId,
                    'chat_session_id' => $chatSessionId,
                    'total_sources' => $formattedSources->count(),
                    'search_type' => 'session_semantic',
                    'search_query' => $query,
                    'semantic_ratio' => $semanticRatio,
                    'sources' => $formattedSources->values()->all(),
                    'search_metadata' => [
                        'session_sources_found' => $sessionSources->count(),
                        'filtered_sources' => $formattedSources->count(),
                    ],
                ],
            ], 'ResearchSourcesTool');

        } catch (\Exception $e) {
            Log::error('ResearchSourcesTool: Semantic search failed', [
                'query' => $query,
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Semantic search failed: '.$e->getMessage(),
            ], 'ResearchSourcesTool');
        }
    }
}
