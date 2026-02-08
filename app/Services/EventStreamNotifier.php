<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Event Stream Notifier - Redis-based Real-time Event Broadcasting.
 *
 * Manages Server-Sent Events (SSE) using Redis FIFO queues for real-time
 * communication with client browsers. Provides event queueing for agent
 * executions, status updates, and interaction changes with automatic TTL
 * management and memory-safe operations.
 *
 * Redis Architecture:
 * - **Connection**: Dedicated 'eventstream' connection (DB 3)
 * - **Key Pattern**: `eventstream:user_{userId}:interaction_{interactionId}` (user-isolated)
 * - **Data Structure**: Redis List (FIFO queue via rpush/lrange)
 * - **TTL**: 5 minutes per queue
 * - **Memory Safety**: Auto-trim to last 50 events
 *
 * Event Flow:
 * 1. Agent execution dispatches events via send()
 * 2. Events pushed to Redis list (rpush) with unique IDs
 * 3. Client polls StreamingController endpoint
 * 4. getAndClearEvents() retrieves and atomically clears queue (with authorization)
 * 5. Events sent to client as SSE format
 * 6. Client reconnects on disconnect (automatic retry)
 *
 * Event Types Supported:
 * - **step_added**: Agent execution progress steps
 * - **source_added**: Research source discoveries
 * - **artifact_added**: Artifact creation/updates
 * - **interaction_updated**: Chat answer updates (streaming)
 * - **research_complete**: Research job completion
 * - **knowledge_source_added**: Knowledge base document usage
 *
 * Database Isolation:
 * - **DB 0**: Default Laravel cache
 * - **DB 1**: Session storage
 * - **DB 2**: Horizon job queue
 * - **DB 3**: EventStream (this service) - prevents queue conflicts
 *
 * Memory Management:
 * - TTL: 5 minutes (auto-cleanup stale queues)
 * - Trim: Keep last 50 events (prevent unbounded growth)
 * - Atomic operations: Pipeline for get-and-clear consistency
 *
 * Security Features:
 * - **User Isolation**: Queue keys include user_id to prevent cross-user access
 * - **Authorization**: getAndClearEvents() verifies user owns interaction
 * - **Rate Limiting**: 100 events per user per minute (system events exempt)
 * - **Unpredictable Keys**: User context makes enumeration harder
 *
 * Integration Points:
 * - StreamingController: Polls events for SSE transmission
 * - StatusReporter: Creates status updates that trigger events
 * - AgentExecutor: Dispatches completion events
 * - ChatInteractionUpdated: Broadcasts answer updates via Laravel events
 *
 * @see \App\Http\Controllers\StreamingController
 * @see \App\Services\StatusReporter
 * @see \App\Services\Agents\AgentExecutor
 * @see \App\Events\ChatInteractionUpdated
 */
class EventStreamNotifier
{
    /**
     * Get secure queue key with user isolation.
     *
     * SECURITY: Includes user_id in key format to prevent cross-user event injection.
     * Makes enumeration harder by requiring knowledge of both user_id and interaction_id.
     *
     * @param  int  $interactionId  Chat interaction ID
     * @return string Redis queue key with user isolation
     */
    private static function getQueueKey(int $interactionId): string
    {
        // Look up interaction to get user_id for isolation
        $interaction = \App\Models\ChatInteraction::select('id', 'user_id')
            ->find($interactionId);

        if (! $interaction) {
            Log::warning('EventStreamNotifier: Interaction not found for queue key', [
                'interaction_id' => $interactionId,
            ]);

            // Fallback to system context for non-existent interactions
            return "eventstream:user_system:interaction_{$interactionId}";
        }

        // Use user-isolated key format
        return "eventstream:user_{$interaction->user_id}:interaction_{$interactionId}";
    }

    /**
     * Send a real-time event to active EventStream connections.
     *
     * Pushes event to Redis FIFO queue for SSE streaming. Events include
     * unique IDs for client-side deduplication and ISO timestamps for
     * ordering guarantees.
     *
     * SECURITY: Includes rate limiting (100 events/user/minute) and uses
     * user-isolated queue keys to prevent cross-user event injection.
     *
     * IMPORTANT: Uses dedicated 'eventstream' Redis connection (DB 3)
     * to avoid conflicts with Horizon queue (DB 2)
     *
     * @param  int  $interactionId  Chat interaction ID for queue routing
     * @param  string  $eventType  Event type identifier (e.g., 'step_added')
     * @param  array  $data  Event payload data
     */
    public static function send(int $interactionId, string $eventType, array $data): void
    {
        // SECURITY: Get user-isolated queue key
        $queueKey = self::getQueueKey($interactionId);

        // SECURITY: Rate limiting - prevent event spam and Redis memory exhaustion
        // Only rate limit when there's an authenticated user (skip for system/background jobs)
        if (auth()->check()) {
            try {
                $rateLimitKey = 'eventstream_rate:user_'.auth()->id();
                $currentCount = Cache::get($rateLimitKey, 0);

                if ($currentCount >= 100) {
                    Log::warning('EventStreamNotifier: Rate limit exceeded', [
                        'user_id' => auth()->id(),
                        'interaction_id' => $interactionId,
                        'event_type' => $eventType,
                        'current_count' => $currentCount,
                        'limit' => 100,
                    ]);

                    throw new \Exception('Event rate limit exceeded. Maximum 100 events per minute.');
                }

                // Increment rate limit counter
                Cache::put($rateLimitKey, $currentCount + 1, now()->addMinute());
            } catch (\RedisException $e) {
                // Redis unavailable - skip rate limiting but continue with event
                Log::info('EventStreamNotifier: Redis cache unavailable, skipping rate limit', [
                    'user_id' => auth()->id(),
                    'interaction_id' => $interactionId,
                ]);
            }
        }

        $event = [
            'type' => $eventType,
            'data' => $data,
            'timestamp' => now()->toISOString(),
            'id' => uniqid(),
        ];

        try {
            // Use dedicated eventstream connection (DB 3) to avoid Horizon conflicts
            $redis = \Illuminate\Support\Facades\Redis::connection('eventstream');

            // Push event to Redis list (FIFO queue)
            $result = $redis->rpush($queueKey, json_encode($event));

            Log::info('EventStreamNotifier: Event sent to Redis', [
                'interaction_id' => $interactionId,
                'event_type' => $eventType,
                'queue_key' => $queueKey,
                'queue_length_after_push' => $result,
                'redis_connection' => 'eventstream',
                'redis_db' => config('database.redis.eventstream.database'),
            ]);

            // Set TTL on the queue key (5 minutes)
            $redis->expire($queueKey, 300);

            // Trim to keep only last 50 events to prevent memory exhaustion
            $length = $redis->llen($queueKey);
            if ($length > 50) {
                $startPos = $length - 50;
                $redis->ltrim($queueKey, $startPos, -1);

                Log::warning('EventStream queue trimmed due to size', [
                    'interaction_id' => $interactionId,
                    'queue_key' => $queueKey,
                    'length_before' => $length,
                    'trimmed_events' => $startPos,
                ]);
            }
        } catch (\RedisException $e) {
            // Redis unavailable - log info and continue without event streaming
            Log::info('EventStreamNotifier: Redis unavailable, event not sent', [
                'interaction_id' => $interactionId,
                'event_type' => $eventType,
            ]);
        }
    }

    /**
     * Get and clear events for an interaction.
     *
     * Atomically retrieves all queued events and clears the queue using
     * Redis pipeline for consistency. Returns events in FIFO order with
     * JSON decoding and null filtering for robustness.
     *
     * SECURITY: Verifies authenticated user owns the interaction before
     * returning events. Prevents unauthorized access to other users' event streams.
     *
     * IMPORTANT: Uses dedicated 'eventstream' Redis connection (DB 3)
     * to match where events are written
     *
     * @param  int  $interactionId  Chat interaction ID
     * @return array<array{type: string, data: array, timestamp: string, id: string}> Array of event objects
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If user doesn't own interaction
     */
    public static function getAndClearEvents(int $interactionId): array
    {
        // SECURITY: Authorization check - verify user owns the interaction
        if (auth()->check()) {
            $interaction = \App\Models\ChatInteraction::select('id', 'user_id')
                ->find($interactionId);

            if ($interaction && $interaction->user_id !== auth()->id()) {
                Log::warning('EventStreamNotifier: Unauthorized event access attempt blocked', [
                    'interaction_id' => $interactionId,
                    'interaction_user_id' => $interaction->user_id,
                    'requesting_user_id' => auth()->id(),
                    'ip_address' => request()->ip(),
                ]);

                throw new \Illuminate\Auth\Access\AuthorizationException(
                    'You do not have permission to access these events.'
                );
            }
        }

        // SECURITY: Use user-isolated queue key
        $queueKey = self::getQueueKey($interactionId);

        try {
            // Use dedicated eventstream connection (DB 3) to match send()
            $redis = \Illuminate\Support\Facades\Redis::connection('eventstream');

            // Get all events from queue and clear atomically
            $rawEvents = $redis->pipeline(function ($pipe) use ($queueKey) {
                $pipe->lrange($queueKey, 0, -1);
                $pipe->del($queueKey);
            });

            // First element of pipeline result contains the events
            $eventStrings = $rawEvents[0] ?? [];

            // Decode JSON events
            $events = array_map(function ($eventString) {
                return json_decode($eventString, true);
            }, $eventStrings);

            return array_filter($events);
        } catch (\RedisException $e) {
            // Redis unavailable - return empty array, no events available
            Log::info('EventStreamNotifier: Redis unavailable, no events retrieved', [
                'interaction_id' => $interactionId,
            ]);

            return [];
        }
    }

    /**
     * Send a step added event
     */
    public static function stepAdded(int $interactionId, string $source, string $message): void
    {
        self::send($interactionId, 'step_added', [
            'source' => $source,
            'message' => $message,
            'timestamp' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Send a source added event
     */
    public static function sourceAdded(int $interactionId, array $sourceData): void
    {
        self::send($interactionId, 'source_added', [
            'source_id' => $sourceData['source_id'] ?? null,
            'url' => $sourceData['url'] ?? null,
            'title' => $sourceData['title'] ?? null,
            'domain' => $sourceData['domain'] ?? null,
            'discovery_method' => $sourceData['discovery_method'] ?? null,
            'relevance_score' => $sourceData['relevance_score'] ?? null,
        ]);
    }

    /**
     * Send a artifact added event
     */
    public static function artifactAdded(int $interactionId, array $artifactData): void
    {
        self::send($interactionId, 'artifact_added', [
            'artifact_id' => $artifactData['artifact_id'] ?? null,
            'interaction_type' => $artifactData['interaction_type'] ?? 'created',
            'tool_used' => $artifactData['tool_used'] ?? null,
        ]);
    }

    /**
     * Send an interaction updated event
     */
    public static function interactionUpdated(int $interactionId, string $answer): void
    {
        \Illuminate\Support\Facades\Log::info('EventStreamNotifier: Interaction updated', [
            'interaction_id' => $interactionId,
            'answer_length' => strlen($answer),
            'has_answer' => ! empty($answer),
        ]);

        // Dispatch event using Laravel's built-in event system for Livewire compatibility
        try {
            // Use Laravel's event system directly with a string event for Livewire listeners
            event('chat-interaction-updated', [
                'interaction_id' => $interactionId,
                'answer' => $answer,
                'answer_length' => strlen($answer),
                'has_answer' => ! empty($answer),
                'timestamp' => now()->toISOString(),
            ]);

            \Illuminate\Support\Facades\Log::info('EventStreamNotifier: Dispatched chat-interaction-updated Laravel event', [
                'interaction_id' => $interactionId,
                'has_answer' => ! empty($answer),
            ]);

            // Also broadcast the ChatInteractionUpdated event for WebSocket real-time updates
            $interaction = \App\Models\ChatInteraction::find($interactionId);
            if ($interaction) {
                event(new \App\Events\ChatInteractionUpdated($interaction));

                \Illuminate\Support\Facades\Log::info('EventStreamNotifier: Broadcasted ChatInteractionUpdated event', [
                    'interaction_id' => $interactionId,
                    'has_answer' => ! empty($answer),
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('EventStreamNotifier: Failed to dispatch events', [
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Also send to EventStream cache for backwards compatibility
        self::send($interactionId, 'interaction_updated', [
            'answer' => $answer,
            'answer_length' => strlen($answer),
            'has_answer' => ! empty($answer),
        ]);

        // NOTE: We no longer fire ResearchComplete here because ResearchJob/BroadcastsWorkflowEvents
        // handles all completion events properly (HolisticWorkflowCompleted or ResearchComplete based on mode).
        // Firing it here caused duplicate Slack notifications and other duplicate event handlers.
    }

    public static function researchComplete(int $interactionId, string $answer): void
    {
        // Send event via event stream
        self::send($interactionId, 'research_complete', [
            'interaction_id' => $interactionId,
            'timestamp' => now()->toISOString(),
            'answer_length' => strlen($answer),
        ]);

        // Log for debugging
        \Illuminate\Support\Facades\Log::info('EventStreamNotifier: Research complete event triggered', [
            'interaction_id' => $interactionId,
            'answer_length' => strlen($answer),
        ]);

        // Dispatch event using Laravel's built-in event system
        try {
            // Use Laravel's native event system which is compatible with all Livewire versions
            \Illuminate\Support\Facades\Log::info('EventStreamNotifier: Using Laravel events system for compatibility');

            // Use Laravel's event system directly with a string event
            // This is compatible with both Livewire 2 and Livewire 3 (Volt)
            event('research-complete', [
                'interactionId' => $interactionId,
                'timestamp' => now()->toISOString(),
            ]);

            // Use proper event broadcasting with a dedicated event class
            try {
                // Create and dispatch the ResearchComplete event
                // Use a fake execution ID since EventStreamNotifier is used for legacy compatibility
                $event = new \App\Events\ResearchComplete(
                    $interactionId,
                    0, // Execution ID - use 0 for EventStreamNotifier calls since we don't have a real execution
                    $answer, // The result/answer
                    ['source' => 'EventStreamNotifier'] // Metadata to indicate this came from legacy system
                );

                // Broadcast the event
                event($event);
            } catch (\Exception $broadcastError) {
                \Illuminate\Support\Facades\Log::warning('Broadcast error: '.$broadcastError->getMessage());
            }

            \Illuminate\Support\Facades\Log::info('EventStreamNotifier: Research complete event dispatched');
        } catch (\Exception $e) {
            // Log any errors but don't let them break the flow
            \Illuminate\Support\Facades\Log::warning('Failed to dispatch Livewire event: '.$e->getMessage());
        }
    }

    /**
     * Send a knowledge source added event
     */
    public static function knowledgeSourceAdded(int $interactionId, array $sourceData): void
    {
        self::send($interactionId, 'knowledge_source_added', [
            'document_id' => $sourceData['document_id'] ?? null,
            'title' => $sourceData['title'] ?? 'Untitled Document',
            'source_type' => $sourceData['source_type'] ?? 'knowledge',
            'is_expired' => $sourceData['is_expired'] ?? false,
            'preview_url' => $sourceData['preview_url'] ?? null,
            'content_excerpt' => $sourceData['content_excerpt'] ?? null,
            'tags' => $sourceData['tags'] ?? [],
            'created_at' => $sourceData['created_at'] ?? null,
        ]);
    }
}
