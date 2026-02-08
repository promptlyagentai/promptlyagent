<?php

namespace Database\Seeders;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Models\User;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DemoKnowledgeDocumentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding demo knowledge documents...');

        // Use first admin user for demo documents
        $user = User::where('is_admin', true)->first();

        if (! $user) {
            $this->command->warn('⚠️  No admin user found. Skipping demo knowledge documents.');
            $this->command->info('Create an admin user first: php artisan make:admin');

            return;
        }

        $this->command->info("✅ Assigning knowledge documents to: {$user->email}");

        // Path to the knowledge documents
        $docsPath = database_path('seeders/data/knowledge');

        if (! File::exists($docsPath)) {
            $this->command->error("Directory not found: {$docsPath}");

            return;
        }

        // Get all markdown files
        $files = File::glob($docsPath.'/*.md');

        if (empty($files)) {
            $this->command->warn("No markdown files found in {$docsPath}");

            return;
        }

        $this->command->info('Found '.count($files).' markdown files to process');

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($files as $filePath) {
            try {
                $filename = basename($filePath);

                // Read the file content
                $content = File::get($filePath);

                // Parse the document
                $parsed = $this->parseMarkdownDocument($content, $filename);

                if (! $parsed) {
                    $this->command->warn("Failed to parse: {$filename}");
                    $skipped++;

                    continue;
                }

                // Check if document already exists with this title
                $existing = KnowledgeDocument::where('title', $parsed['title'])
                    ->where('created_by', $user->id)
                    ->first();

                if ($existing) {
                    $this->command->info("Skipping (already exists): {$parsed['title']}");
                    $skipped++;

                    continue;
                }

                // Create the knowledge document
                $document = KnowledgeDocument::create([
                    'created_by' => $user->id,
                    'title' => $parsed['title'],
                    'description' => $parsed['description'],
                    'content' => $parsed['content'],
                    'content_type' => 'text',
                    'privacy_level' => 'public',
                    'processing_status' => 'pending',
                    'metadata' => [
                        'source' => 'demo_seeder',
                        'original_filename' => $filename,
                        'seeded_at' => now()->toISOString(),
                    ],
                ]);

                // Attach tags
                if (! empty($parsed['tags'])) {
                    $tagIds = $this->getOrCreateTags($parsed['tags']);
                    $document->tags()->sync($tagIds);
                }

                // Queue document for processing (embedding generation and indexing)
                $knowledgeManager = app(KnowledgeManager::class);
                $knowledgeManager->queueProcessing($document);

                $this->command->info("✓ Created: {$parsed['title']} ({$parsed['tag_count']} tags)");
                $created++;

            } catch (\Exception $e) {
                $this->command->error("Failed to process {$filename}: {$e->getMessage()}");
                Log::error('DemoKnowledgeDocumentsSeeder: Failed to process file', [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->command->newLine();
        $this->command->info('Summary:');
        $this->command->info("  Created: {$created}");
        $this->command->info("  Skipped: {$skipped}");
        $this->command->info("  Errors:  {$errors}");
    }

    /**
     * Parse a markdown document to extract title, tags, and content
     */
    protected function parseMarkdownDocument(string $content, string $filename): ?array
    {
        $lines = explode("\n", $content);

        $title = null;
        $tags = [];
        $description = null;

        // Extract title (first # heading)
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '# ')) {
                $title = trim(substr($trimmed, 2));
                break;
            }
        }

        // Extract tags (Tags: line)
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, 'Tags:')) {
                $tagsString = trim(substr($trimmed, 5));
                $tags = array_filter(array_map('trim', explode(' ', $tagsString)));
                break;
            }
        }

        // Generate description from first paragraph after metadata
        $description = $this->extractDescription($lines);

        // Fallback to filename if no title found
        if (! $title) {
            $title = pathinfo($filename, PATHINFO_FILENAME);
            $title = str_replace(['-', '_'], ' ', $title);
        }

        return [
            'title' => $title,
            'description' => $description,
            'content' => $content,
            'tags' => $tags,
            'tag_count' => count($tags),
        ];
    }

    /**
     * Extract a description from the document content
     */
    protected function extractDescription(array $lines): ?string
    {
        $inMetadata = true;
        $description = [];
        $foundContent = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip title and metadata lines
            if (str_starts_with($trimmed, '#') ||
                str_starts_with($trimmed, 'Tags:') ||
                str_starts_with($trimmed, 'Report Status:') ||
                str_starts_with($trimmed, 'Next Opportunity') ||
                str_starts_with($trimmed, 'Knowledge Assessment:') ||
                str_starts_with($trimmed, 'Company') ||
                str_starts_with($trimmed, 'Last edited')) {
                $inMetadata = true;

                continue;
            }

            // Empty line ends metadata section
            if ($inMetadata && empty($trimmed)) {
                continue;
            }

            if ($inMetadata && ! empty($trimmed)) {
                $inMetadata = false;
            }

            // Once past metadata, collect non-empty, non-heading lines
            if (! $inMetadata && ! empty($trimmed) && ! str_starts_with($trimmed, '#')) {
                // Skip bullet points and special formatting
                if (! str_starts_with($trimmed, '-') &&
                    ! str_starts_with($trimmed, '*') &&
                    ! str_starts_with($trimmed, '1.') &&
                    strlen($trimmed) > 50) {
                    $description[] = $trimmed;
                    $foundContent = true;
                }

                // Stop after collecting a good paragraph
                if ($foundContent && count($description) >= 2) {
                    break;
                }
            }
        }

        $result = implode(' ', $description);

        return ! empty($result) ? substr($result, 0, 500) : null;
    }

    /**
     * Get or create tags and return their IDs
     */
    protected function getOrCreateTags(array $tagNames): array
    {
        $tagIds = [];

        foreach ($tagNames as $tagName) {
            // Skip empty tags
            if (empty($tagName)) {
                continue;
            }

            // Find or create the tag
            $tag = KnowledgeTag::firstOrCreate(
                ['name' => $tagName],
                [
                    'color' => $this->getTagColor($tagName),
                    'is_system' => false,
                    'description' => null,
                ]
            );

            $tagIds[] = $tag->id;
        }

        return $tagIds;
    }

    /**
     * Get appropriate color for a tag based on its prefix
     */
    protected function getTagColor(string $tagName): string
    {
        // Match colors from config/knowledge_tags.php
        $colorMap = [
            'type:' => '#3b82f6',      // blue
            'client:' => '#10b981',    // green
            'project:' => '#6366f1',   // indigo
            'service:' => '#8b5cf6',   // purple
            'industry:' => '#06b6d4',  // cyan
            'meeting-date:' => '#f59e0b', // amber
            'discipline:' => '#ec4899', // pink
        ];

        foreach ($colorMap as $prefix => $color) {
            if (str_starts_with($tagName, $prefix)) {
                return $color;
            }
        }

        // Default color
        return '#6b7280'; // gray
    }
}
