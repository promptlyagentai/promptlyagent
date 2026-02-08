<?php

namespace App\Services\Knowledge\Processors;

use App\Services\Knowledge\Contracts\KnowledgeProcessorInterface;
use App\Services\Knowledge\DTOs\KnowledgeSource;
use App\Services\Knowledge\DTOs\ProcessedKnowledge;
use App\Services\Knowledge\FileAnalyzer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File Upload Knowledge Processor
 *
 * Processes uploaded files through AI analysis and content extraction. Supports text files,
 * documents (PDF, Office), images, code files, and archives with intelligent fallback strategies.
 *
 * Architecture:
 * - AI Analysis: FileAnalyzer for metadata generation (title, description, tags)
 * - Content Extraction: MarkItDown for binary files, direct reading for text, PDF parsers for fallback
 * - Multi-strategy: Tries multiple extraction methods with graceful degradation
 * - Size Optimization: Special handling for large PDFs (>50MB) to avoid timeouts
 * - Encoding Safety: UTF-8 sanitization for database storage compatibility
 *
 * Supported File Types (100+ formats):
 * - Documents: PDF, DOCX, DOC, PPTX, PPT, ODT, RTF
 * - Spreadsheets: XLSX, XLS, CSV, ODS
 * - Images: PNG, JPG, GIF, WebP, BMP, TIFF, SVG
 * - Text: TXT, MD, HTML, XML, JSON, YAML
 * - Code: JS, TS, PHP, PY, Java, C, C++, SQL, CSS, SCSS
 * - Config: INI, CONF, ENV, LOG, Dockerfile
 * - Archives: ZIP, TAR, GZ
 *
 * Processing Strategy:
 * 1. Large PDFs (>50MB): Direct PDF extraction (skip FileAnalyzer to avoid timeout)
 * 2. Standard files + FileAnalyzer enabled: AI analysis + content extraction
 * 3. FileAnalyzer disabled: MarkItDown extraction only
 * 4. Fallback: Text files read directly, PDFs use pdftotext or Smalot parser
 *
 * PDF Extraction Methods (priority order):
 * 1. pdftotext CLI (most reliable if available)
 * 2. Smalot\PdfParser (PHP-based fallback)
 * 3. Minimal metadata (if all methods fail)
 *
 * Static Methods (for Asset processing without UploadedFile):
 * - extractContentFromBytes(): Process raw file bytes
 * - extractPdfContentFromBytes(): PDF-specific extraction
 *
 * Encoding Sanitization:
 * - Detects: UTF-8, ISO-8859-1, Windows-1252
 * - Converts to UTF-8 for database compatibility
 * - Removes control characters and problematic bytes
 * - Handles special characters (ä, ö, ü, ß, etc.)
 *
 * Configuration:
 * - maxFileSizeMB: 50 (configurable)
 * - markitdownUrl: config('services.markitdown.url')
 * - FileAnalyzer integration: config('knowledge.file_analysis.enabled')
 *
 * @see \App\Services\Knowledge\FileAnalyzer
 * @see \App\Services\Knowledge\Contracts\KnowledgeProcessorInterface
 * @see config/services.php (markitdown)
 */
class FileProcessor implements KnowledgeProcessorInterface
{
    /**
     * Supported file types and their MIME types
     */
    protected array $supportedTypes = [
        // Documents
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
        ],
        'doc' => ['application/msword'],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-powerpoint',
        ],
        'ppt' => ['application/vnd.ms-powerpoint'],

        // Spreadsheets
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
        ],
        'xls' => ['application/vnd.ms-excel'],
        'csv' => ['text/csv', 'application/csv'],

        // Images (OCR)
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp'],

        // Text formats
        'txt' => ['text/plain'],
        'rtf' => ['application/rtf', 'text/rtf'],
        'html' => ['text/html'],
        'htm' => ['text/html'],
        'xml' => ['application/xml', 'text/xml'],
        'json' => ['application/json'],
        'yaml' => ['application/x-yaml', 'text/yaml', 'text/x-yaml'],
        'yml' => ['application/x-yaml', 'text/yaml', 'text/x-yaml'],

        // Markdown and documentation
        'md' => ['text/markdown', 'text/x-markdown', 'text/plain', 'application/javascript', 'application/octet-stream'],
        'markdown' => ['text/markdown', 'text/x-markdown', 'text/plain', 'application/javascript', 'application/octet-stream'],
        'rst' => ['text/x-rst', 'text/plain'],
        'asciidoc' => ['text/plain'],
        'adoc' => ['text/plain'],

        // Code and configuration files
        'js' => ['application/javascript', 'text/javascript', 'text/plain'],
        'ts' => ['application/typescript', 'text/plain'],
        'py' => ['text/x-python', 'text/plain'],
        'php' => ['application/x-php', 'text/x-php', 'text/plain'],
        'java' => ['text/x-java-source', 'text/plain'],
        'c' => ['text/x-c', 'text/plain'],
        'cpp' => ['text/x-c++', 'text/plain'],
        'h' => ['text/x-c', 'text/plain'],
        'css' => ['text/css'],
        'scss' => ['text/x-scss', 'text/plain'],
        'sass' => ['text/x-sass', 'text/plain'],
        'less' => ['text/x-less', 'text/plain'],
        'sql' => ['application/sql', 'text/plain'],
        'sh' => ['application/x-sh', 'text/plain'],
        'bash' => ['application/x-sh', 'text/plain'],
        'dockerfile' => ['text/plain'],
        'env' => ['text/plain'],
        'ini' => ['text/plain'],
        'conf' => ['text/plain'],
        'config' => ['text/plain'],
        'log' => ['text/plain'],

        // Additional image formats
        'tiff' => ['image/tiff'],
        'tif' => ['image/tiff'],
        'svg' => ['image/svg+xml'],

        // Archives (will extract and process contents)
        'zip' => ['application/zip'],
        'tar' => ['application/x-tar'],
        'gz' => ['application/gzip'],
    ];

    protected int $maxFileSizeMB = 50;

    protected string $markitdownUrl;

    protected FileAnalyzer $fileAnalyzer;

    public function __construct()
    {
        $this->markitdownUrl = config('services.markitdown.url', 'http://markitdown:8000');
        $this->fileAnalyzer = new FileAnalyzer;
    }

    /**
     * Process uploaded file and extract text content with cached analysis
     */
    public function processWithCache(KnowledgeSource $source, array $cachedAnalysis = []): ProcessedKnowledge
    {
        $file = $source->getFile();

        if (! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException('FileProcessor requires an UploadedFile in the source');
        }

        // Validate file
        $this->validateFile($file);

        try {
            $markdownContent = '';
            $analysisResults = [];
            $metadata = $this->extractFileMetadata($file);

            if (! empty($cachedAnalysis)) {
                // Use cached analysis to avoid duplicate processing
                $analysisResults = $cachedAnalysis;

                // Extract content only (skip analysis since we have cached results)
                if ($this->fileAnalyzer->isEnabled()) {
                    $markdownContent = $this->fileAnalyzer->extractContent($file);
                } else {
                    $markdownContent = $this->extractContentWithMarkItDown($file);
                }

                Log::info('FileProcessor: Using cached analysis for file processing', [
                    'filename' => $file->getClientOriginalName(),
                    'has_cached_title' => ! empty($cachedAnalysis['suggested_title']),
                    'has_cached_description' => ! empty($cachedAnalysis['suggested_description']),
                    'has_cached_tags' => ! empty($cachedAnalysis['suggested_tags']),
                ]);
            } else {
                // Proceed with normal analysis
                if ($this->fileAnalyzer->isEnabled()) {
                    $analysisResults = $this->fileAnalyzer->analyzeFile($file);
                    $markdownContent = $this->fileAnalyzer->extractContent($file);
                } else {
                    $markdownContent = $this->extractContentWithMarkItDown($file);
                }
            }

            // Combine file metadata with analysis results
            $metadata = array_merge($metadata, $analysisResults);

            // Use AI-suggested title if available, otherwise generate from content
            $title = ! empty($analysisResults['suggested_title']) ?
                $analysisResults['suggested_title'] :
                $this->generateTitle($file, $markdownContent);

            // Use AI-suggested summary/description if available, otherwise generate from content
            $summary = ! empty($analysisResults['suggested_description']) ?
                $analysisResults['suggested_description'] :
                $this->generateSummary($markdownContent);

            return new ProcessedKnowledge(
                content: $markdownContent,
                title: $title,
                summary: $summary,
                metadata: $metadata
            );

        } catch (\Exception $e) {
            Log::error('FileProcessor: Failed to process file with cache', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'has_cache' => ! empty($cachedAnalysis),
            ]);
            throw new \Exception("Failed to process file: {$e->getMessage()}");
        }
    }

    /**
     * Process uploaded file and extract text content
     */
    public function process(KnowledgeSource $source): ProcessedKnowledge
    {
        $file = $source->getFile();

        if (! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException('FileProcessor requires an UploadedFile in the source');
        }

        // Validate file
        $this->validateFile($file);

        try {
            // For very large PDFs (over 50MB), use direct extraction
            $isPdf = $file->getMimeType() == 'application/pdf' || strtolower($file->getClientOriginalExtension()) == 'pdf';
            $isVeryLargeFile = $file->getSize() > 50 * 1024 * 1024; // 50MB

            if ($isPdf && $isVeryLargeFile) {
                // Very large PDF - use direct extraction, skip FileAnalyzer to avoid timeouts
                Log::info('FileProcessor: Large PDF detected, using direct extraction', [
                    'filename' => $file->getClientOriginalName(),
                    'filesize' => $this->formatFileSize($file->getSize()),
                ]);

                $markdownContent = $this->extractPdfContent($file);
                $metadata = $this->extractFileMetadata($file);
                $title = $this->generateTitle($file, $markdownContent);
                $summary = $this->generateSummary($markdownContent);
                $keywords = $this->extractKeywords($markdownContent);

            }
            // Standard file processing path
            elseif ($this->fileAnalyzer->isEnabled()) {
                $analysisResults = $this->fileAnalyzer->analyzeFile($file);

                // Extract content using the enhanced analyzer
                $markdownContent = $this->fileAnalyzer->extractContent($file);

                // If content is empty, try direct conversion
                if (empty(trim($markdownContent)) && $isPdf) {
                    Log::info('FileProcessor: FileAnalyzer returned empty content for PDF, trying direct extraction', [
                        'filename' => $file->getClientOriginalName(),
                    ]);
                    $markdownContent = $this->extractPdfContent($file);
                }

                // Combine file metadata with AI analysis results
                $metadata = array_merge(
                    $this->extractFileMetadata($file),
                    $analysisResults
                );

                // Use AI-suggested title if available, otherwise generate from content
                $title = ! empty($analysisResults['suggested_title']) ?
                    $analysisResults['suggested_title'] :
                    $this->generateTitle($file, $markdownContent);

                // Use AI-suggested description as summary if available
                $summary = ! empty($analysisResults['suggested_description']) ?
                    $analysisResults['suggested_description'] :
                    $this->generateSummary($markdownContent);

                // Use AI-suggested tags as keywords if available
                $keywords = ! empty($analysisResults['suggested_tags']) ?
                    $analysisResults['suggested_tags'] :
                    $this->extractKeywords($markdownContent);

                Log::info('FileProcessor: Using enhanced FileAnalyzer processing', [
                    'filename' => $file->getClientOriginalName(),
                    'has_ai_title' => ! empty($analysisResults['suggested_title']),
                    'has_ai_description' => ! empty($analysisResults['suggested_description']),
                    'has_ai_tags' => ! empty($analysisResults['suggested_tags']),
                    'content_length' => strlen($markdownContent),
                ]);

            } else {
                // Fallback to old MarkItDown-only processing
                $markdownContent = $this->convertFileToMarkdown($file);

                // If content is empty, try direct PDF extraction for PDFs
                if (empty(trim($markdownContent)) && $isPdf) {
                    Log::info('FileProcessor: MarkItDown returned empty content for PDF, trying direct extraction', [
                        'filename' => $file->getClientOriginalName(),
                    ]);
                    $markdownContent = $this->extractPdfContent($file);
                }

                $metadata = $this->extractFileMetadata($file);
                $title = $this->generateTitle($file, $markdownContent);
                $summary = $this->generateSummary($markdownContent);
                $keywords = $this->extractKeywords($markdownContent);

                Log::info('FileProcessor: Using fallback MarkItDown processing', [
                    'filename' => $file->getClientOriginalName(),
                    'content_length' => strlen($markdownContent),
                ]);
            }

            return ProcessedKnowledge::create(
                content: $markdownContent,
                title: $title,
                options: [
                    'summary' => $summary,
                    'keywords' => $keywords,
                    'metadata' => $metadata,
                    'processingType' => 'file',
                    'sourceFile' => [
                        'originalName' => $file->getClientOriginalName(),
                        'mimeType' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'extension' => $file->getClientOriginalExtension(),
                    ],
                    'contentStats' => [
                        'originalLength' => strlen($markdownContent),
                        'wordCount' => str_word_count($markdownContent),
                        'lineCount' => substr_count($markdownContent, "\n") + 1,
                    ],
                ]
            );

        } catch (\Exception $e) {
            throw new \Exception("Failed to process file '{$file->getClientOriginalName()}': ".$e->getMessage());
        }
    }

    /**
     * Check if this processor supports the given content type
     */
    public function supports(string $contentType): bool
    {
        // Support 'file' content type and also check MIME types directly
        if ($contentType === 'file') {
            return true;
        }

        // Also support direct MIME type matching
        foreach ($this->supportedTypes as $extensions => $mimeTypes) {
            if (in_array($contentType, $mimeTypes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get supported file extensions or content types
     */
    public function getSupportedTypes(): array
    {
        return $this->supportedTypes;
    }

    /**
     * Validate the source before processing
     */
    public function validate(KnowledgeSource $source): bool
    {
        if (! $source->isFile()) {
            return false;
        }

        $file = $source->getFile();
        if (! $file) {
            return false;
        }

        try {
            $this->validateFile($file);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get processor name for identification
     */
    public function getName(): string
    {
        return 'File Processor';
    }

    /**
     * Get validation rules for file uploads
     */
    public function getValidationRules(): array
    {
        $maxSizeKB = $this->maxFileSizeMB * 1024;
        $allowedMimes = collect($this->supportedTypes)->flatten()->unique()->implode(',');

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxSizeKB}",
                "mimes:{$this->getAllowedExtensions()}",
                // Custom validation for MIME types
                function ($attribute, $value, $fail) {
                    if (! $this->isFileTypeSupported($value)) {
                        $fail('The uploaded file type is not supported.');
                    }
                },
            ],
        ];
    }

    /**
     * Get priority for this processor (higher = more priority)
     */
    public function getPriority(): int
    {
        return 80; // High priority for file processing
    }

    /**
     * Validate uploaded file
     */
    protected function validateFile(UploadedFile $file): void
    {
        // Check file size
        $fileSizeMB = $file->getSize() / (1024 * 1024);
        if ($fileSizeMB > $this->maxFileSizeMB) {
            throw new \InvalidArgumentException(
                "File is too large ({$fileSizeMB}MB). Maximum allowed size is {$this->maxFileSizeMB}MB."
            );
        }

        // Check file type
        if (! $this->isFileTypeSupported($file)) {
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();

            throw new \InvalidArgumentException(
                "Unsupported file type: {$extension} (MIME: {$mimeType}). Supported types include: PDF, DOCX, TXT, MD, HTML, images, code files, and more."
            );
        }

        // Check if file is readable
        if (! $file->isValid()) {
            throw new \InvalidArgumentException('Uploaded file is not valid or corrupted.');
        }
    }

    /**
     * Check if file type is supported
     */
    protected function isFileTypeSupported(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        if (! isset($this->supportedTypes[$extension])) {
            return false;
        }

        $allowedMimeTypes = $this->supportedTypes[$extension];

        return in_array($mimeType, $allowedMimeTypes, true);
    }

    /**
     * Extract content using MarkItDown service only (without AI analysis)
     */
    protected function extractContentWithMarkItDown(UploadedFile $file): string
    {
        return $this->convertFileToMarkdown($file);
    }

    /**
     * Convert file to markdown using MarkItDown service
     */
    protected function convertFileToMarkdown(UploadedFile $file): string
    {
        try {
            // Check file size first to avoid 413 errors
            $fileSizeMB = $file->getSize() / (1024 * 1024);
            if ($fileSizeMB > 50) { // 50MB limit for MarkItDown service
                Log::warning('FileProcessor: File too large for MarkItDown, using fallback extraction', [
                    'file_name' => $file->getClientOriginalName(),
                    'file_size_mb' => $fileSizeMB,
                ]);
                throw new \Exception('File too large for MarkItDown service');
            }

            // Make HTTP request to MarkItDown service
            $response = Http::timeout(120) // 2 minute timeout for large files
                ->attach('file', $file->get(), $file->getClientOriginalName())
                ->post($this->markitdownUrl.'/convert-file');

            if (! $response->successful()) {
                throw new \Exception(
                    "MarkItDown service failed with status {$response->status()}: ".$response->body()
                );
            }

            $data = $response->json();

            if (empty($data['markdown'])) {
                throw new \Exception('MarkItDown service returned empty content');
            }

            return $data['markdown'];

        } catch (\Exception $e) {
            Log::info('FileProcessor: Using alternative extraction method', [
                'reason' => $e->getMessage(),
                'file_name' => $file->getClientOriginalName(),
            ]);

            // For PDFs, try to use the PDF parser directly
            if ($file->getMimeType() == 'application/pdf' || strtolower($file->getClientOriginalExtension()) == 'pdf') {
                return $this->extractPdfContent($file);
            }

            // Fallback for other file types
            return $this->fallbackTextExtraction($file);
        }
    }

    /**
     * Fallback text extraction for when MarkItDown fails
     */
    protected function fallbackTextExtraction(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // For text files, read directly
        if (in_array($extension, ['txt', 'md', 'html', 'htm', 'xml', 'json', 'csv'])) {
            $content = $file->get();

            // Basic encoding detection and conversion
            if (! mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            }

            return $content;
        }

        // For unsupported types, return basic metadata
        return "# {$file->getClientOriginalName()}\n\n".
               "File type: {$extension}\n".
               'Size: '.$this->formatFileSize($file->getSize())."\n".
               "MIME type: {$file->getMimeType()}\n\n".
               '*This file type could not be processed for text content extraction.*';
    }

    /**
     * Extract file metadata
     */
    protected function extractFileMetadata(UploadedFile $file): array
    {
        return [
            'originalFileName' => $file->getClientOriginalName(),
            'mimeType' => $file->getMimeType(),
            'fileSize' => $file->getSize(),
            'fileSizeFormatted' => $this->formatFileSize($file->getSize()),
            'fileExtension' => $file->getClientOriginalExtension(),
            'uploadedAt' => now()->toISOString(),
            'processorUsed' => 'FileProcessor',
        ];
    }

    /**
     * Generate title from filename or content
     */
    protected function generateTitle(UploadedFile $file, string $content): string
    {
        $filename = $file->getClientOriginalName();

        // Remove extension and clean up filename
        $title = pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['_', '-'], ' ', $title);
        $title = ucwords($title);

        // If title is too short or generic, try to extract from content
        if (strlen($title) < 3 || preg_match('/^(file|document|untitled)/i', $title)) {
            $contentTitle = $this->extractTitleFromContent($content);
            if ($contentTitle) {
                $title = $contentTitle;
            }
        }

        return $title;
    }

    /**
     * Extract title from content
     */
    protected function extractTitleFromContent(string $content): ?string
    {
        // Look for markdown headers
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        // Look for HTML title
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $matches)) {
            return trim(strip_tags($matches[1]));
        }

        // Look for first line if it looks like a title
        $lines = explode("\n", $content);
        $firstLine = trim($lines[0]);

        if (strlen($firstLine) > 5 && strlen($firstLine) < 100 && ! str_contains($firstLine, '.')) {
            return $firstLine;
        }

        return null;
    }

    /**
     * Generate summary from content
     */
    protected function generateSummary(string $content): string
    {
        $cleanContent = strip_tags($content);
        $sentences = preg_split('/[.!?]+/', $cleanContent, -1, PREG_SPLIT_NO_EMPTY);

        // Take first 2-3 sentences, up to 200 characters
        $summary = '';
        $maxLength = 200;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }

            if (strlen($summary.$sentence) > $maxLength) {
                break;
            }

            $summary .= $sentence.'. ';
        }

        return trim($summary) ?: 'No summary available.';
    }

    /**
     * Extract keywords from content
     */
    protected function extractKeywords(string $content): array
    {
        $cleanContent = strtolower(strip_tags($content));

        // Remove common words
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'should', 'could', 'can', 'may', 'might', 'must', 'shall', 'this', 'that', 'these', 'those'];

        // Extract words
        $words = str_word_count($cleanContent, 1);
        $words = array_filter($words, fn ($word) => strlen($word) > 3 && ! in_array($word, $stopWords));

        // Count frequency
        $wordCounts = array_count_values($words);
        arsort($wordCounts);

        // Return top 10 keywords
        return array_slice(array_keys($wordCounts), 0, 10);
    }

    /**
     * Format file size in human readable format
     */
    protected function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2).' '.$units[$pow];
    }

    /**
     * Extract content from PDF file using direct parser
     * This is used when MarkItDown fails or for large PDF files
     */
    protected function extractPdfContent(UploadedFile $file): string
    {
        try {
            // Create a temporary file to work with
            $tempPath = tempnam(sys_get_temp_dir(), 'pdf_extract_');
            file_put_contents($tempPath, $file->get());

            // Try to use various PDF extraction methods
            $content = '';

            // Method 1: Try using pdftotext if available (most reliable)
            if (exec('which pdftotext')) {
                $outputPath = $tempPath.'.txt';
                exec("pdftotext -enc UTF-8 '{$tempPath}' '{$outputPath}' 2>&1", $output, $returnCode);

                if ($returnCode === 0 && file_exists($outputPath)) {
                    $content = file_get_contents($outputPath);
                    unlink($outputPath); // Clean up
                }
            }

            // Method 2: Try using the PHP-based PDF parser
            if (empty($content)) {
                if (class_exists('\Smalot\PdfParser\Parser')) {
                    $parser = new \Smalot\PdfParser\Parser;
                    $pdf = $parser->parseFile($tempPath);
                    $content = $pdf->getText();
                }
            }

            // Clean up the temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            // If we got content, clean and format it
            if (! empty($content)) {
                // Clean up the content
                $content = trim($content);
                $content = preg_replace('/[\r\n]{3,}/', "\n\n", $content); // Remove excessive newlines

                // Fix encoding issues - crucial for MySQL storage
                $content = $this->sanitizeTextEncoding($content);

                // Add the title as a heading
                $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $title = str_replace(['_', '-'], ' ', $title);
                $title = ucwords($title);

                return "# {$title}\n\n{$content}";
            }

            // If all extraction methods failed
            Log::warning('FileProcessor: All PDF extraction methods failed', [
                'file_name' => $file->getClientOriginalName(),
            ]);

        } catch (\Exception $e) {
            Log::error('FileProcessor: PDF extraction error', [
                'error' => $e->getMessage(),
                'file_name' => $file->getClientOriginalName(),
            ]);
        }

        // Fallback: provide minimal information about the PDF
        return "# PDF: {$file->getClientOriginalName()}\n\nThis PDF file could not be processed for text content extraction.\n\nFile size: {$this->formatFileSize($file->getSize())}";
    }

    /**
     * Extract content from any supported file type using raw bytes
     * This leverages MarkItDown for full format support (PDFs, DOCX, PPTX, XLSX, images, etc.)
     *
     * @param  string  $fileContent  Raw file bytes
     * @param  string  $fileName  Original filename
     * @param  int  $fileSize  File size in bytes
     * @param  string|null  $mimeType  Optional MIME type
     * @return string Extracted markdown content
     */
    public static function extractContentFromBytes(
        string $fileContent,
        string $fileName,
        int $fileSize,
        ?string $mimeType = null
    ): string {
        try {
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $isPdf = $mimeType === 'application/pdf' || $extension === 'pdf';
            $fileSizeMB = $fileSize / (1024 * 1024);

            Log::info('FileProcessor: Extracting content from bytes', [
                'file_name' => $fileName,
                'file_size_mb' => round($fileSizeMB, 2),
                'mime_type' => $mimeType,
                'extension' => $extension,
            ]);

            // Try MarkItDown service first (supports all formats: PDF, DOCX, PPTX, XLSX, images, etc.)
            if ($fileSizeMB <= 50) { // MarkItDown 50MB limit
                try {
                    $markitdownUrl = config('services.markitdown.url', 'http://markitdown:8000');

                    $response = Http::timeout(120) // 2 minute timeout for large files
                        ->attach('file', $fileContent, $fileName)
                        ->post($markitdownUrl.'/convert-file');

                    if ($response->successful()) {
                        $data = $response->json();

                        if (! empty($data['markdown'])) {
                            Log::info('FileProcessor: Successfully extracted content via MarkItDown', [
                                'file_name' => $fileName,
                                'content_length' => strlen($data['markdown']),
                            ]);

                            return $data['markdown'];
                        }
                    }

                    Log::warning('FileProcessor: MarkItDown service failed', [
                        'file_name' => $fileName,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                } catch (\Exception $e) {
                    Log::warning('FileProcessor: MarkItDown service error', [
                        'file_name' => $fileName,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::info('FileProcessor: File too large for MarkItDown, using direct extraction', [
                    'file_name' => $fileName,
                    'file_size_mb' => $fileSizeMB,
                ]);
            }

            // Fallback: For PDFs, use direct PDF extraction
            if ($isPdf) {
                return static::extractPdfContentFromBytes($fileContent, $fileName, $fileSize);
            }

            // Fallback: For text files, extract directly
            $textExtensions = ['txt', 'md', 'markdown', 'csv', 'json', 'xml', 'log', 'yml', 'yaml',
                'html', 'htm', 'rtf', 'js', 'ts', 'py', 'php', 'java', 'c', 'cpp', 'h',
                'css', 'scss', 'sass', 'less', 'sql', 'sh', 'bash', 'env', 'ini', 'conf', 'config', ];

            if (in_array($extension, $textExtensions)) {
                $content = mb_substr($fileContent, 0, 100000); // Limit to 100KB for text files
                $content = static::sanitizeTextEncodingStatic($content);

                Log::info('FileProcessor: Extracted text file content directly', [
                    'file_name' => $fileName,
                    'content_length' => strlen($content),
                ]);

                return "# {$fileName}\n\n```\n{$content}\n```";
            }

            // No extraction method worked
            Log::warning('FileProcessor: No extraction method available for file type', [
                'file_name' => $fileName,
                'extension' => $extension,
                'mime_type' => $mimeType,
            ]);

            return "# {$fileName}\n\nFile type not supported for content extraction.\n\nFile size: ".static::formatFileSizeStatic($fileSize);

        } catch (\Exception $e) {
            Log::error('FileProcessor: Content extraction error', [
                'file_name' => $fileName,
                'error' => $e->getMessage(),
            ]);

            return "# {$fileName}\n\nError extracting content: {$e->getMessage()}";
        }
    }

    /**
     * Extract content from PDF file using raw bytes
     */
    public static function extractPdfContentFromBytes(string $fileContent, string $fileName, int $fileSize): string
    {
        try {
            // Create a temporary file to work with
            $tempPath = tempnam(sys_get_temp_dir(), 'pdf_extract_');
            file_put_contents($tempPath, $fileContent);

            Log::info('FileProcessor: Extracting PDF content from bytes', [
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'temp_path' => $tempPath,
            ]);

            $content = '';

            // Method 1: Try using pdftotext if available (most reliable)
            if (exec('which pdftotext')) {
                $outputPath = $tempPath.'.txt';
                exec("pdftotext -enc UTF-8 '{$tempPath}' '{$outputPath}' 2>&1", $output, $returnCode);

                if ($returnCode === 0 && file_exists($outputPath)) {
                    $content = file_get_contents($outputPath);
                    unlink($outputPath);

                    Log::info('FileProcessor: Successfully extracted PDF content with pdftotext', [
                        'content_length' => strlen($content),
                    ]);
                }
            }

            // Method 2: Try using the PHP-based PDF parser
            if (empty($content) && class_exists('\Smalot\PdfParser\Parser')) {
                $parser = new \Smalot\PdfParser\Parser;
                $pdf = $parser->parseFile($tempPath);
                $content = $pdf->getText();

                Log::info('FileProcessor: Successfully extracted PDF content with PdfParser', [
                    'content_length' => strlen($content),
                ]);
            }

            // Clean up the temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            // If we got content, clean and format it
            if (! empty($content)) {
                $content = trim($content);
                $content = preg_replace('/[\r\n]{3,}/', "\n\n", $content);
                $content = static::sanitizeTextEncodingStatic($content);

                $title = pathinfo($fileName, PATHINFO_FILENAME);
                $title = str_replace(['_', '-'], ' ', $title);
                $title = ucwords($title);

                return "# {$title}\n\n{$content}";
            }

            Log::warning('FileProcessor: All PDF extraction methods failed', [
                'file_name' => $fileName,
            ]);

        } catch (\Exception $e) {
            Log::error('FileProcessor: PDF extraction error', [
                'error' => $e->getMessage(),
                'file_name' => $fileName,
            ]);
        }

        return "# PDF: {$fileName}\n\nThis PDF file could not be processed for text content extraction.\n\nFile size: ".static::formatFileSizeStatic($fileSize);
    }

    /**
     * Static version of sanitizeTextEncoding for use in static methods
     */
    protected static function sanitizeTextEncodingStatic(string $text): string
    {
        $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }

        if (! $encoding) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $text);

        $replacements = [
            '\xD3' => 'Ó', '\xF3' => 'ó', '\xD6' => 'Ö', '\xF6' => 'ö',
            '\xC4' => 'Ä', '\xE4' => 'ä', '\xDC' => 'Ü', '\xFC' => 'ü', '\xDF' => 'ß',
        ];

        foreach ($replacements as $hex => $replacement) {
            $text = str_replace(stripslashes($hex), $replacement, $text);
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = Str::ascii($text);
            }
        }

        return $text;
    }

    /**
     * Static version of formatFileSize for use in static methods
     */
    protected static function formatFileSizeStatic(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Get allowed file extensions as comma-separated string
     */
    /**
     * Sanitize text encoding for database storage
     * Handles conversion to UTF-8 and removes problematic characters
     */
    protected function sanitizeTextEncoding(string $text): string
    {
        // Detect encoding if possible
        $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252'], true);

        // Convert to UTF-8 if another encoding detected
        if ($encoding && $encoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }

        // If encoding detection failed, force UTF-8 conversion
        if (! $encoding) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Remove or replace problematic characters
        $text = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $text); // Control characters

        // Replace common special characters that cause MySQL issues
        $replacements = [
            '\xD3' => 'Ó', // Latin capital letter O with acute
            '\xF3' => 'ó', // Latin small letter o with acute
            '\xD6' => 'Ö', // Latin capital letter O with diaeresis
            '\xF6' => 'ö', // Latin small letter o with diaeresis
            '\xC4' => 'Ä', // Latin capital letter A with diaeresis
            '\xE4' => 'ä', // Latin small letter a with diaeresis
            '\xDC' => 'Ü', // Latin capital letter U with diaeresis
            '\xFC' => 'ü', // Latin small letter u with diaeresis
            '\xDF' => 'ß', // Latin small letter sharp s
        ];

        foreach ($replacements as $hex => $replacement) {
            $text = str_replace(stripslashes($hex), $replacement, $text);
        }

        // Ensure the string is valid UTF-8
        if (! mb_check_encoding($text, 'UTF-8')) {
            // Last resort: strip invalid UTF-8 characters
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

            // If still invalid, use Str::ascii as final fallback
            if (! mb_check_encoding($text, 'UTF-8')) {
                $text = Str::ascii($text);
            }
        }

        return $text;
    }

    protected function getAllowedExtensions(): string
    {
        return implode(',', array_keys($this->supportedTypes));
    }

    /**
     * Get list of supported file types
     */
    public function getSupportedFileTypes(): array
    {
        return $this->supportedTypes;
    }

    /**
     * Get maximum file size in MB
     */
    public function getMaxFileSizeMB(): int
    {
        return $this->maxFileSizeMB;
    }
}
