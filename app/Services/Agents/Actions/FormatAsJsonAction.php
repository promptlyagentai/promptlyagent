<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

/**
 * Format As JSON Action
 *
 * Converts research agent markdown output into structured JSON format
 * for easier processing in subsequent workflow stages.
 *
 * Extracts:
 * - Topics/key points (bullet lists)
 * - News summaries
 * - Relevance explanations
 * - Source URLs (markdown links)
 */
class FormatAsJsonAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        // SAFETY: Wrap in try-catch to ensure we return valid data on failure
        try {
            // Extract markdown links as sources
            $sources = [];
            if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $data, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $url = $match[2];
                    // Basic URL validation
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $sources[] = [
                            'title' => $match[1],
                            'url' => $url,
                        ];
                    }
                }
            }

            // Extract bullet points (topics/key points)
            $topics = [];
            if (preg_match_all('/^[\*\-]\s+(.+)$/m', $data, $matches)) {
                $topics = array_map('trim', $matches[1]);
            }

            // Extract numbered lists (news items or structured content)
            $news = [];
            if (preg_match_all('/^\d+\.\s+(.+)$/m', $data, $matches)) {
                $news = array_map('trim', $matches[1]);
            }

            // Extract sections that might contain relevance/reasoning
            // Look for common patterns like "Why relevant:", "Relevance:", etc.
            $relevance = [];
            if (preg_match_all('/(?:why|relevance|reason|important)[:\s]*(.+?)(?:\n\n|\n-|\n\d+\.|\z)/is', $data, $matches)) {
                $relevance = array_map('trim', $matches[1]);
            }

            // Build structured JSON
            $structured = [
                'topics' => $topics,
                'news' => $news,
                'relevance' => $relevance,
                'sources' => $sources,
                'source_count' => count($sources),
                'raw_content' => $data, // Preserve original for reference
            ];

            // Add metadata if available
            if (isset($context['agent'])) {
                $structured['metadata'] = [
                    'agent_name' => $context['agent']->name,
                    'agent_id' => $context['agent']->id,
                ];
            }

            $json = json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            Log::info('FormatAsJsonAction: Converted markdown to JSON', [
                'topics_count' => count($topics),
                'news_count' => count($news),
                'sources_count' => count($sources),
                'original_length' => strlen($data),
                'json_length' => strlen($json),
            ]);

            return $json;

        } catch (\Throwable $e) {
            Log::error('FormatAsJsonAction: Failed to format as JSON', [
                'error' => $e->getMessage(),
                'data_length' => strlen($data),
            ]);

            // Return original data on failure (safety contract)
            return $data;
        }
    }

    public function validate(array $params): bool
    {
        // This action doesn't require any parameters
        // It works with any input data
        return true;
    }

    public function getDescription(): string
    {
        return 'Convert research agent markdown output into structured JSON with topics, news, relevance, and sources';
    }

    public function getParameterSchema(): array
    {
        // No parameters required for this action
        return [];
    }

    public function shouldQueue(): bool
    {
        // JSON formatting is fast, no need to queue
        return false;
    }
}
