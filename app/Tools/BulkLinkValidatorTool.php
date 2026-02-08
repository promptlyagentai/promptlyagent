<?php

namespace App\Tools;

use App\Models\ChatInteractionSource;
use App\Models\Source;
use App\Services\LinkValidator;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * BulkLinkValidatorTool - Batch URL Validation for Multiple Links.
 *
 * Prism tool for validating multiple URLs in a single operation. More efficient
 * than individual validation for checking many links at once.
 *
 * Batch Processing:
 * - Accepts array of URLs to validate
 * - Concurrent validation (parallel requests)
 * - Configurable batch size limits
 * - Progress reporting for large batches
 *
 * Validation Per URL:
 * - HTTP status code
 * - Accessibility check
 * - Redirect detection
 * - Response time
 * - Error categorization
 *
 * Response Format:
 * - Array of validation results (one per URL)
 * - Summary statistics (total, valid, invalid, errors)
 * - Individual URL status details
 * - Categorized error reporting
 *
 * Performance:
 * - Parallel validation using concurrent HTTP requests
 * - Timeout controls to prevent slow links from blocking
 * - Early termination on too many failures
 *
 * Use Cases:
 * - Validating knowledge base links in bulk
 * - Checking document reference lists
 * - Pre-flight validation before import
 * - Periodic link health checking
 *
 * @see \App\Services\LinkValidator
 * @see \App\Tools\LinkValidatorTool
 */
class BulkLinkValidatorTool
{
    use \App\Tools\Concerns\SafeJsonResponse;

    public static function create()
    {
        return Tool::as('bulk_link_validator')
            ->for('Validate multiple URLs in parallel for comprehensive research. Efficiently processes up to 30 URLs with intelligent batching and retry logic for failed validations.')
            ->withStringParameter('urls', 'JSON array of URLs to validate in parallel (e.g., ["url1", "url2", "url3"])')
            ->withStringParameter('context', 'Optional context about what these URLs are for (helps with reporting)', false)
            ->using(function (string $urls, string $context = '') {
                $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

                if (! $statusReporter) {
                    Log::warning('BulkLinkValidatorTool: No status reporter available for status reporting');
                    if (empty($urls)) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'URLs array is required',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '2.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'bulk_link_validator');
                    }
                }

                try {
                    if (empty($urls)) {
                        if ($statusReporter) {
                            $statusReporter->report('bulk_link_validator', 'Error: URLs array is required', false, false);
                        }

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_argument',
                            'message' => 'URLs array is required',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '2.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'bulk_link_validator');
                    }

                    // Parse URLs array
                    $urlList = json_decode($urls, true);
                    if (! is_array($urlList)) {
                        if ($statusReporter) {
                            $statusReporter->report('bulk_link_validator', 'Error: URLs must be provided as JSON array', false, false);
                        }

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'invalid_format',
                            'message' => 'URLs must be provided as JSON array format',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '2.0.0',
                                'error_occurred' => true,
                            ],
                        ], 'bulk_link_validator');
                    }

                    $originalCount = count($urlList);
                    if ($originalCount > 30) {
                        $batches = array_chunk($urlList, 30);
                        $allResults = [];
                        $batchCount = count($batches);

                        if ($statusReporter) {
                            $statusReporter->report('bulk_link_validator', "Processing {$originalCount} URLs in {$batchCount} batches for comprehensive validation", false, false);
                        }

                        Log::info('BulkLinkValidatorTool: Processing URLs in batches', [
                            'total_urls' => $originalCount,
                            'batch_count' => $batchCount,
                            'batch_size' => 30,
                        ]);

                        foreach ($batches as $batchIndex => $batch) {
                            $batchNumber = $batchIndex + 1;
                            if ($statusReporter) {
                                $statusReporter->report('bulk_link_validator', "Processing batch {$batchNumber}/{$batchCount} (".count($batch).' URLs)', false, false);
                            }

                            $batchResults = static::processBatch($batch, $statusReporter, $context);
                            $allResults[] = $batchResults;

                            if ($batchIndex < count($batches) - 1) {
                                usleep(500000); // 0.5 second delay
                            }
                        }

                        return static::formatBatchResults($allResults, $originalCount, $context);
                    } else {
                        if ($statusReporter) {
                            $contextMsg = $context ? " for: {$context}" : '';
                            $statusReporter->report('bulk_link_validator', 'Validating '.count($urlList)." URLs in parallel{$contextMsg}", false, false);
                        }

                        $results = static::processBatch($urlList, $statusReporter, $context);

                        return static::formatBatchResults($results, $originalCount, $context);
                    }

                } catch (\Throwable $e) {
                    Log::error('BulkLinkValidatorTool: Exception in main execution', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'urls_provided' => ! empty($urls),
                    ]);

                    if ($statusReporter) {
                        $statusReporter->report('bulk_link_validator', 'Error: '.$e->getMessage(), false, false);
                    }

                    return static::safeJsonEncode([
                        'success' => false,
                        'error' => 'bulk_validation_error',
                        'message' => 'Bulk validation failed: '.$e->getMessage(),
                        'metadata' => [
                            'executed_at' => now()->toISOString(),
                            'tool_version' => '2.0.0',
                            'error_occurred' => true,
                            'exception_class' => get_class($e),
                        ],
                    ], 'bulk_link_validator');
                }
            });
    }

    /**
     * Process a batch of URLs with retry logic
     */
    protected static function processBatch(array $urlList, $statusReporter, string $context): array
    {
        $start = microtime(true);
        $linkValidator = new LinkValidator;
        $results = [];
        $summary = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'cached' => 0, 'retried' => 0];

        // Initial validation attempt
        $validationResults = $linkValidator->validateMultipleUrls($urlList);

        $failedUrls = [];
        foreach ($validationResults as $url => $result) {
            if (! is_numeric($result['status']) || $result['status'] >= 400) {
                $failedUrls[] = $url;
            }
        }

        if (! empty($failedUrls) && count($failedUrls) <= 10) {
            if ($statusReporter) {
                $statusReporter->report('bulk_link_validator', 'Retrying '.count($failedUrls).' failed URLs', false, false);
            }

            Log::info('BulkLinkValidatorTool: Retrying failed URLs', [
                'failed_count' => count($failedUrls),
                'retry_urls' => array_slice($failedUrls, 0, 5),
            ]);

            sleep(1);
            $retryResults = $linkValidator->validateMultipleUrls($failedUrls);
            foreach ($retryResults as $url => $result) {
                if (is_numeric($result['status']) && $result['status'] < 400) {
                    $validationResults[$url] = $result;
                    $summary['retried']++;
                }
            }
        }

        foreach ($validationResults as $url => $result) {
            $summary['total']++;

            $isValid = is_numeric($result['status']) && $result['status'] < 400;
            if ($isValid) {
                $summary['valid']++;
            } else {
                $summary['invalid']++;
            }

            if ($statusReporter) {
                $domain = parse_url($url, PHP_URL_HOST) ?? 'unknown';
                $statusText = $isValid ? '✓ Valid' : '✗ Invalid';
                $httpStatus = $result['status'] ?? 'unknown';
                $title = ! empty($result['title']) ? ' - '.substr($result['title'], 0, 50) : '';

                $timing = isset($result['response_time']) ? sprintf(' (%.0fms)', $result['response_time'] * 1000) : '';

                $statusReporter->report(
                    'bulk_link_validator',
                    "{$statusText} ({$httpStatus}): {$domain}{$title}{$timing}", true, false
                );
                usleep(50000);
            }
            $enrichedResult = [
                'url' => $url,
                'is_valid' => $isValid,
                'status' => $result['status'],
                'title' => $result['title'] ?? 'No title',
                'description' => $result['description'] ?? 'No description',
                'has_content' => ! empty($result['content_markdown']),
                'domain' => parse_url($url, PHP_URL_HOST) ?? 'unknown',
                'favicon' => $result['favicon'] ?? null,
                'content_length' => isset($result['content_markdown']) ? strlen($result['content_markdown']) : 0,
                'response_time' => $result['response_time'] ?? null,
                'was_retried' => in_array($url, $failedUrls),
            ];

            $results[] = $enrichedResult;

            if ($statusReporter && $statusReporter->getInteractionId() && $isValid) {
                try {
                    $urlHash = md5($url);
                    $source = Source::where('url_hash', $urlHash)->first();

                    if ($source) {
                        ChatInteractionSource::createOrUpdate(
                            $statusReporter->getInteractionId(),
                            $source->id,
                            $context ?: 'bulk validation',
                            [
                                'url' => $url,
                                'domain' => $source->domain,
                                'title' => $source->title,
                                'description' => $source->description,
                                'content_category' => $source->content_category,
                                'http_status' => $source->http_status,
                            ],
                            'bulk_link_validator',
                            'BulkLinkValidatorTool'
                        );
                    }
                } catch (\Exception $sourceException) {
                    Log::error('BulkLinkValidatorTool: Failed to create ChatInteractionSource', [
                        'url' => $url,
                        'error' => $sourceException->getMessage(),
                    ]);
                }
            }
        }

        return [
            'results' => $results,
            'summary' => $summary,
            'duration' => microtime(true) - $start,
        ];
    }

    /**
     * Format batch results for consistent output
     */
    protected static function formatBatchResults(array $batchData, int $originalCount, string $context): string
    {
        if (isset($batchData['results'])) {
            $results = $batchData['results'];
            $summary = $batchData['summary'];
            $duration = $batchData['duration'];
        } else {
            $results = [];
            $summary = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'cached' => 0, 'retried' => 0];
            $totalDuration = 0;

            foreach ($batchData as $batch) {
                $results = array_merge($results, $batch['results']);
                foreach (['total', 'valid', 'invalid', 'cached', 'retried'] as $key) {
                    $summary[$key] += $batch['summary'][$key];
                }
                $totalDuration += $batch['duration'];
            }
            $duration = $totalDuration;
        }

        return static::safeJsonEncode([
            'success' => true,
            'data' => [
                'original_count' => $originalCount,
                'processed_count' => count($results),
                'summary' => $summary,
                'context' => $context,
                'results' => $results,
                'performance_metrics' => [
                    'total_duration_seconds' => round($duration, 3),
                    'average_time_per_url' => $summary['total'] > 0 ? round($duration / $summary['total'], 3) : 0,
                    'success_rate' => $summary['total'] > 0 ? round(($summary['valid'] / $summary['total']) * 100, 1) : 0,
                    'retry_success_rate' => $summary['retried'],
                ],
            ],
            'metadata' => [
                'executed_at' => now()->toISOString(),
                'tool_version' => '2.0.0',
                'enhanced_features' => [
                    'intelligent_batching',
                    'retry_logic',
                    'performance_metrics',
                    'comprehensive_validation',
                ],
            ],
        ], 'bulk_link_validator');
    }
}
