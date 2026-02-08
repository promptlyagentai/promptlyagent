<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ResearchTopicService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateResearchTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum execution time (2 minutes).
     */
    public int $timeout = 120;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * Backoff delay between retries (seconds).
     */
    public array $backoff = [30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user) {}

    /**
     * Execute the job.
     */
    public function handle(ResearchTopicService $topicService): void
    {
        // Check if feature is enabled for this user
        $preferences = $this->user->preferences ?? [];
        $enabled = $preferences['research_suggestions']['enabled'] ?? false;

        if (! $enabled) {
            Log::debug('Research topics generation skipped - feature disabled', [
                'user_id' => $this->user->id,
            ]);

            return;
        }

        try {
            // Generate and cache topics (service handles caching internally)
            $topics = $topicService->getTopicsForUser($this->user);

            Log::info('Research topics generated successfully', [
                'user_id' => $this->user->id,
                'topic_count' => count($topics),
                'topics' => collect($topics)->pluck('title')->toArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate research topics in job', [
                'user_id' => $this->user->id,
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
        Log::error('Research topics job failed permanently', [
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
