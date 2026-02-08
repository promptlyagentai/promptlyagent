<?php

namespace App\Events;

use App\Models\KnowledgeDocument;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExternalKnowledgeUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public KnowledgeDocument $document;

    public string $action;

    public array $changes;

    /**
     * Create a new event instance.
     */
    public function __construct(KnowledgeDocument $document, string $action = 'updated', array $changes = [])
    {
        $this->document = $document->load(['tags']);
        $this->action = $action;
        $this->changes = $changes;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('knowledge.user.'.$this->document->created_by),
            new Channel('knowledge.external.updates'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'document' => [
                'id' => $this->document->id,
                'title' => $this->document->title,
                'source_type' => $this->document->source_type,
                'external_source_identifier' => $this->document->external_source_identifier,
                'processing_status' => $this->document->processing_status,
                'auto_refresh_enabled' => $this->document->auto_refresh_enabled,
                'last_fetched_at' => $this->document->last_fetched_at,
                'next_refresh_at' => $this->document->next_refresh_at,
                'content_hash' => $this->document->content_hash,
                'updated_at' => $this->document->updated_at,
            ],
            'action' => $this->action,
            'changes' => $this->changes,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the event name for broadcasting.
     */
    public function broadcastAs(): string
    {
        return 'external.knowledge.'.$this->action;
    }
}
