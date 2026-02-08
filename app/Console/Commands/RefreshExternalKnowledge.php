<?php

namespace App\Console\Commands;

use App\Services\Knowledge\ExternalKnowledgeManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshExternalKnowledge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'knowledge:refresh-external
                            {--force : Force refresh all refreshable sources regardless of schedule}
                            {--source-type= : Only refresh sources of specified type}
                            {--limit=50 : Maximum number of documents to refresh in one run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh knowledge sources (external URLs and text documents with URLs) that are due for update';

    /**
     * Execute the console command.
     */
    public function handle(ExternalKnowledgeManager $externalKnowledgeManager)
    {
        Log::info('RefreshExternalKnowledge: Command started', [
            'force' => $this->option('force'),
            'source_type' => $this->option('source-type'),
            'limit' => $this->option('limit'),
        ]);

        $this->info('Starting knowledge source refresh...');

        try {
            if ($this->option('force')) {
                $this->info('Force refresh mode enabled - checking all refreshable sources...');
                $this->forceRefreshAll($externalKnowledgeManager);
            } else {
                $this->info('Scheduled refresh mode - checking sources due for update...');
                $externalKnowledgeManager->scheduleRefreshes();
            }

            $this->info('Knowledge refresh completed successfully.');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to refresh external knowledge: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Force refresh all refreshable knowledge sources (external and text with URLs).
     */
    private function forceRefreshAll(ExternalKnowledgeManager $externalKnowledgeManager): void
    {
        $query = \App\Models\KnowledgeDocument::with('integration.integrationToken')
            ->whereIn('content_type', ['external', 'text'])
            ->whereNotNull('external_source_identifier');

        if ($sourceType = $this->option('source-type')) {
            $query->where('source_type', $sourceType);
            $this->info("Filtering by source type: {$sourceType}");
        }

        $limit = (int) $this->option('limit');
        $documents = $query->limit($limit)->get();

        $this->info("Found {$documents->count()} refreshable knowledge documents to refresh");

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        $refreshed = 0;
        $errors = 0;

        foreach ($documents as $document) {
            try {
                $wasUpdated = $externalKnowledgeManager->refreshDocument($document);
                if ($wasUpdated) {
                    $refreshed++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->warn("Failed to refresh document {$document->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Refresh summary:');
        $this->info("- Total documents processed: {$documents->count()}");
        $this->info("- Documents updated: {$refreshed}");
        $this->info("- Errors: {$errors}");

        Log::info('RefreshExternalKnowledge: Force refresh completed', [
            'total_processed' => $documents->count(),
            'updated' => $refreshed,
            'errors' => $errors,
            'source_type' => $this->option('source-type') ?? 'all',
        ]);
    }
}
