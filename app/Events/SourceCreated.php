<?php

namespace App\Events;

use App\Models\Source;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SourceCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $source;

    public function __construct(Source $source)
    {
        $this->source = $source;

        \Log::debug('SourceCreated event constructed', [
            'source_id' => $source->id,
            'domain' => $source->domain,
        ]);
    }

    public function broadcastOn()
    {
        // Broadcast to a general channel - we'll filter by interaction on the frontend
        return new Channel('sources-updated');
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->source->id,
            'url' => $this->source->url,
            'title' => $this->source->title,
            'description' => $this->source->description,
            'domain' => $this->source->domain,
            'favicon' => $this->source->favicon,
            'http_status' => $this->source->http_status,
            'content_category' => $this->source->content_category,
            'is_scrapeable' => $this->source->is_scrapeable,
            'content_preview' => $this->source->content_preview,
            'created_at' => $this->source->created_at->toISOString(),
        ];
    }
}
