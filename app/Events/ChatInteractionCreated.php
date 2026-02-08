<?php

namespace App\Events;

use App\Models\ChatInteraction;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a new ChatInteraction is created
 *
 * Broadcasts on the session channel so the UI can discover new interactions
 * in real-time without requiring page reload. This is essential for API/webhook
 * triggered interactions that are created outside the normal UI flow.
 */
class ChatInteractionCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChatInteraction $chatInteraction)
    {
        \Log::debug('ChatInteractionCreated event constructed', [
            'interaction_id' => $chatInteraction->id,
            'session_id' => $chatInteraction->chat_session_id,
            'has_answer' => ! empty($chatInteraction->answer),
            'input_trigger_id' => $chatInteraction->input_trigger_id,
        ]);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Broadcasts on the SESSION channel (not interaction channel) because
     * the UI is already subscribed to the session channel when viewing it.
     * This allows the UI to discover new interactions created via API/webhooks.
     */
    public function broadcastOn()
    {
        return new PrivateChannel('chat-session.'.$this->chatInteraction->chat_session_id);
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'interaction.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'interaction_id' => $this->chatInteraction->id,
            'chat_session_id' => $this->chatInteraction->chat_session_id,
            'question' => $this->chatInteraction->question,
            'answer' => $this->chatInteraction->answer,
            'agent_id' => $this->chatInteraction->agent_id,
            'input_trigger_id' => $this->chatInteraction->input_trigger_id,
            'has_answer' => ! empty($this->chatInteraction->answer),
            'created_at' => $this->chatInteraction->created_at->toISOString(),
            'timestamp' => now()->toISOString(),
        ];
    }
}
