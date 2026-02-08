<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ToolStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $sessionId,
        public string $status,
        public array $data = []
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

        // Always use private channel for session-based tool status updates
        // This ensures only authorized users of the session receive real-time events
        return new PrivateChannel('chat-session.'.$this->sessionId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'tool.status.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $broadcastData = [
            'status' => $this->status,
            'data' => $this->data,
            'timestamp' => now()->toISOString(),
            'session_id' => $this->sessionId, // Add session ID for debugging
        ];

        // Log the actual broadcast data
        \Illuminate\Support\Facades\Log::info('📡 ToolStatusUpdated broadcasting data', [
            'session_id' => $this->sessionId,
            'status' => $this->status,
            'channel' => $this->broadcastOn()->name,
            'event_name' => $this->broadcastAs(),
            'data_keys' => array_keys($this->data),
            'broadcast_payload' => $broadcastData,
        ]);

        return $broadcastData;
    }
}
