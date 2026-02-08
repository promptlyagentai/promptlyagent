<?php

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * HttpRequestTool - Generic HTTP Client for API Testing and Integration
 *
 * Prism tool for making arbitrary HTTP requests (GET, POST, PUT, PATCH, DELETE).
 * Enables agents to test APIs, scrape websites, and interact with external services.
 *
 * Supported Features:
 * - All HTTP methods (GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS)
 * - Custom headers (authentication, content-type, user-agent, etc.)
 * - Request body (JSON, form data, raw text)
 * - Query parameters
 * - Timeout configuration
 * - Response body, headers, and status code
 * - Error handling with detailed messages
 *
 * Security:
 * - 30-second timeout to prevent hanging
 * - SSL verification enabled by default
 * - Response size limited to prevent memory issues
 * - Sensitive headers (Authorization) logged as [REDACTED]
 *
 * Use Cases:
 * - Testing REST APIs during agent creation
 * - Web scraping and data extraction
 * - Webhook testing and validation
 * - API integration verification
 * - Custom HTTP workflows
 *
 * Response Format:
 * - success: boolean
 * - status_code: HTTP status code
 * - headers: Response headers (selected)
 * - body: Response body (truncated if too large)
 * - error: Error message if request failed
 *
 * @see \Illuminate\Support\Facades\Http
 */
class HttpRequestTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('http_request')
            ->for('Make HTTP requests to any URL. Supports GET, POST, PUT, PATCH, DELETE methods with custom headers and body. Perfect for testing APIs, scraping websites, or validating webhooks during agent creation.')
            ->withStringParameter('url', 'The URL to send the request to (required, must be valid URL)')
            ->withStringParameter('method', 'HTTP method: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS (default: GET)')
            ->withArrayParameter('headers', 'Custom headers as key-value pairs (e.g., {"Authorization": "Bearer token", "Content-Type": "application/json"})', new StringSchema('value', 'Header value'), false)
            ->withStringParameter('body', 'Request body (for POST/PUT/PATCH). Can be JSON string, form data, or raw text', false)
            ->withArrayParameter('query', 'Query parameters as key-value pairs (e.g., {"page": "1", "limit": "10"})', new StringSchema('value', 'Query parameter value'), false)
            ->withNumberParameter('timeout', 'Request timeout in seconds (default: 30, max: 60)')
            ->using(function (
                string $url,
                string $method = 'GET',
                ?array $headers = null,
                ?string $body = null,
                ?array $query = null,
                int $timeout = 30
            ) {
                return static::executeHttpRequest([
                    'url' => $url,
                    'method' => $method,
                    'headers' => $headers ?? [],
                    'body' => $body,
                    'query' => $query ?? [],
                    'timeout' => $timeout,
                ]);
            });
    }

    protected static function executeHttpRequest(array $arguments): string
    {
        try {
            $interactionId = null;
            $statusReporter = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            if ($statusReporter) {
                $statusReporter->report('http_request', 'Making HTTP request: '.$arguments['method'].' '.$arguments['url'], true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'url' => 'required|url',
                'method' => 'required|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS',
                'headers' => 'array',
                'body' => 'nullable|string',
                'query' => 'array',
                'timeout' => 'integer|min:1|max:60',
            ]);

            if ($validator->fails()) {
                Log::warning('HttpRequestTool: Validation failed', [
                    'interaction_id' => $interactionId,
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'HttpRequestTool');
            }

            $validated = $validator->validated();

            // Build HTTP request
            $http = Http::timeout($validated['timeout'])
                ->withHeaders($validated['headers']);

            // Add query parameters
            if (! empty($validated['query'])) {
                $http = $http->withQueryParameters($validated['query']);
            }

            // Execute request based on method
            $method = strtoupper($validated['method']);
            $startTime = microtime(true);

            $response = null;
            switch ($method) {
                case 'GET':
                    $response = $http->get($validated['url']);
                    break;
                case 'POST':
                    $response = $http->send('POST', $validated['url'], [
                        'body' => $validated['body'],
                    ]);
                    break;
                case 'PUT':
                    $response = $http->send('PUT', $validated['url'], [
                        'body' => $validated['body'],
                    ]);
                    break;
                case 'PATCH':
                    $response = $http->send('PATCH', $validated['url'], [
                        'body' => $validated['body'],
                    ]);
                    break;
                case 'DELETE':
                    $response = $http->delete($validated['url']);
                    break;
                case 'HEAD':
                    $response = $http->send('HEAD', $validated['url']);
                    break;
                case 'OPTIONS':
                    $response = $http->send('OPTIONS', $validated['url']);
                    break;
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Get response data
            $statusCode = $response->status();
            $responseHeaders = $response->headers();
            $responseBody = $response->body();

            // Truncate large responses
            $maxBodyLength = 50000; // 50KB
            $bodyTruncated = false;
            if (strlen($responseBody) > $maxBodyLength) {
                $responseBody = substr($responseBody, 0, $maxBodyLength);
                $bodyTruncated = true;
            }

            // Select important headers to return (not all)
            $importantHeaders = [
                'content-type',
                'content-length',
                'server',
                'date',
                'cache-control',
                'etag',
                'last-modified',
                'x-ratelimit-remaining',
                'x-ratelimit-limit',
            ];

            $filteredHeaders = [];
            foreach ($importantHeaders as $headerKey) {
                if (isset($responseHeaders[$headerKey])) {
                    $filteredHeaders[$headerKey] = is_array($responseHeaders[$headerKey])
                        ? $responseHeaders[$headerKey][0]
                        : $responseHeaders[$headerKey];
                }
            }

            if ($statusReporter) {
                $statusReporter->report('http_request', "Response: {$statusCode} ({$duration}ms)", true, false);
            }

            // Redact sensitive headers for logging
            $logHeaders = $validated['headers'];
            if (isset($logHeaders['Authorization'])) {
                $logHeaders['Authorization'] = '[REDACTED]';
            }
            if (isset($logHeaders['X-API-Key'])) {
                $logHeaders['X-API-Key'] = '[REDACTED]';
            }

            Log::info('HttpRequestTool: Request completed successfully', [
                'interaction_id' => $interactionId,
                'method' => $method,
                'url' => $validated['url'],
                'status_code' => $statusCode,
                'duration_ms' => $duration,
                'body_size' => strlen($response->body()),
                'body_truncated' => $bodyTruncated,
                'headers_sent' => array_keys($logHeaders),
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'status_code' => $statusCode,
                    'headers' => $filteredHeaders,
                    'body' => $responseBody,
                    'body_truncated' => $bodyTruncated,
                    'duration_ms' => $duration,
                    'successful' => $response->successful(),
                    'ok' => $response->ok(),
                    'redirect' => $response->redirect(),
                    'client_error' => $response->clientError(),
                    'server_error' => $response->serverError(),
                ],
                'message' => "HTTP {$method} request completed with status {$statusCode} in {$duration}ms",
            ], 'HttpRequestTool');

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('HttpRequestTool: Connection failed', [
                'interaction_id' => $interactionId ?? null,
                'url' => $arguments['url'] ?? null,
                'error_message' => $e->getMessage(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Connection failed: '.$e->getMessage(),
                'error_type' => 'connection_error',
            ], 'HttpRequestTool');

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $response = $e->response;
            $statusCode = $response ? $response->status() : 'unknown';

            Log::error('HttpRequestTool: Request failed', [
                'interaction_id' => $interactionId ?? null,
                'url' => $arguments['url'] ?? null,
                'status_code' => $statusCode,
                'error_message' => $e->getMessage(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Request failed with status '.$statusCode.': '.$e->getMessage(),
                'error_type' => 'request_error',
                'status_code' => $statusCode,
            ], 'HttpRequestTool');

        } catch (\Exception $e) {
            Log::error('HttpRequestTool: Exception during execution', [
                'interaction_id' => $interactionId ?? null,
                'url' => $arguments['url'] ?? null,
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to make HTTP request: '.$e->getMessage(),
                'error_type' => 'general_error',
            ], 'HttpRequestTool');
        }
    }
}
