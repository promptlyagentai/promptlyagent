<?php

namespace App\Livewire\Components\Tabs;

use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\StatusStream;
use Livewire\Component;

class StepsTabContent extends Component
{
    public $executionId;

    public $interactionId;

    public $timeline = [];

    public $expandedSteps = [];

    public $executionStats = [];

    public function mount($executionId, $interactionId = null)
    {
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;
        $this->loadTimeline();
    }

    public function toggleStep($index)
    {
        if (in_array($index, $this->expandedSteps)) {
            $this->expandedSteps = array_filter($this->expandedSteps, fn ($i) => $i !== $index);
        } else {
            $this->expandedSteps[] = $index;
        }
    }

    public function expandAll()
    {
        $this->expandedSteps = range(0, count($this->timeline) - 1);
    }

    public function collapseAll()
    {
        $this->expandedSteps = [];
    }

    protected function loadTimeline()
    {
        $execution = AgentExecution::find($this->executionId);
        $combinedTimeline = [];

        if ($execution) {
            // Load AgentExecution phase timeline
            $phaseTimeline = $execution->phase_timeline ?? [];

            // Convert phase timeline to standardized format
            foreach ($phaseTimeline as $index => $phase) {
                $combinedTimeline[] = [
                    'type' => 'phase',
                    'source' => 'agent_execution',
                    'phase' => $phase['phase'] ?? 'unknown',
                    'message' => $phase['message'] ?? $phase['phase'] ?? 'Phase update',
                    'timestamp' => $phase['timestamp'] ?? now(),
                    'data' => $phase,
                    'original_index' => $index,
                ];
            }

            // Load StatusStream entries if we have an interaction ID
            if ($this->interactionId) {
                $statusEntries = StatusStream::where('interaction_id', $this->interactionId)
                    ->orderBy('timestamp')
                    ->get();

                foreach ($statusEntries as $status) {
                    $combinedTimeline[] = [
                        'type' => 'status',
                        'source' => $status->source,
                        'phase' => $status->source,
                        'message' => $status->message,
                        'timestamp' => $status->timestamp,
                        'is_significant' => $status->is_significant ?? false,
                        'create_event' => $status->create_event ?? true,
                        'data' => [
                            'id' => $status->id,
                            'source' => $status->source,
                            'message' => $status->message,
                            'is_significant' => $status->is_significant ?? false,
                            'create_event' => $status->create_event ?? true,
                        ],
                    ];
                }
            } else {
                // Try to find StatusStream entries by chat session and timing
                $statusEntries = $this->findStatusStreamBySession($execution);

                foreach ($statusEntries as $status) {
                    $combinedTimeline[] = [
                        'type' => 'status',
                        'source' => $status->source,
                        'phase' => $status->source,
                        'message' => $status->message,
                        'timestamp' => $status->timestamp,
                        'is_significant' => $status->is_significant ?? false,
                        'create_event' => $status->create_event ?? true,
                        'data' => [
                            'id' => $status->id,
                            'source' => $status->source,
                            'message' => $status->message,
                            'is_significant' => $status->is_significant ?? false,
                            'create_event' => $status->create_event ?? true,
                        ],
                    ];
                }
            }

            // Sort combined timeline chronologically
            usort($combinedTimeline, function ($a, $b) {
                $timestampA = \Carbon\Carbon::parse($a['timestamp']);
                $timestampB = \Carbon\Carbon::parse($b['timestamp']);

                return $timestampA->timestamp <=> $timestampB->timestamp;
            });

            $this->timeline = $combinedTimeline;
            $this->calculateExecutionStats($execution);
        }
    }

    protected function findStatusStreamBySession($execution)
    {
        if (! $execution->chat_session_id) {
            return collect();
        }

        // Find interactions in the same session around the execution time
        $interactions = ChatInteraction::where('chat_session_id', $execution->chat_session_id)
            ->when($execution->started_at, function ($query) use ($execution) {
                $query->where('created_at', '>=', $execution->started_at->subMinutes(5))
                    ->where('created_at', '<=', ($execution->completed_at ?? now())->addMinutes(5));
            })
            ->get();

        $statusEntries = collect();

        foreach ($interactions as $interaction) {
            $entries = StatusStream::where('interaction_id', $interaction->id)
                ->orderBy('timestamp')
                ->get();
            $statusEntries = $statusEntries->merge($entries);
        }

        return $statusEntries;
    }

    protected function calculateExecutionStats($execution)
    {
        $timeline = $this->timeline;

        if (empty($timeline)) {
            $this->executionStats = [];

            return;
        }

        $startTime = $timeline[0]['timestamp'] ?? null;
        $endTime = end($timeline)['timestamp'] ?? null;

        $totalDuration = null;
        $phaseDurations = [];

        if ($startTime && $endTime) {
            $start = \Carbon\Carbon::parse($startTime);
            $end = \Carbon\Carbon::parse($endTime);
            $totalDuration = $start->diffInSeconds($end);
        }

        // Calculate duration for each phase
        for ($i = 0; $i < count($timeline) - 1; $i++) {
            $current = \Carbon\Carbon::parse($timeline[$i]['timestamp']);
            $next = \Carbon\Carbon::parse($timeline[$i + 1]['timestamp']);
            $phaseDurations[$i] = $current->diffInSeconds($next);
        }

        $this->executionStats = [
            'total_phases' => count($timeline),
            'total_duration' => $totalDuration,
            'phase_durations' => $phaseDurations,
            'current_phase' => $execution->getCurrentPhaseDisplay(),
            'is_completed' => $execution->current_phase === \App\Enums\AgentPhase::COMPLETED,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    public function refreshTimeline()
    {
        $this->loadTimeline();
    }

    public function getPhaseIcon($phase, $type = 'phase')
    {
        if ($type === 'status') {
            return match (strtolower($phase)) {
                'system' => 'cog-6-tooth',
                'agent' => 'cpu-chip',
                'tool' => 'wrench-screwdriver',
                'search' => 'magnifying-glass',
                'read' => 'document-text',
                default => 'information-circle'
            };
        }

        return match (strtolower($phase)) {
            'initializing' => 'play',
            'planning' => 'light-bulb',
            'searching' => 'magnifying-glass',
            'reading' => 'document-text',
            'processing' => 'cog-6-tooth',
            'synthesizing' => 'flask',
            'streaming' => 'wifi',
            'completed' => 'check-circle',
            default => 'clock'
        };
    }

    public function getStepColor($type)
    {
        return match ($type) {
            'phase' => 'blue',
            'status' => 'green',
            default => 'gray'
        };
    }

    public function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;

            return "{$minutes}m {$remainingSeconds}s";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return "{$hours}h {$minutes}m";
        }
    }

    public function exportTimeline()
    {
        $timelineData = [
            'execution_id' => $this->executionId,
            'stats' => $this->executionStats,
            'timeline' => $this->timeline,
        ];

        $this->dispatch('download-json', [
            'filename' => 'execution_timeline_'.$this->executionId.'_'.date('Y-m-d_H-i-s').'.json',
            'data' => $timelineData,
        ]);
    }

    public function render()
    {
        return view('livewire.components.tabs.steps-tab-content');
    }
}
