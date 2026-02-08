<?php

namespace App\Livewire\Components\Tabs;

use App\Models\ChatInteractionArtifact;
use Livewire\Component;

class ArtifactsTabContent extends Component
{
    public $executionId;

    public $interactionId;

    public $artifacts = [];

    public $timeline = [];

    public $interactionQuery = null;

    public $showAsTimelineItem = false;

    public $interactionQuestion = null;

    public $interactionTimestamp = null;

    protected $listeners = [
        'refreshArtifacts' => 'loadArtifacts',
        'artifact-created' => 'handleArtifactCreated',
        'chat-interaction-artifact-created' => 'handleChatInteractionArtifactCreated',
    ];

    public function mount($executionId = null, $interactionId = null, $showAsTimelineItem = false, $interactionQuestion = null, $interactionTimestamp = null)
    {
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;
        $this->showAsTimelineItem = $showAsTimelineItem;
        $this->interactionQuestion = $interactionQuestion;
        $this->interactionTimestamp = $interactionTimestamp;
        $this->loadArtifacts();
    }

    public function refreshArtifacts()
    {
        $this->loadArtifacts();
    }

    /**
     * Handle real-time artifact creation events
     */
    public function handleArtifactCreated($artifactData = [])
    {
        $this->loadArtifacts();
    }

    /**
     * Handle real-time chat interaction artifact creation events
     */
    public function handleChatInteractionArtifactCreated($cifData = [])
    {
        if (isset($cifData['chat_interaction_id']) && $cifData['chat_interaction_id'] == $this->interactionId) {
            $this->loadArtifacts();
        }
    }

    public function loadArtifacts()
    {
        $combinedTimeline = [];

        // Load artifacts from ChatInteractionArtifact if we have an interaction ID
        if ($this->interactionId) {
            $chatInteractionArtifacts = ChatInteractionArtifact::with(['artifact.tags', 'chatInteraction'])
                ->where('chat_interaction_id', $this->interactionId)
                ->get();

            // Get the interaction query for display
            $firstInteractionArtifact = $chatInteractionArtifacts->first();
            if ($firstInteractionArtifact && $firstInteractionArtifact->chatInteraction) {
                $this->interactionQuery = $firstInteractionArtifact->chatInteraction->question;
            }

            foreach ($chatInteractionArtifacts as $cif) {
                if ($cif->artifact) {
                    $artifact = $cif->artifact;

                    $combinedTimeline[] = [
                        'type' => 'chat_interaction_artifact',
                        'artifact' => $artifact,
                        'chat_interaction_artifact' => $cif,
                        'id' => $artifact->id,
                        'title' => $artifact->id.': '.($artifact->title ?: 'Untitled Artifact'),
                        'description' => $artifact->description,
                        'filetype' => $artifact->filetype,
                        'filetype_badge_class' => $artifact->filetype_badge_class,
                        'interaction_type' => $cif->interaction_type,
                        'tool_used' => $cif->tool_used,
                        'context_summary' => $cif->context_summary,
                        'timestamp' => $cif->interacted_at,
                        'word_count' => $artifact->word_count,
                        'reading_time' => $artifact->reading_time,
                        'tags' => $artifact->tags->pluck('name')->toArray(),
                        'privacy_level' => $artifact->privacy_level,
                        'url' => '#',
                        'is_code_file' => $artifact->is_code_file,
                        'is_text_file' => $artifact->is_text_file,
                        'is_data_file' => $artifact->is_data_file,
                    ];
                }
            }
        }

        // Sort by timestamp (newest first)
        usort($combinedTimeline, function ($a, $b) {
            $timestampA = \Carbon\Carbon::parse($a['timestamp']);
            $timestampB = \Carbon\Carbon::parse($b['timestamp']);

            return $timestampB->timestamp <=> $timestampA->timestamp;
        });

        $this->timeline = $combinedTimeline;
        $this->artifacts = collect($combinedTimeline);
    }

    public function openArtifactDrawer($artifactId)
    {
        $this->dispatch('open-artifact-drawer', [
            'artifactId' => $artifactId,
            'mode' => 'preview',
        ]);
    }

    public function render()
    {
        return view('livewire.components.tabs.artifacts-tab-content');
    }
}
