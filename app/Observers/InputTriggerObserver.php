<?php

namespace App\Observers;

use App\Models\InputTrigger;
use App\Services\InputTrigger\InputTriggerRegistry;
use Illuminate\Support\Facades\Log;
use PromptlyAgentAI\ScheduleIntegration\Services\ScheduleManager;

/**
 * InputTrigger Observer
 *
 * Handles cache invalidation for scheduled triggers to ensure
 * Laravel scheduler picks up changes immediately.
 *
 * Also handles provider lifecycle hooks (webhook registration, etc.)
 * when triggers are created, updated, or deleted.
 */
class InputTriggerObserver
{
    /**
     * Handle the InputTrigger "created" event.
     */
    public function created(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);

        // Activate trigger if status is active
        if ($inputTrigger->status === 'active') {
            $this->activateTrigger($inputTrigger);
        }
    }

    /**
     * Handle the InputTrigger "updated" event.
     */
    public function updated(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);

        // Check if status changed
        if ($inputTrigger->isDirty('status')) {
            $oldStatus = $inputTrigger->getOriginal('status');
            $newStatus = $inputTrigger->status;

            if ($oldStatus === 'active' && $newStatus !== 'active') {
                // Trigger was deactivated
                $this->deactivateTrigger($inputTrigger);
            } elseif ($oldStatus !== 'active' && $newStatus === 'active') {
                // Trigger was activated
                $this->activateTrigger($inputTrigger);
            }
        }
    }

    /**
     * Handle the InputTrigger "deleted" event.
     */
    public function deleted(InputTrigger $inputTrigger): void
    {
        $this->invalidateScheduleCacheIfNeeded($inputTrigger);

        // Deactivate trigger (unregister webhooks, etc.)
        if ($inputTrigger->status === 'active') {
            $this->deactivateTrigger($inputTrigger);
        }
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
                Log::warning('Failed to clear schedule cache in observer', [
                    'trigger_id' => $inputTrigger->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Activate trigger (register webhooks, etc.)
     */
    protected function activateTrigger(InputTrigger $inputTrigger): void
    {
        try {
            $registry = app(InputTriggerRegistry::class);
            $provider = $registry->getProvider($inputTrigger->provider_id);

            if ($provider && method_exists($provider, 'onTriggerActivated')) {
                $provider->onTriggerActivated($inputTrigger);

                Log::info('Input trigger activated via observer', [
                    'trigger_id' => $inputTrigger->id,
                    'provider_id' => $inputTrigger->provider_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to activate trigger in observer', [
                'trigger_id' => $inputTrigger->id,
                'provider_id' => $inputTrigger->provider_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Deactivate trigger (unregister webhooks, etc.)
     */
    protected function deactivateTrigger(InputTrigger $inputTrigger): void
    {
        try {
            $registry = app(InputTriggerRegistry::class);
            $provider = $registry->getProvider($inputTrigger->provider_id);

            if ($provider && method_exists($provider, 'onTriggerDeactivated')) {
                $provider->onTriggerDeactivated($inputTrigger);

                Log::info('Input trigger deactivated via observer', [
                    'trigger_id' => $inputTrigger->id,
                    'provider_id' => $inputTrigger->provider_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to deactivate trigger in observer', [
                'trigger_id' => $inputTrigger->id,
                'provider_id' => $inputTrigger->provider_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
