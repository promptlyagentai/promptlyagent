<?php

namespace App\Events;

use App\Models\ChatInteractionSource;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatInteractionSourceCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatInteractionSource;

    public function __construct(ChatInteractionSource $chatInteractionSource)
    {
        $this->chatInteractionSource = $chatInteractionSource;

        \Log::debug('ChatInteractionSourceCreated event constructed', [
            'interaction_id' => $chatInteractionSource->chat_interaction_id,
            'source_id' => $chatInteractionSource->source_id,
        ]);
    }

    public function broadcastOn()
    {
        return new Channel('sources-updated.'.$this->chatInteractionSource->chat_interaction_id);
    }

    public function broadcastWith()
    {
        // Load the source relationship
        $this->chatInteractionSource->load('source');

        return [
            'id' => $this->chatInteractionSource->id,
            'chat_interaction_id' => $this->chatInteractionSource->chat_interaction_id,
            'source_id' => $this->chatInteractionSource->source_id,
            'relevance_score' => $this->chatInteractionSource->relevance_score,
            'discovery_method' => $this->chatInteractionSource->discovery_method,
            'discovery_tool' => $this->chatInteractionSource->discovery_tool,
            'was_scraped' => $this->chatInteractionSource->was_scraped,
            'discovered_at' => $this->chatInteractionSource->discovered_at->toISOString(),
            'source' => $this->chatInteractionSource->source ? [
                'id' => $this->chatInteractionSource->source->id,
                'url' => $this->chatInteractionSource->source->url,
                'title' => $this->chatInteractionSource->source->title,
                'description' => $this->chatInteractionSource->source->description,
                'domain' => $this->chatInteractionSource->source->domain,
                'favicon' => $this->chatInteractionSource->source->favicon,
                'http_status' => $this->chatInteractionSource->source->http_status,
                'content_category' => $this->chatInteractionSource->source->content_category,
                'content_preview' => $this->chatInteractionSource->source->content_preview,
                'has_content' => ! empty($this->chatInteractionSource->source->content_markdown),
            ] : null,
        ];
    }
}
