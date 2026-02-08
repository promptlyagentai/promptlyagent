<?php

namespace App\Observers;

use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Agent Observer - Cache Invalidation for System Agents.
 *
 * Invalidates cached agent lookups when agents are modified or deleted.
 * Currently monitors Research Synthesizer agent for workflow synthesis caching.
 *
 * @see \App\Jobs\SynthesizeWorkflowJob::getSynthesizerAgentId()
 */
class AgentObserver
{
    /**
     * Handle the Agent "created" event.
     */
    public function created(Agent $agent): void
    {
        // Clear cache if Research Synthesizer is created
        $this->clearSynthesizerCacheIfNeeded($agent, 'created');
    }

    /**
     * Handle the Agent "updated" event.
     */
    public function updated(Agent $agent): void
    {
        // Clear cache if Research Synthesizer is updated (especially name changes)
        $this->clearSynthesizerCacheIfNeeded($agent, 'updated');
    }

    /**
     * Handle the Agent "deleted" event.
     */
    public function deleted(Agent $agent): void
    {
        // Clear cache if Research Synthesizer is deleted
        $this->clearSynthesizerCacheIfNeeded($agent, 'deleted');
    }

    /**
     * Handle the Agent "restored" event.
     */
    public function restored(Agent $agent): void
    {
        // Clear cache if Research Synthesizer is restored
        $this->clearSynthesizerCacheIfNeeded($agent, 'restored');
    }

    /**
     * Handle the Agent "force deleted" event.
     */
    public function forceDeleted(Agent $agent): void
    {
        // Clear cache if Research Synthesizer is force deleted
        $this->clearSynthesizerCacheIfNeeded($agent, 'force_deleted');
    }

    /**
     * Clear Research Synthesizer agent cache if this agent is the synthesizer
     *
     * @param  Agent  $agent  The agent being modified
     * @param  string  $event  The event type for logging
     */
    protected function clearSynthesizerCacheIfNeeded(Agent $agent, string $event): void
    {
        if ($agent->name === 'Research Synthesizer') {
            Cache::forget('agent:synthesizer:id');

            Log::info('AgentObserver: Cleared synthesizer agent cache', [
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'event' => $event,
            ]);
        }
    }
}
