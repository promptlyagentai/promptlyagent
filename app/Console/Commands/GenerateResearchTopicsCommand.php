<?php

namespace App\Console\Commands;

use App\Jobs\GenerateResearchTopicsJob;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateResearchTopicsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'research:generate-topics
                            {--user-id= : Generate topics for a specific user ID}
                            {--global : Generate global trending topics only}';

    /**
     * The console command description.
     */
    protected $description = 'Generate personalized research topics for users and global trending topics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Generate global trending topics only
        if ($this->option('global')) {
            return $this->generateGlobalTrendingTopics();
        }

        // Generate for specific user if provided
        if ($userId = $this->option('user-id')) {
            return $this->generateForUser($userId);
        }

        // Generate for all active users + global trending
        return $this->generateForActiveUsers();
    }

    /**
     * Generate topics for a specific user.
     */
    protected function generateForUser(int $userId): int
    {
        $user = User::find($userId);

        if (! $user) {
            $this->error("User with ID {$userId} not found");

            return self::FAILURE;
        }

        GenerateResearchTopicsJob::dispatch($user);

        $this->info("Research topic generation job dispatched for user {$userId}");
        $this->comment('Check Horizon dashboard to monitor job progress');

        return self::SUCCESS;
    }

    /**
     * Generate topics for all active users with feature enabled.
     */
    protected function generateForActiveUsers(): int
    {
        // First, generate global trending topics
        $this->info('Generating global trending topics...');
        $this->generateGlobalTrendingTopics();
        $this->newLine();

        // Get active users (updated in last 30 days)
        $activeUsers = User::where('updated_at', '>=', now()->subDays(30))->get();

        // Filter to users with feature enabled
        $enabledUsers = $activeUsers->filter(function ($user) {
            $preferences = $user->preferences ?? [];

            return $preferences['research_suggestions']['enabled'] ?? false;
        });

        if ($enabledUsers->isEmpty()) {
            $this->warn('No users have personalized research suggestions enabled');

            return self::SUCCESS;
        }

        $this->info("Found {$enabledUsers->count()} active users with personalization enabled");

        $progressBar = $this->output->createProgressBar($enabledUsers->count());
        $progressBar->start();

        // Dispatch jobs for each user
        foreach ($enabledUsers as $user) {
            GenerateResearchTopicsJob::dispatch($user);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Successfully dispatched {$enabledUsers->count()} personalized topic generation jobs");
        $this->comment('Jobs are running in the background. Check Horizon dashboard for progress.');

        return self::SUCCESS;
    }

    /**
     * Generate global trending topics (shared across all non-personalized users).
     */
    protected function generateGlobalTrendingTopics(): int
    {
        $poolSize = config('research_topics.generation.pool_size', 12);
        $displayCount = config('research_topics.generation.display_count', 4);

        $this->info('Generating global trending topics pool (shared across all non-personalized users)...');
        $this->comment("Pool size: {$poolSize} topics | Display: Random {$displayCount} per page load");

        try {
            $service = app(\App\Services\ResearchTopicService::class);

            // Clear existing cache
            $cacheKey = config('research_topics.cache.key_prefix').':global_trending';
            \Illuminate\Support\Facades\Cache::forget($cacheKey);

            // Generate fresh topic pool
            $topics = $service->getGlobalTrendingTopics();

            $this->newLine();
            $this->info('✓ Generated '.count($topics).' global trending topics:');

            foreach ($topics as $index => $topic) {
                $this->line('  '.($index + 1).'. '.$topic['title'].' ['.$topic['color_theme'].']');
            }

            $this->newLine();
            $this->info('✓ Topic pool cached for 48 hours');
            $this->comment("✓ Users will see {$displayCount} random topics from this pool on each visit");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Failed to generate global trending topics: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
