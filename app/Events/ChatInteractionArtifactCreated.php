<?php

namespace App\Events;

use App\Models\ChatInteractionArtifact;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatInteractionArtifactCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatInteractionArtifact;

    public function __construct(ChatInteractionArtifact $chatInteractionArtifact)
    {
        $this->chatInteractionArtifact = $chatInteractionArtifact;

        $sessionId = $this->chatInteractionArtifact->chatInteraction->chat_session_id ?? 'unknown';

        \Log::debug('ChatInteractionArtifactCreated event constructed', [
            'interaction_id' => $chatInteractionArtifact->chat_interaction_id,
            'artifact_id' => $chatInteractionArtifact->artifact_id,
            'session_id' => $sessionId,
            'channel' => 'artifacts-updated.'.$sessionId,
        ]);
    }

    public function broadcastOn()
    {
        $sessionId = $this->chatInteractionArtifact->chatInteraction->chat_session_id;

        return new Channel('artifacts-updated.'.$sessionId);
    }

    public function broadcastWith()
    {
        // Load the artifact relationship
        $this->chatInteractionArtifact->load('artifact');

        return [
            'id' => $this->chatInteractionArtifact->id,
            'chat_interaction_id' => $this->chatInteractionArtifact->chat_interaction_id,
            'artifact_id' => $this->chatInteractionArtifact->artifact_id,
            'interaction_type' => $this->chatInteractionArtifact->interaction_type,
            'tool_used' => $this->chatInteractionArtifact->tool_used,
            'context_summary' => $this->chatInteractionArtifact->context_summary,
            'interacted_at' => $this->chatInteractionArtifact->interacted_at->toISOString(),
            'artifact' => $this->chatInteractionArtifact->artifact ? [
                'id' => $this->chatInteractionArtifact->artifact->id,
                'title' => $this->chatInteractionArtifact->artifact->title,
                'description' => $this->chatInteractionArtifact->artifact->description,
                'filetype' => $this->chatInteractionArtifact->artifact->filetype,
                'privacy_level' => $this->chatInteractionArtifact->artifact->privacy_level,
                'word_count' => $this->chatInteractionArtifact->artifact->word_count,
                'reading_time' => $this->chatInteractionArtifact->artifact->reading_time,
                'filetype_badge_class' => $this->chatInteractionArtifact->artifact->filetype_badge_class,
                'is_code_file' => $this->chatInteractionArtifact->artifact->is_code_file,
                'is_text_file' => $this->chatInteractionArtifact->artifact->is_text_file,
                'is_data_file' => $this->chatInteractionArtifact->artifact->is_data_file,
            ] : null,
        ];
    }
}
