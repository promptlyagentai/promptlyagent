<?php

namespace App\Services\Knowledge\Processors;

use App\Services\Knowledge\Contracts\KnowledgeProcessorInterface;
use App\Services\Knowledge\DTOs\KnowledgeSource;
use App\Services\Knowledge\DTOs\ProcessedKnowledge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * External Knowledge Source Processor
 *
 * Processes external URLs and HTML/text content from external sources using MarkItDown
 * for content conversion and extraction.
 *
 * Architecture:
 * - URL Processing: Fetches web content via MarkItDown service (markdown conversion)
 * - Text Processing: Direct processing of external HTML/text/markdown content
 * - Metadata Extraction: Automatic title, summary, and keyword generation
 * - Image Discovery: Extracts and converts image URLs to absolute paths
 *
 * Supported Content Types:
 * - url: Web URLs for fetching and conversion
 * - external/html: External HTML content
 * - external/text: External plain text
 * - external/markdown: External markdown content
 *
 * MarkItDown Integration:
 * - URL: config('services.markitdown.url') default: http://markitdown:8000
 * - Endpoint: POST /convert with {url, format: 'markdown'}
 * - Timeout: 60 seconds
 * - Error Handling: Graceful fallback with user-friendly error messages
 *
 * Processing Pipeline:
 * 1. URL validation or content extraction
 * 2. MarkItDown conversion (for URLs)
 * 3. Title extraction (from metadata, headings, or first line)
 * 4. Summary generation (first 2-3 sentences, max 200 words)
 * 5. Keyword extraction (top 10 words by frequency, stop words filtered)
 * 6. Image URL extraction and conversion to absolute URLs
 *
 * Error Classification:
 * - Connection Errors: Service unavailable, timeout, connection refused
 * - Content Errors: Empty response, malformed HTML
 * - General Errors: Other processing failures
 *
 * @see \App\Services\Knowledge\Contracts\KnowledgeProcessorInterface
 * @see config/services.php (markitdown configuration)
 */
class ExternalProcessor implements KnowledgeProcessorInterface
{
    public function process(KnowledgeSource $source): ProcessedKnowledge
    {
        if (! $this->supports($source->contentType)) {
            throw new \InvalidArgumentException("Unsupported content type: {$source->contentType}");
        }

        // For URL sources, we need to use MarkItDown to fetch and convert content
        if ($source->contentType === 'url') {
            return $this->processUrl($source);
        }

        // For other external sources with text content, process as text
        return $this->processTextContent($source);
    }

    public function supports(string $contentType): bool
    {
        return in_array($contentType, [
            'url',
            'external/html',
            'external/text',
            'external/markdown',
        ]);
    }

    public function getPriority(): int
    {
        return 90; // High priority for external content processing
    }

    public function getSupportedTypes(): array
    {
        return [
            'url',
            'external/html',
            'external/text',
            'external/markdown',
        ];
    }

    public function validate(KnowledgeSource $source): bool
    {
        if ($source->contentType === 'url') {
            $url = $source->content;

            return ! empty($url) && filter_var($url, FILTER_VALIDATE_URL);
        }

        // For other external content, ensure we have actual content
        $content = $source->getText();

        return ! empty($content) && mb_strlen(trim($content)) >= 10;
    }

    public function getName(): string
    {
        return 'external_processor';
    }

    protected function processUrl(KnowledgeSource $source): ProcessedKnowledge
    {
        $url = $source->content;

        Log::info('ExternalProcessor: Processing URL with MarkItDown', ['url' => $url]);

        try {
            // Use MarkItDown to fetch and convert the URL to markdown
            $markitdownUrl = config('services.markitdown.url', 'http://markitdown:8000');

            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($markitdownUrl.'/convert', [
                    'url' => $url,
                    'format' => 'markdown',
                ]);

            if (! $response->successful()) {
                throw new \Exception('MarkItDown conversion failed with status: '.$response->status());
            }

            $data = $response->json();
            $markdownContent = $data['markdown'] ?? $data['content'] ?? '';

            if (empty($markdownContent)) {
                throw new \Exception('No markdown content returned from MarkItDown service');
            }

            // Extract title from metadata or generate from content
            $title = $this->extractTitle($markdownContent, $source->metadata);

            // Generate summary if content is long enough
            $summary = $this->generateSummary($markdownContent);

            // Extract keywords
            $keywords = $this->extractKeywords($markdownContent);

            // Get content statistics
            $wordCount = str_word_count(strip_tags($markdownContent));
            $charCount = strlen($markdownContent);

            Log::info('ExternalProcessor: MarkItDown conversion completed', [
                'url' => $url,
                'word_count' => $wordCount,
                'char_count' => $charCount,
            ]);

            return ProcessedKnowledge::create(
                content: $markdownContent,
                title: $title,
                options: [
                    'summary' => $summary,
                    'keywords' => $keywords,
                    'language' => 'en', // Default to English for web content
                    'wordCount' => $wordCount,
                    'confidence' => 0.9, // High confidence for MarkItDown conversion
                    'processorName' => $this->getName(),
                    'metadata' => array_merge($source->metadata, [
                        'originalUrl' => $url,
                        'markdownLength' => $charCount,
                        'processingDate' => now()->toISOString(),
                        'processingMethod' => 'markitdown',
                        'images' => $this->extractImages($markdownContent, $url),
                    ]),
                ]
            );

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $errorClass = get_class($e);

            // Classify error type for better error handling
            $isConnectionError = $e instanceof \Illuminate\Http\Client\ConnectionException
                || str_contains($errorMessage, 'cURL error 28')
                || str_contains($errorMessage, 'Timeout was reached')
                || str_contains($errorMessage, 'Connection timed out')
                || str_contains($errorMessage, 'Connection refused')
                || str_contains($errorMessage, 'Failed to connect');

            $isServiceUnavailable = $isConnectionError
                || str_contains($errorMessage, 'Could not resolve host')
                || str_contains($errorMessage, 'cURL error 6')
                || str_contains($errorMessage, 'cURL error 7');

            $isContentError = str_contains($errorMessage, 'No markdown content')
                || str_contains($errorMessage, 'DOMEntityReference')
                || str_contains($errorMessage, 'HTML parsing error');

            // Determine user-friendly error message
            if ($isServiceUnavailable) {
                $friendlyError = 'MarkItDown service is currently unavailable. The URL cannot be processed at this time.';
            } elseif ($isContentError) {
                $friendlyError = 'The URL content could not be parsed or is malformed.';
            } else {
                $friendlyError = 'Failed to process external URL.';
            }

            Log::error('ExternalProcessor: Failed to process URL with MarkItDown', [
                'url' => $url,
                'error' => $errorMessage,
                'error_class' => $errorClass,
                'error_code' => $e->getCode(),
                'is_connection_error' => $isConnectionError,
                'is_service_unavailable' => $isServiceUnavailable,
                'is_content_error' => $isContentError,
            ]);

            throw new \Exception("{$friendlyError} ({$errorMessage})");
        }
    }

    protected function processTextContent(KnowledgeSource $source): ProcessedKnowledge
    {
        $content = $source->getText();

        // Extract title from metadata or content
        $title = $this->extractTitle($content, $source->metadata);

        // Generate summary
        $summary = $this->generateSummary($content);

        // Extract keywords
        $keywords = $this->extractKeywords($content);

        return ProcessedKnowledge::create(
            content: $content,
            title: $title,
            options: [
                'summary' => $summary,
                'keywords' => $keywords,
                'language' => 'en',
                'wordCount' => str_word_count(strip_tags($content)),
                'confidence' => 0.8,
                'processorName' => $this->getName(),
                'metadata' => array_merge($source->metadata, [
                    'originalLength' => mb_strlen($content),
                    'processingDate' => now()->toISOString(),
                    'processingMethod' => 'text',
                ]),
            ]
        );
    }

    protected function extractTitle(string $content, array $metadata): string
    {
        // Check if title is provided in metadata
        if (! empty($metadata['title'])) {
            return $metadata['title'];
        }

        // Extract first line as title if it looks like a heading
        $lines = explode("\n", $content);
        $firstLine = trim($lines[0]);

        // Check for markdown heading
        if (preg_match('/^#+\s*(.+)$/', $firstLine, $matches)) {
            return trim($matches[1]);
        }

        // Check for HTML heading
        if (preg_match('/<h[1-6][^>]*>(.+?)<\/h[1-6]>/i', $firstLine, $matches)) {
            return strip_tags($matches[1]);
        }

        // Use first line if it's short and looks like a title
        if (mb_strlen($firstLine) < 100 && mb_strlen($firstLine) > 5) {
            // Check if it doesn't end with punctuation (except question marks)
            if (! preg_match('/[.!;,]$/', $firstLine) || preg_match('/\?$/', $firstLine)) {
                return $firstLine;
            }
        }

        // Generate title from first few words
        $words = explode(' ', strip_tags($content));
        $titleWords = array_slice($words, 0, 8);
        $generatedTitle = implode(' ', $titleWords);

        if (mb_strlen($generatedTitle) > 50) {
            $generatedTitle = mb_substr($generatedTitle, 0, 47).'...';
        }

        return $generatedTitle ?: 'External Document';
    }

    protected function generateSummary(string $content): ?string
    {
        $wordCount = str_word_count($content);

        // Only generate summary for longer texts
        if ($wordCount < 50) {
            return null;
        }

        // Simple extractive summary - take first paragraph or first few sentences
        $sentences = preg_split('/[.!?]+/', $content);
        $sentences = array_filter(array_map('trim', $sentences));

        if (empty($sentences)) {
            return null;
        }

        // Take first 2-3 sentences for summary, up to 200 words
        $summary = '';
        $summaryWordCount = 0;
        $maxWords = 200;
        $maxSentences = 3;
        $sentenceCount = 0;

        foreach ($sentences as $sentence) {
            $sentenceWords = str_word_count($sentence);

            if ($summaryWordCount + $sentenceWords > $maxWords || $sentenceCount >= $maxSentences) {
                break;
            }

            $summary .= $sentence.'. ';
            $summaryWordCount += $sentenceWords;
            $sentenceCount++;
        }

        return trim($summary) ?: null;
    }

    protected function extractKeywords(string $content): array
    {
        // Simple keyword extraction
        $text = strtolower(strip_tags($content));

        // Remove common stop words
        $stopWords = [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he',
            'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the', 'to', 'was', 'will', 'with',
            'the', 'this', 'but', 'they', 'have', 'had', 'what', 'said', 'each', 'which',
            'their', 'time', 'will', 'about', 'if', 'up', 'out', 'many', 'then', 'them',
            'these', 'so', 'some', 'her', 'would', 'make', 'like', 'into', 'him', 'could',
            'two', 'more', 'very', 'after', 'words', 'long', 'than', 'first', 'been', 'call',
            'who', 'now', 'find', 'down', 'day', 'did', 'get', 'come', 'made', 'may', 'part',
        ];

        // Extract words (only alphabetic, 3+ characters)
        preg_match_all('/\b[a-z]{3,}\b/', $text, $matches);
        $words = $matches[0];

        // Remove stop words
        $words = array_diff($words, $stopWords);

        // Count word frequency
        $wordCounts = array_count_values($words);

        // Sort by frequency and take top keywords
        arsort($wordCounts);
        $keywords = array_keys(array_slice($wordCounts, 0, 10));

        return $keywords;
    }

    protected function extractImages(string $markdown, string $sourceUrl): array
    {
        $images = [];

        // Extract markdown image syntax ![alt](url)
        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $markdown, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $altText = trim($match[1]);
            $imageUrl = trim($match[2]);

            // Convert relative URLs to absolute
            $absoluteUrl = $this->makeAbsoluteUrl($imageUrl, $sourceUrl);

            $images[] = [
                'url' => $absoluteUrl,
                'alt' => $altText ?: 'Image',
                'type' => 'markdown',
                'source_url' => $sourceUrl,
            ];
        }

        return $images;
    }

    protected function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        // Already absolute URL
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        // Protocol-relative URL
        if (str_starts_with($url, '//')) {
            $parsedBase = parse_url($baseUrl);

            return ($parsedBase['scheme'] ?? 'https').':'.$url;
        }

        $parsedBase = parse_url($baseUrl);
        if (! $parsedBase) {
            return $url;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':'.$parsedBase['port'] : '';
        $basePath = isset($parsedBase['path']) ? dirname($parsedBase['path']) : '';

        // Root-relative URL
        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$port.$url;
        }

        // Relative URL
        if ($basePath === '.') {
            $basePath = '';
        }

        return $scheme.'://'.$host.$port.$basePath.'/'.$url;
    }
}
