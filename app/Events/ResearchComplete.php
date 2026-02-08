<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when a single-agent research workflow is completed
 * This is for simple, single-step research without plan/execute/synthesize phases
 */
class ResearchComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $interactionId;

    public int $executionId;

    public string $result;

    public array $metadata;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $interactionId,
        int $executionId,
        string $result,
        array $metadata = []
    ) {
        $this->interactionId = $interactionId;
        $this->executionId = $executionId;
        $this->result = $result;
        $this->metadata = $metadata;

        \Log::info('ResearchComplete event constructed', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'channel' => 'chat-interaction.'.$interactionId,
            'result_length' => strlen($result),
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('chat-interaction.'.$this->interactionId),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * IMPORTANT: Truncates result to prevent Pusher/Reverb payload size limit (10KB).
     * The frontend checks metadata.broadcast_truncated and refetches from database when needed.
     * Full result is always stored in database regardless of truncation.
     */
    public function broadcastWith(): array
    {
        $resultLength = strlen($this->result);

        // Truncate result to prevent Pusher/Reverb payload size limit (10KB)
        // Send only first 2000 chars for preview, frontend will refetch full result from DB
        $truncatedResult = $resultLength > 2000
            ? substr($this->result, 0, 2000).'...'
            : $this->result;

        // Add truncation flag to metadata so frontend knows to refetch
        $metadata = $this->metadata;
        $metadata['broadcast_truncated'] = $resultLength > 2000;
        $metadata['full_result_length'] = $resultLength;

        return [
            'interaction_id' => $this->interactionId,
            'execution_id' => $this->executionId,
            'result' => $truncatedResult,
            'metadata' => $metadata,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'ResearchComplete';
    }
}
