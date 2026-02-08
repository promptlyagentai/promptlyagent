<?php

namespace App\Services;

use App\Traits\UsesAIModels;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class SystemHealthService
{
    use UsesAIModels;

    private const CACHE_KEY = 'system_health_status';

    private const CACHE_TTL = 3600; // 1 hour in seconds

    /**
     * Get system health status with caching
     */
    public function getSystemHealth(bool $forceRefresh = false): array
    {
        try {
            if ($forceRefresh) {
                Cache::forget(self::CACHE_KEY);
            }

            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return $this->performHealthChecks();
            });
        } catch (\RedisException $e) {
            // Cache unavailable - perform health checks directly without caching
            return $this->performHealthChecks();
        }
    }

    private function performHealthChecks(): array
    {
        $services = [];

        // Core Infrastructure
        $services[] = $this->checkDatabase();
        $services[] = $this->checkRedis();
        $services[] = $this->checkQueue();

        // Search Services
        $services[] = $this->checkMeilisearch();
        $services[] = $this->checkSearxng();

        // AI Services
        $services[] = $this->checkOpenAI();

        // Document Processing
        $services[] = $this->checkMarkitdown();
        $services[] = $this->checkPandoc();
        $services[] = $this->checkMermaid();

        // Real-time Services
        $services[] = $this->checkReverb();

        // MCP Services
        $services = array_merge($services, $this->checkMcpServers());

        return [
            'services' => $services,
            'last_checked' => now()->toISOString(),
            'overall_status' => $this->calculateOverallStatus($services),
            'stats' => $this->calculateStats($services),
        ];
    }

    /**
     * Check Database connectivity
     */
    private function checkDatabase(): array
    {
        try {
            \DB::connection()->getPdo();
            $tableCount = \DB::select('SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE()')[0]->count;

            $timing = $this->measureResponseTime(fn () => \DB::select('SELECT 1'));

            return [
                'name' => 'Database',
                'type' => 'infrastructure',
                'status' => 'healthy',
                'response_time' => $timing['duration'],
                'details' => [
                    'connection' => 'MySQL',
                    'tables' => $tableCount,
                    'driver' => config('database.default'),
                ],
                'message' => "Connected with {$tableCount} tables",
            ];
        } catch (Exception $e) {
            return [
                'name' => 'Database',
                'type' => 'infrastructure',
                'status' => 'error',
                'response_time' => null,
                'details' => [],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Redis connectivity
     */
    private function checkRedis(): array
    {
        try {
            $timing = $this->measureResponseTime(function () {
                Redis::ping();

                return Redis::info();
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $info = $timing['result'];

            return [
                'name' => 'Redis',
                'type' => 'infrastructure',
                'status' => 'healthy',
                'response_time' => $timing['duration'],
                'details' => [
                    'version' => $info['redis_version'] ?? 'unknown',
                    'memory_used' => isset($info['used_memory_human']) ? $info['used_memory_human'] : 'unknown',
                    'connected_clients' => $info['connected_clients'] ?? 0,
                ],
                'message' => 'Connected and responding',
            ];
        } catch (Exception $e) {
            return [
                'name' => 'Redis',
                'type' => 'infrastructure',
                'status' => 'error',
                'response_time' => null,
                'details' => [],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Queue system
     */
    private function checkQueue(): array
    {
        try {
            $timing = $this->measureResponseTime(fn () => Queue::size());

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $queueSize = $timing['result'];

            return [
                'name' => 'Queue System',
                'type' => 'infrastructure',
                'status' => 'healthy',
                'response_time' => $timing['duration'],
                'details' => [
                    'connection' => config('queue.default'),
                    'pending_jobs' => $queueSize,
                    'driver' => config('queue.connections.'.config('queue.default').'.driver'),
                ],
                'message' => $queueSize > 0 ? "{$queueSize} jobs pending" : 'No pending jobs',
            ];
        } catch (Exception $e) {
            return [
                'name' => 'Queue System',
                'type' => 'infrastructure',
                'status' => 'error',
                'response_time' => null,
                'details' => [],
                'message' => 'Queue check failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Meilisearch
     */
    private function checkMeilisearch(): array
    {
        try {
            $url = config('scout.meilisearch.host');
            $key = config('scout.meilisearch.key');

            $timing = $this->measureResponseTime(function () use ($url, $key) {
                return $this->httpClient(5)
                    ->withHeaders(['Authorization' => "Bearer {$key}"])
                    ->get("{$url}/health");
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $response = $timing['result'];

            if ($response->successful()) {
                // Get stats
                $statsResponse = $this->httpClient(5)
                    ->withHeaders(['Authorization' => "Bearer {$key}"])
                    ->get("{$url}/stats");

                $stats = $statsResponse->successful() ? $statsResponse->json() : [];

                return [
                    'name' => 'Meilisearch',
                    'type' => 'search',
                    'status' => 'healthy',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'url' => $url,
                        'database_size' => $stats['databaseSize'] ?? 'unknown',
                        'indexes' => count($stats['indexes'] ?? []),
                    ],
                    'message' => 'Search engine operational',
                ];
            } else {
                return [
                    'name' => 'Meilisearch',
                    'type' => 'search',
                    'status' => 'error',
                    'response_time' => $timing['duration'],
                    'details' => ['url' => $url],
                    'message' => 'Health check failed: HTTP '.$response->status(),
                    'error' => 'HTTP '.$response->status(),
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'Meilisearch',
                'type' => 'search',
                'status' => 'error',
                'response_time' => null,
                'details' => ['url' => config('scout.meilisearch.host')],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check SearXNG
     */
    private function checkSearxng(): array
    {
        try {
            $url = config('services.searxng.url');

            $timing = $this->measureResponseTime(function () use ($url) {
                return $this->httpClient(5)->get("{$url}/healthz");
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $response = $timing['result'];

            if ($response->successful()) {
                // Test search functionality
                $searchResponse = $this->httpClient(10)->get("{$url}/search", [
                    'q' => 'test',
                    'format' => 'json',
                ]);

                $searchWorking = $searchResponse->successful();

                return [
                    'name' => 'SearXNG',
                    'type' => 'search',
                    'status' => $searchWorking ? 'healthy' : 'warning',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'url' => $url,
                        'search_api' => $searchWorking ? 'working' : 'failed',
                    ],
                    'message' => $searchWorking ? 'Web search operational' : 'Health OK but search API failed',
                ];
            } else {
                return [
                    'name' => 'SearXNG',
                    'type' => 'search',
                    'status' => 'error',
                    'response_time' => $timing['duration'],
                    'details' => ['url' => $url],
                    'message' => 'Health check failed: HTTP '.$response->status(),
                    'error' => 'HTTP '.$response->status(),
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'SearXNG',
                'type' => 'search',
                'status' => 'error',
                'response_time' => null,
                'details' => ['url' => config('services.searxng.url')],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkOpenAI(): array
    {
        try {
            if (! config('prism.providers.openai.api_key')) {
                return [
                    'name' => 'OpenAI',
                    'type' => 'ai',
                    'status' => 'warning',
                    'response_time' => null,
                    'details' => [],
                    'message' => 'API key not configured',
                ];
            }

            $timing = $this->measureResponseTime(function () {
                return $this->useLowCostModel()
                    ->withMessages([
                        new \Prism\Prism\ValueObjects\Messages\UserMessage('test'),
                    ])
                    ->withMaxTokens(16)
                    ->generate();
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            return [
                'name' => 'OpenAI',
                'type' => 'ai',
                'status' => 'healthy',
                'response_time' => $timing['duration'],
                'details' => [
                    'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'],
                ],
                'message' => 'API responding normally',
            ];
        } catch (Exception $e) {
            return [
                'name' => 'OpenAI',
                'type' => 'ai',
                'status' => 'error',
                'response_time' => null,
                'details' => [],
                'message' => 'API request failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Markitdown service
     */
    private function checkMarkitdown(): array
    {
        try {
            $url = config('services.markitdown.url');

            $timing = $this->measureResponseTime(function () use ($url) {
                return $this->httpClient(5)->get("{$url}/health");
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $response = $timing['result'];

            if ($response->successful()) {
                return [
                    'name' => 'Markitdown',
                    'type' => 'processing',
                    'status' => 'healthy',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'url' => $url,
                    ],
                    'message' => 'Document processor operational',
                ];
            } else {
                return [
                    'name' => 'Markitdown',
                    'type' => 'processing',
                    'status' => 'error',
                    'response_time' => $timing['duration'],
                    'details' => ['url' => $url],
                    'message' => 'Health check failed: HTTP '.$response->status(),
                    'error' => 'HTTP '.$response->status(),
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'Markitdown',
                'type' => 'processing',
                'status' => 'error',
                'response_time' => null,
                'details' => ['url' => config('services.markitdown.url')],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Pandoc service
     */
    private function checkPandoc(): array
    {
        try {
            $url = config('services.pandoc.url');

            $timing = $this->measureResponseTime(function () use ($url) {
                return $this->httpClient(5)->get("{$url}/health");
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $response = $timing['result'];

            if ($response->successful()) {
                return [
                    'name' => 'Pandoc',
                    'type' => 'processing',
                    'status' => 'healthy',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'url' => $url,
                    ],
                    'message' => 'PDF/document converter operational',
                ];
            } else {
                return [
                    'name' => 'Pandoc',
                    'type' => 'processing',
                    'status' => 'error',
                    'response_time' => $timing['duration'],
                    'details' => ['url' => $url],
                    'message' => 'Health check failed: HTTP '.$response->status(),
                    'error' => 'HTTP '.$response->status(),
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'Pandoc',
                'type' => 'processing',
                'status' => 'error',
                'response_time' => null,
                'details' => ['url' => config('services.pandoc.url')],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Mermaid service
     */
    private function checkMermaid(): array
    {
        try {
            $url = config('services.mermaid.url');

            if (! config('services.mermaid.enabled', true)) {
                return [
                    'name' => 'Mermaid',
                    'type' => 'processing',
                    'status' => 'warning',
                    'response_time' => null,
                    'details' => ['url' => $url],
                    'message' => 'Service disabled in configuration',
                ];
            }

            $timing = $this->measureResponseTime(function () use ($url) {
                return $this->httpClient(5)->get("{$url}/health");
            });

            if ($timing['exception']) {
                throw $timing['exception'];
            }

            $response = $timing['result'];

            if ($response->successful()) {
                return [
                    'name' => 'Mermaid',
                    'type' => 'processing',
                    'status' => 'healthy',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'url' => $url,
                    ],
                    'message' => 'Diagram renderer operational',
                ];
            } else {
                return [
                    'name' => 'Mermaid',
                    'type' => 'processing',
                    'status' => 'error',
                    'response_time' => $timing['duration'],
                    'details' => ['url' => $url],
                    'message' => 'Health check failed: HTTP '.$response->status(),
                    'error' => 'HTTP '.$response->status(),
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'Mermaid',
                'type' => 'processing',
                'status' => 'error',
                'response_time' => null,
                'details' => ['url' => config('services.mermaid.url')],
                'message' => 'Connection failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkReverb(): array
    {
        try {
            // Check if Reverb port is accessible
            $reverbPort = config('broadcasting.connections.reverb.options.port', 8080);
            $reverbHost = config('broadcasting.connections.reverb.options.host', 'reverb');

            $timing = $this->measureResponseTime(function () use ($reverbHost, $reverbPort) {
                $socket = @fsockopen($reverbHost, $reverbPort, $errno, $errstr, 5);
                if ($socket) {
                    fclose($socket);

                    return true;
                }

                return false;
            });

            if ($timing['result']) {
                return [
                    'name' => 'Reverb',
                    'type' => 'realtime',
                    'status' => 'healthy',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'host' => $reverbHost,
                        'port' => $reverbPort,
                    ],
                    'message' => 'WebSocket server accessible',
                ];
            } else {
                return [
                    'name' => 'Reverb',
                    'type' => 'realtime',
                    'status' => 'error',
                    'response_time' => null,
                    'details' => [
                        'host' => $reverbHost,
                        'port' => $reverbPort,
                    ],
                    'message' => "Connection failed: {$errstr} ({$errno})",
                    'error' => "{$errstr} ({$errno})",
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'Reverb',
                'type' => 'realtime',
                'status' => 'error',
                'response_time' => null,
                'details' => [],
                'message' => 'Check failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check MCP servers
     */
    private function checkMcpServers(): array
    {
        $services = [];
        $mcpServers = config('relay.servers', []);

        foreach ($mcpServers as $name => $config) {
            $services[] = $this->checkMcpServer($name, $config);
        }

        return $services;
    }

    /**
     * Check individual MCP server
     */
    private function checkMcpServer(string $name, array $config): array
    {
        try {
            // For MCP servers, we'll do a basic connectivity check
            // Since they use STDIO transport, we can't easily health check them
            // Instead we'll check if the command/executable exists

            if (isset($config['command']) && is_array($config['command'])) {
                $command = $config['command'][0];

                // Check if command exists
                $timing = $this->measureResponseTime(function () use ($command) {
                    $output = [];
                    $returnCode = 0;
                    exec("command -v $command 2>/dev/null", $output, $returnCode);

                    return $returnCode === 0;
                });

                $commandExists = $timing['result'];

                if ($commandExists) {
                    return [
                        'name' => 'MCP: '.ucfirst(str_replace('-', ' ', $name)),
                        'type' => 'mcp',
                        'status' => 'healthy',
                        'response_time' => $timing['duration'],
                        'details' => [
                            'command' => implode(' ', $config['command']),
                            'transport' => $config['transport']->value ?? 'stdio',
                        ],
                        'message' => 'Command available',
                    ];
                } else {
                    return [
                        'name' => 'MCP: '.ucfirst(str_replace('-', ' ', $name)),
                        'type' => 'mcp',
                        'status' => 'error',
                        'response_time' => null,
                        'details' => [
                            'command' => implode(' ', $config['command']),
                        ],
                        'message' => "Command '$command' not found",
                        'error' => "Command '$command' not available in PATH",
                    ];
                }
            } elseif (isset($config['url'])) {
                // HTTP-based MCP server
                $url = $config['url'];
                $timing = $this->measureResponseTime(function () use ($url) {
                    return $this->httpClient(5)->get($url);
                });

                if ($timing['exception']) {
                    throw $timing['exception'];
                }

                $response = $timing['result'];

                return [
                    'name' => 'MCP: '.ucfirst(str_replace('-', ' ', $name)),
                    'type' => 'mcp',
                    'status' => $response->successful() ? 'healthy' : 'error',
                    'response_time' => $timing['duration'],
                    'details' => [
                        'url' => $url,
                        'transport' => 'http',
                    ],
                    'message' => $response->successful() ? 'Server responding' : 'HTTP '.$response->status(),
                    'error' => $response->successful() ? null : 'HTTP '.$response->status(),
                ];
            } else {
                return [
                    'name' => 'MCP: '.ucfirst(str_replace('-', ' ', $name)),
                    'type' => 'mcp',
                    'status' => 'warning',
                    'response_time' => null,
                    'details' => [],
                    'message' => 'Configuration incomplete',
                    'error' => 'No command or URL specified',
                ];
            }
        } catch (Exception $e) {
            return [
                'name' => 'MCP: '.ucfirst(str_replace('-', ' ', $name)),
                'type' => 'mcp',
                'status' => 'error',
                'response_time' => null,
                'details' => [],
                'message' => 'Check failed: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create HTTP client with timeout
     */
    private function httpClient(int $timeout = 5): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($timeout);
    }

    /**
     * Measure response time of a callable and return both result and duration
     */
    private function measureResponseTime(callable $callback): array
    {
        $start = microtime(true);
        $result = null;
        $exception = null;

        try {
            $result = $callback();
        } catch (Exception $e) {
            $exception = $e;
        }

        $end = microtime(true);
        $duration = (int) (($end - $start) * 1000); // Convert to milliseconds

        return [
            'result' => $result,
            'duration' => $duration,
            'exception' => $exception,
        ];
    }

    /**
     * Calculate overall system status
     */
    private function calculateOverallStatus(array $services): string
    {
        $statuses = collect($services)->pluck('status');

        if ($statuses->contains('error')) {
            return 'error';
        }

        if ($statuses->contains('warning')) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Calculate system statistics
     */
    private function calculateStats(array $services): array
    {
        $total = count($services);
        $healthy = collect($services)->where('status', 'healthy')->count();
        $warnings = collect($services)->where('status', 'warning')->count();
        $errors = collect($services)->where('status', 'error')->count();

        $avgResponseTime = collect($services)
            ->where('response_time', '!=', null)
            ->avg('response_time');

        return [
            'total_services' => $total,
            'healthy' => $healthy,
            'warnings' => $warnings,
            'errors' => $errors,
            'avg_response_time' => $avgResponseTime ? round($avgResponseTime, 1) : null,
        ];
    }
}
