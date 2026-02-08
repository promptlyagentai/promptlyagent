<?php

namespace App\Livewire;

use Livewire\Component;

class WorkflowPlanDisplay extends Component
{
    public array $workflowPlan;

    public bool $expanded = false;

    public function mount(array $workflowPlan)
    {
        $this->workflowPlan = $workflowPlan;
    }

    public function toggleExpanded()
    {
        $this->expanded = ! $this->expanded;
    }

    public function getPlanTypeProperty(): string
    {
        return $this->workflowPlan['type'] ?? 'unknown';
    }

    public function getStrategyTypeProperty(): string
    {
        if ($this->planType === 'parallel_research') {
            return $this->workflowPlan['execution_strategy'] ?? 'standard';
        }

        return $this->workflowPlan['strategy_type'] ?? 'unknown';
    }

    public function getEstimatedDurationProperty(): int
    {
        return $this->workflowPlan['estimated_duration_seconds'] ?? 0;
    }

    public function getTotalStagesProperty(): int
    {
        if ($this->planType === 'parallel_research') {
            return count($this->workflowPlan['sub_queries'] ?? []);
        }

        return count($this->workflowPlan['stages'] ?? []);
    }

    public function render()
    {
        return view('livewire.workflow-plan-display');
    }
}
