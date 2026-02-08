<?php

namespace App\Services\CodeExecution;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Judge0 Client - Sandboxed Code Execution API Integration.
 *
 * Provides HTTP client for Judge0 code execution service, enabling secure
 * server-side execution of user-submitted code in isolated Docker containers
 * with resource limits.
 *
 * Judge0 Features:
 * - 60+ programming languages supported
 * - Docker isolation per execution
 * - CPU/memory/time limits enforced
 * - No network access from executed code
 * - Async execution with status polling
 *
 * Polling Strategy:
 * - Submit code → receive submission token
 * - Poll status endpoint until complete (max 20 attempts)
 * - 500ms intervals between polls
 * - Timeout after 10 seconds total
 *
 * Resource Limits:
 * - CPU time: Configurable per language
 * - Memory: Configurable per language
 * - Wall time: 10 second maximum
 *
 * API Configuration:
 * - Base URL: env('JUDGE0_API_URL')
 * - API Key: env('JUDGE0_API_KEY') (optional)
 * - Timeout: 30 seconds per request
 *
 * @see https://ce.judge0.com/
 * @see https://github.com/judge0/judge0
 * @see \App\Services\Artifacts\Executors\Judge0Executor
 */
class Judge0Client
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    private int $maxPollingAttempts;

    private int $pollingInterval;

    public function __construct()
    {
        // Remove trailing slash from base URL to avoid double-slash issues
        $this->baseUrl = rtrim(config('code-execution.judge0.url', 'https://ce.judge0.com'), '/');
        $this->apiKey = config('code-execution.judge0.api_key');
        $this->timeout = config('code-execution.judge0.timeout', 30);
        $this->maxPollingAttempts = config('code-execution.judge0.max_polling_attempts', 10);
        $this->pollingInterval = config('code-execution.judge0.polling_interval', 1);
    }

    /**
     * Check if Judge0 is configured
     */
    public static function isConfigured(): bool
    {
        $url = config('code-execution.judge0.url');

        // Judge0 is configured if a URL is set
        return ! empty($url);
    }

    /**
     * Execute code using Judge0
     *
     * @param  string  $code  Source code to execute
     * @param  int  $languageId  Judge0 language ID
     * @param  string|null  $stdin  Standard input for the program
     * @param  array  $options  Additional execution options
     *
     * @throws \Exception
     */
    public function execute(
        string $code,
        int $languageId,
        ?string $stdin = null,
        array $options = []
    ): ExecutionResult {
        try {
            // Submit the code for execution
            $token = $this->submit($code, $languageId, $stdin, $options);

            // Poll for results
            $result = $this->getResult($token);

            return ExecutionResult::fromJudge0Response($result);
        } catch (ConnectionException $e) {
            Log::error('Judge0 connection error', [
                'error' => $e->getMessage(),
                'language_id' => $languageId,
            ]);

            throw new \Exception('Failed to connect to Judge0 service: '.$e->getMessage());
        } catch (\Exception $e) {
            Log::error('Judge0 execution error', [
                'error' => $e->getMessage(),
                'language_id' => $languageId,
            ]);

            throw $e;
        }
    }

    /**
     * Submit code to Judge0 and get submission token
     *
     * @param  string  $code  Source code to execute
     * @param  int  $languageId  Judge0 language ID
     * @param  string|null  $stdin  Standard input
     * @param  array  $options  Additional options
     * @return string Submission token
     *
     * @throws \Exception
     */
    private function submit(string $code, int $languageId, ?string $stdin = null, array $options = []): string
    {
        $payload = [
            'source_code' => $code,
            'language_id' => $languageId,
        ];

        if ($stdin !== null) {
            $payload['stdin'] = $stdin;
        }

        // Apply resource limits from config
        $payload['cpu_time_limit'] = $options['cpu_time_limit'] ?? config('code-execution.limits.cpu_time', 5);
        $payload['memory_limit'] = $options['memory_limit'] ?? config('code-execution.limits.memory', 256000);
        $payload['wall_time_limit'] = $options['wall_time_limit'] ?? config('code-execution.limits.wall_time', 10);

        $http = Http::timeout($this->timeout);

        // Add API key header if configured (for RapidAPI)
        if ($this->apiKey) {
            $http = $http->withHeaders([
                'X-RapidAPI-Key' => $this->apiKey,
                'X-RapidAPI-Host' => 'judge0-ce.p.rapidapi.com',
            ]);
        }

        $response = $http->post("{$this->baseUrl}/submissions?wait=false", $payload);

        if (! $response->successful()) {
            throw new \Exception('Failed to submit code to Judge0: '.$response->body());
        }

        $data = $response->json();

        if (! isset($data['token'])) {
            throw new \Exception('Judge0 did not return a submission token');
        }

        return $data['token'];
    }

    /**
     * Get submission result from Judge0
     *
     * @param  string  $token  Submission token
     * @return array Submission result
     *
     * @throws \Exception
     */
    private function getResult(string $token): array
    {
        $attempts = 0;

        while ($attempts < $this->maxPollingAttempts) {
            $http = Http::timeout($this->timeout);

            // Add API key header if configured (for RapidAPI)
            if ($this->apiKey) {
                $http = $http->withHeaders([
                    'X-RapidAPI-Key' => $this->apiKey,
                    'X-RapidAPI-Host' => 'judge0-ce.p.rapidapi.com',
                ]);
            }

            $response = $http->get("{$this->baseUrl}/submissions/{$token}", [
                'fields' => '*',
            ]);

            if (! $response->successful()) {
                throw new \Exception('Failed to get submission result from Judge0: '.$response->body());
            }

            $data = $response->json();

            // Status IDs: 1 = In Queue, 2 = Processing
            // 3+ = Finished (various statuses)
            $statusId = $data['status']['id'] ?? 0;

            if ($statusId > 2) {
                return $data;
            }

            $attempts++;
            sleep($this->pollingInterval);
        }

        throw new \Exception('Judge0 execution timed out after '.$this->maxPollingAttempts.' attempts');
    }

    /**
     * Get list of supported languages from Judge0
     */
    public function getLanguages(): array
    {
        try {
            $http = Http::timeout($this->timeout);

            // Add API key header if configured (for RapidAPI)
            if ($this->apiKey) {
                $http = $http->withHeaders([
                    'X-RapidAPI-Key' => $this->apiKey,
                    'X-RapidAPI-Host' => 'judge0-ce.p.rapidapi.com',
                ]);
            }

            $response = $http->get("{$this->baseUrl}/languages");

            if (! $response->successful()) {
                return [];
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Failed to fetch Judge0 languages', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Health check for Judge0 service
     */
    public function isHealthy(): bool
    {
        try {
            $http = Http::timeout(5);

            // Add API key header if configured (for RapidAPI)
            if ($this->apiKey) {
                $http = $http->withHeaders([
                    'X-RapidAPI-Key' => $this->apiKey,
                    'X-RapidAPI-Host' => 'judge0-ce.p.rapidapi.com',
                ]);
            }

            $response = $http->get("{$this->baseUrl}/about");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
