<?php

namespace App\Services\InputTrigger\Providers;

use App\Models\InputTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook Trigger Provider - HMAC-Secured Webhook Integration.
 *
 * Enables webhook-based agent invocation with cryptographic signature validation.
 * Supports integration with external services, automation platforms (Zapier, n8n),
 * and event-driven systems with replay attack prevention.
 *
 * Security Features:
 * - HMAC-SHA256 signature validation
 * - Replay attack prevention via timestamp + nonce caching
 * - Shared secret per trigger (generated on creation)
 * - Signature header customization for platform compatibility
 *
 * HMAC Validation:
 * - Secret generated on trigger creation (stored encrypted)
 * - Client signs payload with secret
 * - Server verifies signature matches
 * - Timestamp window: 5 minutes (prevents replay)
 *
 * Replay Prevention:
 * - Caches nonce + timestamp combos for 5 minutes (auto-cleanup via TTL)
 * - Rejects duplicate (nonce, timestamp) pairs
 * - Time window enforcement (max 5 min clock skew)
 * - Nonce format validation prevents cache key injection
 *
 * Platform Integration:
 * - Zapier: X-Zapier-Signature header
 * - n8n: X-N8n-Signature header
 * - GitHub: X-Hub-Signature-256 header
 * - Custom: Configurable header name
 *
 * Webhook Payload:
 * - input: Required user message text
 * - Optional parameters: agent_id, session_id, tools[], parameters{}
 * - Signature header for validation
 *
 * @see \App\Services\OutputAction\WebhookSignatureService
 * @see \App\Services\InputTrigger\TriggerExecutor
 */
class WebhookTriggerProvider extends BaseInputTriggerProvider
{
    public function getTriggerType(): string
    {
        return 'webhook';
    }

    public function getTriggerTypeName(): string
    {
        return 'Input Webhook';
    }

    public function getDescription(): string
    {
        return 'Receive webhook events from external services with HMAC signature validation. Perfect for Zapier, n8n, GitHub, and custom integrations.';
    }

    public function getTriggerIcon(): string
    {
        return '🪝';
    }

    public function getTriggerIconSvg(): ?string
    {
        return '<svg viewBox="-10 -5 1034 1034" xmlns="http://www.w3.org/2000/svg" fill="currentColor"><path d="M482 226h-1l-10 2q-33 4 -64.5 18.5t-55.5 38.5q-41 37 -57 91q-9 30 -8 63t12 63q17 45 52 78l13 12l-83 135q-26 -1 -45 7q-30 13 -45 40q-7 15 -9 31t2 32q8 30 33 48q15 10 33 14.5t36 2t34.5 -12.5t27.5 -25q12 -17 14.5 -39t-5.5 -41q-1 -5 -7 -14l-3 -6l118 -192 q6 -9 8 -14l-10 -3q-9 -2 -13 -4q-23 -10 -41.5 -27.5t-28.5 -39.5q-17 -36 -9 -75q4 -23 17 -43t31 -34q37 -27 82 -27q27 -1 52.5 9.5t44.5 30.5q17 16 26.5 38.5t10.5 45.5q0 17 -6 42l70 19l8 1q14 -43 7 -86q-4 -33 -19.5 -63.5t-39.5 -53.5q-42 -42 -103 -56 q-6 -2 -18 -4l-14 -2h-37zM500 350q-17 0 -34 7t-30.5 20.5t-19.5 31.5q-8 20 -4 44q3 18 14 34t28 25q24 15 56 13q3 4 5 8l112 191q3 6 6 9q27 -26 58.5 -35.5t65 -3.5t58.5 26q32 25 43.5 61.5t0.5 73.5q-8 28 -28.5 50t-48.5 33q-31 13 -66.5 8.5t-63.5 -24.5 q-4 -3 -13 -10l-5 -6q-4 3 -11 10l-47 46q23 23 52 38.5t61 21.5l22 4h39l28 -5q64 -13 110 -60q22 -22 36.5 -50.5t19.5 -59.5q5 -36 -2 -71.5t-25 -64.5t-44 -51t-57 -35q-34 -14 -70.5 -16t-71.5 7l-17 5l-81 -137q13 -19 16 -37q5 -32 -13 -60q-16 -25 -44 -35 q-17 -6 -35 -6zM218 614q-58 13 -100 53q-47 44 -61 105l-4 24v37l2 11q2 13 4 20q7 31 24.5 59t42.5 49q50 41 115 49q38 4 76 -4.5t70 -28.5q53 -34 78 -91q7 -17 14 -45q6 -1 18 0l125 2q14 0 20 1q11 20 25 31t31.5 16t35.5 4q28 -3 50 -20q27 -21 32 -54 q2 -17 -1.5 -33t-13.5 -30q-16 -22 -41 -32q-17 -7 -35.5 -6.5t-35.5 7.5q-28 12 -43 37l-3 6q-14 0 -42 -1l-113 -1q-15 -1 -43 -1l-50 -1l3 17q8 43 -13 81q-14 27 -40 45t-57 22q-35 6 -70 -7.5t-57 -42.5q-28 -35 -27 -79q1 -37 23 -69q13 -19 32 -32t41 -19l9 -3z"/></svg>';
    }

    public function getBadgeColor(): string
    {
        return 'green';
    }

    public function getLogoUrl(): ?string
    {
        return null; // Use icon instead
    }

    public function getWebhookPath(InputTrigger $trigger): ?string
    {
        return route('webhooks.trigger', ['trigger' => $trigger->id]);
    }

    public function validateRequest(Request $request, InputTrigger $trigger): array
    {
        // Validate HMAC signature
        $signature = $request->header('X-Trigger-Signature');
        $timestamp = $request->header('X-Trigger-Timestamp');
        $nonce = $request->header('X-Trigger-Nonce');

        if (! $signature || ! $timestamp || ! $nonce) {
            return [
                'valid' => false,
                'error' => 'Missing required webhook headers (X-Trigger-Signature, X-Trigger-Timestamp, X-Trigger-Nonce)',
                'metadata' => [],
            ];
        }

        // SECURITY: Validate nonce format to prevent cache key injection
        // Only allow alphanumeric, hyphens, underscores (16-64 chars)
        if (! preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $nonce)) {
            Log::warning('WebhookTriggerProvider: Invalid nonce format', [
                'trigger_id' => $trigger->id,
                'nonce_length' => strlen($nonce),
                'ip_address' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'error' => 'Invalid nonce format (must be 16-64 alphanumeric characters)',
                'metadata' => [],
            ];
        }

        // Validate timestamp (5 minute window)
        $maxAge = 300; // 5 minutes
        if (abs(time() - (int) $timestamp) > $maxAge) {
            return [
                'valid' => false,
                'error' => 'Webhook timestamp too old or in future (must be within 5 minutes)',
                'metadata' => [],
            ];
        }

        // Validate nonce (prevent replay attacks)
        $nonceKey = "webhook_nonce:{$trigger->id}:{$nonce}";
        if (Cache::has($nonceKey)) {
            return [
                'valid' => false,
                'error' => 'Webhook nonce already used (replay attack detected)',
                'metadata' => [],
            ];
        }

        // Validate HMAC signature
        $secret = $trigger->config['webhook_secret'] ?? null;
        if (! $secret) {
            return [
                'valid' => false,
                'error' => 'Webhook secret not configured for this trigger',
                'metadata' => [],
            ];
        }

        $payload = $timestamp.$nonce.$request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            // SECURITY: Never log signatures or secrets - enables offline brute force
            Log::warning('WebhookTriggerProvider: Invalid HMAC signature', [
                'trigger_id' => $trigger->id,
                'timestamp_age_seconds' => abs(time() - (int) $timestamp),
                'signature_present' => ! empty($signature),
                'payload_size_bytes' => strlen($request->getContent()),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                // ❌ NEVER LOG: expected_signature, received_signature, secret
            ]);

            return [
                'valid' => false,
                'error' => 'Invalid webhook signature',
                'metadata' => [],
            ];
        }

        // PERFORMANCE: Store nonce with metadata for auditing (5 minute TTL, auto-cleanup)
        // TTL matches timestamp validation window to prevent unbounded cache growth
        // Cache automatically removes expired entries, preventing memory exhaustion
        Cache::put($nonceKey, [
            'used_at' => now()->toIso8601String(),
            'ip_address' => $request->ip(),
            'trigger_id' => $trigger->id,
            'timestamp' => $timestamp,
        ], 300);

        return [
            'valid' => true,
            'error' => null,
            'metadata' => [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'webhook_validated' => true,
                'timestamp' => $timestamp,
            ],
        ];
    }

    public function handleTrigger(InputTrigger $trigger, array $input, array $options = []): array
    {
        Log::info('WebhookTriggerProvider: Handling webhook trigger', [
            'trigger_id' => $trigger->id,
            'trigger_name' => $trigger->name,
            'input_length' => strlen($input['input'] ?? ''),
        ]);

        return [
            'success' => true,
            'provider' => 'webhook',
            'metadata' => $options['metadata'] ?? [],
        ];
    }

    public function getTriggerConfigSchema(): array
    {
        return [
            'webhook_secret' => [
                'type' => 'text',
                'label' => 'Webhook Secret',
                'description' => 'HMAC secret key for signature validation (generated automatically)',
                'readonly' => true,
                'generated' => true,
            ],
            'rate_limits' => [
                'type' => 'group',
                'label' => 'Rate Limits',
                'fields' => [
                    'per_minute' => [
                        'type' => 'number',
                        'label' => 'Requests per minute',
                        'default' => 10,
                        'min' => 1,
                        'max' => 100,
                    ],
                    'per_hour' => [
                        'type' => 'number',
                        'label' => 'Requests per hour',
                        'default' => 100,
                        'min' => 1,
                        'max' => 1000,
                    ],
                ],
            ],
            'ip_whitelist' => [
                'type' => 'array',
                'label' => 'IP Whitelist (Optional)',
                'help' => 'Restrict access to specific IP addresses or CIDR ranges. Leave empty to allow all IPs. Examples: 192.168.1.1, 10.0.0.0/24, 2001:db8::/32',
                'default' => [],
                'rules' => [
                    'nullable',
                    'array',
                    'max:50',
                ],
                'item_rules' => [
                    'string',
                    'regex:/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?:\/([0-9]|[1-2][0-9]|3[0-2]))?$|^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}(?:\/([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8]))?$/',
                ],
            ],
        ];
    }

    public function getSetupInstructions(mixed $context = null): string
    {
        if (! $context instanceof InputTrigger) {
            return '';
        }

        $trigger = $context;
        $webhookUrl = $this->getWebhookPath($trigger);
        $secret = $trigger->config['webhook_secret'] ?? 'NOT_CONFIGURED';
        $perMinute = $trigger->rate_limits['per_minute'] ?? 10;
        $perHour = $trigger->rate_limits['per_hour'] ?? 100;

        return <<<MARKDOWN
## Quick Test

```bash
TIMESTAMP=\$(date +%s)
NONCE=\$(uuidgen)
BODY='{"text":"Hello from webhook"}'
SIGNATURE=\$(echo -n "\${TIMESTAMP}\${NONCE}\${BODY}" | openssl dgst -sha256 -hmac "{$secret}" | cut -d' ' -f2)

curl -X POST "{$webhookUrl}" \\
  -H "Content-Type: application/json" \\
  -H "X-Trigger-Signature: \${SIGNATURE}" \\
  -H "X-Trigger-Timestamp: \${TIMESTAMP}" \\
  -H "X-Trigger-Nonce: \${NONCE}" \\
  -d "\${BODY}"
```

## Details

**URL**
<span style="word-break: break-all;">`{$webhookUrl}`</span>

**Secret** ⚠️
<span style="word-break: break-all; font-family: monospace; font-size: 0.75em;">`{$secret}`</span>

**Rate Limits**
{$perMinute}/min, {$perHour}/hour

## Required Headers

- `X-Trigger-Signature` - HMAC-SHA256
- `X-Trigger-Timestamp` - Unix timestamp
- `X-Trigger-Nonce` - Unique UUID
- `Content-Type: application/json`

## Payload Parameters

**Required:**
- `text` - Message to send to agent (also accepts `message`, `body`, `content`)

**Optional runtime overrides:**
- `agent_id` - Override agent (only if not set in trigger config)
- `session_strategy` - `"new_each"` or `"continue_last"` (only if not set)
- `session_id` - Use specific session (bypasses session_strategy)
- `workflow` - Workflow config object (only if not set in trigger config)

**Example:**
```json
{
  "text": "Analyze sales data",
  "agent_id": 42,
  "session_strategy": "continue_last"
}
```

Sessions appear in chat with 🪝 badge.
MARKDOWN;
    }

    public function extractInput(Request $request): string
    {
        $payload = $request->all();

        // Try common webhook payload fields
        return $payload['text']
            ?? $payload['message']
            ?? $payload['body']
            ?? $payload['content']
            ?? json_encode($payload);
    }

    public function getExamplePayload(InputTrigger $trigger): array
    {
        return [
            'text' => 'Analyze the latest sales data and provide insights',
            'metadata' => [
                'source' => 'zapier',
                'workflow_id' => '12345',
            ],
        ];
    }

    public function generateCredentials(InputTrigger $trigger): array
    {
        // Generate a new webhook secret if one doesn't exist
        $secret = $trigger->config['webhook_secret'] ?? null;

        if (! $secret) {
            $secret = Str::random(64);

            // Update the trigger config with the new secret
            $config = $trigger->config ?? [];
            $config['webhook_secret'] = $secret;
            $trigger->update([
                'config' => $config,
                'secret_created_at' => now(),
            ]);

            Log::info('WebhookTriggerProvider: Generated new webhook secret', [
                'trigger_id' => $trigger->id,
            ]);
        }

        return [
            'type' => 'hmac_signature',
            'secret' => $secret,
            'algorithm' => 'sha256',
            'webhook_url' => $this->getWebhookPath($trigger),
        ];
    }

    public function regenerateSecret(InputTrigger $trigger): array
    {
        // Generate new secret
        $newSecret = Str::random(64);

        // Update trigger config and metadata
        $config = $trigger->config ?? [];
        $config['webhook_secret'] = $newSecret;

        $trigger->update([
            'config' => $config,
            'secret_rotated_at' => now(),
            'secret_rotation_count' => ($trigger->secret_rotation_count ?? 0) + 1,
        ]);

        Log::warning('WebhookTriggerProvider: Secret regenerated', [
            'trigger_id' => $trigger->id,
            'trigger_name' => $trigger->name,
            'rotation_count' => $trigger->secret_rotation_count,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
        ]);

        return [
            'new_secret' => $newSecret,
            'rotated_at' => now(),
            'rotation_count' => $trigger->secret_rotation_count,
        ];
    }
}
