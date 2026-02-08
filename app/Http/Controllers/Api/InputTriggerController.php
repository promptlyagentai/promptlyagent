<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FileValidationException;
use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\InputTrigger;
use App\Services\FileUploadService;
use App\Services\InputTrigger\InputTriggerRegistry;
use App\Services\InputTrigger\StreamingTriggerExecutor;
use App\Services\InputTrigger\TriggerExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Input Triggers
 *
 * Input Triggers provide webhook-based automation for invoking AI agents.
 * Execute triggers via REST API with Sanctum authentication and optional IP whitelisting.
 *
 * ## Authentication & Authorization
 * Required token abilities:
 * - `trigger:invoke` - Execute triggers
 * - `trigger:attach` - Upload file attachments
 * - `trigger:tools` - Override tool selection
 * - `trigger:status` - View trigger metadata
 *
 * ## Execution Modes
 * - **Synchronous** (`/invoke`): Returns complete response after execution
 * - **Streaming** (`/stream`): Real-time SSE streaming with step-by-step updates
 *
 * ## Rate Limiting
 * - Expensive operations (invoke, stream): 10 requests/minute
 * - Read operations (index, show): 300 requests/minute
 */
class InputTriggerController extends Controller
{
    public function __construct(
        private TriggerExecutor $executor,
        private StreamingTriggerExecutor $streamingExecutor,
        private InputTriggerRegistry $registry,
        private FileUploadService $fileUploadService
    ) {}

    /**
     * Execute trigger synchronously
     *
     * Invoke an input trigger and wait for the complete response. Supports both synchronous
     * and asynchronous execution modes. For real-time streaming responses, use the `/stream` endpoint instead.
     *
     * The request format depends on the trigger's provider. Common providers include:
     * - `direct_text`: Simple text input via `input` parameter
     * - `schedule`: No input required (scheduled triggers)
     * - Custom providers: May require specific fields
     *
     * @authenticated
     *
     * @urlParam trigger integer required The trigger ID. Example: 1
     *
     * @bodyParam input string Optional text input (provider-dependent). Maximum 50,000 characters. Example: Analyze this data
     * @bodyParam options object Optional execution options and metadata. Example: {"temperature": 0.7}
     * @bodyParam async boolean Optional execute asynchronously and return immediately. Defaults to false. Example: false
     *
     * @response 200 scenario="Success (Sync)" {"success": true, "result": {"answer": "Analysis complete. Here are the findings...", "sources": [], "artifacts": []}, "execution_time": 5.2}
     * @response 200 scenario="Success (Async)" {"success": true, "execution_id": 456, "status": "queued", "message": "Trigger execution started asynchronously"}
     * @response 403 scenario="Missing trigger:invoke ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:invoke ability"}
     * @response 403 scenario="Not Trigger Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to invoke this trigger"}
     * @response 403 scenario="Trigger Disabled" {"success": false, "error": "Trigger Disabled", "message": "This trigger is currently disabled"}
     * @response 422 scenario="Validation Failed" {"success": false, "error": "Validation Failed", "message": "Invalid input format", "errors": {"input": ["The input field is required"]}}
     * @response 422 scenario="Invalid Input" {"success": false, "error": "Invalid Input", "message": "Input exceeds maximum length"}
     * @response 500 scenario="Provider Not Found" {"success": false, "error": "Provider Not Found", "message": "Provider 'custom_provider' is not registered"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField result object Execution result (synchronous mode only)
     * @responseField result.answer string AI agent's response
     * @responseField result.sources array Knowledge sources referenced
     * @responseField result.artifacts array Generated artifacts (code, documents)
     * @responseField execution_time number Execution duration in seconds
     * @responseField execution_id integer Execution ID (async mode only)
     * @responseField status string Execution status: queued, processing, completed, failed (async mode only)
     */
    public function invoke(Request $request, InputTrigger $trigger): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('trigger:invoke')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:invoke ability',
                ], 403);
            }

            // Verify ownership
            if ($trigger->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to invoke this trigger',
                ], 403);
            }

            // Check if trigger is active
            if (! $trigger->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => 'Trigger Disabled',
                    'message' => 'This trigger is currently disabled',
                ], 403);
            }

            // Validate request using provider
            $provider = $this->registry->getProvider($trigger->provider_id);
            if (! $provider) {
                return response()->json([
                    'success' => false,
                    'error' => 'Provider Not Found',
                    'message' => "Provider '{$trigger->provider_id}' is not registered",
                ], 500);
            }

            $validation = $provider->validateRequest($request, $trigger);
            if (! $validation['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation Failed',
                    'message' => $validation['error'],
                    'errors' => $validation['errors'] ?? [],
                ], 422);
            }

            // Extract input
            $input = ['input' => $provider->extractInput($request)];

            // Extract options
            $options = array_merge(
                $request->input('options', []),
                ['metadata' => $validation['metadata']]
            );

            // Check if async execution requested
            $async = $request->boolean('async', false);

            // Execute trigger
            if ($async) {
                $result = $this->executor->executeAsync($trigger, $input, $options);
            } else {
                $result = $this->executor->execute($trigger, $input, $options);
            }

            Log::info('InputTriggerController: Trigger invoked successfully', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'async' => $async,
            ]);

            return response()->json($result, 200);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid Input',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('InputTriggerController: Trigger invocation failed', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Rethrow to let global exception handler sanitize the response
            throw $e;
        }
    }

    /**
     * View a trigger's details
     *
     * Retrieve comprehensive information about a specific input trigger including configuration,
     * usage statistics, and provider metadata.
     *
     * @authenticated
     *
     * @urlParam trigger integer required The trigger ID. Example: 1
     *
     * @response 200 scenario="Success" {"success": true, "trigger": {"id": 1, "name": "Daily Report Generator", "description": "Generates daily analytics reports", "provider": "schedule", "is_active": true, "agent_id": 5, "agent_name": "Report Agent", "session_strategy": "continue_last", "usage_count": 42, "last_invoked_at": "2024-01-01T10:00:00Z", "rate_limits": {"max_per_minute": 10}, "created_at": "2023-12-01T00:00:00Z"}, "provider_info": {"name": "Schedule", "description": "Scheduled triggers", "icon": "⏰"}}
     * @response 403 scenario="Missing trigger:status ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:status ability"}
     * @response 403 scenario="Not Trigger Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to view this trigger"}
     * @response 404 scenario="Trigger Not Found" {"success": false, "error": "Not Found", "message": "Trigger not found"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while retrieving trigger information"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField trigger object Trigger details
     * @responseField trigger.id integer Trigger ID
     * @responseField trigger.name string Trigger name
     * @responseField trigger.description string Trigger description
     * @responseField trigger.provider string Provider ID (schedule, direct_text, webhook, etc.)
     * @responseField trigger.is_active boolean Whether trigger is currently active
     * @responseField trigger.agent_id integer Associated agent ID
     * @responseField trigger.agent_name string Associated agent name
     * @responseField trigger.session_strategy string Session handling strategy (new_each, continue_last, specified)
     * @responseField trigger.usage_count integer Number of times trigger has been invoked
     * @responseField trigger.last_invoked_at string Last invocation timestamp (ISO 8601) or null
     * @responseField trigger.rate_limits object Rate limiting configuration
     * @responseField trigger.created_at string Creation timestamp (ISO 8601)
     * @responseField provider_info object Provider metadata (name, description, icon)
     */
    public function show(Request $request, InputTrigger $trigger): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('trigger:status')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:status ability',
                ], 403);
            }

            // Verify ownership
            if (! $trigger) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not Found',
                    'message' => 'Trigger not found',
                ], 404);
            }

            // Verify ownership
            if ($trigger->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to view this trigger',
                ], 403);
            }

            // Get provider metadata
            $provider = $this->registry->getProvider($trigger->provider_id);
            $providerMetadata = $provider ? $this->registry->getTriggerMetadata($trigger->provider_id) : null;

            return response()->json([
                'success' => true,
                'trigger' => [
                    'id' => $trigger->id,
                    'name' => $trigger->name,
                    'description' => $trigger->description,
                    'provider' => $trigger->provider_id,
                    'is_active' => $trigger->is_active,
                    'agent_id' => $trigger->agent_id,
                    'agent_name' => $trigger->agent?->name,
                    'session_strategy' => $trigger->session_strategy,
                    'usage_count' => $trigger->usage_count,
                    'last_invoked_at' => $trigger->last_invoked_at,
                    'rate_limits' => $trigger->rate_limits,
                    'created_at' => $trigger->created_at,
                ],
                'provider_info' => $providerMetadata,
            ], 200);

        } catch (\Exception $e) {
            Log::error('InputTriggerController: Failed to retrieve trigger', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving trigger information',
            ], 500);
        }
    }

    /**
     * List all triggers
     *
     * Retrieve all input triggers for the authenticated user with basic information and API URLs.
     * Results are ordered by creation date (newest first).
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"success": true, "triggers": [{"id": 1, "name": "Daily Report Generator", "description": "Generates daily analytics reports", "provider": "schedule", "is_active": true, "agent_name": "Report Agent", "usage_count": 42, "last_invoked_at": "2024-01-01T10:00:00Z", "api_url": "https://promptlyagent.com/api/input-triggers/1"}]}
     * @response 403 scenario="Missing trigger:status ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:status ability"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while retrieving triggers"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField triggers array Array of triggers
     * @responseField triggers[].id integer Trigger ID
     * @responseField triggers[].name string Trigger name
     * @responseField triggers[].description string Trigger description
     * @responseField triggers[].provider string Provider ID
     * @responseField triggers[].is_active boolean Whether trigger is active
     * @responseField triggers[].agent_name string Associated agent name
     * @responseField triggers[].usage_count integer Number of invocations
     * @responseField triggers[].last_invoked_at string Last invocation timestamp (ISO 8601) or null
     * @responseField triggers[].api_url string Full API URL for invoking this trigger
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('trigger:status')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:status ability',
                ], 403);
            }

            $triggers = InputTrigger::where('user_id', Auth::id())
                ->with('agent:id,name')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($trigger) {
                    return [
                        'id' => $trigger->id,
                        'name' => $trigger->name,
                        'description' => $trigger->description,
                        'provider' => $trigger->provider_id,
                        'is_active' => $trigger->is_active,
                        'agent_name' => $trigger->agent?->name,
                        'usage_count' => $trigger->usage_count,
                        'last_invoked_at' => $trigger->last_invoked_at,
                        'api_url' => $trigger->generateApiUrl(),
                    ];
                });

            return response()->json([
                'success' => true,
                'triggers' => $triggers,
            ], 200);

        } catch (\Exception $e) {
            Log::error('InputTriggerController: Failed to list triggers', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving triggers',
            ], 500);
        }
    }

    /**
     * Execute trigger with real-time SSE streaming
     *
     * Invoke an input trigger and stream the AI response in real-time using Server-Sent Events (SSE).
     * Supports file attachments, tool overrides, and session continuity.
     *
     * The stream returns various event types (message, tool_call, source, artifact, heartbeat, complete, error)
     * with JSON-encoded data payloads. Connections are kept alive with periodic heartbeats.
     *
     * ## Example Usage
     *
     * **Trigger API Client** ([github.com/promptlyagentai/trigger-api-client](https://github.com/promptlyagentai/trigger-api-client)) - Full-featured Python CLI:
     * - Three operation modes: Interactive TUI, Direct, and JSON output
     * - Real-time SSE streaming with live status updates
     * - File attachment support (up to 5MB per file)
     * - Session management and conversation history
     * - Rich markdown rendering in TUI mode
     * - Requires `trigger:invoke`, `trigger:status`, and `trigger:attach` token abilities
     *
     * @authenticated
     *
     * @urlParam trigger integer required The trigger ID. Example: 1
     *
     * @bodyParam input string Optional text input (provider-dependent). Maximum 50,000 characters. Example: Analyze this data
     * @bodyParam attachments file[] Optional file attachments (requires `trigger:attach` token ability). Maximum 10 files, 50MB each. No-example
     * @bodyParam tools string[] Optional tool override (requires `trigger:tools` token ability). Array of tool names to enable. Example: ["web_search", "calculator"]
     * @bodyParam options object Optional execution options and metadata. Example: {"temperature": 0.7}
     *
     * @response 200 scenario="SSE Stream" event: message
     * data: {"type": "text", "content": "Analysis complete. Here are the key findings...", "delta": true}
     *
     * event: tool_call
     * data: {"tool": "search_knowledge", "arguments": {"query": "data analysis"}}
     *
     * event: source
     * data: {"title": "Data Analysis Guide", "url": "https://example.com/guide", "domain": "example.com"}
     *
     * event: heartbeat
     * data: {"timestamp": 1704067200}
     *
     * event: complete
     * data: {"status": "completed", "duration_seconds": 8}
     * @response 403 scenario="Missing trigger:invoke ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:invoke ability"}
     * @response 403 scenario="Missing trigger:attach for files" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:attach ability required for file uploads"}
     * @response 403 scenario="Missing trigger:tools for override" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:tools ability required for tool override"}
     * @response 403 scenario="Not Trigger Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to invoke this trigger"}
     * @response 403 scenario="Trigger Disabled" {"success": false, "error": "Trigger Disabled", "message": "This trigger is currently disabled"}
     * @response 422 scenario="File Validation Failed" {"success": false, "error": "File Validation Failed", "message": "File type not allowed: executable"}
     * @response 422 scenario="Invalid Tool Override" {"success": false, "error": "Invalid Tool Override", "message": "Tool 'invalid_tool' not available for this agent", "details": []}
     * @response 422 scenario="Validation Failed" {"success": false, "error": "Validation Failed", "message": "Invalid input format", "errors": {}}
     * @response 500 scenario="Provider Not Found" {"success": false, "error": "Provider Not Found", "message": "Provider 'custom_provider' is not registered"}
     *
     * @responseField event string The SSE event type (message, tool_call, source, artifact, heartbeat, complete, error)
     * @responseField data string JSON-encoded event data. Structure varies by event type.
     */
    public function stream(Request $request, InputTrigger $trigger)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('trigger:invoke')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:invoke ability',
                ], 403);
            }

            // Check for file attachments - requires trigger:attach scope
            if ($request->hasFile('attachments') && ! $request->user()->tokenCan('trigger:attach')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:attach ability required for file uploads',
                ], 403);
            }

            // Verify ownership
            if ($trigger->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to invoke this trigger',
                ], 403);
            }

            // Check if trigger is active
            if (! $trigger->is_active) {
                return response()->json([
                    'success' => false,
                    'error' => 'Trigger Disabled',
                    'message' => 'This trigger is currently disabled',
                ], 403);
            }

            // Validate request using provider
            $provider = $this->registry->getProvider($trigger->provider_id);
            if (! $provider) {
                return response()->json([
                    'success' => false,
                    'error' => 'Provider Not Found',
                    'message' => "Provider '{$trigger->provider_id}' is not registered",
                ], 500);
            }

            $validation = $provider->validateRequest($request, $trigger);
            if (! $validation['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation Failed',
                    'message' => $validation['error'],
                    'errors' => $validation['errors'] ?? [],
                ], 422);
            }

            // Extract input
            $input = ['input' => $provider->extractInput($request)];

            // Validate and process file attachments if present
            $attachments = [];
            $attachmentMetadata = [];
            if ($request->hasFile('attachments')) {
                $files = is_array($request->file('attachments'))
                    ? $request->file('attachments')
                    : [$request->file('attachments')];

                foreach ($files as $file) {
                    try {
                        // SECURITY: Validate file using centralized FileUploadService
                        // Performs magic byte verification, executable detection, archive scanning
                        $validationData = $this->fileUploadService->validateOnly(
                            file: $file,
                            context: [
                                'trigger_id' => $trigger->id,
                                'user_id' => Auth::id(),
                                'ip' => $request->ip(),
                            ]
                        );

                        // Store file metadata and file object for later use by executor
                        $attachmentMetadata[] = $validationData;
                        $attachments[] = $file;

                    } catch (FileValidationException $e) {
                        return response()->json([
                            'success' => false,
                            'error' => 'File Validation Failed',
                            'message' => $e->getMessage(),
                        ], 422);
                    }
                }
            }

            // Check for tool override - requires trigger:tools scope
            $toolOverride = $request->input('tools');
            if ($toolOverride !== null && ! $request->user()->tokenCan('trigger:tools')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:tools ability required for tool override',
                ], 403);
            }

            // Validate tool override if provided
            if ($toolOverride !== null) {
                $toolValidator = app(\App\Services\InputTrigger\ToolOverrideValidator::class);
                $validationResult = $toolValidator->validate($toolOverride, $trigger->agent, $request->user());

                if (! $validationResult['valid']) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Invalid Tool Override',
                        'message' => $validationResult['error'],
                        'details' => $validationResult['details'] ?? [],
                    ], 422);
                }
            }

            // Extract options
            $options = array_merge(
                $request->input('options', []),
                [
                    'metadata' => $validation['metadata'],
                    'api_version' => 'v1-streaming',
                    'attachments' => $attachments,
                    'attachment_metadata' => $attachmentMetadata,
                    'tool_override' => $toolOverride,
                ]
            );

            Log::info('InputTriggerController: Streaming trigger invocation', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'input_length' => strlen($input['input']),
            ]);

            // Return SSE stream with timeout protection
            return response()->stream(function () use ($trigger, $input, $options) {
                $startTime = time();
                $timeout = config('services.streaming.timeout_seconds', 600); // 10 minutes default
                $lastHeartbeat = time();
                $heartbeatInterval = config('services.streaming.heartbeat_interval', 30); // 30 seconds

                // Disable all output buffering for real-time streaming
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                try {
                    foreach ($this->streamingExecutor->stream($trigger, $input, $options) as $chunk) {
                        // PERFORMANCE: Check timeout to prevent unbounded execution
                        if (time() - $startTime > $timeout) {
                            echo "event: error\n";
                            echo 'data: {"error":"STREAM_TIMEOUT","message":"Stream exceeded maximum duration"}'."\n\n";

                            Log::warning('SSE stream timeout', [
                                'trigger_id' => $trigger->id,
                                'user_id' => Auth::id(),
                                'duration_seconds' => time() - $startTime,
                                'timeout' => $timeout,
                            ]);

                            break;
                        }

                        // Send heartbeat to keep connection alive and reset client timeouts
                        if (time() - $lastHeartbeat > $heartbeatInterval) {
                            echo "event: heartbeat\n";
                            echo 'data: {"timestamp":'.time().'}'."\n\n";
                            $lastHeartbeat = time();

                            if (function_exists('flush')) {
                                flush();
                            }
                        }

                        echo $chunk;

                        // Force immediate flush after each SSE event
                        if (function_exists('flush')) {
                            flush();
                        }

                        // Detect client disconnect to free resources immediately
                        if (connection_aborted()) {
                            Log::info('Client disconnected from SSE stream', [
                                'trigger_id' => $trigger->id,
                                'duration_seconds' => time() - $startTime,
                            ]);

                            break;
                        }
                    }

                    // Send completion event
                    echo "event: complete\n";
                    echo 'data: {"status":"completed","duration_seconds":'.(time() - $startTime).'}'."\n\n";

                } catch (\Exception $e) {
                    Log::error('SSE stream error', [
                        'trigger_id' => $trigger->id,
                        'user_id' => Auth::id(),
                        'error' => $e->getMessage(),
                        'duration_seconds' => time() - $startTime,
                    ]);

                    echo "event: error\n";
                    echo 'data: {"error":"STREAM_ERROR","message":"An error occurred during streaming"}'."\n\n";
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
                'X-Stream-Timeout' => config('services.streaming.timeout_seconds', 600),
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid Input',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('InputTriggerController: Streaming invocation failed', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Rethrow to let global exception handler sanitize the response
            throw $e;
        }
    }

    /**
     * Resolve session for a trigger
     *
     * Determine which chat session would be used for a trigger execution based on its session strategy,
     * without actually executing the trigger. Useful for understanding session continuity behavior
     * or pre-loading session history in CLI tools.
     *
     * Session strategies:
     * - `new_each`: Creates a new session for each execution
     * - `continue_last`: Continues the most recent session for this trigger
     * - `specified`: Uses a specific default session
     *
     * @authenticated
     *
     * @urlParam trigger integer required The trigger ID. Example: 1
     *
     * @response 200 scenario="Will Create New Session" {"success": true, "session_id": null, "is_existing": false, "will_create": true, "session_name": "⏰ Daily Report Generator", "strategy": "new_each"}
     * @response 200 scenario="Will Continue Existing" {"success": true, "session_id": 123, "session_name": "Daily Report Generator", "is_existing": true, "will_create": false, "strategy": "continue_last", "interactions_count": 15}
     * @response 403 scenario="Missing trigger:status ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:status ability"}
     * @response 403 scenario="Not Trigger Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to access this trigger"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while resolving the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session_id integer Existing session ID, or null if new session will be created
     * @responseField session_name string Name of existing session or proposed name for new session
     * @responseField is_existing boolean Whether an existing session was found
     * @responseField will_create boolean Whether a new session will be created on execution
     * @responseField strategy string Session strategy used (new_each, continue_last, specified)
     * @responseField interactions_count integer Number of interactions in existing session (only if is_existing=true)
     */
    public function resolveSession(Request $request, InputTrigger $trigger): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('trigger:status')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:status ability',
                ], 403);
            }

            // Verify ownership
            if ($trigger->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to access this trigger',
                ], 403);
            }

            // Resolve session using same logic as StreamingTriggerExecutor
            $session = null;
            $isExisting = false;

            // Option 1: Continue last session from THIS trigger
            if ($trigger->session_strategy === 'continue_last') {
                $session = ChatSession::where('user_id', $trigger->user_id)
                    ->where('metadata->input_trigger_id', $trigger->id)
                    ->latest()
                    ->first();

                if ($session) {
                    $isExisting = true;
                }
            }

            // Option 2: Use trigger's default session
            if (! $session && $trigger->session_strategy === 'specified' && $trigger->default_session_id) {
                $session = ChatSession::find($trigger->default_session_id);
                if ($session) {
                    $isExisting = true;
                }
            }

            // If no existing session found, return info about what would be created
            if (! $session) {
                $provider = $this->registry->getProvider($trigger->provider_id);
                $sessionName = $provider ? "{$provider->getTriggerIcon()} {$trigger->name}" : $trigger->name;

                return response()->json([
                    'success' => true,
                    'session_id' => null,
                    'is_existing' => false,
                    'will_create' => true,
                    'session_name' => $sessionName,
                    'strategy' => $trigger->session_strategy,
                ], 200);
            }

            // Return existing session info
            return response()->json([
                'success' => true,
                'session_id' => $session->id,
                'session_name' => $session->name,
                'is_existing' => true,
                'will_create' => false,
                'strategy' => $trigger->session_strategy,
                'interactions_count' => $session->interactions()->count(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('InputTriggerController: Failed to resolve session', [
                'trigger_id' => $trigger->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while resolving the session',
            ], 500);
        }
    }

    /**
     * Validate or resolve session
     *
     * Validate that a specific session ID is compatible with this trigger, or resolve which session
     * would be used based on the trigger's session strategy.
     *
     * Use cases:
     * - Validate a session ID before execution: `?session_id=123`
     * - Resolve which session will be used: No parameters
     *
     * @authenticated
     *
     * @urlParam trigger integer required The trigger ID. Example: 1
     *
     * @queryParam session_id integer Optional session ID to validate. Example: 123
     *
     * @response 200 scenario="Session Valid" {"success": true, "valid": true, "session": {"id": 123, "name": "Daily Report Generator", "url": "https://promptlyagent.com/dashboard/research-chat/123", "strategy_used": "validated"}}
     * @response 200 scenario="Session Resolved" {"success": true, "valid": true, "session": {"id": 456, "name": "Report Session", "url": "https://promptlyagent.com/dashboard/research-chat/456", "strategy_used": "continue_last"}}
     * @response 200 scenario="No Session Available" {"success": true, "valid": true, "session": null, "message": "No existing session available", "strategy": "new_each", "url_pattern": "https://promptlyagent.com/dashboard/research-chat/<id>"}
     * @response 403 scenario="Missing trigger:status ability" {"success": false, "valid": false, "error": "Unauthorized", "message": "Your API token does not have the trigger:status ability"}
     * @response 403 scenario="Not Trigger Owner" {"success": false, "valid": false, "error": "Forbidden", "message": "You do not have permission to access this trigger"}
     * @response 404 scenario="Session Not Found" {"success": false, "valid": false, "session": null, "message": "Session not found"}
     * @response 404 scenario="Session Not Owned" {"success": false, "valid": false, "session": null, "message": "Session does not belong to you"}
     * @response 404 scenario="Session Wrong Trigger" {"success": false, "valid": false, "session": null, "message": "Session does not belong to this trigger"}
     * @response 500 scenario="Server Error" {"success": false, "valid": false, "error": "Internal Server Error", "message": "Failed to validate session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField valid boolean Whether the session is valid for this trigger
     * @responseField session object Session details (null if no session available)
     * @responseField session.id integer Session ID
     * @responseField session.name string Session name
     * @responseField session.url string Full URL to view session in dashboard
     * @responseField session.strategy_used string Strategy that resolved this session (validated, continue_last, specified)
     * @responseField message string Informational message (when session is null)
     * @responseField strategy string Trigger's session strategy (when session is null)
     * @responseField url_pattern string URL pattern for accessing sessions (when session is null)
     */
    public function validateSession(Request $request, InputTrigger $trigger): JsonResponse
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('trigger:status')) {
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the trigger:status ability',
                ], 403);
            }

            // Verify ownership
            if ($trigger->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'valid' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to access this trigger',
                ], 403);
            }

            $providedSessionId = $request->query('session_id');

            // Case 1: Session ID provided - validate it
            if ($providedSessionId) {
                $session = ChatSession::find($providedSessionId);

                // Check if session exists
                if (! $session) {
                    return response()->json([
                        'success' => false,
                        'valid' => false,
                        'session' => null,
                        'message' => 'Session not found',
                    ], 404);
                }

                // Check if session belongs to the user
                if ($session->user_id !== Auth::id()) {
                    return response()->json([
                        'success' => false,
                        'valid' => false,
                        'session' => null,
                        'message' => 'Session does not belong to you',
                    ], 404);
                }

                // Check if session belongs to this trigger (if session has trigger metadata)
                $sessionTriggerId = $session->metadata['input_trigger_id'] ?? null;
                if ($sessionTriggerId && $sessionTriggerId !== $trigger->id) {
                    return response()->json([
                        'success' => false,
                        'valid' => false,
                        'session' => null,
                        'message' => 'Session does not belong to this trigger',
                    ], 404);
                }

                // Session is valid - either has no trigger metadata, or belongs to this trigger
                // This allows sessions to be reused across triggers when strategy is not configured
                return response()->json([
                    'success' => true,
                    'valid' => true,
                    'session' => [
                        'id' => $session->id,
                        'name' => $session->name,
                        'url' => route('dashboard.research-chat.session', $session->id),
                        'strategy_used' => 'validated',
                    ],
                ], 200);
            }

            // Case 2: No session ID provided - resolve based on strategy
            $session = null;
            $strategyUsed = $trigger->session_strategy;

            // Strategy: continue_last - get most recent session for this trigger
            if ($trigger->session_strategy === 'continue_last') {
                $session = ChatSession::where('user_id', $trigger->user_id)
                    ->where('metadata->input_trigger_id', $trigger->id)
                    ->latest()
                    ->first();
            }

            // Strategy: specified - use trigger's default session
            if (! $session && $trigger->session_strategy === 'specified' && $trigger->default_session_id) {
                $session = ChatSession::find($trigger->default_session_id);
                if ($session && $session->user_id !== Auth::id()) {
                    $session = null; // Security check
                }
            }

            // Return session if found
            if ($session) {
                return response()->json([
                    'success' => true,
                    'valid' => true,
                    'session' => [
                        'id' => $session->id,
                        'name' => $session->name,
                        'url' => route('dashboard.research-chat.session', $session->id),
                        'strategy_used' => $strategyUsed,
                    ],
                ], 200);
            }

            // No session available (new_each strategy or no existing session)
            return response()->json([
                'success' => true,
                'valid' => true,
                'session' => null,
                'message' => 'No existing session available',
                'strategy' => $strategyUsed,
                'url_pattern' => url('/dashboard/chat/<id>'),
            ], 200);

        } catch (\Exception $e) {
            Log::error('InputTriggerController: Failed to validate session', [
                'trigger_id' => $trigger->id,
                'session_id' => $request->query('session_id'),
                'user_id' => Auth::id(),
                'trace' => $e->getTraceAsString(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'valid' => false,
                'error' => 'Internal Server Error',
                'message' => 'Failed to validate session',
            ], 500);
        }
    }
}
