<?php

namespace App\Events;

use App\Models\ChatInteraction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chat Interaction Updated - Real-time Chat Message Event.
 *
 * Broadcasts chat interaction changes (question/answer updates) to connected
 * clients via WebSocket. Used primarily for streaming agent responses and
 * updating UI when interaction content changes.
 *
 * Broadcasting Strategy:
 * - **ShouldBroadcastNow**: Immediate broadcast for real-time updates
 * - **Public Channel**: `chat-interaction-updated.{interactionId}`
 * - **No Authentication**: Public channel (access controlled at session level)
 *
 * Use Cases:
 * - Streaming agent responses character-by-character or chunk-by-chunk
 * - Updating answer field when agent execution completes
 * - Notifying clients when interaction state changes
 * - Real-time collaboration (multiple users viewing same session)
 *
 * Broadcast Payload:
 * - id: ChatInteraction ID
 * - question: User's original question (immutable)
 * - answer: Agent's response (updated incrementally during streaming)
 * - has_answer: Boolean flag for UI state management
 * - answer_length: Character count for progress indicators
 * - updated_at: Last modification timestamp
 * - created_at: Original creation timestamp
 *
 * UI Integration:
 * - Real-time markdown rendering of streaming responses
 * - Loading indicators toggle on has_answer flag
 * - Progress tracking via answer_length
 * - Optimistic UI updates with server-side reconciliation
 *
 * Performance Considerations:
 * - Broadcasts synchronously (blocking) - keep payload minimal
 * - No full model serialization (only essential fields)
 * - Client-side debouncing for rapid updates during streaming
 *
 * @see \App\Models\ChatInteraction
 * @see \App\Http\Controllers\StreamingController
 * @see \App\Services\Agents\AgentExecutor
 */
class ChatInteractionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatInteraction;

    public function __construct(ChatInteraction $chatInteraction)
    {
        $this->chatInteraction = $chatInteraction;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Uses public channel for chat interaction updates. Access control
     * happens at the session level, not individual interactions.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        return new Channel('chat-interaction-updated.'.$this->chatInteraction->id);
    }

    /**
     * Get the data to broadcast.
     *
     * Minimal payload optimized for streaming performance. Includes
     * answer length and has_answer flag for UI state management.
     *
     * IMPORTANT: Truncates answer to prevent Pusher payload size limit (10KB).
     * Clients should refetch full answer from API when needed.
     *
     * @return array Broadcast payload with interaction state
     */
    public function broadcastWith()
    {
        $answer = $this->chatInteraction->answer ?? '';
        $answerLength = strlen($answer);

        // Truncate answer to prevent Pusher/Reverb payload size limit (10KB)
        // Send only first 2000 chars for preview, clients can refetch full answer
        $truncatedAnswer = $answerLength > 2000
            ? substr($answer, 0, 2000).'...'
            : $answer;

        return [
            'id' => $this->chatInteraction->id,
            'question' => $this->chatInteraction->question,
            'answer' => $truncatedAnswer,
            'answer_truncated' => $answerLength > 2000,
            'has_answer' => ! empty($answer),
            'answer_length' => $answerLength,
            'updated_at' => $this->chatInteraction->updated_at->toISOString(),
            'created_at' => $this->chatInteraction->created_at->toISOString(),
        ];
    }
}
