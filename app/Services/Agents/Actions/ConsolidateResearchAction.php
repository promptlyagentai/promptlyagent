<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

/**
 * Consolidate Research Action
 *
 * Deduplicates and merges JSON outputs from multiple research agents.
 * Used as an input action before synthesis to provide clean, consolidated data.
 *
 * Features:
 * - Deduplicates news items by title similarity and URL matching
 * - Merges overlapping topics
 * - Collects unique sources
 * - Generates consolidated markdown for synthesizer
 */
class ConsolidateResearchAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        // SAFETY: Wrap in try-catch to ensure we return valid data on failure
        try {
            $similarityThreshold = $params['similarity_threshold'] ?? 80;

            // Extract JSON blocks from the enhanced input
            // The input contains all previous agent outputs
            $jsonBlocks = $this->extractJsonBlocks($data);

            if (empty($jsonBlocks)) {
                Log::warning('ConsolidateResearchAction: No JSON blocks found in input', [
                    'data_length' => strlen($data),
                ]);

                // Return original data if no JSON found
                return $data;
            }

            // Parse and collect all data
            $allTopics = [];
            $allNews = [];
            $allSources = [];
            $allRelevance = [];

            foreach ($jsonBlocks as $json) {
                $parsed = json_decode($json, true);

                if ($parsed) {
                    $allTopics = array_merge($allTopics, $parsed['topics'] ?? []);
                    $allNews = array_merge($allNews, $parsed['news'] ?? []);
                    $allSources = array_merge($allSources, $parsed['sources'] ?? []);
                    $allRelevance = array_merge($allRelevance, $parsed['relevance'] ?? []);
                }
            }

            // Deduplicate and consolidate
            $uniqueTopics = $this->deduplicateItems($allTopics, $similarityThreshold);
            $uniqueNews = $this->deduplicateItems($allNews, $similarityThreshold);
            $uniqueSources = $this->deduplicateSources($allSources);
            $uniqueRelevance = array_unique($allRelevance);

            // Generate consolidated markdown for synthesizer
            $consolidated = $this->generateConsolidatedMarkdown(
                $uniqueTopics,
                $uniqueNews,
                $uniqueRelevance,
                $uniqueSources
            );

            Log::info('ConsolidateResearchAction: Consolidated research findings', [
                'json_blocks_found' => count($jsonBlocks),
                'topics_before' => count($allTopics),
                'topics_after' => count($uniqueTopics),
                'news_before' => count($allNews),
                'news_after' => count($uniqueNews),
                'sources_before' => count($allSources),
                'sources_after' => count($uniqueSources),
            ]);

            return $consolidated;

        } catch (\Throwable $e) {
            Log::error('ConsolidateResearchAction: Failed to consolidate research', [
                'error' => $e->getMessage(),
                'data_length' => strlen($data),
            ]);

            // Return original data on failure (safety contract)
            return $data;
        }
    }

    /**
     * Extract JSON blocks from enhanced input text
     */
    protected function extractJsonBlocks(string $text): array
    {
        $jsonBlocks = [];

        // Try to find complete JSON objects using brace matching
        $openBraces = 0;
        $currentJson = '';
        $inJson = false;

        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];

            if ($char === '{') {
                if ($openBraces === 0) {
                    $inJson = true;
                    $currentJson = '';
                }
                $openBraces++;
            }

            if ($inJson) {
                $currentJson .= $char;
            }

            if ($char === '}') {
                $openBraces--;
                if ($openBraces === 0 && $inJson) {
                    // Try to parse as JSON
                    if (json_decode($currentJson) !== null) {
                        $jsonBlocks[] = $currentJson;
                    }
                    $inJson = false;
                    $currentJson = '';
                }
            }
        }

        return $jsonBlocks;
    }

    /**
     * Deduplicate items by similarity
     */
    protected function deduplicateItems(array $items, int $threshold): array
    {
        $unique = [];

        foreach ($items as $item) {
            $isDuplicate = false;

            foreach ($unique as $existingItem) {
                // Calculate similarity percentage
                similar_text(strtolower($item), strtolower($existingItem), $percent);

                if ($percent >= $threshold) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (! $isDuplicate) {
                $unique[] = $item;
            }
        }

        return $unique;
    }

    /**
     * Deduplicate sources by URL
     */
    protected function deduplicateSources(array $sources): array
    {
        $seen = [];
        $unique = [];

        foreach ($sources as $source) {
            $url = $source['url'] ?? null;

            if ($url && ! in_array($url, $seen)) {
                $seen[] = $url;
                $unique[] = $source;
            }
        }

        return $unique;
    }

    /**
     * Generate consolidated markdown for synthesizer
     */
    protected function generateConsolidatedMarkdown(
        array $topics,
        array $news,
        array $relevance,
        array $sources
    ): string {
        $markdown = "# Consolidated Research Findings\n\n";
        $markdown .= '*Aggregated from '.count($sources)." unique sources*\n\n";

        // Topics section
        if (! empty($topics)) {
            $markdown .= "## Key Topics & Developments\n\n";
            foreach ($topics as $topic) {
                $markdown .= "- {$topic}\n";
            }
            $markdown .= "\n";
        }

        // News section
        if (! empty($news)) {
            $markdown .= '## News Items ('.count($news)." unique)\n\n";
            foreach ($news as $index => $newsItem) {
                $markdown .= ($index + 1).". {$newsItem}\n\n";
            }
        }

        // Relevance section
        if (! empty($relevance)) {
            $markdown .= "## Why These Topics Matter\n\n";
            foreach ($relevance as $reason) {
                $markdown .= "- {$reason}\n";
            }
            $markdown .= "\n";
        }

        // Sources section
        if (! empty($sources)) {
            $markdown .= "## Sources\n\n";
            foreach ($sources as $source) {
                $title = $source['title'] ?? 'Source';
                $url = $source['url'] ?? '#';
                $markdown .= "- [{$title}]({$url})\n";
            }
            $markdown .= "\n";
        }

        $markdown .= "---\n\n";
        $markdown .= '*Please synthesize the above findings into a comprehensive daily news digest, ';
        $markdown .= "ensuring all claims are properly sourced and the content is well-organized by topic.*\n";

        return $markdown;
    }

    public function validate(array $params): bool
    {
        // Validate similarity_threshold if provided
        if (isset($params['similarity_threshold'])) {
            if (! is_int($params['similarity_threshold']) ||
                $params['similarity_threshold'] < 0 ||
                $params['similarity_threshold'] > 100) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Deduplicate and consolidate research findings from multiple agents into a unified markdown summary';
    }

    public function getParameterSchema(): array
    {
        return [
            'operation' => [
                'type' => 'string',
                'required' => false,
                'default' => 'deduplicate_and_merge',
                'description' => 'Consolidation operation type',
            ],
            'similarity_threshold' => [
                'type' => 'int',
                'required' => false,
                'default' => 80,
                'min' => 0,
                'max' => 100,
                'description' => 'Similarity threshold for deduplication (0-100)',
            ],
        ];
    }

    public function shouldQueue(): bool
    {
        // Consolidation is CPU-intensive but fast enough to run synchronously
        return false;
    }
}
