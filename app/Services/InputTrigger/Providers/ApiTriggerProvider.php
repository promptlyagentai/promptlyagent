<?php

namespace App\Services\InputTrigger\Providers;

use App\Models\InputTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API Trigger Provider - REST API Agent Invocation.
 *
 * Enables programmatic agent invocation via REST API using Laravel Sanctum
 * token authentication. Perfect for custom applications, automation scripts,
 * CI/CD pipelines, and third-party integrations.
 *
 * Authentication:
 * - Bearer token via Authorization header
 * - Sanctum personal access tokens
 * - Token tied to user account (user_id)
 * - Scopes control API access permissions
 *
 * API Endpoint:
 * - POST /api/triggers/{trigger}/invoke
 * - Content-Type: application/json
 * - Authorization: Bearer {token}
 *
 * Request Payload:
 * - input: Required user message (string)
 * - agent_id: Override default agent (optional)
 * - session_id: Use specific session (optional)
 * - session_strategy: new|existing|latest (optional)
 * - tools[]: Override tools (optional, validated)
 * - parameters{}: Custom parameters (optional)
 * - files[]: Uploaded files (optional, validated)
 *
 * Response Formats:
 * - JSON: Immediate response with ChatInteraction
 * - SSE: Real-time streaming via StreamingTriggerExecutor
 *
 * Use Cases:
 * - Slack bots, Discord bots, Telegram bots
 * - GitHub Actions, GitLab CI, Jenkins
 * - Custom dashboards and applications
 * - Automation platforms (Make, Zapier)
 *
 * @see \App\Services\InputTrigger\TriggerExecutor
 * @see \App\Services\InputTrigger\StreamingTriggerExecutor
 * @see \App\Http\Controllers\Api\InputTriggerController
 */
class ApiTriggerProvider extends BaseInputTriggerProvider
{
    public function getTriggerType(): string
    {
        return 'api';
    }

    public function getTriggerTypeName(): string
    {
        return 'API';
    }

    public function getDescription(): string
    {
        return 'Invoke agents via REST API using token authentication. Perfect for integrating with custom applications, automation scripts, and CI/CD pipelines.';
    }

    public function getTriggerIcon(): string
    {
        return '🔗';
    }

    public function getTriggerIconSvg(): ?string
    {
        return '<svg fill="currentColor" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><path d="M8,9H4a2,2,0,0,0-2,2V23H4V18H8v5h2V11A2,2,0,0,0,8,9ZM4,16V11H8v5Z"></path><polygon points="22 11 25 11 25 21 22 21 22 23 30 23 30 21 27 21 27 11 30 11 30 9 22 9 22 11"></polygon><path d="M14,23H12V9h6a2,2,0,0,1,2,2v5a2,2,0,0,1-2,2H14Zm0-7h4V11H14Z"></path></svg>';
    }

    public function getBadgeColor(): string
    {
        return 'blue';
    }

    public function getLogoUrl(): ?string
    {
        return null; // Use icon instead
    }

    public function validateRequest(Request $request, InputTrigger $trigger): array
    {
        // API triggers use Sanctum middleware for authentication
        // This is called after auth, so just validate the request structure

        $errors = [];

        if (! $request->has('input')) {
            $errors['input'] = 'Input field is required';
        }

        if ($request->has('input') && strlen($request->input('input')) > 10000) {
            $errors['input'] = 'Input exceeds maximum length of 10,000 characters';
        }

        if ($request->has('options.workflow') && ! is_array($request->input('options.workflow'))) {
            $errors['options.workflow'] = 'Workflow must be a valid JSON object';
        }

        if (! empty($errors)) {
            return [
                'valid' => false,
                'error' => 'Validation failed',
                'errors' => $errors,
                'metadata' => [],
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'metadata' => [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'api_version' => 'v1',
            ],
        ];
    }

    public function handleTrigger(InputTrigger $trigger, array $input, array $options = []): array
    {
        // This method is called by the TriggerExecutor
        // It doesn't need to do anything special for API triggers
        // Just return success - the TriggerExecutor handles the actual execution

        Log::info('ApiTriggerProvider: Handling API trigger', [
            'trigger_id' => $trigger->id,
            'trigger_name' => $trigger->name,
            'input_length' => strlen($input['input'] ?? ''),
        ]);

        return [
            'success' => true,
            'provider' => 'api',
            'metadata' => $options['metadata'] ?? [],
        ];
    }

    public function getTriggerConfigSchema(): array
    {
        return [
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
                    'per_day' => [
                        'type' => 'number',
                        'label' => 'Requests per day',
                        'default' => 1000,
                        'min' => 1,
                        'max' => 10000,
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
        $apiUrl = $trigger->generateApiUrl();
        $perMinute = $trigger->rate_limits['per_minute'] ?? 10;
        $perHour = $trigger->rate_limits['per_hour'] ?? 100;
        $perDay = $trigger->rate_limits['per_day'] ?? 1000;

        return <<<MARKDOWN
# API Trigger Setup

Your API trigger is ready to use. Here's how to invoke it:

## Endpoint

```
POST {$apiUrl}
```

## Authentication

Include your API token in the Authorization header:

```
Authorization: Bearer YOUR_API_TOKEN
```

**Generate a token:** Go to Settings → API Tokens → Create Token

Select the following abilities:
- `trigger:invoke` - Required to invoke triggers
- `trigger:status` - Optional, to check execution status

## Request Format

### Simple Invocation

```bash
curl -X POST {$apiUrl} \\
  -H "Authorization: Bearer YOUR_API_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{
    "input": "What is quantum computing?"
  }'
```

### With Workflow

```bash
curl -X POST {$apiUrl} \\
  -H "Authorization: Bearer YOUR_API_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{
    "input": "Research quantum computing and create a report",
    "options": {
      "workflow": {
        "originalQuery": "Research quantum computing",
        "strategyType": "sequential",
        "stages": [
          {
            "type": "sequential",
            "nodes": [
              {
                "agentId": 2,
                "agentName": "Research Assistant",
                "input": "Research quantum computing comprehensively",
                "rationale": "Gather information"
              }
            ]
          }
        ]
      }
    }
  }'
```

### Continue Existing Session

```bash
curl -X POST {$apiUrl} \\
  -H "Authorization: Bearer YOUR_API_TOKEN" \\
  -H "Content-Type: application/json" \\
  -d '{
    "input": "Follow-up question...",
    "options": {
      "session_id": 123
    }
  }'
```

## Response Format

```json
{
  "success": true,
  "session_id": 456,
  "interaction_id": 789,
  "execution_id": 101,
  "status": "completed",
  "result": {
    "answer": "...",
    "artifacts": [...],
    "sources": [...]
  },
  "chat_url": "https://app.example.com/chat/sessions/456"
}
```

## Rate Limits

- **Per minute:** {$perMinute} requests
- **Per hour:** {$perHour} requests
- **Per day:** {$perDay} requests

## Viewing Results

All API-triggered sessions appear in your chat interface with a 🔗 API badge. You can:
- View real-time progress
- Continue the conversation
- See complete history with artifacts and sources
MARKDOWN;
    }

    public function extractInput(Request $request): string
    {
        return $request->input('input', '');
    }

    public function getExamplePayload(InputTrigger $trigger): array
    {
        return [
            'input' => 'What are the latest developments in AI?',
            'options' => [
                'session_name' => 'My API Research Session',
                'agent_id' => $trigger->agent_id,
                'async' => true,
            ],
        ];
    }

    public function generateCredentials(InputTrigger $trigger): array
    {
        // API triggers use Sanctum tokens, not provider-specific credentials
        return [
            'type' => 'sanctum_token',
            'message' => 'Generate a Sanctum API token with trigger:invoke ability in Settings → API Tokens',
            'required_abilities' => ['trigger:invoke', 'trigger:status'],
        ];
    }

    public function requiresApiToken(): bool
    {
        return true; // API triggers require a Sanctum token
    }

    public function getRequiredTokenAbilities(): array
    {
        return ['trigger:invoke']; // Required ability for API trigger invocation
    }

    public function getApiTokenSetupRoute(): string
    {
        return 'settings.api-tokens';
    }

    public function getApiTokenMissingMessage(): string
    {
        return 'You need to create an API token with "trigger:invoke" ability first. Please create a token below.';
    }
}
