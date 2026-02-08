<?php

namespace App\Tools;

use App\Models\ChatInteractionSource;
use App\Models\Source;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * SourceContentTool - Full Source Content Retrieval.
 *
 * Prism tool for retrieving full or summarized content from sources identified
 * during research. Access detailed information from sources referenced in threads
 * or knowledge documents. Supports semantic search for finding similar content.
 *
 * Retrieval Methods:
 * - By URL: Retrieve content from specific web source
 * - By source_id: Retrieve from stored Source model
 * - Automatic caching of previously fetched content
 *
 * Content Options:
 * - full: Complete source content
 * - summary: Abbreviated version (default)
 * - Configurable content length limits
 *
 * Source Types Supported:
 * - Web pages (HTML extraction)
 * - Knowledge documents (full text)
 * - Research sources (cached content)
 *
 * Semantic Search:
 * - Find sources with similar content
 * - Natural language queries across all sources
 * - Relevance-ranked results
 *
 * Use Cases:
 * - Deep-diving into research sources
 * - Extracting specific information from URLs
 * - Finding related source content
 * - Verifying source information
 *
 * @see \App\Models\Source
 * @see \App\Tools\ResearchSourcesTool
 */
class SourceContentTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('source_content')
            ->for('Retrieve full or summarized content from sources identified during research. Use this to access detailed information from sources referenced in threads or knowledge documents. Supports semantic search for finding similar content.')
            ->withStringParameter('url', 'URL of the source to retrieve content for')
            ->withNumberParameter('source_id', 'The ID of the source to retrieve content for')
            ->withBooleanParameter('summarize', 'If true, return a summary of the content instead of full text (default: false)')
            ->withStringParameter('semantic_query', 'Optional semantic search query to find similar sources based on this content')
            ->withNumberParameter('similar_limit', 'Number of similar sources to return when using semantic search (default: 5)')
            ->using(function (
                ?string $url = null,
                ?int $source_id = null,
                bool $summarize = false,
                ?string $semantic_query = null,
                int $similar_limit = 5
            ) {
                return static::executeSourceContent([
                    'url' => $url,
                    'source_id' => $source_id,
                    'summarize' => $summarize,
                    'semantic_query' => $semantic_query,
                    'similar_limit' => $similar_limit,
                ]);
            });
    }

    protected static function executeSourceContent(array $arguments = []): string
    {
        try {
            // Get interaction ID from context if available
            $interactionId = null;
            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'url' => 'nullable|string|url',
                'source_id' => 'nullable|integer',
                'summarize' => 'boolean',
                'semantic_query' => 'nullable|string|max:1000',
                'similar_limit' => 'integer|min:1|max:20',
            ]);

            if ($validator->fails()) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'SourceContentTool');
            }

            $validated = $validator->validated();

            // Ensure at least one identifier is provided
            if (empty($validated['url']) && empty($validated['source_id'])) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Either url or source_id must be provided',
                ], 'SourceContentTool');
            }

            // Find source by ID or URL
            $source = null;
            if (! empty($validated['source_id'])) {
                $source = Source::find($validated['source_id']);
            } elseif (! empty($validated['url'])) {
                $source = Source::where('url', $validated['url'])->first();
            }

            if (! $source) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Source not found',
                ], 'SourceContentTool');
            }

            // Find the chat interaction source record if we have an interaction ID
            $chatInteractionSource = null;
            if ($interactionId) {
                $chatInteractionSource = ChatInteractionSource::where('chat_interaction_id', $interactionId)
                    ->where('source_id', $source->id)
                    ->first();
            }

            $summarize = $validated['summarize'] ?? false;
            $semanticQuery = $validated['semantic_query'] ?? null;
            $similarLimit = $validated['similar_limit'] ?? 5;

            // Base response data
            $responseData = [
                'source_id' => $source->id,
                'url' => $source->url,
                'title' => $source->title,
                'domain' => $source->domain,
                'is_expired' => $source->isExpired(),
                'content_retrieved_at' => $source->content_retrieved_at ? $source->content_retrieved_at->toDateTimeString() : null,
                'expires_at' => $source->expires_at ? $source->expires_at->toDateTimeString() : null,
            ];

            // Handle content/summary retrieval
            if ($summarize && $chatInteractionSource) {
                // Get current interaction for the query
                $chatInteraction = null;
                if ($interactionId) {
                    $chatInteraction = \App\Models\ChatInteraction::find($interactionId);
                }

                $query = $chatInteraction ? $chatInteraction->question : '';
                $summary = $chatInteractionSource->content_summary;

                // If no summary exists, generate one
                if (! $summary && $source->content_markdown) {
                    $summary = $chatInteractionSource->generateAndStoreSummary($query);
                }

                // If we have a summary, use it
                if (! empty($summary)) {
                    $responseData['content'] = $summary;
                    $responseData['is_summarized'] = true;
                } else {
                    // Fall back to full content
                    $responseData['content'] = $source->content_markdown ?? $source->content_preview ?? 'No content available';
                    $responseData['is_summarized'] = false;
                }
            } else {
                // Return full content
                $responseData['content'] = $source->content_markdown ?? $source->content_preview ?? 'No content available';
                $responseData['is_summarized'] = false;
            }

            // Handle semantic search for similar sources
            $similarSources = [];
            if (! empty($semanticQuery)) {
                try {
                    $semanticSearchService = app(\App\Services\Research\SemanticSearchService::class);
                    $searchResults = $semanticSearchService->search(
                        $semanticQuery,
                        $interactionId,
                        $similarLimit,
                        0.3, // Lower threshold for similarity search
                        0.8  // Higher semantic ratio for similarity
                    );

                    if (! isset($searchResults['error'])) {
                        $similarSources = collect($searchResults['results'])
                            ->filter(function ($result) use ($source) {
                                // Exclude the current source
                                return $result['id'] !== $source->id || $result['type'] !== 'source';
                            })
                            ->take($similarLimit)
                            ->map(function ($result) {
                                return [
                                    'source_id' => $result['id'],
                                    'type' => $result['type'],
                                    'title' => $result['title'],
                                    'url' => $result['url'],
                                    'domain' => $result['domain'],
                                    'similarity_score' => round(($result['final_score'] ?? 0) * 10, 2),
                                    'content_preview' => substr($result['content_preview'] ?? '', 0, 200),
                                ];
                            })
                            ->values()
                            ->all();
                    }
                } catch (\Exception $e) {
                    Log::warning('SourceContentTool: Semantic search for similar sources failed', [
                        'query' => $semanticQuery,
                        'source_id' => $source->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $responseData['similar_sources'] = $similarSources;
            $responseData['similar_sources_query'] = $semanticQuery;

            return static::safeJsonEncode([
                'success' => true,
                'data' => $responseData,
            ], 'SourceContentTool');

        } catch (\Exception $e) {
            Log::error('SourceContentTool: Failed to retrieve source content', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to retrieve source content: '.$e->getMessage(),
            ], 'SourceContentTool');
        }
    }
}
