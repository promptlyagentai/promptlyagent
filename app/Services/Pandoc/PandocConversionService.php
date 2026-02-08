<?php

namespace App\Services\Pandoc;

use App\Models\Artifact;
use App\Services\Markdown\BackendMarkdownProcessor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pandoc Conversion Service - Mermaid Preprocessing.
 *
 * Handles pre-processing of markdown content before Pandoc conversion to replace
 * Mermaid diagram code blocks with rendered image references. This ensures diagrams
 * appear correctly in exported PDF, DOCX, ODT, and LaTeX documents.
 *
 * Processing Flow:
 * 1. Extract all ```mermaid code blocks from markdown content
 * 2. Call Mermaid-CLI service to render each block to PNG (best compatibility)
 * 3. Save rendered images to temporary storage
 * 4. Replace mermaid blocks with markdown image references
 * 5. Return modified content + temp file paths for cleanup
 *
 * Image Format:
 * - PNG chosen for maximum Pandoc compatibility across formats
 * - SVG has issues with some output formats (LaTeX, older Word versions)
 * - Temp files automatically cleaned up after conversion
 *
 * Usage:
 * ```php
 * $service = new PandocConversionService();
 * $result = $service->preprocessContent($markdown, 'pdf');
 * $modifiedMarkdown = $result['content'];
 * $tempFiles = $result['tempFiles'];
 *
 * // ... use modifiedMarkdown with Pandoc ...
 *
 * // Cleanup
 * foreach ($tempFiles as $file) {
 *
 *     @unlink($file);
 * }
 * ```
 *
 * Configuration:
 * - Mermaid service URL: config('services.mermaid.url')
 * - Timeout: config('services.mermaid.timeout', 60)
 * - Retry logic: config('services.mermaid.retry_times', 2)
 *
 * Error Handling:
 * - Failed renders fallback to code blocks (graceful degradation)
 * - Logs all failures for debugging
 * - Continues processing remaining diagrams on individual failures
 *
 * @see \App\Http\Controllers\ArtifactController::downloadWithPandoc()
 */
class PandocConversionService
{
    /**
     * Preprocess markdown content to convert Mermaid blocks to image references.
     *
     * @param  string  $content  Markdown content with ```mermaid blocks
     * @param  string  $format  Target export format (pdf, docx, odt, latex)
     * @return string Modified content with mermaid blocks replaced by image references
     */
    public function preprocessContent(string $content, string $format = 'pdf'): string
    {
        // Quick check: if no mermaid blocks, return early
        if (! str_contains($content, '```mermaid')) {
            return $content;
        }

        $blocks = $this->extractMermaidBlocks($content);

        if (empty($blocks)) {
            return $content;
        }

        Log::info('PandocConversionService: Processing mermaid blocks', [
            'block_count' => count($blocks),
            'format' => $format,
        ]);

        $modifiedContent = $content;
        $successCount = 0;

        foreach ($blocks as $i => $block) {
            $url = $this->renderMermaidToS3($block['code']);

            if ($url) {
                // Replace mermaid block with markdown image reference
                // Use angle brackets to properly handle URLs with special characters
                $replacement = "![Mermaid Diagram](<{$url}>)";
                $modifiedContent = str_replace($block['fullMatch'], $replacement, $modifiedContent);
                $successCount++;

                Log::info('PandocConversionService: Mermaid block replaced with S3 image', [
                    'block_index' => $i,
                ]);
            } else {
                // Fallback: keep as code block if rendering fails
                Log::warning('PandocConversionService: Failed to render mermaid diagram', [
                    'block_index' => $i,
                    'code_preview' => Str::limit($block['code'], 100),
                ]);
            }
        }

        Log::info('PandocConversionService: Preprocessing complete', [
            'original_blocks' => count($blocks),
            'rendered_images' => $successCount,
        ]);

        return $modifiedContent;
    }

    /**
     * Extract all Mermaid code blocks from markdown content.
     *
     * @param  string  $content  Markdown content
     * @return array<array{code: string, fullMatch: string}>
     */
    protected function extractMermaidBlocks(string $content): array
    {
        $blocks = [];
        $pattern = '/```mermaid\n([\s\S]*?)\n```/';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blocks[] = [
                    'code' => $match[1],
                    'fullMatch' => $match[0],
                ];
            }
        }

        return $blocks;
    }

    /**
     * Render Mermaid diagram code to PNG and upload to S3.
     *
     * Follows the chat-attachments storage pattern for consistency.
     * Uses permanent URLs (not temporary) since Pandoc needs reliable access.
     * Files are kept in S3 as they're part of the exported artifact content.
     *
     * @param  string  $code  Mermaid diagram code
     * @return string|null S3 URL or null on failure
     */
    protected function renderMermaidToS3(string $code): ?string
    {
        // Check if service is enabled
        if (! config('services.mermaid.enabled', true)) {
            Log::warning('PandocConversionService: Mermaid service is disabled');

            return null;
        }

        $serviceUrl = config('services.mermaid.url');
        $timeout = config('services.mermaid.timeout', 60);

        // Calculate smart scaling: max 2000px width to prevent LaTeX errors
        // LaTeX has image size limits that can cause conversion failures for oversized images
        $maxWidth = config('services.mermaid.max_width_pdf', 2000);
        $scale = config('services.mermaid.scale_pdf', 4);

        $requestData = [
            'code' => $code,
            'format' => 'png', // PNG for best Pandoc compatibility
            'backgroundColor' => 'white', // White background for print
            'theme' => 'default', // Default theme with professional colors
            'scale' => $scale, // Scale factor for high-res output
            'maxWidth' => $maxWidth, // Maximum width constraint to prevent oversized images
        ];

        Log::info('PandocConversionService: Rendering mermaid diagram', [
            'service_url' => $serviceUrl,
            'request_data' => $requestData,
            'code_preview' => Str::limit($code, 50),
        ]);

        try {
            $response = Http::timeout($timeout)
                ->retry(config('services.mermaid.retry_times', 2), config('services.mermaid.retry_delay', 1000))
                ->post("{$serviceUrl}/render", $requestData);

            if (! $response->successful()) {
                $errorBody = $response->json();

                Log::error('PandocConversionService: Mermaid service error', [
                    'status' => $response->status(),
                    'error' => $errorBody['error'] ?? 'Unknown error',
                    'details' => $errorBody['details'] ?? '',
                    'code_preview' => Str::limit($code, 100),
                ]);

                return null;
            }

            // Follow chat-attachments storage pattern
            $filename = 'mermaid-'.Str::random(8).'-'.time().'.png';
            $s3Path = 'chat-attachments/'.date('Y/m/d').'/'.$filename;

            Storage::disk('s3')->put($s3Path, $response->body());

            // Generate pre-signed URL valid for 2 hours (enough time for Pandoc conversion)
            $url = Storage::disk('s3')->temporaryUrl($s3Path, now()->addHours(2));

            Log::info('PandocConversionService: Mermaid diagram uploaded to S3', [
                's3_path' => $s3Path,
                'file_size' => strlen($response->body()),
                'url' => $url,
                'expires_at' => now()->addHours(2)->toDateTimeString(),
            ]);

            return $url;

        } catch (\Exception $e) {
            Log::error('PandocConversionService: Exception during mermaid rendering', [
                'error' => $e->getMessage(),
                'service_url' => $serviceUrl,
                'code_preview' => Str::limit($code, 100),
            ]);

            return null;
        }
    }

    /**
     * Render Mermaid diagram code to PNG image file (DEPRECATED - use renderMermaidToS3).
     *
     * @param  string  $code  Mermaid diagram code
     * @return string|null Path to rendered image file, or null on failure
     */
    protected function renderMermaidToImage(string $code): ?string
    {
        // Check if service is enabled
        if (! config('services.mermaid.enabled', true)) {
            Log::warning('PandocConversionService: Mermaid service is disabled');

            return null;
        }

        $serviceUrl = config('services.mermaid.url');
        $timeout = config('services.mermaid.timeout', 60);

        try {
            $response = Http::timeout($timeout)
                ->retry(config('services.mermaid.retry_times', 2), config('services.mermaid.retry_delay', 1000))
                ->post("{$serviceUrl}/render", [
                    'code' => $code,
                    'format' => 'png', // PNG for best Pandoc compatibility
                    'backgroundColor' => 'white', // White background for printed documents
                ]);

            if (! $response->successful()) {
                $errorBody = $response->json();

                Log::error('PandocConversionService: Mermaid service error', [
                    'status' => $response->status(),
                    'error' => $errorBody['error'] ?? 'Unknown error',
                    'details' => $errorBody['details'] ?? '',
                    'code_preview' => Str::limit($code, 100),
                ]);

                return null;
            }

            // Save to temp file
            $tempPath = sys_get_temp_dir().'/mermaid-'.uniqid().'.png';
            file_put_contents($tempPath, $response->body());

            Log::info('PandocConversionService: Mermaid diagram rendered', [
                'temp_path' => $tempPath,
                'file_size' => filesize($tempPath),
            ]);

            return $tempPath;

        } catch (\Exception $e) {
            Log::error('PandocConversionService: Exception during mermaid rendering', [
                'error' => $e->getMessage(),
                'service_url' => $serviceUrl,
                'code_preview' => Str::limit($code, 100),
            ]);

            return null;
        }
    }

    /**
     * Prepare artifact content for Pandoc conversion.
     *
     * - Resolves internal URLs (asset://, attachment://) to presigned S3 URLs
     * - Wraps code content in markdown code blocks
     * - Preprocesses mermaid blocks to images
     *
     * @param  Artifact  $artifact  The artifact to prepare
     * @return string Prepared content ready for Pandoc
     */
    public function prepareContentForPandoc(Artifact $artifact): string
    {
        $content = $artifact->content ?? '';
        $filetype = $artifact->filetype;

        // Resolve internal URLs (asset:// and attachment://) for markdown artifacts
        if (in_array($filetype, ['md', 'markdown'])) {
            // Use BackendMarkdownProcessor to resolve internal URLs to presigned S3 URLs
            // Pandoc runs in a separate Docker container and needs direct S3 access
            $processor = app(BackendMarkdownProcessor::class);
            $content = $processor->resolveUrls($content, 120); // 2-hour validity for Pandoc

            // Preprocess mermaid blocks for Pandoc
            $content = $this->preprocessContent($content);

            // Convert S3 SVG references to accessible PNG format for LaTeX
            $content = $this->convertS3SvgReferences($content);
        }

        // Wrap code files in markdown code blocks
        $codeFileTypes = [
            'php', 'javascript', 'typescript', 'python', 'java', 'c', 'cpp', 'csharp',
            'ruby', 'go', 'rust', 'swift', 'kotlin', 'scala', 'bash', 'sh', 'sql',
            'html', 'css', 'json', 'xml', 'yaml', 'yml', 'dockerfile', 'makefile',
        ];

        if (in_array(strtolower($filetype ?? ''), $codeFileTypes) && ! str_contains($content, '```')) {
            $content = "```{$filetype}\n{$content}\n```";
        }

        return $content;
    }

    /**
     * Extract asset and attachment references from markdown content.
     *
     * Uses BackendMarkdownProcessor for consistent reference extraction.
     *
     * @param  string  $content  Markdown content
     * @return array{assets: array<int>, attachments: array<int>, external_urls: array<string>}
     */
    public function extractAssetReferences(string $content): array
    {
        $processor = app(BackendMarkdownProcessor::class);

        return $processor->extractReferences($content);
    }

    /**
     * Convert S3 SVG image references to PNG format with presigned URLs.
     *
     * LaTeX (used by pandoc for PDF conversion) cannot handle SVG images well.
     * This method detects S3 SVG references and either converts them to PNG or
     * removes them from the markdown (with warning) if conversion is not available.
     *
     * NOTE: SVG conversion requires ImageMagick with librsvg delegate, which may
     * not be available in all environments. If unavailable, SVG images are removed
     * from the output with a warning logged.
     *
     * @param  string  $content  Markdown content
     * @return string Modified content with SVG references converted to PNG or removed
     */
    protected function convertS3SvgReferences(string $content): string
    {
        // Pattern to match markdown images with S3 SVG URLs
        $pattern = '/!\[([^\]]*)\]\((https?:\/\/[^)]+\.svg[^)]*)\)/';

        if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        Log::info('PandocConversionService: Found S3 SVG references', [
            'count' => count($matches),
            'conversion_available' => extension_loaded('imagick'),
        ]);

        foreach ($matches as $match) {
            $fullMatch = $match[0];
            $altText = $match[1];
            $svgUrl = $match[2];

            // Extract S3 path from URL (handle both custom S3 domains and s3.amazonaws.com patterns)
            if (preg_match('/chat-attachments\/[\d\/]+\/[^\/\?]+\.svg/', $svgUrl, $pathMatch)) {
                $s3Path = $pathMatch[0];

                try {
                    // Check if file exists in S3
                    if (! Storage::disk('s3')->exists($s3Path)) {
                        Log::warning('PandocConversionService: SVG file not found in S3, removing from output', [
                            's3_path' => $s3Path,
                            'url' => $svgUrl,
                        ]);

                        // Remove SVG reference from content
                        $content = str_replace($fullMatch, '', $content);

                        continue;
                    }

                    // Fetch SVG content from S3
                    $svgContent = Storage::disk('s3')->get($s3Path);

                    // Try to convert SVG to PNG
                    $pngContent = $this->convertSvgToPng($svgContent);

                    if (! $pngContent) {
                        Log::warning('PandocConversionService: SVG conversion not available, removing SVG from output', [
                            's3_path' => $s3Path,
                            'alt_text' => $altText,
                            'suggestion' => 'Use PNG format for images in PDF exports, or install librsvg in Docker container',
                        ]);

                        // Remove SVG reference from content since we can't convert it
                        $content = str_replace($fullMatch, '', $content);

                        continue;
                    }

                    // Upload PNG to S3
                    $pngFilename = str_replace('.svg', '.png', basename($s3Path));
                    $pngS3Path = 'chat-attachments/'.date('Y/m/d').'/converted-'.$pngFilename;
                    Storage::disk('s3')->put($pngS3Path, $pngContent);

                    // Generate presigned URL (2 hours, enough for pandoc conversion)
                    $pngUrl = Storage::disk('s3')->temporaryUrl($pngS3Path, now()->addHours(2));

                    // Replace SVG reference with PNG reference
                    $replacement = "![{$altText}]({$pngUrl})";
                    $content = str_replace($fullMatch, $replacement, $content);

                    Log::info('PandocConversionService: Converted S3 SVG to PNG', [
                        'svg_path' => $s3Path,
                        'png_path' => $pngS3Path,
                        'png_size' => strlen($pngContent),
                    ]);

                } catch (\Exception $e) {
                    Log::error('PandocConversionService: Exception handling S3 SVG, removing from output', [
                        's3_path' => $s3Path,
                        'error' => $e->getMessage(),
                    ]);

                    // Remove SVG reference on error
                    $content = str_replace($fullMatch, '', $content);
                }
            }
        }

        return $content;
    }

    /**
     * Convert SVG content to PNG using Chromium-based rendering.
     *
     * Uses the mermaid service's puppeteer setup to render SVG to PNG.
     *
     * @param  string  $svgContent  SVG file content
     * @return string|null PNG binary content or null on failure
     */
    protected function convertSvgToPng(string $svgContent): ?string
    {
        // Check if Imagick extension is available
        if (! extension_loaded('imagick')) {
            Log::warning('PandocConversionService: Imagick extension not available, cannot convert SVG');

            return null;
        }

        try {
            $imagick = new \Imagick;

            // Read SVG content
            $imagick->readImageBlob($svgContent);

            // Set format to PNG
            $imagick->setImageFormat('png');

            // Set resolution for better quality (300 DPI)
            $imagick->setResolution(300, 300);

            // Set background to white (important for transparent SVGs)
            $imagick->setImageBackgroundColor('white');
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

            // Scale to reasonable size for PDFs (max width 2000px)
            $maxWidth = config('services.mermaid.max_width_pdf', 2000);
            if ($imagick->getImageWidth() > $maxWidth) {
                $imagick->scaleImage($maxWidth, 0); // 0 = maintain aspect ratio
            }

            // Get PNG binary content
            $pngContent = $imagick->getImageBlob();

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            Log::info('PandocConversionService: Successfully converted SVG to PNG using Imagick', [
                'original_size' => strlen($svgContent),
                'png_size' => strlen($pngContent),
            ]);

            return $pngContent;

        } catch (\Exception $e) {
            Log::error('PandocConversionService: SVG to PNG conversion exception', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Get MIME type for a given output format.
     *
     * @param  string  $format  Output format (pdf, docx, odt, latex, etc.)
     * @return string MIME type
     */
    public function getMimeTypeForFormat(string $format): string
    {
        return match (strtolower($format)) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'latex', 'tex' => 'application/x-latex',
            'html', 'html5' => 'text/html',
            'epub', 'epub3' => 'application/epub+zip',
            'rtf' => 'application/rtf',
            'txt', 'plain' => 'text/plain',
            'md', 'markdown' => 'text/markdown',
            default => 'application/octet-stream',
        };
    }
}
