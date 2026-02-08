<?php

namespace App\Tools;

use App\Models\ChatInteractionSource;
use App\Models\Source;
use App\Services\LinkValidator;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * LinkValidatorTool - Single URL Validation with Status and Accessibility Checking.
 *
 * Prism tool for validating individual URLs with comprehensive status checking.
 * Returns HTTP status codes, accessibility information, and redirect chains.
 *
 * Validation Features:
 * - HTTP status code checking (200, 404, 500, etc.)
 * - Redirect chain following (up to 10 hops)
 * - SSL certificate validation
 * - Response time measurement
 * - Content-type detection
 *
 * Response Information:
 * - Final URL after redirects
 * - HTTP status code and message
 * - Redirect count and chain
 * - Response time in milliseconds
 * - Whether URL is accessible
 *
 * Error Handling:
 * - Network timeouts (configurable)
 * - SSL/TLS errors
 * - DNS resolution failures
 * - Invalid URL formats
 *
 * Use Cases:
 * - Verifying link validity before adding to knowledge
 * - Checking research source accessibility
 * - Validating external references
 * - Dead link detection
 *
 * @see \App\Services\LinkValidator
 * @see \App\Tools\BulkLinkValidatorTool
 */
class LinkValidatorTool
{
    public static function create()
    {
        return Tool::as('link_validator')
            ->for('Validates URLs and extracts metadata including title, description, favicon, and content preview')
            ->withStringParameter('url', 'The URL to validate and analyze')
            ->using(function (string $url) {
                try {
                    $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

                    if (! $statusReporter) {
                        Log::warning('LinkValidatorTool: No status reporter available for status reporting');
                    }
                    if ($statusReporter) {
                        $statusReporter->report('link_validator', "Validating: {$url}", false, false);
                    }

                    $linkValidator = new LinkValidator;

                    try {
                        $linkInfo = $linkValidator->validateAndExtractLinkInfo($url);
                        if ($statusReporter && $statusReporter->getInteractionId()) {
                            try {
                                $urlHash = md5($url);
                                $source = Source::where('url_hash', $urlHash)->first();

                                if ($source) {
                                    $userQuery = 'URL validation request';
                                    ChatInteractionSource::createOrUpdate(
                                        $statusReporter->getInteractionId(),
                                        $source->id,
                                        $userQuery,
                                        [
                                            'url' => $url,
                                            'domain' => $source->domain,
                                            'title' => $source->title,
                                            'description' => $source->description,
                                            'content_category' => $source->content_category,
                                            'http_status' => $source->http_status,
                                        ],
                                        'link_validator',
                                        'LinkValidatorTool'
                                    );
                                }
                            } catch (\Exception $sourceException) {
                                Log::error('LinkValidatorTool: Failed to create ChatInteractionSource record', [
                                    'url' => $url,
                                    'error' => $sourceException->getMessage(),
                                ]);
                            }
                        }

                    } catch (\Exception $validationException) {
                        Log::error('LinkValidatorTool: validateAndExtractLinkInfo threw exception', [
                            'url' => $url,
                            'error' => $validationException->getMessage(),
                            'file' => $validationException->getFile(),
                            'line' => $validationException->getLine(),
                            'trace' => $validationException->getTraceAsString(),
                        ]);

                        throw new \Exception('LinkValidator service failed: '.$validationException->getMessage(), 0, $validationException);
                    }
                    if ($statusReporter) {
                        $isValid = is_numeric($linkInfo['status']) && $linkInfo['status'] < 400;
                        $statusCode = $linkInfo['status'];
                        $title = $linkInfo['title'] ?: '';
                        $titleSuffix = $title ? " - {$title}" : '';

                        if ($isValid) {
                            $statusReporter->report('link_validator', "✓ Valid ({$statusCode}): {$url}{$titleSuffix}", true, false);
                        } else {
                            $statusReporter->report('link_validator', "✗ Invalid ({$statusCode}): {$url}{$titleSuffix}", true, false);
                        }
                    }

                    $recommendScraping = ! empty($linkInfo['content_markdown']) && is_numeric($linkInfo['status']) && $linkInfo['status'] < 400;

                    $result = [
                        'url' => $url,
                        'validation_status' => $linkInfo['status'],
                        'is_valid' => is_numeric($linkInfo['status']) && $linkInfo['status'] < 400,
                        'metadata' => [
                            'title' => $linkInfo['title'],
                            'description' => $linkInfo['description'],
                            'favicon' => $linkInfo['favicon'],
                            'open_graph' => $linkInfo['open_graph'],
                            'twitter_card' => $linkInfo['twitter_card'],
                        ],
                        'content_preview' => $linkInfo['content_markdown'] ? substr($linkInfo['content_markdown'], 0, 500).'...' : null,
                        'has_content' => ! empty($linkInfo['content_markdown']),
                        'recommend_full_scraping' => $recommendScraping,
                        'timestamp' => now()->toISOString(),
                    ];

                    return json_encode($result);

                } catch (\Exception $e) {
                    $errorMessage = "URL validation failed: {$e->getMessage()}";

                    Log::error('LinkValidatorTool: Exception caught in main try block', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    if ($statusReporter) {
                        $statusReporter->report('link_validator', $errorMessage, false, false);
                    }

                    return json_encode([
                        'url' => $url,
                        'validation_status' => 'error',
                        'is_valid' => false,
                        'error' => $errorMessage,
                        'timestamp' => now()->toISOString(),
                    ]);
                } catch (\Exception $outerException) {
                    Log::error('LinkValidatorTool: Outer exception caught', [
                        'url' => $url,
                        'error' => $outerException->getMessage(),
                        'file' => $outerException->getFile(),
                        'line' => $outerException->getLine(),
                        'trace' => $outerException->getTraceAsString(),
                    ]);

                    return json_encode([
                        'url' => $url,
                        'validation_status' => 'error',
                        'is_valid' => false,
                        'error' => "Tool execution failed: {$outerException->getMessage()}",
                        'timestamp' => now()->toISOString(),
                    ]);
                }
            });
    }
}
