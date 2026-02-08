<?php

namespace App\Livewire\Components;

use App\Enums\AgentPhase;
use App\Models\AgentExecution;
use Livewire\Attributes\On;
use Livewire\Component;

class AgentProgressBar extends Component
{
    public $executionId;

    public $currentPhase = 'initializing';

    public $progressPercentage = 0;

    public $statusMessage = 'Initializing...';

    public $showTimeline = false;

    public $phaseTimeline = [];

    public $isCompleted = false;

    public $sessionId;

    public function mount($executionId, $sessionId = null)
    {
        $this->executionId = $executionId;
        $this->sessionId = $sessionId;
        $this->loadExecutionState();
    }

    #[On('echo-private:chat-session.{sessionId},AgentPhaseChanged')]
    public function handlePhaseChanged($event)
    {
        if ($event['execution_id'] === $this->executionId) {
            $this->currentPhase = $event['phase'];
            $this->statusMessage = $event['message'];
            $this->loadExecutionState();
        }
    }

    #[On('echo-private:chat-session.{sessionId},AgentProgressUpdated')]
    public function handleProgressUpdated($event)
    {
        if ($event['execution_id'] === $this->executionId) {
            $this->progressPercentage = $event['progress_percentage'] ?? 0;
            $this->statusMessage = $event['message'];
        }
    }

    #[On('echo-private:chat-session.{sessionId},ToolStatusUpdated')]
    public function handleToolStatusUpdated($event)
    {
        // Handle legacy tool status updates if they include execution context
        if (isset($event['data']['execution_id']) && $event['data']['execution_id'] === $this->executionId) {
            $this->statusMessage = $event['data']['step_description'] ?? $this->statusMessage;
        }
    }

    public function toggleTimeline()
    {
        $this->showTimeline = ! $this->showTimeline;

        // Load fresh timeline data when opening
        if ($this->showTimeline) {
            $this->loadExecutionState();
        }
    }

    protected function loadExecutionState()
    {
        $execution = AgentExecution::find($this->executionId);
        if ($execution) {
            $this->currentPhase = $execution->current_phase?->value ?? 'initializing';
            $this->statusMessage = $execution->status_message ?? 'Processing...';
            $this->phaseTimeline = $execution->phase_timeline ?? [];
            $this->isCompleted = $execution->current_phase === AgentPhase::COMPLETED;
            $this->progressPercentage = $execution->getProgressPercentage();
        }
    }

    public function getCurrentPhaseDisplay()
    {
        $execution = AgentExecution::find($this->executionId);

        return $execution?->getCurrentPhaseDisplay() ?? 'Processing';
    }

    public function getCurrentPhaseDescription()
    {
        $execution = AgentExecution::find($this->executionId);

        return $execution?->getCurrentPhaseDescription() ?? 'Agent is working on your request';
    }

    public function render()
    {
        return view('livewire.components.agent-progress-bar');
    }
}
