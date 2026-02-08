<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $interactionId;

    public array $jobData;

    public function __construct(string $interactionId, array $jobData)
    {
        $this->interactionId = $interactionId;
        $this->jobData = $jobData;
    }

    public function broadcastOn()
    {
        return new Channel('chat-interaction.'.$this->interactionId);
    }

    public function broadcastAs()
    {
        return 'QueueStatusUpdated';
    }

    public function broadcastWith()
    {
        return [
            'interaction_id' => $this->interactionId,
            'type' => 'queue_status_update',
            'job_data' => $this->jobData,
            'timestamp' => now()->toISOString(),
        ];
    }
}
