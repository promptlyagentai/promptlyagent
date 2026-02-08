<?php

namespace App\Events;

use App\Enums\AgentPhase;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $executionId,
        public int $sessionId,
        public AgentPhase $phase,
        public array $progress, // ['current' => 5, 'total' => 12]
        public string $message
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

        // Always use private channel for session-based agent progress updates
        return new PrivateChannel('chat-session.'.$this->sessionId);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'agent.progress.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        $progressPercentage = 0;
        if (isset($this->progress['total']) && $this->progress['total'] > 0) {
            $progressPercentage = (int) round(($this->progress['current'] / $this->progress['total']) * 100);
        }

        $broadcastData = [
            'execution_id' => $this->executionId,
            'phase' => $this->phase->value,
            'phase_display' => $this->phase->getDisplayName(),
            'progress' => $this->progress,
            'progress_percentage' => $progressPercentage,
            'message' => $this->message,
            'timestamp' => now()->toISOString(),
            'session_id' => $this->sessionId,
        ];

        // Log the broadcast data
        \Illuminate\Support\Facades\Log::info('📡 AgentProgressUpdated broadcasting data', [
            'execution_id' => $this->executionId,
            'session_id' => $this->sessionId,
            'phase' => $this->phase->value,
            'progress' => $this->progress,
            'progress_percentage' => $progressPercentage,
            'message' => $this->message,
            'channel' => $this->broadcastOn()->name,
            'event_name' => $this->broadcastAs(),
        ]);

        return $broadcastData;
    }
}
