<?php

namespace App\Console\Commands\Knowledge;

use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;

/**
 * Completely rebuilds the knowledge documents search index with embeddings.
 *
 * WARNING: This is an expensive operation that:
 * - Flushes the entire Meilisearch index
 * - Reimports all completed documents
 * - Processes queued embedding jobs
 *
 * Use with caution in production environments. Consider scheduled off-peak execution.
 */
class ReindexKnowledge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'knowledge:reindex {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear and rebuild the entire knowledge documents search index with embeddings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Knowledge Documents Reindex');
        $this->newLine();

        // Get current statistics
        $totalDocs = KnowledgeDocument::where('processing_status', 'completed')->count();

        if ($totalDocs === 0) {
            $this->warn('No completed knowledge documents found to reindex.');

            return 0;
        }

        $this->line("📊 Found {$totalDocs} completed documents to reindex");
        $this->newLine();

        $this->warn('⚠️  This will:');
        $this->line('   • Clear the entire Meilisearch knowledge_documents index');
        $this->line('   • Reimport all completed documents with fresh embeddings');
        $this->line('   • This process may take several minutes for large collections');
        $this->newLine();

        if (! $this->option('force')) {
            if (! $this->confirm('Continue with complete reindex?')) {
                $this->info('Reindex cancelled.');

                return 0;
            }
        }

        $this->newLine();
        $this->info('🗑️  Step 1: Clearing existing index...');

        try {
            $this->call('scout:flush', ['model' => KnowledgeDocument::class]);
            $this->info('✅ Index cleared successfully');
        } catch (\Exception $e) {
            $this->error('❌ Failed to clear index: '.$e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->info('📥 Step 2: Reimporting all documents with embeddings...');

        try {
            $this->call('scout:import', ['model' => KnowledgeDocument::class]);
            $this->info('✅ Documents imported successfully');
        } catch (\Exception $e) {
            $this->error('❌ Failed to import documents: '.$e->getMessage());

            return 1;
        }

        $this->newLine();
        $this->info('⏳ Step 3: Processing any queued jobs...');

        try {
            $this->call('queue:work', ['--stop-when-empty' => true]);
            $this->info('✅ Queue processing completed');
        } catch (\Exception $e) {
            $this->warn('⚠️  Queue processing had issues: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('🧹 Step 4: Clearing caches...');
        $this->call('cache:clear');

        $this->newLine();
        $this->info('✅ Reindex completed successfully!');
        $this->line('📊 Run `php artisan knowledge:embedding-status` to verify results');

        return 0;
    }
}
