<?php

namespace App\Observers;

use App\Jobs\SyncArtifactToIntegration;
use App\Models\Artifact;
use Illuminate\Support\Facades\Log;

/**
 * Observer for Artifact lifecycle events
 *
 * Manages automatic synchronization of artifacts to external integrations:
 * - Detects content changes and triggers auto-sync jobs
 * - Dispatches async jobs to integration providers (GitHub, GitLab, etc.)
 * - Handles deletion cleanup and logging
 *
 * Note: External integration deletions must be performed manually via UI.
 * Cascade deletion only affects local artifact_integration pivot records.
 */
class ArtifactObserver
{
    /**
     * Handle the Artifact "updated" event
     *
     * Automatically syncs content changes to external integrations with auto-sync enabled.
     * Only triggers when 'content' field changes (metadata updates are ignored).
     * Dispatches async SyncArtifactToIntegration jobs for each auto-sync integration.
     *
     * @param  Artifact  $artifact  The updated artifact
     */
    public function updated(Artifact $artifact): void
    {
        // Only sync if content actually changed (not just metadata)
        if (! $artifact->wasChanged('content')) {
            return;
        }

        // Dispatch entire sync check to background (after response) to prevent blocking UI
        dispatch(function () use ($artifact) {
            // Get all integrations with auto-sync enabled
            $autoSyncIntegrations = $artifact->integrations()
                ->where('auto_sync_enabled', true)
                ->with('integration.integrationToken')  // Eager load to prevent N+1
                ->get();

            if ($autoSyncIntegrations->isEmpty()) {
                return;
            }

            Log::info('Artifact content updated, dispatching auto-sync jobs', [
                'artifact_id' => $artifact->id,
                'integration_count' => $autoSyncIntegrations->count(),
            ]);

            // Dispatch async jobs for each auto-sync integration
            foreach ($autoSyncIntegrations as $integration) {
                try {
                    SyncArtifactToIntegration::dispatch($artifact, $integration);

                    Log::debug('Auto-sync job dispatched', [
                        'artifact_id' => $artifact->id,
                        'integration_id' => $integration->id,
                        'provider_id' => $integration->integrationToken->provider_id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to dispatch auto-sync job', [
                        'artifact_id' => $artifact->id,
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        })->afterResponse();
    }

    /**
     * Handle the Artifact "deleted" event
     *
     * Logs deletion for artifacts with integrations but does NOT automatically
     * delete from external providers (GitHub/GitLab/etc.). User must manually
     * delete external content via integration UI.
     *
     * Database cleanup: ArtifactIntegration pivot records are cascade deleted
     * via foreign key constraints.
     *
     * @param  Artifact  $artifact  The deleted artifact (soft-delete data still accessible)
     */
    public function deleted(Artifact $artifact): void
    {
        $integrationCount = $artifact->integrations()->count();

        if ($integrationCount > 0) {
            Log::info('Artifact with integrations deleted', [
                'artifact_id' => $artifact->id,
                'integration_count' => $integrationCount,
                'note' => 'Integration records will be cascade deleted. External content remains.',
            ]);
        }
    }
}
