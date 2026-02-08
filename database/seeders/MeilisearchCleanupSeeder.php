<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class MeilisearchCleanupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder cleans up stale entries in the Meilisearch index
     * to prevent foreign key constraint violations in knowledge source tracking.
     */
    public function run(): void
    {
        $this->command->info('🔧 Running Meilisearch index cleanup...');

        try {
            // Run the cleanup command with force flag to skip confirmation
            $exitCode = Artisan::call('knowledge:cleanup-index', ['--force' => true], $this->command->getOutput());

            if ($exitCode === 0) {
                $this->command->info('✅ Meilisearch index cleanup completed successfully');
            } else {
                $this->command->warn('⚠️  Meilisearch index cleanup completed with warnings');
            }

            // Show the command output
            $output = Artisan::output();
            if (! empty(trim($output))) {
                $this->command->line($output);
            }

        } catch (\Exception $e) {
            $this->command->error('❌ Meilisearch index cleanup failed: '.$e->getMessage());
            Log::error('Meilisearch cleanup seeder failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't fail the entire seeding process - just log the error
            $this->command->warn('⚠️  Continuing with seeding despite cleanup failure...');
        }
    }
}
