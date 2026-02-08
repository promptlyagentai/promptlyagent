<?php

namespace App\Services\Knowledge;

use App\Traits\UsesAIModels;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\ValueObjects\Media\Image;

/**
 * AI-Powered File Analysis Service
 *
 * Analyzes uploaded files and text content to generate intelligent metadata suggestions including
 * titles, descriptions, tags, and content classification using OpenAI GPT-4.1-mini vision + text models.
 *
 * Architecture:
 * - Content Extraction: Supports text files (direct read), images (vision AI + OCR), binary files (MarkItDown)
 * - AI Analysis: GPT-4.1-mini for text understanding and metadata generation
 * - Vision Processing: Hybrid approach combining MarkItDown OCR + GPT-4.1-mini vision for comprehensive image analysis
 * - EXIF Support: Extracts technical metadata from images (camera, location, dimensions)
 * - Tag Intelligence: Validates tags against existing database, supports entity-specific tags (client:*, service:*, project:*)
 *
 * Supported File Types:
 * - Text: txt, md, csv, json, xml, yml, html, css, js, ts, php, py, java, c, cpp, sql, log
 * - Images: jpg, jpeg, png, gif, bmp, webp (with vision AI + OCR + EXIF)
 * - Documents: Processed via MarkItDown (PDF, DOCX, etc.) if service available
 *
 * Image Processing Pipeline:
 * 1. MarkItDown: OCR + EXIF extraction (technical metadata)
 * 2. Vision AI: GPT-4.1-mini analyzes image for comprehensive visual description
 * 3. EXIF Parser: Camera settings, GPS, dimensions (if available)
 * 4. Combined Output: Merged analysis for complete understanding
 *
 * AI Analysis Process:
 * 1. Content truncation: First 70% + last 30% if content > max_content_length (default 4000 chars)
 * 2. Tag validation: AI must select from existing tags OR create entity-specific tags
 * 3. Type tag requirement: At least one "type:*" tag required
 * 4. Entity tags: Supports client:name, service:name, project:name in lowercase-with-dashes format
 *
 * Tag Validation Rules:
 * - Must include at least one "type:*" tag (e.g., type:client, type:document)
 * - Can include 0-4 additional general tags from database
 * - Entity tags (client:*, service:*, project:*) created/matched automatically
 * - Format: lowercase, alphanumeric with dashes only, no spaces
 * - Invalid tags rejected with debug logging
 *
 * Configuration (config/knowledge.file_analysis):
 * - enabled: Toggle AI analysis
 * - model: OpenAI model to use (default: gpt-4.1-mini)
 * - max_content_length: Character limit for AI analysis (default: 4000)
 *
 * MarkItDown Integration:
 * - URL: config('services.markitdown.url') default: http://markitdown:8000
 * - Timeout: 60 seconds
 * - Fallback: Graceful degradation if service unavailable
 * - Supports: PDF, DOCX, PPTX, XLSX, images (OCR), and more
 *
 * @see config/knowledge.php
 * @see \App\Services\Knowledge\Processors\FileProcessor
 *
 * @return array{
 *     suggested_title?: string,
 *     suggested_description?: string,
 *     suggested_tags?: string[],
 *     suggested_ttl_hours?: int,
 *     ai_confidence?: float,
 *     content_classification?: string,
 *     original_filename: string,
 *     file_extension: string,
 *     file_size: int,
 *     mime_type: string,
 *     analysis_timestamp: string
 * }
 */
class FileAnalyzer
{
    use UsesAIModels;

    protected string $markitdownUrl;

    protected string $model;

    protected int $maxContentLength;

    public function __construct()
    {
        $this->markitdownUrl = config('services.markitdown.url', 'http://markitdown:8000');
        $this->model = config('knowledge.file_analysis.model', 'gpt-4.1-mini');
        $this->maxContentLength = config('knowledge.file_analysis.max_content_length', 4000);
    }

    /**
     * Analyze uploaded file and generate metadata suggestions
     */
    public function analyzeFile(UploadedFile $file): array
    {
        try {
            Log::info('FileAnalyzer: Starting file analysis', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);

            // Step 1: Extract content based on file type
            $extractedContent = $this->extractContent($file);

            if (empty($extractedContent)) {
                return $this->getFallbackMetadata($file);
            }

            // Step 2: Use AI to analyze content and generate metadata
            $aiAnalysis = $this->analyzeContentWithAI($extractedContent, $file->getClientOriginalName());

            // Step 3: Merge with file-based metadata
            return array_merge($this->getFileBasedMetadata($file), $aiAnalysis);

        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: Analysis failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackMetadata($file);
        }
    }

    /**
     * Analyze text content and generate metadata suggestions (for text documents, external sources, etc.)
     */
    public function analyzeTextContent(string $content, string $contextName = 'document'): array
    {
        try {
            Log::info('FileAnalyzer: Starting text content analysis', [
                'context_name' => $contextName,
                'content_length' => strlen($content),
            ]);

            if (empty(trim($content))) {
                return [];
            }

            // Use AI to analyze content and generate metadata
            return $this->analyzeContentWithAI($content, $contextName);

        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: Text analysis failed', [
                'context_name' => $contextName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Extract content based on file type - direct reading for text files, vision AI for images, MarkItDown for others
     */
    public function extractContent(UploadedFile $file): string
    {
        // Check if this is a text-based file that can be read directly
        if ($this->isTextBasedFile($file)) {
            return $this->extractTextDirectly($file);
        }

        // Check if this is an image file that should be processed with vision AI
        if ($this->isImageFile($file)) {
            return $this->extractContentFromImage($file);
        }

        // Use MarkItDown for binary files (PDF, Office documents, etc.)
        return $this->extractContentWithMarkItDown($file);
    }

    /**
     * Check if file is an image that should be processed with vision AI
     */
    protected function isImageFile(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'];

        return in_array($extension, $imageExtensions) ||
               ($mimeType && (str_starts_with($mimeType, 'image/') || in_array($mimeType, $imageMimeTypes)));
    }

    /**
     * Check if file is text-based and can be read directly without MarkItDown
     */
    protected function isTextBasedFile(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        // Text-based extensions that should be read directly
        $textExtensions = [
            'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yml', 'yaml',
            'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'rb', 'java', 'c',
            'cpp', 'h', 'sql', 'log', 'ini', 'conf', 'cfg', 'properties', 'env',
        ];

        // Text-based MIME types
        $textMimeTypes = [
            'text/plain', 'text/markdown', 'text/csv', 'application/json',
            'application/xml', 'text/xml', 'application/yaml', 'text/yaml',
            'text/html', 'text/css', 'text/javascript', 'application/javascript',
            'text/x-php', 'text/x-python', 'text/x-ruby', 'text/x-java-source',
            'text/x-c', 'text/x-c++', 'text/x-sql', 'text/x-log',
        ];

        return in_array($extension, $textExtensions) ||
               ($mimeType && (str_starts_with($mimeType, 'text/') || in_array($mimeType, $textMimeTypes)));
    }

    /**
     * Extract text content directly from file
     */
    protected function extractTextDirectly(UploadedFile $file): string
    {
        try {
            $content = $file->get();

            // Handle potential encoding issues
            if (! mb_check_encoding($content, 'UTF-8')) {
                // Try to detect and convert encoding
                $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
                if ($encoding && $encoding !== 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                }
            }

            return $content;

        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: Direct text extraction failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Extract content using MarkItDown service
     */
    protected function extractContentWithMarkItDown(UploadedFile $file): string
    {
        try {
            $response = Http::timeout(60)
                ->attach('file', $file->get(), $file->getClientOriginalName())
                ->post($this->markitdownUrl.'/convert-file');

            if (! $response->successful()) {
                Log::warning('FileAnalyzer: MarkItDown failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return '';
            }

            $data = $response->json();

            return $data['markdown'] ?? '';

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $errorClass = get_class($e);

            // Classify error type for better diagnostics
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

            Log::warning('FileAnalyzer: MarkItDown extraction failed', [
                'error' => $errorMessage,
                'error_class' => $errorClass,
                'error_code' => $e->getCode(),
                'is_connection_error' => $isConnectionError,
                'is_service_unavailable' => $isServiceUnavailable,
                'fallback' => 'Returning empty string for graceful degradation',
            ]);

            // Return empty string to allow graceful degradation
            return '';
        }
    }

    /**
     * Extract content from image using MarkItDown (with OCR) and vision AI
     */
    protected function extractContentFromImage(UploadedFile $file): string
    {
        try {
            Log::info('FileAnalyzer: Processing image with hybrid approach', [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);

            $combinedContent = '';

            // Step 1: Try MarkItDown first (includes OCR and EXIF)
            try {
                $markitdownContent = $this->extractContentWithMarkItDown($file);
                if (! empty(trim($markitdownContent))) {
                    $combinedContent .= "--- MarkItDown Analysis (OCR + EXIF) ---\n";
                    $combinedContent .= $markitdownContent."\n\n";
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $isServiceUnavailable = $e instanceof \Illuminate\Http\Client\ConnectionException
                    || str_contains($errorMessage, 'Timeout was reached')
                    || str_contains($errorMessage, 'Connection refused')
                    || str_contains($errorMessage, 'Failed to connect');

                Log::debug('FileAnalyzer: MarkItDown image processing failed', [
                    'filename' => $file->getClientOriginalName(),
                    'error' => $errorMessage,
                    'error_class' => get_class($e),
                    'is_service_unavailable' => $isServiceUnavailable,
                    'fallback' => 'Continuing with vision AI analysis only',
                ]);
            }

            // Step 2: Add vision AI analysis for comprehensive description
            // Create a more reliable temporary file
            $tempPath = null;
            $fullPath = null;

            try {
                // Create temporary file using PHP's built-in functions
                $extension = $file->getClientOriginalExtension();
                $tempPath = tempnam(sys_get_temp_dir(), 'fileanalyzer_').'.'.$extension;

                // Write file contents to temp file
                $fileContent = $file->get();
                file_put_contents($tempPath, $fileContent);
                $fullPath = $tempPath;

                // Verify file exists and has content before proceeding
                if (! file_exists($fullPath)) {
                    throw new \Exception("Temporary file was not created properly: {$fullPath}");
                }

                $fileSize = filesize($fullPath);
                if ($fileSize === false || $fileSize === 0) {
                    throw new \Exception("Temporary file is empty or unreadable: {$fullPath}");
                }

                // Use Prism for vision analysis (same as embeddings)

                try {
                    $modelConfig = $this->getModelConfig('low_cost');
                    $response = app(\App\Services\AI\PrismWrapper::class)
                        ->text()
                        ->using($modelConfig['provider'], $modelConfig['model'])
                        ->withPrompt(
                            'Analyze this image thoroughly. Describe what you see, identify objects, scenes, people, activities, context, and any other relevant visual details. Focus on the overall scene and visual content rather than just text extraction. Be comprehensive as this will be used for search and retrieval.',
                            [Image::fromBase64(base64: base64_encode($fileContent))]

                        )
                        ->withContext([
                            'mode' => 'file_analysis_vision',
                            'filename' => $file->getClientOriginalName(),
                            'file_size' => $file->getSize(),
                        ])
                        ->generate();

                } catch (\Exception $prismError) {
                    // PrismWrapper already logged the full exception chain
                    // No fallback - fail fast if vision analysis fails
                    Log::error('FileAnalyzer: Vision analysis failed', [
                        'error' => $prismError->getMessage(),
                        'filename' => $file->getClientOriginalName(),
                    ]);

                    throw new \Exception('Vision analysis failed: '.$prismError->getMessage(), 0, $prismError);
                }

                $visionDescription = $response->text;

                if (! empty(trim($visionDescription))) {
                    $combinedContent .= "--- Vision AI Analysis ---\n";
                    $combinedContent .= $visionDescription."\n\n";
                }

                // Step 3: Extract EXIF data if not already included by MarkItDown
                $exifData = $this->extractExifData($fullPath);
                if (! empty($exifData) && strpos($combinedContent, 'EXIF') === false) {
                    $combinedContent .= "--- Technical Metadata ---\n";
                    $combinedContent .= $this->formatExifData($exifData);
                }

                return ! empty(trim($combinedContent)) ? $combinedContent : 'Image file: '.$file->getClientOriginalName();

            } finally {
                // Clean up temporary file
                if ($fullPath && file_exists($fullPath)) {
                    try {
                        unlink($fullPath);
                    } catch (\Exception $cleanupError) {
                        Log::debug('FileAnalyzer: Failed to cleanup temporary file', [
                            'file' => $fullPath,
                            'error' => $cleanupError->getMessage(),
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: Hybrid image analysis failed', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            // Fallback: try to extract just EXIF data
            try {
                // Create temporary file using PHP's built-in functions
                $extension = $file->getClientOriginalExtension();
                $tempPath = tempnam(sys_get_temp_dir(), 'fileanalyzer_fallback_').'.'.$extension;

                // Write file contents to temp file
                file_put_contents($tempPath, $file->get());

                if (file_exists($tempPath)) {
                    $exifData = $this->extractExifData($tempPath);
                    unlink($tempPath);
                } else {
                    $exifData = [];
                }

                if (! empty($exifData)) {
                    return "Image file with technical metadata:\n".$this->formatExifData($exifData);
                }
            } catch (\Exception $exifError) {
                // Ignore EXIF extraction errors in fallback
            }

            return 'Image file: '.$file->getClientOriginalName();
        }
    }

    /**
     * Extract EXIF data from image file
     */
    protected function extractExifData(string $filePath): array
    {
        try {
            if (! function_exists('exif_read_data')) {
                return [];
            }

            $exifData = @exif_read_data($filePath);

            if (! $exifData) {
                return [];
            }

            // Filter and clean up EXIF data
            $relevantData = [];

            $fieldsToExtract = [
                'Make', 'Model', 'DateTime', 'DateTimeOriginal', 'DateTimeDigitized',
                'ImageWidth', 'ImageLength', 'Orientation', 'XResolution', 'YResolution',
                'ResolutionUnit', 'Software', 'ExposureTime', 'FNumber', 'ISO',
                'FocalLength', 'WhiteBalance', 'Flash', 'ColorSpace', 'FileSize',
                'MimeType', 'Artist', 'Copyright', 'GPS',
            ];

            foreach ($fieldsToExtract as $field) {
                if (isset($exifData[$field]) && ! empty($exifData[$field])) {
                    $value = $exifData[$field];

                    // Handle GPS data specially
                    if ($field === 'GPS' && is_array($value)) {
                        $relevantData[$field] = $this->formatGpsData($value);
                    } elseif (is_string($value) || is_numeric($value)) {
                        $relevantData[$field] = $value;
                    }
                }
            }

            return $relevantData;

        } catch (\Exception $e) {
            Log::debug('FileAnalyzer: EXIF extraction failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Format EXIF data for human readability
     */
    protected function formatExifData(array $exifData): string
    {
        if (empty($exifData)) {
            return '';
        }

        $formatted = [];

        foreach ($exifData as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $formatted[] = "$key: $value";
        }

        return implode("\n", $formatted);
    }

    /**
     * Format GPS data from EXIF
     */
    protected function formatGpsData(array $gpsData): array
    {
        $formatted = [];

        if (isset($gpsData['GPSLatitude']) && isset($gpsData['GPSLongitude'])) {
            $lat = $this->convertGpsCoordinate($gpsData['GPSLatitude'], $gpsData['GPSLatitudeRef'] ?? 'N');
            $lon = $this->convertGpsCoordinate($gpsData['GPSLongitude'], $gpsData['GPSLongitudeRef'] ?? 'E');

            $formatted['latitude'] = $lat;
            $formatted['longitude'] = $lon;
            $formatted['coordinates'] = "$lat, $lon";
        }

        if (isset($gpsData['GPSAltitude'])) {
            $formatted['altitude'] = $gpsData['GPSAltitude'];
        }

        return $formatted;
    }

    /**
     * Convert GPS coordinates from EXIF format to decimal degrees
     */
    protected function convertGpsCoordinate(array $coordinate, string $ref): float
    {
        $degrees = count($coordinate) > 0 ? $this->gpsToDecimal($coordinate[0]) : 0;
        $minutes = count($coordinate) > 1 ? $this->gpsToDecimal($coordinate[1]) : 0;
        $seconds = count($coordinate) > 2 ? $this->gpsToDecimal($coordinate[2]) : 0;

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        return in_array($ref, ['S', 'W']) ? -$decimal : $decimal;
    }

    /**
     * Convert GPS fraction to decimal
     */
    protected function gpsToDecimal(string $fraction): float
    {
        $parts = explode('/', $fraction);
        if (count($parts) == 2) {
            return floatval($parts[0]) / floatval($parts[1]);
        }

        return floatval($fraction);
    }

    /**
     * Analyze content with AI to generate metadata
     */
    protected function analyzeContentWithAI(string $content, string $contextName): array
    {
        try {
            // Truncate content to manage costs and API limits
            $analysisContent = $this->truncateContent($content);

            // Get available tags from database
            $availableTags = $this->getAvailableTags();

            $prompt = $this->buildAnalysisPrompt($analysisContent, $contextName, $availableTags);

            Log::debug('FileAnalyzer: Sending content to AI for analysis', [
                'content_length' => strlen($analysisContent),
                'model' => $this->model,
                'available_tags_count' => count($availableTags),
            ]);

            $response = app(\App\Services\AI\PrismWrapper::class)
                ->text()
                ->using('openai', $this->model)
                ->withPrompt($prompt)
                ->withContext([
                    'mode' => 'content_analysis',
                    'context_name' => $contextName,
                    'content_length' => strlen($analysisContent),
                ])
                ->generate();

            return $this->parseAIResponse($response->text, $availableTags);

        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: AI analysis failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get available tags from database
     */
    protected function getAvailableTags(): array
    {
        try {
            return \App\Models\KnowledgeTag::pluck('name')->toArray();
        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: Failed to fetch available tags', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build analysis prompt for AI
     */
    protected function buildAnalysisPrompt(string $content, string $contextName, array $availableTags): string
    {
        $typeTags = array_filter($availableTags, fn ($tag) => str_starts_with($tag, 'type:'));
        $otherTags = array_filter($availableTags, fn ($tag) => ! str_starts_with($tag, 'type:'));

        // Get existing specific entity tags (client:*, service:*, project:*)
        $clientTags = array_filter($availableTags, fn ($tag) => str_starts_with($tag, 'client:'));
        $serviceTags = array_filter($availableTags, fn ($tag) => str_starts_with($tag, 'service:'));
        $projectTags = array_filter($availableTags, fn ($tag) => str_starts_with($tag, 'project:'));

        $typeTagsList = implode(', ', array_slice($typeTags, 0, 50));
        $otherTagsList = implode(', ', array_slice($otherTags, 0, 50));

        $clientTagsList = ! empty($clientTags) ? "\nExisting client tags: ".implode(', ', array_slice($clientTags, 0, 20)) : '';
        $serviceTagsList = ! empty($serviceTags) ? "\nExisting service tags: ".implode(', ', array_slice($serviceTags, 0, 20)) : '';
        $projectTagsList = ! empty($projectTags) ? "\nExisting project tags: ".implode(', ', array_slice($projectTags, 0, 20)) : '';

        return <<<PROMPT
Analyze the following document content and provide metadata suggestions in JSON format.

Document context: {$contextName}

Content:
{$content}

IMPORTANT CONSTRAINTS:
1. You MUST select general tags ONLY from the available tags list below
2. You MUST include AT LEAST ONE tag that starts with "type:" (document type)
3. If you select type:client, type:service, or type:project, you SHOULD also suggest a corresponding specific tag
4. For specific entity tags (client:*, service:*, project:*), use existing tags if available, or create new ones in the format: "entity:name-in-lowercase-with-dashes"

Available TYPE tags (REQUIRED - pick at least one):
{$typeTagsList}

Available OTHER tags (optional - pick 0-4 additional tags):
{$otherTagsList}{$clientTagsList}{$serviceTagsList}{$projectTagsList}

SPECIAL TAG RULES:
- If you select "type:client", also suggest a "client:name" tag (e.g., "client:coca-cola", "client:acme-corp")
- If you select "type:service", also suggest a "service:name" tag (e.g., "service:widget-pro", "service:platform-x")
- If you select "type:project", also suggest a "project:name" tag (e.g., "project:alpha", "project:redesign-2024")
- Format: all lowercase, use dashes instead of spaces (e.g., "client:coca-cola" not "client:Coca Cola")
- Check if existing entity tags match first; if not, create a new appropriately formatted tag

Please provide a JSON response with the following structure:
{
    "title": "A clear, descriptive title (max 60 characters)",
    "description": "A concise description of the document's purpose and content (max 200 characters)",
    "tags": ["type:client", "client:acme-corp", "discipline:sales"],
    "suggested_ttl_hours": 0,
    "content_type": "document|manual|guide|report|presentation|data|code|other",
    "confidence": 0.95
}

Guidelines:
- Title should be descriptive and professional
- Description should explain what the document contains and its purpose
- Select 1-5 tags (MUST include at least one type: tag)
- Add corresponding entity tags for type:client, type:service, type:project when applicable
- Entity tag format: lowercase with dashes (client:coca-cola, service:widget-pro, project:alpha)
- If no relevant tags exist in the available list, return "tags": []
- TTL hours: 0 for permanent content, 24-168 for temporary content, 720+ for time-sensitive content
- Content type should reflect the document's nature
- Confidence should reflect how certain you are about the analysis (0-1)
- Keep responses concise and actionable

Return only valid JSON without any additional text or formatting.
PROMPT;
    }

    /**
     * Parse AI response into structured data
     */
    protected function parseAIResponse(string $response, array $availableTags): array
    {
        try {
            // Clean response (remove any markdown formatting)
            $cleanResponse = trim($response);
            $cleanResponse = preg_replace('/^```json\s*|\s*```$/m', '', $cleanResponse);

            $data = json_decode($cleanResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('FileAnalyzer: Invalid JSON response from AI', [
                    'response' => $response,
                    'error' => json_last_error_msg(),
                ]);

                return [];
            }

            // Validate and sanitize the response
            return $this->validateAIResponse($data, $availableTags);

        } catch (\Exception $e) {
            Log::warning('FileAnalyzer: Failed to parse AI response', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 200),
            ]);

            return [];
        }
    }

    /**
     * Validate and sanitize AI response
     */
    protected function validateAIResponse(array $data, array $availableTags): array
    {
        $validated = [];

        // Validate title
        if (! empty($data['title']) && is_string($data['title'])) {
            $validated['suggested_title'] = trim(substr($data['title'], 0, 255));
        }

        // Validate description
        if (! empty($data['description']) && is_string($data['description'])) {
            $validated['suggested_description'] = trim(substr($data['description'], 0, 1000));
        }

        // Validate tags - allow existing tags + valid entity-specific tags
        if (! empty($data['tags']) && is_array($data['tags'])) {
            $validTags = [];

            foreach (array_map('trim', $data['tags']) as $tag) {
                if (empty($tag)) {
                    continue;
                }

                // Check if tag exists in available tags
                if (in_array($tag, $availableTags, true)) {
                    $validTags[] = $tag;

                    continue;
                }

                // Allow entity-specific tags (client:*, service:*, project:*) even if they don't exist
                if ($this->isValidEntityTag($tag)) {
                    $validTags[] = $tag;
                    Log::info('FileAnalyzer: Accepting new entity-specific tag', [
                        'tag' => $tag,
                    ]);

                    continue;
                }

                Log::debug('FileAnalyzer: Rejecting invalid tag', [
                    'tag' => $tag,
                    'reason' => 'Not in available list and not a valid entity tag',
                ]);
            }

            if (! empty($validTags)) {
                // Check if at least one type: tag exists
                $hasTypeTag = false;
                foreach ($validTags as $tag) {
                    if (str_starts_with($tag, 'type:')) {
                        $hasTypeTag = true;
                        break;
                    }
                }

                // Only include tags if at least one type: tag is present
                if ($hasTypeTag) {
                    $validated['suggested_tags'] = array_slice(array_values($validTags), 0, 10);

                    Log::info('FileAnalyzer: Tags validated successfully', [
                        'suggested_tags' => $validated['suggested_tags'],
                        'has_type_tag' => $hasTypeTag,
                    ]);
                } else {
                    Log::warning('FileAnalyzer: No type: tag found in AI suggestions, rejecting all tags', [
                        'suggested_tags' => $validTags,
                    ]);
                    // Don't include suggested_tags at all if no type: tag exists
                }
            } else {
                Log::warning('FileAnalyzer: No valid tags found', [
                    'ai_suggested' => $data['tags'],
                    'available_count' => count($availableTags),
                ]);
            }
        }

        // Validate TTL
        if (isset($data['suggested_ttl_hours']) && is_numeric($data['suggested_ttl_hours'])) {
            $ttl = (int) $data['suggested_ttl_hours'];
            if ($ttl >= 0 && $ttl <= 8760) { // Max 1 year
                $validated['suggested_ttl_hours'] = $ttl;
            }
        }

        // Add confidence and content type for reference
        if (isset($data['confidence']) && is_numeric($data['confidence'])) {
            $validated['ai_confidence'] = round((float) $data['confidence'], 2);
        }

        if (! empty($data['content_type']) && is_string($data['content_type'])) {
            $validated['content_classification'] = trim($data['content_type']);
        }

        return $validated;
    }

    /**
     * Check if tag is a valid entity-specific tag format
     */
    protected function isValidEntityTag(string $tag): bool
    {
        // Valid entity prefixes
        $validPrefixes = ['client:', 'service:', 'project:', 'source:'];

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($tag, $prefix)) {
                $name = substr($tag, strlen($prefix));

                // Validate format: lowercase, alphanumeric with dashes only, no spaces
                if (empty($name)) {
                    return false;
                }

                // Check if it's lowercase and only contains allowed characters
                if ($name !== strtolower($name)) {
                    return false;
                }

                // Only allow lowercase letters, numbers, and dashes
                if (! preg_match('/^[a-z0-9-]+$/', $name)) {
                    return false;
                }

                // Don't allow leading/trailing dashes or multiple consecutive dashes
                if (preg_match('/^-|-$|--/', $name)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Get file-based metadata
     */
    protected function getFileBasedMetadata(UploadedFile $file): array
    {
        $filename = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());

        return [
            'original_filename' => $filename,
            'file_extension' => $extension,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'analysis_timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get fallback metadata when AI analysis fails
     */
    protected function getFallbackMetadata(UploadedFile $file): array
    {
        $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $cleanTitle = str_replace(['_', '-'], ' ', $filename);
        $cleanTitle = ucwords($cleanTitle);

        return array_merge($this->getFileBasedMetadata($file), [
            'suggested_title' => $cleanTitle,
            'suggested_description' => "Uploaded file: {$file->getClientOriginalName()}",
            'suggested_tags' => [$file->getClientOriginalExtension()],
            'ai_confidence' => 0.0,
            'analysis_method' => 'fallback',
        ]);
    }

    /**
     * Truncate content for analysis while preserving important parts
     */
    protected function truncateContent(string $content): string
    {
        if (strlen($content) <= $this->maxContentLength) {
            return $content;
        }

        // Take first part and last part to get both intro and conclusion
        $firstPart = substr($content, 0, $this->maxContentLength * 0.7);
        $lastPart = substr($content, -($this->maxContentLength * 0.3));

        return $firstPart."\n\n[... content truncated ...]\n\n".$lastPart;
    }

    /**
     * Check if file analysis is enabled
     */
    public function isEnabled(): bool
    {
        return config('knowledge.file_analysis.enabled', true);
    }

    /**
     * Get the model being used for analysis
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
