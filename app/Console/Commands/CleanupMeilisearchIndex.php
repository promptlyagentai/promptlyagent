<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client as MeilisearchClient;

class CleanupMeilisearchIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'knowledge:cleanup-index {--dry-run : Show what would be removed without actually removing it} {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove stale documents from Meilisearch index that no longer exist in database';

    /**
     * Execute the console command.
     *
     * Remove stale documents from Meilisearch index that no longer exist in database.
     * Supports dry-run mode and force flag to skip confirmation prompts.
     * Uses pagination to handle large indexes efficiently.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle()
    {
        $this->info('🔍 Checking for stale documents in Meilisearch index...');

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        try {
            // Use Meilisearch client directly
            $client = new MeilisearchClient(
                config('scout.meilisearch.host'),
                config('scout.meilisearch.key')
            );
            $index = $client->index('knowledge_documents');

            // Get all documents from Meilisearch (paginated approach for large indexes)
            $allDocuments = [];
            $offset = 0;
            $limit = 1000;

            do {
                $searchResult = $index->search('', [
                    'limit' => $limit,
                    'offset' => $offset,
                    'attributesToRetrieve' => ['id', 'document_id', 'title'],
                ]);

                $hits = $searchResult->getHits();
                $allDocuments = array_merge($allDocuments, $hits);
                $offset += $limit;

            } while (count($hits) === $limit);

            if (empty($allDocuments)) {
                $this->info('✅ No documents found in Meilisearch index');

                return Command::SUCCESS;
            }

            $this->info('📊 Found '.count($allDocuments).' documents in Meilisearch index');

            // Get all valid document IDs from database
            $validDocumentIds = KnowledgeDocument::pluck('id')->toArray();
            $this->info('📊 Found '.count($validDocumentIds).' documents in database');

            $staleDocuments = [];
            $validDocuments = 0;

            foreach ($allDocuments as $doc) {
                $documentId = $this->extractDocumentId($doc);

                if (! in_array($documentId, $validDocumentIds)) {
                    $staleDocuments[] = [
                        'document_id' => $documentId,
                        'meilisearch_id' => $doc['id'],
                        'title' => $doc['title'] ?? 'Untitled',
                    ];
                } else {
                    $validDocuments++;
                }
            }

            if (empty($staleDocuments)) {
                $this->info('✅ No stale documents found - index is clean');

                return Command::SUCCESS;
            }

            $this->warn('🗑️  Found '.count($staleDocuments).' stale documents to remove:');

            foreach ($staleDocuments as $doc) {
                $this->line("   - Document ID {$doc['document_id']} (Meilisearch ID: {$doc['meilisearch_id']}): {$doc['title']}");
            }

            if ($dryRun) {
                $this->info("\n🔍 Dry run mode - no changes made");
                $this->info('Run without --dry-run to actually remove these documents');

                return Command::SUCCESS;
            }

            // Confirm removal (skip if force flag is used)
            if (! $force && ! $this->confirm('Are you sure you want to remove these '.count($staleDocuments).' stale documents?')) {
                $this->info('❌ Operation cancelled');

                return Command::SUCCESS;
            }

            // Remove stale documents
            $removedCount = 0;
            foreach ($staleDocuments as $doc) {
                try {
                    $index->deleteDocument($doc['meilisearch_id']);
                    $removedCount++;
                    $this->info("✅ Removed: {$doc['title']} (Document ID: {$doc['document_id']})");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to remove Document ID {$doc['document_id']}: {$e->getMessage()}");
                    Log::error('Failed to remove stale Meilisearch document', [
                        'document_id' => $doc['document_id'],
                        'meilisearch_id' => $doc['meilisearch_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->info("\n🎉 Cleanup completed!");
            $this->info("   - Valid documents: {$validDocuments}");
            $this->info("   - Removed stale documents: {$removedCount}");
            $this->info('   - Failed removals: '.(count($staleDocuments) - $removedCount));

        } catch (\Exception $e) {
            $this->error("❌ Error during cleanup: {$e->getMessage()}");
            Log::error('Meilisearch cleanup failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Extract document ID from Meilisearch document record.
     *
     * Handles both direct document_id field and Scout's doc_123 format.
     *
     * @param  array{id: string, document_id?: int, title?: string}  $doc  Meilisearch document
     * @return int|string|null The extracted document ID
     */
    protected function extractDocumentId(array $doc): int|string|null
    {
        $documentId = $doc['document_id'] ?? null;
        if (! $documentId && isset($doc['id'])) {
            // Handle Scout's default ID format like "doc_123"
            if (preg_match('/^doc_(\d+)$/', $doc['id'], $matches)) {
                $documentId = (int) $matches[1];
            } else {
                $documentId = $doc['id'];
            }
        }

        return $documentId;
    }
}
