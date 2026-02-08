<?php

namespace App\Tools;

use App\Models\ChatInteraction;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * GetChatInteractionTool - Retrieve Specific Chat Interaction Details.
 *
 * Prism tool for retrieving complete details of a specific chat interaction by ID.
 * Returns full interaction data including messages, artifacts, sources, and execution info.
 *
 * Retrieval Options:
 * - By interaction_id: Get specific interaction
 * - Include relationships: Artifacts, sources, agent execution
 * - Load message history
 *
 * Response Data:
 * - Full user message content
 * - Complete AI response
 * - Associated artifacts (created or modified)
 * - Knowledge sources used
 * - Agent execution details
 * - Status and timestamps
 *
 * Use Cases:
 * - Reviewing specific interactions
 * - Debugging interaction issues
 * - Extracting interaction artifacts
 * - Analyzing source usage
 *
 * @see \App\Models\ChatInteraction
 * @see \App\Tools\ChatInteractionLookupTool
 */
class GetChatInteractionTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('get_chat_interaction')
            ->for('Retrieve previous chat interactions from the current conversation for context. Only returns interactions that belong to the current user for security.')
            ->withNumberParameter('interaction_id', 'The ID of the specific interaction to retrieve')
            ->withBooleanParameter('include_summary', 'Whether to include the AI-generated summary (default: true)')
            ->withBooleanParameter('include_answer', 'Whether to include the full answer text (default: false)')
            ->withBooleanParameter('include_metadata', 'Whether to include metadata like execution strategy (default: false)')
            ->using(function (
                int $interaction_id,
                bool $include_summary = true,
                bool $include_answer = false,
                bool $include_metadata = false
            ) {
                return static::executeGetChatInteraction([
                    'interaction_id' => $interaction_id,
                    'include_summary' => $include_summary,
                    'include_answer' => $include_answer,
                    'include_metadata' => $include_metadata,
                ]);
            });
    }

    protected static function executeGetChatInteraction(array $arguments = []): string
    {
        try {
            // Validate input
            $validator = Validator::make($arguments, [
                'interaction_id' => 'required|integer|min:1',
                'include_summary' => 'boolean',
                'include_answer' => 'boolean',
                'include_metadata' => 'boolean',
            ]);

            if ($validator->fails()) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'GetChatInteractionTool');
            }

            $validated = $validator->validated();

            // Get current user ID from execution context
            $currentUserId = static::getCurrentUserId();

            if (! $currentUserId) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Unable to determine current user context',
                ], 'GetChatInteractionTool');
            }

            // Find the interaction with user security check
            $query = ChatInteraction::where('id', $validated['interaction_id'])
                ->where('user_id', $currentUserId);

            // Include metadata if requested
            if ($validated['include_metadata']) {
                $query->addSelect('metadata');
            }

            $interaction = $query->first();

            if (! $interaction) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Interaction not found or access denied',
                ], 'GetChatInteractionTool');
            }

            // Build response data based on requested fields
            $responseData = [
                'id' => $interaction->id,
                'question' => $interaction->question,
                'created_at' => $interaction->created_at->toISOString(),
                'chat_session_id' => $interaction->chat_session_id,
            ];

            // Include summary if requested (default: true)
            if ($validated['include_summary'] && $interaction->summary) {
                $responseData['summary'] = $interaction->summary;
            }

            // Include full answer if requested (default: false to save tokens)
            if ($validated['include_answer'] && $interaction->answer) {
                $responseData['answer'] = $interaction->answer;
            }

            // Include metadata if requested (default: false)
            if ($validated['include_metadata'] && $interaction->metadata) {
                $responseData['metadata'] = $interaction->metadata;
            }

            // Include basic stats
            $responseData['has_answer'] = ! empty($interaction->answer);
            $responseData['has_summary'] = ! empty($interaction->summary);

            Log::info('GetChatInteractionTool: Successfully retrieved interaction', [
                'interaction_id' => $interaction->id,
                'user_id' => $currentUserId,
                'include_summary' => $validated['include_summary'],
                'include_answer' => $validated['include_answer'],
                'include_metadata' => $validated['include_metadata'],
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'data' => $responseData,
            ], 'GetChatInteractionTool');

        } catch (\Exception $e) {
            Log::error('GetChatInteractionTool: Failed to retrieve interaction', [
                'interaction_id' => $arguments['interaction_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to retrieve interaction: '.$e->getMessage(),
            ], 'GetChatInteractionTool');
        }
    }

    /**
     * Get current user ID from execution context
     */
    protected static function getCurrentUserId(): ?int
    {
        // Try multiple approaches to get the current user ID

        // 1. Check if there's an authenticated user (web requests)
        if (auth()->check()) {
            return auth()->id();
        }

        // 2. Check if current_user_id is available in the container (during agent execution)
        if (app()->has('current_user_id')) {
            return app('current_user_id');
        }

        // 3. Check session for user context
        if (session()->has('current_user_id')) {
            return session('current_user_id');
        }

        // 4. Try to get from status reporter context
        if (app()->has('status_reporter')) {
            $statusReporter = app('status_reporter');
            $interactionId = $statusReporter->getInteractionId();

            if ($interactionId) {
                // Get user ID from the interaction
                $interaction = ChatInteraction::find($interactionId);
                if ($interaction) {
                    return $interaction->user_id;
                }
            }
        }

        // 5. Last resort: check request for user_id parameter
        if (request()->has('user_id')) {
            return (int) request('user_id');
        }

        return null;
    }
}
