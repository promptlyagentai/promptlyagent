<?php

namespace App\Observers;

use App\Models\InputTrigger;
use PromptlyAgentAI\ScheduleIntegration\Services\ScheduleManager;

/**
 * InputTrigger Observer
 *
 * Handles cache invalidation for scheduled triggers to ensure
 * Laravel scheduler picks up changes immediately.
 */
class InputTriggerObserver
{
    /**
     * Handle the InputTrigger "created" event.
     */
    public function created(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);
    }

    /**
     * Handle the InputTrigger "updated" event.
     */
    public function updated(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);
    }

    /**
     * Handle the InputTrigger "deleted" event.
     */
    public function deleted(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);
    }

    /**
     * Handle the InputTrigger "restored" event.
     */
    public function restored(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);
    }

    /**
     * Handle the InputTrigger "force deleted" event.
     */
    public function forceDeleted(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);
    }

    /**
     * Invalidate schedule cache if this is a scheduled trigger
     */
    protected function invalidateScheduleCacheIfNeeded(InputTrigger $inputTrigger): void
    {
        if ($inputTrigger->provider_id === 'schedule') {
            try {
                app(ScheduleManager::class)->clearCache();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to clear schedule cache in observer', [
                    'trigger_id' => $inputTrigger->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
