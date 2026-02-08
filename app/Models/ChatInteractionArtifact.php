<?php

namespace App\Models;

use App\Services\EventStreamNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ChatInteractionArtifact extends Model
{
    protected $fillable = [
        'chat_interaction_id',
        'artifact_id',
        'interaction_type',
        'tool_used',
        'context_summary',
        'metadata',
        'interacted_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'interacted_at' => 'datetime',
    ];

    /**
     * Relationship to chat interaction
     */
    public function chatInteraction(): BelongsTo
    {
        return $this->belongsTo(ChatInteraction::class);
    }

    /**
     * Relationship to artifact
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /**
     * Create or update a chat interaction artifact tracking
     */
    public static function createOrUpdate(
        int $chatInteractionId,
        int $artifactId,
        string $interactionType,
        string $toolUsed,
        ?string $contextSummary = null,
        array $metadata = []
    ): self {
        // Try to find existing record
        $chatInteractionArtifact = self::where('chat_interaction_id', $chatInteractionId)
            ->where('artifact_id', $artifactId)
            ->where('interaction_type', $interactionType)
            ->first();

        $data = [
            'tool_used' => $toolUsed,
            'context_summary' => $contextSummary,
            'metadata' => array_merge($metadata, [
                'tracked_at' => now()->toISOString(),
            ]),
            'interacted_at' => now(),
        ];

        if ($chatInteractionArtifact) {
            // Update existing record
            $chatInteractionArtifact->update($data);

            Log::info('Updated chat interaction artifact', [
                'chat_interaction_id' => $chatInteractionId,
                'artifact_id' => $artifactId,
                'interaction_type' => $interactionType,
            ]);

            return $chatInteractionArtifact;
        } else {
            // Create new record
            $data['chat_interaction_id'] = $chatInteractionId;
            $data['artifact_id'] = $artifactId;
            $data['interaction_type'] = $interactionType;

            $chatInteractionArtifact = self::create($data);

            Log::info('Created chat interaction artifact', [
                'chat_interaction_id' => $chatInteractionId,
                'artifact_id' => $artifactId,
                'interaction_type' => $interactionType,
                'tool_used' => $toolUsed,
            ]);

            // Send real-time artifact added event via EventStreamNotifier
            EventStreamNotifier::artifactAdded($chatInteractionId, [
                'artifact_id' => $artifactId,
                'interaction_type' => $interactionType,
                'tool_used' => $toolUsed,
            ]);

            // Broadcast WebSocket event for real-time updates
            try {
                $chatInteractionArtifact->load('chatInteraction');
                event(new \App\Events\ChatInteractionArtifactCreated($chatInteractionArtifact));

                Log::info('ChatInteractionArtifact: Broadcasted ChatInteractionArtifactCreated event', [
                    'chat_interaction_id' => $chatInteractionId,
                    'artifact_id' => $artifactId,
                    'session_id' => $chatInteractionArtifact->chatInteraction->chat_session_id,
                    'channel' => 'artifacts-updated.'.$chatInteractionArtifact->chatInteraction->chat_session_id,
                ]);
            } catch (\Exception $eventError) {
                Log::error('Failed to broadcast ChatInteractionArtifactCreated event', [
                    'chat_interaction_id' => $chatInteractionId,
                    'artifact_id' => $artifactId,
                    'error' => $eventError->getMessage(),
                ]);
            }

            return $chatInteractionArtifact;
        }
    }

    /**
     * Get artifacts for a chat interaction formatted for display
     */
    public static function getArtifactsForInteraction(int $chatInteractionId): array
    {
        return self::where('chat_interaction_id', $chatInteractionId)
            ->with('artifact')
            ->orderBy('interacted_at', 'asc')
            ->get()
            ->map(function ($item) {
                $artifact = $item->artifact;

                return [
                    'id' => $artifact->id,
                    'title' => $artifact->title,
                    'description' => $artifact->description,
                    'filetype' => $artifact->filetype,
                    'interaction_type' => $item->interaction_type,
                    'tool_used' => $item->tool_used,
                    'context_summary' => $item->context_summary,
                    'timestamp' => $item->interacted_at->toISOString(),
                    'word_count' => $artifact->word_count,
                    'reading_time' => $artifact->reading_time,
                ];
            })
            ->toArray();
    }
}
