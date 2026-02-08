<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send Webhook Action
 *
 * Sends workflow results to an external webhook URL via HTTP POST.
 * Used for delivering final outputs to external systems.
 *
 * Features:
 * - Configurable webhook URL
 * - Auto-detects Slack webhooks (hooks.slack.com) and formats accordingly
 * - Slack: Simple markdown text format with title and metadata
 * - Generic: Structured JSON with content, metadata, and sources
 * - Automatic source extraction
 * - HMAC-SHA256 signatures for non-Slack webhooks
 * - Non-blocking (failures don't stop workflow)
 * - Comprehensive error logging
 */
class SendWebhookAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        // SAFETY: Wrap in try-catch to ensure we always return data unchanged
        // Webhook failures should never break the execution chain
        try {
            $url = $params['url'] ?? null;

            if (! $url) {
                Log::warning('SendWebhookAction: No webhook URL provided');

                return $data;
            }

            // Detect webhook type from URL
            $isSlackWebhook = str_contains($url, 'hooks.slack.com');

            // Extract sources for all webhook types
            $sources = $this->extractSources($data);

            // Build payload based on webhook type
            if ($isSlackWebhook) {
                // Slack webhooks expect simple {text: "..."} format with markdown
                $title = 'Daily News Digest - '.now()->format('F j, Y');
                $sourceCount = count($sources);

                $slackText = "*{$title}*\n\n";
                $slackText .= $data."\n\n";

                if ($sourceCount > 0) {
                    $slackText .= "_Generated with {$sourceCount} sources_\n";
                }

                $slackText .= '_Generated at '.now()->format('g:i A').'_';

                $payload = ['text' => $slackText];
            } else {
                // Generic webhook format (PromptlyAgent and others)
                $payload = [
                    'digest' => [
                        'title' => 'Daily News Digest - '.now()->format('F j, Y'),
                        'content' => $data,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ];

                // Add metadata if requested
                if ($params['include_metadata'] ?? true) {
                    $payload['metadata'] = $this->buildMetadata($context, $data);
                }

                if (! empty($sources)) {
                    $payload['sources'] = $sources;
                    $payload['digest']['source_count'] = count($sources);
                }

                // Add format specification
                $payload['format'] = $params['format'] ?? 'json';
            }

            // Prepare request body
            $body = json_encode($payload);

            // Build headers
            $headers = [
                'User-Agent' => 'PromptlyAgent/1.0',
                'Content-Type' => 'application/json',
            ];

            // Add signature headers if secret is provided (not for Slack webhooks)
            $secret = $params['secret'] ?? null;
            if ($secret && ! $isSlackWebhook) {
                $signatureHeaders = $this->generateSignatureHeaders($body, $secret);
                $headers = array_merge($headers, $signatureHeaders);
            }

            // Send HTTP POST to webhook
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                Log::info('SendWebhookAction: Webhook delivered successfully', [
                    'url' => $url,
                    'webhook_type' => $isSlackWebhook ? 'slack' : 'generic',
                    'status_code' => $response->status(),
                    'payload_size' => strlen($body),
                    'source_count' => count($sources),
                    'signed' => ! empty($secret) && ! $isSlackWebhook,
                ]);
            } else {
                Log::warning('SendWebhookAction: Webhook returned non-200 status', [
                    'url' => $url,
                    'webhook_type' => $isSlackWebhook ? 'slack' : 'generic',
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('SendWebhookAction: Webhook delivery failed', [
                'url' => $params['url'] ?? 'unknown',
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // Don't throw - webhook failure shouldn't stop workflow
        }

        // ALWAYS return data unchanged (webhook is side effect only)
        return $data;
    }

    /**
     * Generate signature headers for webhook security
     *
     * Creates HMAC-SHA256 signature compatible with PromptlyAgent webhook format:
     * - X-Trigger-Signature: HMAC-SHA256 of timestamp+nonce+body
     * - X-Trigger-Timestamp: Unix timestamp
     * - X-Trigger-Nonce: Unique UUID
     */
    protected function generateSignatureHeaders(string $body, string $secret): array
    {
        $timestamp = (string) time();
        $nonce = (string) \Illuminate\Support\Str::uuid();

        // Calculate signature: HMAC-SHA256(timestamp + nonce + body, secret)
        $signaturePayload = $timestamp.$nonce.$body;
        $signature = hash_hmac('sha256', $signaturePayload, $secret);

        return [
            'X-Trigger-Signature' => $signature,
            'X-Trigger-Timestamp' => $timestamp,
            'X-Trigger-Nonce' => $nonce,
        ];
    }

    /**
     * Build metadata payload
     */
    protected function buildMetadata(array $context, string $data): array
    {
        $metadata = [
            'content_length' => strlen($data),
        ];

        // Add execution info
        if (isset($context['execution'])) {
            $metadata['execution_id'] = $context['execution']->id;
            $metadata['duration_seconds'] = now()->diffInSeconds($context['execution']->created_at);
        }

        // Add agent info
        if (isset($context['agent'])) {
            $metadata['agent_name'] = $context['agent']->name;
            $metadata['agent_id'] = $context['agent']->id;
            $metadata['agent_type'] = $context['agent']->agent_type ?? null;
        }

        return $metadata;
    }

    /**
     * Extract markdown links as sources
     */
    protected function extractSources(string $data): array
    {
        $sources = [];

        if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $data, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $title = $match[1];
                $url = $match[2];

                // Basic URL validation
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $sources[] = [
                        'title' => $title,
                        'url' => $url,
                    ];
                }
            }
        }

        // Deduplicate by URL
        $unique = [];
        $seen = [];

        foreach ($sources as $source) {
            if (! in_array($source['url'], $seen)) {
                $seen[] = $source['url'];
                $unique[] = $source;
            }
        }

        return $unique;
    }

    public function validate(array $params): bool
    {
        // Validate URL parameter
        if (! isset($params['url'])) {
            return false;
        }

        if (! is_string($params['url'])) {
            return false;
        }

        // Validate URL format
        if (! filter_var($params['url'], FILTER_VALIDATE_URL)) {
            return false;
        }

        // Validate format if provided
        if (isset($params['format'])) {
            $validFormats = ['json', 'xml', 'form'];
            if (! in_array($params['format'], $validFormats)) {
                return false;
            }
        }

        // Validate include_metadata if provided
        if (isset($params['include_metadata']) && ! is_bool($params['include_metadata'])) {
            return false;
        }

        // Validate secret if provided
        if (isset($params['secret'])) {
            if (! is_string($params['secret']) || empty($params['secret'])) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Send workflow results to an external webhook URL via HTTP POST. Auto-detects Slack webhooks (hooks.slack.com) and formats payload accordingly.';
    }

    public function getParameterSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Webhook URL to POST results to. Supports Slack webhooks (hooks.slack.com) and generic webhooks.',
            ],
            'secret' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Webhook secret for HMAC-SHA256 signature (X-Trigger-Signature header). Not used for Slack webhooks.',
            ],
            'format' => [
                'type' => 'string',
                'required' => false,
                'default' => 'json',
                'options' => ['json', 'xml', 'form'],
                'description' => 'Payload format',
            ],
            'include_metadata' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Include execution metadata in payload',
            ],
        ];
    }

    public function shouldQueue(): bool
    {
        // Webhooks are I/O bound but we want immediate delivery
        // Could be queued in future for retry logic
        return false;
    }
}
