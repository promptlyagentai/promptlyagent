<?php

namespace App\Services\Knowledge\Processors;

use App\Services\Knowledge\Contracts\KnowledgeProcessorInterface;
use App\Services\Knowledge\DTOs\KnowledgeSource;
use App\Services\Knowledge\DTOs\ProcessedKnowledge;

/**
 * Text Content Knowledge Processor
 *
 * Processes plain text, markdown, HTML, and JSON content with automatic cleaning, normalization,
 * metadata extraction, and simple language detection.
 *
 * Architecture:
 * - Text Normalization: Line ending standardization, whitespace cleanup, control character removal
 * - Title Extraction: From metadata, markdown/HTML headings, or first line heuristics
 * - Summary Generation: Extractive summary using first 2-3 sentences (max 200 words)
 * - Keyword Extraction: Frequency-based with stop word filtering (top 10 keywords)
 * - Language Detection: Simple heuristic based on common words (en, de, fr, es)
 *
 * Supported Content Types:
 * - text/plain: Plain text documents
 * - text/markdown: Markdown formatted text
 * - text/html: HTML documents
 * - application/json: JSON data
 *
 * Text Cleaning Process:
 * 1. Normalize line endings: \r\n and \r → \n
 * 2. Remove control characters (except newlines and tabs)
 * 3. Collapse excessive newlines (3+ → 2)
 * 4. Normalize spaces and tabs to single spaces
 * 5. Trim whitespace from line beginnings/ends
 *
 * Title Extraction Priority:
 * 1. Metadata['title'] if provided
 * 2. Markdown heading (# Title)
 * 3. HTML heading (<h1>-<h6>)
 * 4. First line if short (<100 chars) and title-like (no trailing punctuation except ?)
 * 5. First 8 words (fallback)
 *
 * Language Detection (simple heuristic):
 * - English (en): 'the', 'and', 'is', 'in', 'to', etc.
 * - German (de): 'der', 'die', 'und', 'in', 'den', etc.
 * - French (fr): 'le', 'de', 'et', 'à', 'un', etc.
 * - Spanish (es): 'el', 'la', 'de', 'que', 'y', etc.
 * - Default: English
 *
 * Validation:
 * - Minimum length: 10 characters (trimmed)
 * - Maximum length: 1MB (configurable via config/knowledge.max_text_length)
 * - Must contain actual text content
 *
 * Priority: 100 (highest among processors)
 *
 * @see \App\Services\Knowledge\Contracts\KnowledgeProcessorInterface
 */
class TextProcessor implements KnowledgeProcessorInterface
{
    public function process(KnowledgeSource $source): ProcessedKnowledge
    {
        if (! $this->supports($source->contentType)) {
            throw new \InvalidArgumentException("Unsupported content type: {$source->contentType}");
        }

        $text = $source->getText();

        if (empty($text)) {
            throw new \InvalidArgumentException('Text content cannot be empty');
        }

        // Clean and normalize the text
        $cleanedText = $this->cleanText($text);

        // Extract title from first line or metadata
        $title = $this->extractTitle($cleanedText, $source->metadata);

        // Generate summary if text is long enough
        $summary = $this->generateSummary($cleanedText);

        // Extract keywords
        $keywords = $this->extractKeywords($cleanedText);

        // Detect language
        $language = $this->detectLanguage($cleanedText);

        return ProcessedKnowledge::create(
            content: $cleanedText,
            title: $title,
            options: [
                'summary' => $summary,
                'keywords' => $keywords,
                'language' => $language,
                'wordCount' => str_word_count(strip_tags($cleanedText)),
                'confidence' => 1.0, // High confidence for direct text input
                'processorName' => $this->getName(),
                'metadata' => array_merge($source->metadata, [
                    'originalLength' => mb_strlen($text),
                    'cleanedLength' => mb_strlen($cleanedText),
                    'processingDate' => now()->toISOString(),
                ]),
            ]
        );
    }

    public function supports(string $contentType): bool
    {
        return in_array($contentType, [
            'text/plain',
            'text/markdown',
            'text/html',
            'application/json',
        ]);
    }

    public function getPriority(): int
    {
        return 100; // High priority for direct text processing
    }

    public function getSupportedTypes(): array
    {
        return [
            'text/plain',
            'text/markdown',
            'text/html',
            'application/json',
        ];
    }

    public function validate(KnowledgeSource $source): bool
    {
        if (! $source->isText()) {
            return false;
        }

        $text = $source->getText();

        // Check if text is not empty and not too short
        if (empty($text) || mb_strlen(trim($text)) < 10) {
            return false;
        }

        // Check if text is not too long (configurable limit)
        $maxLength = config('knowledge.max_text_length', 1000000); // 1MB default
        if (mb_strlen($text) > $maxLength) {
            return false;
        }

        return true;
    }

    public function getName(): string
    {
        return 'text_processor';
    }

    protected function cleanText(string $text): string
    {
        // Normalize line endings FIRST
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove control characters except newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Remove excessive newlines (more than 2 consecutive)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remove excessive spaces and tabs (but preserve single spaces and newlines)
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Clean up spaces at the beginning and end of lines
        $text = preg_replace('/[ \t]+$/m', '', $text);
        $text = preg_replace('/^[ \t]+/m', '', $text);

        return trim($text);
    }

    protected function extractTitle(string $text, array $metadata): string
    {
        // Check if title is provided in metadata
        if (! empty($metadata['title'])) {
            return $metadata['title'];
        }

        // Extract first line as title if it looks like a heading
        $lines = explode("\n", $text);
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
        $words = explode(' ', $text);
        $titleWords = array_slice($words, 0, 8);
        $generatedTitle = implode(' ', $titleWords);

        if (mb_strlen($generatedTitle) > 50) {
            $generatedTitle = mb_substr($generatedTitle, 0, 47).'...';
        }

        return $generatedTitle ?: 'Untitled Document';
    }

    protected function generateSummary(string $text): ?string
    {
        $wordCount = str_word_count($text);

        // Only generate summary for longer texts
        if ($wordCount < 50) {
            return null;
        }

        // Simple extractive summary - take first paragraph or first few sentences
        $sentences = preg_split('/[.!?]+/', $text);
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

    protected function extractKeywords(string $text): array
    {
        // Simple keyword extraction
        $text = strtolower($text);

        // Remove common stop words
        $stopWords = [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he',
            'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the', 'to', 'was', 'will', 'with',
            'the', 'this', 'but', 'they', 'have', 'had', 'what', 'said', 'each', 'which',
            'their', 'time', 'will', 'about', 'if', 'up', 'out', 'many', 'then', 'them',
            'these', 'so', 'some', 'her', 'would', 'make', 'like', 'into', 'him', 'could',
            'two', 'more', 'very', 'after', 'words', 'long', 'than', 'first', 'been', 'call',
            'who', 'oil', 'now', 'find', 'down', 'day', 'did', 'get', 'come', 'made', 'may',
            'part',
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

    protected function detectLanguage(string $text): ?string
    {
        // Simple language detection based on common words
        $text = strtolower($text);

        $languages = [
            'en' => ['the', 'and', 'is', 'in', 'to', 'of', 'a', 'that', 'it', 'with'],
            'de' => ['der', 'die', 'und', 'in', 'den', 'von', 'zu', 'das', 'mit', 'sich'],
            'fr' => ['le', 'de', 'et', 'à', 'un', 'il', 'être', 'et', 'en', 'avoir'],
            'es' => ['el', 'la', 'de', 'que', 'y', 'a', 'en', 'un', 'ser', 'se'],
        ];

        $scores = [];

        foreach ($languages as $lang => $commonWords) {
            $score = 0;
            foreach ($commonWords as $word) {
                $score += substr_count($text, ' '.$word.' ');
            }
            $scores[$lang] = $score;
        }

        arsort($scores);
        $detectedLang = array_key_first($scores);

        // Only return if we have reasonable confidence
        return $scores[$detectedLang] > 0 ? $detectedLang : 'en'; // Default to English
    }
}
