<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @group Public Access
 *
 * Read-only access to publicly shared chat sessions.
 * No authentication required - accessible to anyone with a valid share link.
 */
class PublicChatController extends Controller
{
    /**
     * Retrieve public chat session by UUID
     *
     * Retrieve a chat session that has been shared publicly via its UUID.
     * Returns the full conversation history with all interactions, sources, and metadata.
     *
     * @unauthenticated
     *
     * @urlParam uuid string required The session's unique identifier (UUID). Example: 9d4e1c23-5f2b-4d3e-8a9c-0b1a2c3d4e5f
     *
     * @response 200 scenario="Success" {
     *   "success": true,
     *   "session": {
     *     "uuid": "9d4e1c23-5f2b-4d3e-8a9c-0b1a2c3d4e5f",
     *     "title": "My Research Session",
     *     "shared_at": "2024-01-01T00:00:00.000000Z",
     *     "expires_at": "2024-02-01T00:00:00.000000Z",
     *     "interaction_count": 5,
     *     "created_at": "2024-01-01T00:00:00.000000Z"
     *   },
     *   "interactions": [
     *     {
     *       "id": 1,
     *       "question": "What is the capital of France?",
     *       "answer": "Paris is the capital of France...",
     *       "agent_name": "Research Agent",
     *       "sources": [],
     *       "created_at": "2024-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     * @response 404 scenario="Not found or not public" {
     *   "success": false,
     *   "error": "Not Found",
     *   "message": "This session is not publicly accessible or has expired"
     * }
     */
    public function show(Request $request, string $uuid)
    {
        try {
            // Find session by UUID
            $session = ChatSession::where('uuid', $uuid)->firstOrFail();

            // Check if session is publicly accessible
            if (! $session->isPublic()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not Found',
                    'message' => 'This session is not publicly accessible or has expired',
                ], 404);
            }

            // PERFORMANCE: Eager load interactions with nested relations to avoid N+1 queries
            $interactions = $session->interactions()
                ->with([
                    'agent:id,name',
                    'sources.source:id,title,url,domain',
                ])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($interaction) {
                    return [
                        'id' => $interaction->id,
                        'question' => $interaction->question,
                        'answer' => $interaction->answer,
                        'agent_name' => $interaction->agent?->name,
                        'sources' => $interaction->sources->map(function ($chatInteractionSource) {
                            return [
                                'title' => $chatInteractionSource->source->title,
                                'url' => $chatInteractionSource->source->url,
                                'domain' => $chatInteractionSource->source->domain,
                            ];
                        }),
                        'created_at' => $interaction->created_at,
                    ];
                });

            Log::info('PublicChatController: Public session accessed', [
                'session_id' => $session->id,
                'uuid' => $uuid,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'uuid' => $session->uuid,
                    'title' => $session->title ?? $session->name ?? 'Chat Session',
                    'shared_at' => $session->public_shared_at,
                    'expires_at' => $session->public_expires_at,
                    'interaction_count' => $interactions->count(),
                    'created_at' => $session->created_at,
                ],
                'interactions' => $interactions,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('PublicChatController: Failed to retrieve public session', [
                'uuid' => $uuid,
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving the session',
            ], 500);
        }
    }
}
