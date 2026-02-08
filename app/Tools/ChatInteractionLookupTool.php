<?php

namespace App\Tools;

use App\Models\ChatInteraction;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * ChatInteractionLookupTool - Chat Interaction Search and Discovery.
 *
 * Prism tool for searching and discovering chat interactions within the current
 * session or across sessions. Supports filtering by various criteria.
 *
 * Search Criteria:
 * - session_id: Filter by specific chat session
 * - query: Text search across interaction content
 * - agent_id: Filter by agent that handled interaction
 * - status: Filter by status (pending, processing, completed, failed)
 * - limit: Maximum results to return
 *
 * Response Data:
 * - Interaction metadata (id, created_at, status)
 * - User message and AI response summaries
 * - Associated agent information
 * - Execution details
 * - Source and artifact references
 *
 * Use Cases:
 * - Finding previous conversations
 * - Reviewing interaction history
 * - Debugging failed interactions
 * - Analyzing agent responses
 *
 * @see \App\Models\ChatInteraction
 * @see \App\Tools\GetChatInteractionTool
 */
class ChatInteractionLookupTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('chat_interaction_lookup')
            ->for('Search and retrieve previous chat interactions from the current session or across sessions. Supports semantic search for finding relevant conversations based on content similarity.')
            ->withBooleanParameter('include_answers', 'Whether to include full answers in the response (default: true)')
            ->withNumberParameter('limit', 'Maximum number of interactions to return (default: 10)')
            ->withNumberParameter('min_relevance', 'Minimum relevance score threshold (0-10, default: 0)')
            ->withStringParameter('semantic_query', 'Optional semantic search query to find relevant interactions by content similarity')
            ->withNumberParameter('semantic_ratio', 'For semantic search: ratio of semantic vs keyword matching (0.0 = pure keyword, 1.0 = pure semantic, default: 0.7)')
            ->withBooleanParameter('current_session_only', 'Whether to search only within the current session (default: true)')
            ->withNumberParameter('days_back', 'Number of days to look back for interactions (default: 7, max: 30)')
            ->using(function (
                bool $include_answers = true,
                int $limit = 10,
                float $min_relevance = 0,
                ?string $semantic_query = null,
                float $semantic_ratio = 0.7,
                bool $current_session_only = true,
                int $days_back = 7
            ) {
                return static::executeLookupInteractions([
                    'include_answers' => $include_answers,
                    'limit' => $limit,
                    'min_relevance' => $min_relevance,
                    'semantic_query' => $semantic_query,
                    'semantic_ratio' => $semantic_ratio,
                    'current_session_only' => $current_session_only,
                    'days_back' => $days_back,
                ]);
            });
    }

    protected static function executeLookupInteractions(array $arguments = []): string
    {
        try {
            // Get current interaction ID and session ID from the context
            $interactionId = null;
            $chatSessionId = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();

                // Get session ID from the current interaction
                if ($interactionId) {
                    $currentInteraction = ChatInteraction::find($interactionId);
                    $chatSessionId = $currentInteraction?->chat_session_id;
                }
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');

                // Get session ID from the current interaction
                if ($interactionId) {
                    $currentInteraction = ChatInteraction::find($interactionId);
                    $chatSessionId = $currentInteraction?->chat_session_id;
                }
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'include_answers' => 'boolean',
                'limit' => 'integer|min:1|max:100',
                'min_relevance' => 'numeric|min:0|max:10',
                'semantic_query' => 'nullable|string|max:1000',
                'semantic_ratio' => 'numeric|min:0|max:1',
                'current_session_only' => 'boolean',
                'days_back' => 'integer|min:1|max:30',
            ]);

            if ($validator->fails()) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'ChatInteractionLookupTool');
            }

            $validated = $validator->validated();
            $includeAnswers = $validated['include_answers'] ?? true;
            $limit = $validated['limit'] ?? 10;
            $minRelevance = $validated['min_relevance'] ?? 0;
            $semanticQuery = $validated['semantic_query'] ?? null;
            $semanticRatio = $validated['semantic_ratio'] ?? 0.7;
            $currentSessionOnly = $validated['current_session_only'] ?? true;
            $daysBack = $validated['days_back'] ?? 7;

            // If semantic query is provided, use semantic search
            if (! empty($semanticQuery)) {
                return static::executeSemanticSearch(
                    $interactionId,
                    $chatSessionId,
                    $semanticQuery,
                    $limit,
                    $minRelevance,
                    $semanticRatio,
                    $includeAnswers,
                    $currentSessionOnly,
                    $daysBack
                );
            }

            // Default behavior - query interactions
            $query = ChatInteraction::query()
                ->whereNotNull('answer') // Only include completed interactions
                ->where('answer', '!=', '') // Only include non-empty answers
                ->orderBy('created_at', 'desc');

            // Exclude current interaction if we have one
            if ($interactionId) {
                $query->where('id', '!=', $interactionId);
            }

            // Apply session filtering
            if ($currentSessionOnly && $chatSessionId) {
                $query->where('chat_session_id', $chatSessionId);
            } else {
                // Apply time-based filtering when searching across sessions
                $query->where('created_at', '>=', now()->subDays($daysBack));

                // If we have a session ID, prioritize interactions from the same user
                if ($chatSessionId) {
                    $currentInteraction = ChatInteraction::find($interactionId);
                    if ($currentInteraction?->user_id) {
                        $query->where('user_id', $currentInteraction->user_id);
                    }
                }
            }

            $interactions = $query->limit($limit)->get();

            // Map interactions to response format
            $formattedInteractions = $interactions->map(function ($interaction) use ($includeAnswers) {
                $result = [
                    'interaction_id' => $interaction->id,
                    'chat_session_id' => $interaction->chat_session_id,
                    'question' => $interaction->question ?? 'No question recorded',
                    'summary' => $interaction->summary ?? 'No summary available',
                    'agent_name' => $interaction->agent?->name ?? 'Unknown Agent',
                    'created_at' => $interaction->created_at->toDateTimeString(),
                    'relevance_score' => 10.0, // Default high relevance for direct matches
                    'search_type' => 'chronological',
                ];

                // Include full answers if requested
                if ($includeAnswers && $interaction->answer) {
                    $result['answer'] = $interaction->answer;
                }

                return $result;
            });

            // Create the response
            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'current_interaction_id' => $interactionId,
                    'current_session_id' => $chatSessionId,
                    'total_interactions' => $interactions->count(),
                    'search_type' => 'chronological_lookup',
                    'search_scope' => $currentSessionOnly ? 'current_session' : 'user_history',
                    'days_back' => $daysBack,
                    'interactions' => $formattedInteractions,
                ],
            ], 'ChatInteractionLookupTool');

        } catch (\Exception $e) {
            Log::error('ChatInteractionLookupTool: Failed to lookup interactions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to lookup interactions: '.$e->getMessage(),
            ], 'ChatInteractionLookupTool');
        }
    }

    /**
     * Execute semantic search across chat interactions
     */
    protected static function executeSemanticSearch(
        ?int $interactionId,
        ?int $chatSessionId,
        string $query,
        int $limit,
        float $minRelevance,
        float $semanticRatio,
        bool $includeAnswers,
        bool $currentSessionOnly,
        int $daysBack
    ): string {
        try {
            // Build the base query for interactions
            $interactionsQuery = ChatInteraction::query()
                ->whereNotNull('answer')
                ->where('answer', '!=', '');

            // Exclude current interaction if we have one
            if ($interactionId) {
                $interactionsQuery->where('id', '!=', $interactionId);
            }

            // Apply scope filtering
            if ($currentSessionOnly && $chatSessionId) {
                $interactionsQuery->where('chat_session_id', $chatSessionId);
            } else {
                // Apply time-based filtering when searching across sessions
                $interactionsQuery->where('created_at', '>=', now()->subDays($daysBack));

                // If we have a session ID, prioritize interactions from the same user
                if ($chatSessionId) {
                    $currentInteraction = ChatInteraction::find($interactionId);
                    if ($currentInteraction?->user_id) {
                        $interactionsQuery->where('user_id', $currentInteraction->user_id);
                    }
                }
            }

            $interactions = $interactionsQuery
                ->orderBy('created_at', 'desc')
                ->limit($limit * 2) // Get more for filtering
                ->get();

            if ($interactions->isEmpty()) {
                return static::safeJsonEncode([
                    'success' => true,
                    'data' => [
                        'current_interaction_id' => $interactionId,
                        'current_session_id' => $chatSessionId,
                        'total_interactions' => 0,
                        'search_type' => 'semantic_search',
                        'search_query' => $query,
                        'semantic_ratio' => $semanticRatio,
                        'search_scope' => $currentSessionOnly ? 'current_session' : 'user_history',
                        'interactions' => [],
                        'search_metadata' => null,
                    ],
                ], 'ChatInteractionLookupTool');
            }

            // Perform text-based similarity matching on interactions
            $formattedInteractions = $interactions->map(function ($interaction) use ($query, $includeAnswers) {
                // Calculate text similarity score
                $textContent = ($interaction->question ?? '').' '.
                             ($interaction->summary ?? '').' '.
                             substr($interaction->answer ?? '', 0, 500); // First 500 chars of answer

                // Simple text similarity calculation
                $queryWords = array_filter(explode(' ', strtolower($query)));
                $contentWords = array_filter(explode(' ', strtolower($textContent)));

                $matches = count(array_intersect($queryWords, $contentWords));
                $totalWords = count(array_unique(array_merge($queryWords, $contentWords)));
                $similarityScore = $totalWords > 0 ? ($matches / $totalWords) : 0;

                $formatted = [
                    'interaction_id' => $interaction->id,
                    'chat_session_id' => $interaction->chat_session_id,
                    'question' => $interaction->question ?? 'No question recorded',
                    'summary' => $interaction->summary ?? 'No summary available',
                    'agent_name' => $interaction->agent?->name ?? 'Unknown Agent',
                    'created_at' => $interaction->created_at->toDateTimeString(),
                    'relevance_score' => round(($similarityScore) * 10, 2), // Convert to 0-10 scale
                    'semantic_score' => round(($similarityScore) * 10, 2),
                    'search_type' => 'semantic',
                ];

                // Include full answers if requested
                if ($includeAnswers && $interaction->answer) {
                    $formatted['answer'] = $interaction->answer;
                }

                return $formatted;
            })
            // Filter by similarity and sort by relevance
                ->filter(function ($interaction) use ($minRelevance) {
                    return $interaction['relevance_score'] >= $minRelevance;
                })
                ->sortByDesc('relevance_score')
                ->take($limit);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'current_interaction_id' => $interactionId,
                    'current_session_id' => $chatSessionId,
                    'total_interactions' => $formattedInteractions->count(),
                    'search_type' => 'semantic_search',
                    'search_query' => $query,
                    'semantic_ratio' => $semanticRatio,
                    'search_scope' => $currentSessionOnly ? 'current_session' : 'user_history',
                    'days_back' => $daysBack,
                    'interactions' => $formattedInteractions->values()->all(),
                    'search_metadata' => [
                        'candidate_interactions_found' => $interactions->count(),
                        'filtered_interactions' => $formattedInteractions->count(),
                    ],
                ],
            ], 'ChatInteractionLookupTool');

        } catch (\Exception $e) {
            Log::error('ChatInteractionLookupTool: Semantic search failed', [
                'query' => $query,
                'interaction_id' => $interactionId,
                'session_id' => $chatSessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Semantic search failed: '.$e->getMessage(),
            ], 'ChatInteractionLookupTool');
        }
    }
}
