<?php

namespace App\Events;

use App\Models\StatusStream;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StatusStreamCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $statusStream;

    public function __construct(StatusStream $statusStream)
    {
        $this->statusStream = $statusStream;

        \Log::info('StatusStreamCreated event constructed', [
            'interaction_id' => $statusStream->interaction_id,
            'channel' => 'status-stream.'.$statusStream->interaction_id,
            'source' => $statusStream->source,
            'message' => $statusStream->message,
        ]);
    }

    public function broadcastOn()
    {
        return new Channel('status-stream.'.$this->statusStream->interaction_id);
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->statusStream->id,
            'interaction_id' => $this->statusStream->interaction_id,
            'source' => $this->statusStream->source,
            'message' => $this->statusStream->message,
            'timestamp' => $this->statusStream->timestamp->toISOString(),
            'is_significant' => $this->statusStream->is_significant,
            'metadata' => $this->statusStream->metadata,
            'create_event' => $this->statusStream->create_event ?? true, // Use model value with fallback for backward compatibility
        ];
    }
}
