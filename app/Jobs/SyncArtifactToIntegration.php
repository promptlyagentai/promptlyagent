<?php

namespace App\Jobs;

use App\Models\Artifact;
use App\Models\ArtifactIntegration;
use App\Services\Artifacts\ArtifactIntegrationManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncArtifactToIntegration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [30, 60, 120];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Artifact $artifact,
        public ArtifactIntegration $integration
    ) {
        // Use dedicated queue for integration operations
        $this->onQueue('integrations');
    }

    /**
     * Execute the job.
     */
    public function handle(ArtifactIntegrationManager $manager): void
    {
        try {
            // Update the artifact in the integration
            $manager->updateInIntegration($this->artifact, $this->integration);

            Log::info('Artifact auto-sync job completed successfully', [
                'artifact_id' => $this->artifact->id,
                'integration_id' => $this->integration->id,
                'attempt' => $this->attempts(),
            ]);

        } catch (\Exception $e) {
            // Mark as failed in the integration record
            $this->integration->markFailed($e->getMessage());

            Log::error('Artifact auto-sync job failed', [
                'artifact_id' => $this->artifact->id,
                'integration_id' => $this->integration->id,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry logic
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Final failure after all retries
        $this->integration->markFailed($exception->getMessage());

        Log::error('Artifact auto-sync job permanently failed', [
            'artifact_id' => $this->artifact->id,
            'integration_id' => $this->integration->id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }
}
