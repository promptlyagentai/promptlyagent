<?php

namespace App\Events;

use App\Enums\AgentPhase;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Agent Phase Changed - Real-time Execution Progress Event.
 *
 * Broadcasts agent execution phase transitions to connected clients via WebSocket
 * for real-time progress tracking in the UI. Dispatched by StatusReporter during
 * agent execution lifecycle to keep users informed of current progress.
 *
 * Broadcasting Strategy:
 * - **ShouldBroadcastNow**: Immediate broadcast (not queued) for real-time updates
 * - **Private Channel**: `chat-session.{sessionId}` or `user.{userId}` fallback
 * - **Event Name**: `agent.phase.changed`
 *
 * Channel Selection Logic:
 * - Positive sessionId → Broadcast to `chat-session.{sessionId}` (normal flow)
 * - Negative sessionId → Broadcast to `user.{abs(sessionId)}` (fallback when no session)
 *
 * Broadcast Payload:
 * - execution_id: AgentExecution ID for tracking
 * - phase: Enum value (string)
 * - phase_display: User-friendly display name
 * - phase_description: Detailed description for tooltips
 * - message: Custom status message (if provided)
 * - metadata: Additional context (tool results, progress indicators)
 * - timestamp: ISO 8601 timestamp for client-side ordering
 *
 * UI Integration:
 * - Progress indicators show current phase + description
 * - Phase history timeline for debugging/transparency
 * - Loading states tied to phase transitions
 * - Error recovery when phase changes unexpectedly
 *
 * Performance:
 * - Broadcasts synchronously (ShouldBroadcastNow)
 * - Minimal payload (no full execution context)
 * - Client-side debouncing for rapid phase changes
 *
 * @see \App\Enums\AgentPhase
 * @see \App\Services\StatusReporter
 * @see \App\Services\Agents\AgentExecutor
 */
class AgentPhaseChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $executionId,
        public int $sessionId,
        public AgentPhase $phase,
        public string $message,
        public array $metadata = []
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        // If sessionId is negative, it's a user-based ID (fallback when no session context)
        if ($this->sessionId < 0) {
            $userId = abs($this->sessionId);

            return new PrivateChannel('user.'.$userId);
        }

        // Always use private channel for session-based agent phase updates
        return new PrivateChannel('chat-session.'.$this->sessionId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'agent.phase.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * Prepares payload for WebSocket transmission with full phase context.
     * Includes display names, descriptions, and metadata for UI consumption.
     *
     * @return array Broadcast payload with execution context
     */
    public function broadcastWith(): array
    {
        return [
            'execution_id' => $this->executionId,
            'phase' => $this->phase->value,
            'phase_display' => $this->phase->getDisplayName(),
            'phase_description' => $this->phase->getDescription(),
            'message' => $this->message,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
            'session_id' => $this->sessionId,
        ];
    }
}
