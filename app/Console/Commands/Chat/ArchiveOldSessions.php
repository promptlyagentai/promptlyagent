<?php

namespace App\Console\Commands\Chat;

use App\Services\Chat\SessionArchiveService;
use Illuminate\Console\Command;

class ArchiveOldSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:archive-old-sessions
                            {--force : Skip confirmation prompt}
                            {--dry-run : Show what would be archived without actually archiving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive old chat sessions that exceed the configured age threshold';

    /**
     * Execute the console command.
     */
    public function handle(SessionArchiveService $archiveService)
    {
        if (! config('chat.auto_archive_enabled', true)) {
            $this->warn('Session auto-archiving is disabled in configuration.');

            return Command::FAILURE;
        }

        // Get stats before archiving
        $stats = $archiveService->getArchiveStats();

        $this->info('Session Archive Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Sessions', $stats['total_sessions']],
                ['Already Archived', $stats['archived_sessions']],
                ['Kept Sessions', $stats['kept_sessions']],
                ['Eligible for Archiving', $stats['eligible_for_archiving']],
                ['Threshold (days)', $stats['threshold_days']],
                ['Cutoff Date', $stats['cutoff_date']],
            ]
        );

        if ($stats['eligible_for_archiving'] === 0) {
            $this->info('No sessions eligible for archiving.');

            return Command::SUCCESS;
        }

        // Dry run mode
        if ($this->option('dry-run')) {
            $this->warn("DRY RUN MODE: Would archive {$stats['eligible_for_archiving']} sessions.");

            return Command::SUCCESS;
        }

        // Confirm before archiving (unless --force flag)
        if (! $this->option('force')) {
            if (! $this->confirm("Archive {$stats['eligible_for_archiving']} sessions?")) {
                $this->info('Archive cancelled.');

                return Command::SUCCESS;
            }
        }

        // Perform archiving
        $this->info('Archiving old sessions...');

        $archivedCount = $archiveService->archiveEligibleSessions();

        $this->info("✓ Successfully archived {$archivedCount} sessions.");

        return Command::SUCCESS;
    }
}
