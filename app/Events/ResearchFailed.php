<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when a single-agent research workflow fails
 * This is for simple, single-step research that encounters errors
 */
class ResearchFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $interactionId;

    public int $executionId;

    public string $error;

    public array $metadata;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $interactionId,
        int $executionId,
        string $error,
        array $metadata = []
    ) {
        $this->interactionId = $interactionId;
        $this->executionId = $executionId;
        $this->error = $error;
        $this->metadata = $metadata;

        \Log::error('ResearchFailed event constructed', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'channel' => 'chat-interaction.'.$interactionId,
            'error' => $error,
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
     */
    public function broadcastWith(): array
    {
        return [
            'interaction_id' => $this->interactionId,
            'execution_id' => $this->executionId,
            'error' => $this->error,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'ResearchFailed';
    }
}
