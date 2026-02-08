<?php

namespace App\Livewire\Components;

use App\Models\ChatInteraction;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class ChatResultTabs extends Component
{
    public $interactionId;

    public $activeTab = 'answer';

    public $tabs = ['answer', 'sources', 'steps', 'related', 'context'];

    public $interaction;

    public $execution;

    public $sources;

    public $relatedQuestions = [];

    public $sourcesCount = 0;

    public $stepsCount = 0;

    protected $listeners = ['tab-switched' => 'handleTabSwitch'];

    public function mount($interactionId)
    {
        $this->interactionId = $interactionId;
        $this->sources = collect(); // Initialize as empty collection
        $this->loadInteractionData();
    }

    public function switchTab($tab)
    {
        if (in_array($tab, $this->tabs)) {
            $this->activeTab = $tab;
            $this->dispatch('tab-switched', tab: $tab);
            $this->loadTabData($tab);

            Log::info('Chat result tab switched', [
                'interaction_id' => $this->interactionId,
                'tab' => $tab,
                'execution_id' => $this->execution?->id,
            ]);
        }
    }

    protected function loadInteractionData()
    {
        $this->interaction = ChatInteraction::with(['execution.sources'])->find($this->interactionId);
        $this->execution = $this->interaction?->execution;

        if ($this->execution) {
            $this->sources = $this->execution->sources()
                ->orderBy('link_usage_count', 'desc')
                ->orderBy('quality_score', 'desc')
                ->get();

            $this->sourcesCount = $this->sources->count();
            $this->stepsCount = count($this->execution->phase_timeline ?? []);
        }
    }

    protected function loadTabData($tab)
    {
        switch ($tab) {
            case 'related':
                $this->generateRelatedQuestions();
                break;
            case 'sources':
                // Sources are already loaded, but we might want to refresh them
                if ($this->execution) {
                    $this->sources = $this->execution->sources()
                        ->orderBy('link_usage_count', 'desc')
                        ->orderBy('quality_score', 'desc')
                        ->get();
                }
                break;
        }
    }

    protected function generateRelatedQuestions()
    {
        // Generate contextual follow-up questions based on the interaction
        $baseQuestions = [
            'Can you elaborate on this topic?',
            'What are the alternatives to this approach?',
            'How does this compare to other solutions?',
            'What are the potential risks or limitations?',
            'Can you provide more recent information on this?',
            'What are the practical applications of this?',
        ];

        $this->relatedQuestions = collect($baseQuestions)->take(4)->toArray();

        // If we have sources, we can create more specific questions
        if ($this->sources->isNotEmpty()) {
            $domains = $this->sources->pluck('domain')->unique()->take(2);
            foreach ($domains as $domain) {
                $this->relatedQuestions[] = "What does {$domain} say about this topic?";
            }
        }

        $this->relatedQuestions = array_slice($this->relatedQuestions, 0, 5);
    }

    public function askRelatedQuestion($question)
    {
        $this->dispatch('ask-question', question: $question);

        Log::info('Related question asked', [
            'interaction_id' => $this->interactionId,
            'question' => $question,
        ]);
    }

    public function getSourcesFilterCounts()
    {
        if ($this->sources->isEmpty()) {
            return [
                'all' => 0,
                'referenced' => 0,
                'unreferenced' => 0,
                'high_quality' => 0,
            ];
        }

        return [
            'all' => $this->sources->count(),
            'referenced' => $this->sources->where('link_usage_count', '>', 0)->count(),
            'unreferenced' => $this->sources->where('link_usage_count', 0)->count(),
            'high_quality' => $this->sources->where('quality_score', '>=', 7.0)->count(),
        ];
    }

    public function getExecutionStats()
    {
        if (! $this->execution) {
            return null;
        }

        $timeline = $this->execution->phase_timeline ?? [];
        $startTime = $timeline[0]['timestamp'] ?? null;
        $endTime = end($timeline)['timestamp'] ?? null;

        $duration = null;
        if ($startTime && $endTime) {
            $start = \Carbon\Carbon::parse($startTime);
            $end = \Carbon\Carbon::parse($endTime);
            $duration = $start->diffInSeconds($end);
        }

        return [
            'phase_count' => count($timeline),
            'duration_seconds' => $duration,
            'current_phase' => $this->execution->getCurrentPhaseDisplay(),
            'is_completed' => $this->execution->current_phase === \App\Enums\AgentPhase::COMPLETED,
            'progress_percentage' => $this->execution->getProgressPercentage(),
        ];
    }

    public function render()
    {
        return view('livewire.components.chat-result-tabs', [
            'sourcesFilterCounts' => $this->getSourcesFilterCounts(),
            'executionStats' => $this->getExecutionStats(),
        ]);
    }
}
