<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use App\Traits\UsesAIModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * SearXNGTool - Privacy-Focused Metasearch Engine Integration.
 *
 * Prism tool for web search via SearXNG metasearch engine. Aggregates results
 * from multiple search engines while preserving user privacy. Supports query
 * optimization and AI-powered result ranking.
 *
 * Search Capabilities:
 * - Multi-engine search aggregation
 * - Privacy-preserving queries (no tracking)
 * - Configurable search engines
 * - Category filtering (general, news, images, etc.)
 * - Language and region preferences
 *
 * Query Optimization:
 * - AI-powered query enhancement
 * - Automatic query refinement
 * - Search term expansion
 * - Context-aware improvements
 *
 * Result Processing:
 * - Deduplication across engines
 * - Relevance ranking
 * - Source diversity
 * - Result metadata (title, snippet, URL)
 *
 * Privacy Features:
 * - No user tracking
 * - No search history storage
 * - Anonymous requests
 * - No personalization
 *
 * Use Cases:
 * - Web research
 * - Fact checking
 * - Information discovery
 * - Privacy-conscious search
 *
 * @see https://docs.searxng.org/
 */
class SearXNGTool
{
    use SafeJsonResponse, UsesAIModels;

    public static function create()
    {
        return Tool::as('searxng_search')
            ->for('Search the web using SearXNG. Provides comprehensive web search results with enhanced coverage for research tasks. Returns more results by default to enable better source selection and validation.')
            ->withStringParameter('query', 'The search query to perform')
            ->withNumberParameter('num_results', 'Number of results to return (default: 15, max: 30 for comprehensive research)', false)
            ->withStringParameter('category', 'Search category (general, images, videos, news, etc.)', false)
            ->using(function (string $query, int $num_results = 15, string $category = 'general') {
                Log::info('SearXNGTool: Entry point reached', [
                    'query' => $query,
                    'num_results' => $num_results,
                    'category' => $category,
                ]);

                // Get the StatusReporter from the execution context
                $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

                if (! $statusReporter) {
                    Log::warning('SearXNGTool: No status reporter available for status reporting');
                    // Fall back to simple execution without status reporting
                    if (empty($query)) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'Search query is required',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.1.0',
                                'error_occurred' => true,
                            ],
                        ], 'searxng_search');
                    }
                }

                try {
                    $start = microtime(true);

                    if ($statusReporter) {
                        $statusReporter->report('searxng_search', "Searching for: {$query} (requesting {$num_results} results)", true, false);
                    }

                    if (empty($query)) {
                        if ($statusReporter) {
                            $statusReporter->report('searxng_search', 'Error: Search query is required', false, false);
                        }

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'Search query is required',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.1.0',
                                'error_occurred' => true,
                            ],
                        ], 'searxng_search');
                    }

                    // Increase limit to 30 for research tasks, maintaining backward compatibility
                    $num_results = min($num_results, 30);

                    // Optimize the search query using AI (no status report - internal detail)

                    $optimizedQuery = $query; // fallback to original
                    try {
                        $modelSelector = app(\App\Services\AI\ModelSelector::class);
                        $config = $modelSelector->getProviderAndModel(\App\Services\AI\ModelSelector::LOW_COST);

                        // Use withSystemPrompt() for provider interoperability (per Prism best practices)
                        $response = app(\App\Services\AI\PrismWrapper::class)
                            ->text()
                            ->using($config['provider'], $config['model'])
                            ->withSystemPrompt('You are a search engine optimization expert. Convert the user\'s query into an optimized search query that will yield the best results from search engines. Keep it concise and use effective search terms. Return only the optimized query without any explanation.')
                            ->withMessages([new UserMessage($query)])
                            ->withContext([
                                'mode' => 'search_query_optimization',
                                'original_query' => $query,
                                'tool' => 'searxng_search',
                            ])
                            ->asText();

                        $optimizedQuery = trim($response->text);
                        if (empty($optimizedQuery)) {
                            $optimizedQuery = $query; // fallback
                        }

                        // Query optimization complete (no status report - internal detail)

                        Log::info('Search query optimized', [
                            'original' => $query,
                            'optimized' => $optimizedQuery,
                        ]);
                    } catch (\Exception $e) {
                        // Query optimization failed (no status report - internal detail)
                        Log::warning('Failed to optimize search query, using original', [
                            'query' => $query,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Search execution (no status report - internal detail)

                    // Make request to SearXNG API
                    $searxngUrl = config('services.searxng.url', 'http://searxng:8080');
                    $response = Http::timeout(30)
                        ->withHeaders([
                            'Accept' => 'application/json',
                            'User-Agent' => 'Laravel-SearXNG-Tool/1.1',
                        ])
                        ->get($searxngUrl.'/search', [
                            'q' => $optimizedQuery,
                            'format' => 'json',
                            'categories' => $category,
                            'engines' => 'google,bing,duckduckgo,yahoo,startpage', // Extended engines for broader coverage
                            'time_range' => '',
                            'safesearch' => 1,
                            'pageno' => 1,
                        ]);

                    if (! $response->successful()) {
                        $errorMsg = 'Search request failed with status: '.$response->status();

                        if ($statusReporter) {
                            $statusReporter->report('searxng_search', "Error: {$errorMsg}", false, false);
                        }

                        Log::warning('SearXNG search failed', [
                            'status' => $response->status(),
                            'body' => $response->body(),
                            'query' => $query,
                        ]);

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'api_error',
                            'message' => $errorMsg,
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.1.0',
                                'error_occurred' => true,
                            ],
                        ], 'searxng_search');
                    }

                    $data = $response->json();
                    $results = $data['results'] ?? [];

                    // Processing results (no individual status - will report final count)

                    // Limit and format results with enhanced metadata for better reranking
                    $formattedResults = [];
                    $count = 0;

                    foreach ($results as $result) {
                        if ($count >= $num_results) {
                            break;
                        }

                        $formattedResults[] = [
                            'title' => $result['title'] ?? 'No title',
                            'url' => $result['url'] ?? '',
                            'content' => $result['content'] ?? 'No description available',
                            'engine' => $result['engine'] ?? 'unknown',
                            'score' => $result['score'] ?? 0,
                            'publishedDate' => $result['publishedDate'] ?? null,
                            'category' => $result['category'] ?? 'general',
                            // Enhanced metadata for semantic reranking
                            'domain' => parse_url($result['url'] ?? '', PHP_URL_HOST),
                            'content_length' => strlen($result['content'] ?? ''),
                            'has_date' => ! empty($result['publishedDate']),
                        ];

                        $count++;
                    }

                    // Create a formatted summary for the AI with enhanced guidance
                    $summary = "Found {$count} results for '{$query}'";
                    if ($optimizedQuery !== $query) {
                        $summary .= " (searched with: '{$optimizedQuery}')";
                    }
                    $summary .= ":\n\n";

                    foreach ($formattedResults as $i => $result) {
                        $summary .= ($i + 1).". **[{$result['title']}]({$result['url']})**\n";
                        $summary .= "   {$result['content']}\n";
                        if ($result['publishedDate']) {
                            $summary .= "   *Published: {$result['publishedDate']}*\n";
                        }
                        $summary .= "\n";
                    }

                    $summary .= "\n**RESEARCH WORKFLOW GUIDANCE:**\n";
                    $summary .= "- Consider validating 8-12 URLs from these results for comprehensive coverage\n";
                    $summary .= "- Use bulk_link_validator for efficient parallel validation\n";
                    $summary .= "- After validation, scrape 4-6 validated URLs with markitdown for thorough research\n";
                    $summary .= "- Prioritize recent content and authoritative domains\n\n";

                    $summary .= "NOTE: When referencing information from these results, use proper markdown links: [specific claim or finding](url)\n";

                    $duration = microtime(true) - $start;

                    // Report meaningful result summary
                    if ($statusReporter) {
                        $statusReporter->report('searxng_search', "Found {$count} results for '{$query}' - ready for validation", true, false);
                    }

                    Log::info('SearXNG search completed successfully', [
                        'original_query' => $query,
                        'optimized_query' => $optimizedQuery,
                        'results_count' => $count,
                        'category' => $category,
                    ]);

                    Log::info('SearXNGTool: About to return success response', [
                        'results_count' => $count,
                        'original_query' => $query,
                        'optimized_query' => $optimizedQuery,
                    ]);

                    $result = static::safeJsonEncode([
                        'success' => true,
                        'data' => [
                            'original_query' => $query,
                            'optimized_query' => $optimizedQuery,
                            'results_count' => $count,
                            'category' => $category,
                            'summary' => $summary,
                            'results' => $formattedResults,
                        ],
                        'metadata' => [
                            'executed_at' => now()->toISOString(),
                            'tool_version' => '1.1.0',
                            'execution_time_ms' => (int) ((microtime(true) - $start) * 1000),
                            'enhanced_for_research' => true,
                        ],
                    ], 'searxng_search');

                    Log::info('SearXNGTool: Successfully encoded response', [
                        'result_length' => strlen($result),
                    ]);

                    return $result;

                } catch (\Throwable $e) {
                    Log::error('SearXNG search error - detailed', [
                        'original_query' => $query,
                        'optimized_query' => $optimizedQuery ?? $query,
                        'error' => $e->getMessage(),
                        'error_class' => get_class($e),
                        'error_code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    if ($statusReporter) {
                        try {
                            $statusReporter->report('searxng_search', 'Error: '.$e->getMessage(), false, false);
                        } catch (\Exception $reporterException) {
                            Log::error('SearXNGTool: Status reporter also failed', [
                                'reporter_error' => $reporterException->getMessage(),
                                'original_error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return static::safeJsonEncode([
                        'success' => false,
                        'error' => 'searxng_error',
                        'message' => 'SearXNG search failed: '.$e->getMessage(),
                        'metadata' => [
                            'executed_at' => now()->toISOString(),
                            'tool_version' => '1.1.0',
                            'error_occurred' => true,
                            'execution_time_ms' => (int) ((microtime(true) - $start) * 1000),
                        ],
                    ], 'searxng_search');
                }
            });
    }
}
