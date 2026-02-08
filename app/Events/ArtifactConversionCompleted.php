<?php

namespace App\Events;

use App\Models\ArtifactConversion;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArtifactConversionCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ArtifactConversion $conversion;

    public function __construct(ArtifactConversion $conversion)
    {
        $this->conversion = $conversion;

        \Log::debug('ArtifactConversionCompleted event constructed', [
            'conversion_id' => $conversion->id,
            'artifact_id' => $conversion->artifact_id,
            'user_id' => $conversion->created_by,
            'status' => $conversion->status,
            'format' => $conversion->output_format,
            'channel' => 'artifact-conversion.'.$conversion->created_by,
        ]);
    }

    public function broadcastOn()
    {
        return new Channel('artifact-conversion.'.$this->conversion->created_by);
    }

    public function broadcastAs()
    {
        return 'conversion.completed';
    }

    public function broadcastWith()
    {
        $this->conversion->load(['artifact', 'asset']);

        $downloadUrl = null;
        if ($this->conversion->asset_id && $this->conversion->asset) {
            // Generate signed URL valid for 15 minutes
            $downloadUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'api.v1.artifacts.conversions.download',
                now()->addMinutes(15),
                [
                    'artifact' => $this->conversion->artifact_id,
                    'conversion' => $this->conversion->id,
                ]
            );

        }

        return [
            'conversion_id' => $this->conversion->id,
            'artifact_id' => $this->conversion->artifact_id,
            'artifact_title' => $this->conversion->artifact->title ?? 'Untitled',
            'format' => strtoupper($this->conversion->output_format),
            'template' => $this->conversion->template ?? 'default',
            'status' => $this->conversion->status,
            'file_size' => $this->conversion->formatted_file_size ?? 'N/A',
            'download_url' => $downloadUrl,
            'error_message' => $this->conversion->error_message,
            'completed_at' => $this->conversion->updated_at->toISOString(),
        ];
    }
}
