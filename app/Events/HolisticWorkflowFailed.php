<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when a holistic research workflow fails
 * This includes multi-step research with plan, execute, and synthesize phases that encounter errors
 */
class HolisticWorkflowFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $interactionId;

    public int $executionId;

    public string $error;

    public array $metadata;

    public ?string $phase;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $interactionId,
        int $executionId,
        string $error,
        array $metadata = [],
        ?string $phase = null
    ) {
        $this->interactionId = $interactionId;
        $this->executionId = $executionId;
        $this->error = $error;
        $this->metadata = $metadata;
        $this->phase = $phase;

        \Log::error('HolisticWorkflowFailed event constructed', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'channel' => 'chat-interaction.'.$interactionId,
            'error' => $error,
            'phase' => $phase,
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
            'phase' => $this->phase,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'HolisticWorkflowFailed';
    }
}
