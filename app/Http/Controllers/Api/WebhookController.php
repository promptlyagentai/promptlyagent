<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InputTrigger;
use App\Services\InputTrigger\InputTriggerRegistry;
use App\Services\InputTrigger\TriggerExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @group Webhooks
 *
 * Invoke input triggers via webhooks using HMAC signature authentication. Webhooks provide
 * an alternative to API tokens for automated trigger invocation from external systems.
 *
 * ## Authentication
 * Webhooks use **HMAC-SHA256 signature validation** instead of Sanctum tokens:
 * - **X-Webhook-Signature**: HMAC signature of request body
 * - **X-Webhook-Timestamp**: Unix timestamp (prevents replay attacks)
 * - **X-Webhook-Nonce**: Unique request identifier
 *
 * **Signature Generation:**
 * ```
 * payload = timestamp + ":" + nonce + ":" + request_body
 * signature = HMAC-SHA256(payload, secret_key)
 * ```
 *
 * ## Trigger Types
 * - **Agent Triggers**: Execute agent with webhook payload as input
 * - **Command Triggers**: Execute triggerable command with webhook parameters
 *
 * ## Rate Limiting
 * - Webhook invocations: 60 requests/minute per trigger
 * - Ping endpoint: 120 requests/minute per trigger
 *
 * ## Use Cases
 * - GitHub/GitLab webhook integration for CI/CD automation
 * - External system notifications triggering agent workflows
 * - Third-party service callbacks (payment processors, CRMs, etc.)
 * - Scheduled jobs from external cron services
 */
class WebhookController extends Controller
{
    public function __construct(
        private TriggerExecutor $executor,
        private InputTriggerRegistry $registry
    ) {}

    /**
     * Invoke webhook trigger
     *
     * Execute an input trigger via webhook invocation. The trigger processes the webhook
     * payload asynchronously and returns immediately with a 202 Accepted response.
     *
     * **Agent Triggers**: Webhook payload is passed as the chat input to the agent.
     * **Command Triggers**: Webhook payload parameters are mapped to command arguments.
     *
     * **Security:** All requests must include valid HMAC signature headers or will be rejected
     * with 401 Unauthorized. Disabled triggers return 403 Forbidden.
     *
     * @unauthenticated
     *
     * @urlParam trigger integer required The trigger ID or slug. Example: 1
     *
     * @header X-Webhook-Signature required HMAC-SHA256 signature of the request. Example: a1b2c3d4e5f6...
     * @header X-Webhook-Timestamp required Unix timestamp when request was created. Example: 1704067200
     * @header X-Webhook-Nonce required Unique identifier for this request (prevents replay). Example: uuid-1234-5678
     *
     * @bodyParam * mixed Webhook payload (format depends on trigger type). Agent triggers accept any JSON. Command triggers expect specific parameters defined by the command. Example: {"repository": "owner/repo", "event": "push", "branch": "main"}
     *
     * @response 202 scenario="Agent Trigger Accepted" {"success": true, "message": "Webhook received and processing", "invocation_id": "inv_abc123", "session_id": 1, "interaction_id": 5, "status": "dispatched", "status_url": "/api/status/inv_abc123", "chat_url": "/chat/1"}
     * @response 202 scenario="Command Trigger Accepted" {"success": true, "message": "Command execution dispatched", "trigger_id": 1, "trigger_type": "command", "command_class": "App\\Console\\Commands\\ProcessData", "status": "dispatched", "queue": "research-coordinator"}
     * @response 400 scenario="Invalid Trigger Type" {"success": false, "error": "Invalid Endpoint", "message": "This trigger does not accept webhook invocations"}
     * @response 401 scenario="Invalid Signature" {"success": false, "error": "Validation Failed", "message": "HMAC signature verification failed"}
     * @response 403 scenario="Trigger Disabled" {"success": false, "error": "Trigger Disabled", "message": "This webhook endpoint is currently disabled"}
     * @response 422 scenario="Invalid Input" {"success": false, "error": "Invalid Input", "message": "Missing required parameter: repository"}
     * @response 500 scenario="Processing Failed" {"success": false, "error": "Processing Failed", "message": "An error occurred while processing the webhook"}
     *
     * @responseField success boolean Indicates if the webhook was accepted
     * @responseField message string Human-readable status message
     * @responseField invocation_id string Unique invocation identifier (agent triggers only)
     * @responseField session_id integer Chat session ID (agent triggers only)
     * @responseField interaction_id integer Chat interaction ID (agent triggers only)
     * @responseField status string Processing status (dispatched, queued)
     * @responseField status_url string URL to check execution status (agent triggers only)
     * @responseField chat_url string URL to view chat session (agent triggers only)
     * @responseField trigger_id integer Trigger ID (command triggers only)
     * @responseField trigger_type string Trigger type (command triggers only)
     * @responseField command_class string Command class name (command triggers only)
     * @responseField queue string Queue name where job was dispatched (command triggers only)
     */
    public function handle(Request $request, InputTrigger $trigger): JsonResponse
    {
        try {

            // Check if trigger is active
            if (! $trigger->is_active) {
                Log::warning('WebhookController: Trigger is disabled', [
                    'trigger_id' => $trigger->id,
                    'trigger_name' => $trigger->name,
                    'provider_id' => $trigger->provider_id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Trigger Disabled',
                    'message' => 'This webhook endpoint is currently disabled',
                ], 403);
            }

            // Validate provider is webhook
            if ($trigger->provider_id !== 'webhook') {
                Log::error('WebhookController: Invalid provider type', [
                    'trigger_id' => $trigger->id,
                    'provider_id' => $trigger->provider_id,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Invalid Endpoint',
                    'message' => 'This trigger does not accept webhook invocations',
                ], 400);
            }

            // Get webhook provider
            $provider = $this->registry->getProvider('webhook');
            if (! $provider) {
                Log::error('WebhookController: Webhook provider not registered');

                return response()->json([
                    'success' => false,
                    'error' => 'Server Configuration Error',
                    'message' => 'Webhook provider is not available',
                ], 500);
            }

            // Validate webhook request (HMAC signature, timestamp, nonce)
            $validation = $provider->validateRequest($request, $trigger);
            if (! $validation['valid']) {
                Log::warning('WebhookController: Webhook validation failed', [
                    'trigger_id' => $trigger->id,
                    'error' => $validation['error'],
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Validation Failed',
                    'message' => $validation['error'],
                ], 401);
            }

            // Extract input from webhook payload
            // For command triggers: pass raw request data as parameters
            // For agent triggers: wrap extracted input in 'input' key for chat interaction
            if ($trigger->isCommandTrigger()) {
                // Get all request input as command parameters
                $requestData = $request->all();

                // Filter to only include parameters expected by the command
                $commandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
                $commandDef = $commandRegistry->getByClass($trigger->command_class);

                $input = [];
                if ($commandDef && isset($commandDef['parameters'])) {
                    foreach ($commandDef['parameters'] as $paramName => $paramDef) {
                        // Skip user-id (set automatically by job)
                        if ($paramName === 'user-id') {
                            continue;
                        }

                        // Include parameter if present in request and not empty
                        if (array_key_exists($paramName, $requestData)) {
                            $value = $requestData[$paramName];

                            // Skip empty values for optional parameters
                            // Required parameters will be validated later
                            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                                continue;
                            }

                            $input[$paramName] = $value;
                        }
                    }
                }
            } else {
                $input = ['input' => $provider->extractInput($request)];
            }

            // Prepare options
            $options = [
                'metadata' => $validation['metadata'],
                'api_version' => 'webhook-v1',
            ];

            // Execute trigger asynchronously to enable real-time updates
            // Job execution allows frontend to subscribe before execution starts
            $result = $this->executor->executeAsync($trigger, $input, $options);

            // Log based on trigger type
            if ($trigger->isCommandTrigger()) {
                Log::info('WebhookController: Command webhook received and dispatched', [
                    'trigger_id' => $trigger->id,
                    'command_class' => $result['command_class'] ?? null,
                    'status' => $result['status'] ?? 'dispatched',
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'Command execution dispatched',
                    'trigger_id' => $trigger->id,
                    'trigger_type' => 'command',
                    'command_class' => $result['command_class'] ?? null,
                    'status' => $result['status'] ?? 'dispatched',
                    'queue' => $result['queue'] ?? 'research-coordinator',
                ], 202); // 202 Accepted for async processing
            } else {
                Log::info('WebhookController: Webhook received and queued for processing', [
                    'trigger_id' => $trigger->id,
                    'interaction_id' => $result['interaction_id'],
                    'session_id' => $result['session_id'],
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Webhook received and processing',
                    'invocation_id' => $result['invocation_id'],
                    'session_id' => $result['session_id'],
                    'interaction_id' => $result['interaction_id'],
                    'status' => $result['status'],
                    'status_url' => $result['status_url'],
                    'chat_url' => $result['chat_url'],
                ], 202); // 202 Accepted for async processing
            }

        } catch (\InvalidArgumentException $e) {
            Log::warning('WebhookController: Invalid input', [
                'trigger_id' => $trigger->id,
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid Input',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('WebhookController: Webhook processing failed', [
                'trigger_id' => $trigger->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Processing Failed',
                'message' => 'An error occurred while processing the webhook',
            ], 500);
        }
    }

    /**
     * Test webhook connectivity
     *
     * Ping endpoint to verify webhook configuration and accessibility. Use this to test
     * webhook URLs, verify trigger status, and confirm HMAC signature validation works.
     *
     * Unlike the main webhook endpoint, ping does not execute the trigger or require
     * HMAC signature validation, making it safe for testing and health checks.
     *
     * @unauthenticated
     *
     * @urlParam trigger integer required The trigger ID or slug. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "message": "Webhook endpoint is reachable", "trigger": {"name": "GitHub Push Webhook", "is_active": true, "last_invoked_at": "2024-01-01T12:00:00Z"}}
     * @response 400 scenario="Not a Webhook Trigger" {"success": false, "error": "Invalid Endpoint", "message": "This is not a webhook trigger"}
     *
     * @responseField success boolean Indicates if the endpoint is reachable
     * @responseField message string Confirmation message
     * @responseField trigger object Trigger information
     * @responseField trigger.name string Trigger name
     * @responseField trigger.is_active boolean Whether trigger is currently active
     * @responseField trigger.last_invoked_at string Last invocation timestamp (ISO 8601, null if never invoked)
     */
    public function ping(InputTrigger $trigger): JsonResponse
    {
        // Verify this is a webhook trigger
        if ($trigger->provider_id !== 'webhook') {
            return response()->json([
                'success' => false,
                'error' => 'Invalid Endpoint',
                'message' => 'This is not a webhook trigger',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook endpoint is reachable',
            'trigger' => [
                'name' => $trigger->name,
                'is_active' => $trigger->is_active,
                'last_invoked_at' => $trigger->last_invoked_at,
            ],
        ], 200);
    }
}
