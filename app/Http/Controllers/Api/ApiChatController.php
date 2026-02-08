<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FileValidationException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Services\Chat\ChatStreamingService;
use App\Services\Chat\SessionArchiveService;
use App\Services\Chat\SessionSearchService;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @group Chat & Streaming
 *
 * Direct chat API endpoints with real-time SSE streaming support.
 * Integrate conversational AI into your applications without webhook triggers.
 *
 * ## Features
 * - Real-time streaming responses via Server-Sent Events (SSE)
 * - Session management and history
 * - File attachments support
 * - Multi-turn conversations
 * - Agent selection per message
 *
 * ## Required Token Abilities
 * - `chat:create` - Send messages and create sessions
 * - `agent:attach` - Upload file attachments
 *
 * ## Rate Limiting
 * - Streaming & send: 10 requests/minute (expensive AI operations)
 * - Session management: 60 requests/minute
 * - Session viewing: 300 requests/minute
 */
class ApiChatController extends Controller
{
    public function __construct(
        private ChatStreamingService $chatStreamingService,
        private FileUploadService $fileUploadService,
        private SessionSearchService $sessionSearchService,
        private SessionArchiveService $sessionArchiveService
    ) {}

    /**
     * Stream a chat message with real-time SSE responses
     *
     * Sends a message to an AI agent and streams the response in real-time using Server-Sent Events (SSE).
     * Supports multi-turn conversations, file attachments, and automatic session management.
     *
     * The stream returns various event types (message, tool_call, source, artifact, heartbeat, complete, error)
     * with JSON-encoded data payloads. Connections are kept alive with periodic heartbeats and protected
     * against timeouts.
     *
     * ## Example Usage
     *
     * **Support Widget** ([github.com/promptlyagentai/support-widget](https://github.com/promptlyagentai/support-widget)) - AI-powered support chat:
     * - Real-time streaming responses with SSE
     * - Session persistence with cookies
     * - Element selection for contextual help
     * - Screenshot capture with messages
     *
     * **Ulauncher Extension** ([github.com/promptlyagentai/ulauncher-promptlyagent](https://github.com/promptlyagentai/ulauncher-promptlyagent)) - Desktop AI assistant:
     * - Quick access from Linux desktop
     * - Real-time streaming with notifications
     * - Clipboard integration for results
     * - Agent selection and filtering
     *
     * @authenticated
     *
     * @bodyParam message string required The chat message content. Maximum 10,000 characters. Example: What are Laravel best practices for routing and middleware?
     * @bodyParam session_id integer Optional session ID to continue an existing conversation. If not provided, creates a new session. Example: 123
     * @bodyParam agent_id integer Optional agent ID to use for this message. If not provided, uses the default Direct Chat Agent. Example: 5
     * @bodyParam attachments file[] Optional file attachments (requires `agent:attach` token ability). Maximum 10 files, 50MB each. Supported: documents, images, code files. No-example
     *
     * @response 200 scenario="SSE Stream" event: message
     * data: {"type": "text", "content": "Here are the best practices for Laravel routing...", "delta": true}
     *
     * event: tool_call
     * data: {"tool": "search_knowledge", "arguments": {"query": "routing best practices"}}
     *
     * event: source
     * data: {"title": "Laravel Docs - Routing", "url": "https://laravel.com/docs/routing", "domain": "laravel.com"}
     *
     * event: artifact
     * data: {"type": "code", "language": "php", "content": "Route::get('/user', [UserController::class, 'index']);"}
     *
     * event: heartbeat
     * data: {"timestamp": 1704067200}
     *
     * event: complete
     * data: {"status": "completed", "duration_seconds": 12}
     * @response 400 scenario="Agent Inactive" {"success": false, "error": "Agent Inactive", "message": "This agent is not currently active."}
     * @response 403 scenario="Missing chat:create ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:create ability"}
     * @response 404 scenario="Resource Not Found" {"success": false, "error": "Not Found", "message": "The specified resource was not found"}
     * @response 422 scenario="File Validation Failed" {"success": false, "error": "File Validation Failed", "message": "File type not allowed: executable"}
     * @response 500 scenario="Agent Not Found" {"success": false, "error": "Agent Not Found", "message": "Direct Chat Agent not found. Please run database seeder."}
     *
     * @responseField event string The SSE event type (message, tool_call, source, artifact, heartbeat, complete, error)
     * @responseField data string JSON-encoded event data. Structure varies by event type.
     */
    public function stream(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated',
                    'message' => 'Authentication required',
                ], 401);
            }

            $isTokenAuth = $request->bearerToken() !== null;

            if ($isTokenAuth) {
                if (! $user->tokenCan('chat:create')) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Unauthorized',
                        'message' => 'Your API token does not have the chat:create ability',
                    ], 403);
                }

                if ($request->hasFile('attachments') && ! $user->tokenCan('agent:attach')) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Unauthorized',
                        'message' => 'Your API token does not have the agent:attach ability required for file uploads',
                    ], 403);
                }
            }

            $validator = Validator::make($request->all(), [
                'message' => 'required|string|max:10000',
                'session_id' => 'nullable|integer|exists:chat_sessions,id',
                'agent_id' => 'nullable|integer|exists:agents,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation Failed',
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $message = $request->input('message');
            $sessionId = $request->input('session_id');
            $agentId = $request->input('agent_id');

            if ($sessionId) {
                $session = ChatSession::findOrFail($sessionId);

                if ($session->user_id !== Auth::id()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Forbidden',
                        'message' => 'You do not have permission to access this session',
                    ], 403);
                }
            } else {
                $session = ChatSession::create([
                    'user_id' => Auth::id(),
                    'name' => 'API Chat Session',
                    'metadata' => [
                        'initiated_by' => 'api',
                        'api_version' => 'v1',
                        'can_continue_via_web' => true,
                    ],
                ]);

                Log::info('ApiChatController: Created new session', [
                    'session_id' => $session->id,
                    'user_id' => Auth::id(),
                ]);
            }

            if ($agentId) {
                $agent = Agent::findOrFail($agentId);

                if ($agent->status !== 'active') {
                    return response()->json([
                        'success' => false,
                        'error' => 'Agent Inactive',
                        'message' => 'This agent is not currently active.',
                    ], 400);
                }
            } else {
                $agent = Agent::directType()->active()->first();

                if (! $agent) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Agent Not Found',
                        'message' => 'Direct Chat Agent not found. Please run database seeder.',
                    ], 500);
                }
            }

            $interaction = ChatInteraction::create([
                'chat_session_id' => $session->id,
                'user_id' => Auth::id(),
                'question' => $message,
                'answer' => '', // Will be populated by streaming execution
                'agent_id' => $agent->id,
                'metadata' => [
                    'source' => 'api',
                    'api_version' => 'v1',
                    'streaming_mode' => 'sse',
                ],
            ]);

            $attachmentCount = 0;
            if ($request->hasFile('attachments')) {
                $files = is_array($request->file('attachments'))
                    ? $request->file('attachments')
                    : [$request->file('attachments')];

                foreach ($files as $file) {
                    try {
                        $result = $this->fileUploadService->uploadAndValidate(
                            file: $file,
                            storagePath: 'chat-attachments',
                            context: [
                                'interaction_id' => $interaction->id,
                                'user_id' => Auth::id(),
                            ],
                            onFailure: fn ($validationResult) => $interaction->delete()
                        );

                        \App\Models\ChatInteractionAttachment::create([
                            'chat_interaction_id' => $interaction->id,
                            'filename' => $result->filename,
                            'storage_path' => $result->path,
                            'mime_type' => $result->mimeType,
                            'file_size' => $result->size,
                            'type' => $result->type,
                            'metadata' => $result->metadata,
                            'is_temporary' => true,
                            'expires_at' => now()->addDays(7),
                        ]);

                        $attachmentCount++;

                        Log::info('ApiChatController: Stored attachment', [
                            'interaction_id' => $interaction->id,
                            'filename' => $result->filename,
                            'type' => $result->type,
                            'size' => $result->size,
                        ]);

                    } catch (FileValidationException $e) {
                        return response()->json([
                            'success' => false,
                            'error' => 'File Validation Failed',
                            'message' => $e->getMessage(),
                        ], 422);
                    }
                }
            }

            $interaction->update([
                'metadata' => array_merge($interaction->metadata ?? [], [
                    'has_attachments' => $attachmentCount > 0,
                    'attachment_count' => $attachmentCount,
                ]),
            ]);

            Log::info('ApiChatController: Streaming chat request', [
                'interaction_id' => $interaction->id,
                'session_id' => $session->id,
                'agent_id' => $agent->id,
                'user_id' => Auth::id(),
                'message_length' => strlen($message),
                'attachment_count' => $attachmentCount,
            ]);

            // Attachments are loaded from DB by the streaming service
            $options = [];

            // SSE stream with timeout protection - routes to direct or research agent based on type
            return response()->stream(function () use ($interaction, $agent) {
                $startTime = time();
                $timeout = config('services.streaming.timeout_seconds', 600);
                $lastHeartbeat = time();
                $heartbeatInterval = config('services.streaming.heartbeat_interval', 30);

                // Disable output buffering for real-time streaming
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                try {
                    foreach ($this->chatStreamingService->streamAgentExecution($interaction, $agent) as $chunk) {
                        // PERFORMANCE: Check timeout to prevent unbounded execution
                        if (time() - $startTime > $timeout) {
                            echo "event: error\n";
                            echo 'data: {"error":"STREAM_TIMEOUT","message":"Stream exceeded maximum duration"}'."\n\n";

                            Log::warning('SSE stream timeout', [
                                'interaction_id' => $interaction->id,
                                'agent_id' => $agent->id,
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

                        if (function_exists('flush')) {
                            flush();
                        }

                        // Detect client disconnect to free resources immediately
                        if (connection_aborted()) {
                            Log::info('Client disconnected from SSE stream', [
                                'interaction_id' => $interaction->id,
                                'duration_seconds' => time() - $startTime,
                            ]);

                            break;
                        }
                    }

                    echo "event: complete\n";
                    echo 'data: {"status":"completed","duration_seconds":'.(time() - $startTime).'}'."\n\n";

                } catch (\Exception $e) {
                    Log::error('SSE stream error', [
                        'interaction_id' => $interaction->id,
                        'agent_id' => $agent->id,
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'The specified resource was not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Streaming chat failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Rethrow to let global exception handler sanitize the response
            throw $e;
        }
    }

    /**
     * Send a chat message (non-streaming)
     *
     * Non-streaming chat endpoint for sending messages and receiving complete responses.
     * Currently not implemented - use the `/api/v1/chat/stream` endpoint instead for all chat operations.
     *
     * @authenticated
     *
     * @bodyParam message string required The chat message content. Maximum 10,000 characters. Example: Explain dependency injection in Laravel
     * @bodyParam session_id integer Optional session ID to continue an existing conversation. Example: 123
     * @bodyParam agent_id integer Optional agent ID to use for this message. Example: 5
     * @bodyParam attachments file[] Optional file attachments (requires `agent:attach` token ability). No-example
     *
     * @response 501 scenario="Not Implemented" {"success": false, "error": "Not Implemented", "message": "Non-streaming chat endpoint not yet implemented. Please use /api/v1/chat/stream instead."}
     */
    public function send(Request $request)
    {
        return response()->json([
            'success' => false,
            'error' => 'Not Implemented',
            'message' => 'Non-streaming chat endpoint not yet implemented. Please use /api/v1/chat/stream instead.',
        ], 501);
    }

    /**
     * View a chat session
     *
     * Retrieve a single chat session with all interactions, messages, and sources.
     * Returns the complete conversation history with nested source attributions from the knowledge base.
     *
     * ## Example Usage
     *
     * **Support Widget** ([github.com/promptlyagentai/support-widget](https://github.com/promptlyagentai/support-widget)) - Restore conversation history:
     * - Retrieves existing sessions from cookie-stored session IDs
     * - Displays full conversation history when reopening widget
     *
     * **Trigger API Client** ([github.com/promptlyagentai/trigger-api-client](https://github.com/promptlyagentai/trigger-api-client)) - Session validation:
     * - Validates session IDs before streaming trigger responses
     * - Continues conversations in specific chat sessions
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "session": {"id": 123, "name": "API Chat Session", "title": "Discussion about Laravel routing", "uuid": "550e8400-e29b-41d4-a716-446655440000", "is_public": false, "created_at": "2024-01-01T00:00:00Z", "updated_at": "2024-01-01T00:15:00Z"}, "interactions": [{"id": 456, "question": "What are Laravel best practices?", "answer": "Here are the Laravel best practices...", "agent_name": "Direct Chat Agent", "sources": [{"title": "Laravel Documentation", "url": "https://laravel.com/docs", "domain": "laravel.com"}], "created_at": "2024-01-01T00:00:00Z"}]}
     * @response 403 scenario="Missing chat:view ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:view ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to access this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while retrieving the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session object The chat session details
     * @responseField session.id integer Session ID
     * @responseField session.name string Session name
     * @responseField session.title string Session title (auto-generated from first message)
     * @responseField session.uuid string Unique session identifier for public sharing
     * @responseField session.is_public boolean Whether the session is publicly shared
     * @responseField session.created_at string Session creation timestamp (ISO 8601)
     * @responseField session.updated_at string Last update timestamp (ISO 8601)
     * @responseField interactions array Array of chat interactions in chronological order
     * @responseField interactions[].id integer Interaction ID
     * @responseField interactions[].question string User's question/message
     * @responseField interactions[].answer string Agent's response
     * @responseField interactions[].agent_name string Name of the agent that handled this interaction
     * @responseField interactions[].sources array Knowledge sources referenced in the response
     * @responseField interactions[].sources[].title string Source document title
     * @responseField interactions[].sources[].url string Source URL
     * @responseField interactions[].sources[].domain string Source domain name
     * @responseField interactions[].created_at string Interaction timestamp (ISO 8601)
     */
    public function show(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:view ability',
                ], 403);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to access this session',
                ], 403);
            }

            // PERFORMANCE: Eager load interactions with nested source and artifact relationships to avoid N+1 queries
            // Without 'sources.source': 1 (session) + 1 (interactions) + N (source model queries)
            // With 'sources.source' + 'artifacts.artifact': 1 (session) + 1 (interactions) + 1 (sources) + 1 (artifacts)
            $interactions = $session->interactions()
                ->with([
                    'agent:id,name',
                    'sources.source:id,title,url,domain',
                    'artifacts.artifact:id,title,filetype,privacy_level,created_at',
                ])
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($interaction) {
                    return [
                        'id' => $interaction->id,
                        'question' => $interaction->question,
                        'answer' => $interaction->answer,
                        'agent_name' => $interaction->agent?->name,
                        'sources' => $interaction->sources->map(function ($chatInteractionSource) {
                            return [
                                'title' => $chatInteractionSource->source->title,
                                'url' => $chatInteractionSource->source->url,
                                'domain' => $chatInteractionSource->source->domain,
                            ];
                        }),
                        'artifacts' => $interaction->artifacts->map(function ($chatInteractionArtifact) {
                            $artifact = $chatInteractionArtifact->artifact;

                            return [
                                'id' => $artifact->id,
                                'title' => $artifact->title,
                                'filetype' => $artifact->filetype,
                                'privacy_level' => $artifact->privacy_level,
                                'created_at' => $artifact->created_at,
                            ];
                        }),
                        'created_at' => $interaction->created_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'session' => [
                    'id' => $session->id,
                    'name' => $session->name,
                    'title' => $session->title,
                    'uuid' => $session->uuid,
                    'is_public' => $session->is_public,
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                ],
                'interactions' => $interactions,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to retrieve session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving the session',
            ], 500);
        }
    }

    /**
     * List all chat sessions
     *
     * Retrieve all chat sessions for the authenticated user with optional filtering and search.
     * Supports filtering by source type, archive status, kept status, and full-text search across
     * session titles and interaction content.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/session-api.js`) - Session history and management:
     * - Lists sessions with advanced filtering (search, source_type, archived, kept)
     * - Pagination support (per_page, page parameters)
     * - Powers the chat history interface in the Progressive Web App
     * - Requires `chat:view` token ability
     *
     * @authenticated
     *
     * @queryParam search string Optional search query for session titles and interaction content. Maximum 200 characters. Example: Laravel routing
     * @queryParam source_type string Optional filter by session source. Options: web, api, webhook, slack, trigger, all. Defaults to all. Example: api
     * @queryParam include_archived boolean Optional include archived sessions in results. Defaults to false. Example: false
     * @queryParam kept_only boolean Optional show only sessions marked as kept. Defaults to false. Example: true
     * @queryParam page integer Optional page number for pagination. Minimum 1. Example: 1
     * @queryParam per_page integer Optional items per page (1-50). Defaults to 50. Example: 20
     *
     * @response 200 scenario="Success" {"success": true, "sessions": [{"id": 123, "name": "API Chat Session", "title": "Discussion about Laravel", "uuid": "550e8400-e29b-41d4-a716-446655440000", "is_public": false, "source_type": "api", "is_kept": false, "is_archived": false, "archived_at": null, "interactions_count": 5, "attachments_count": 2, "artifacts_count": 1, "sources_count": 3, "created_at": "2024-01-01T00:00:00Z", "updated_at": "2024-01-01T00:15:00Z"}], "filters": {"source_type": "all", "include_archived": false, "kept_only": false, "limit": 50}}
     * @response 403 scenario="Missing chat:view ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:view ability"}
     * @response 422 scenario="Invalid Parameters" {"success": false, "error": "Validation Failed", "message": "Invalid query parameters", "errors": {"per_page": ["The per page must be between 1 and 50."]}}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while retrieving sessions"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField sessions array Array of chat sessions
     * @responseField sessions[].id integer Session ID
     * @responseField sessions[].name string Session name
     * @responseField sessions[].title string Session title (auto-generated)
     * @responseField sessions[].uuid string Unique session identifier
     * @responseField sessions[].is_public boolean Public sharing status
     * @responseField sessions[].source_type string Source type (web, api, webhook, slack, trigger)
     * @responseField sessions[].is_kept boolean Whether session is marked as kept (protected from auto-deletion)
     * @responseField sessions[].is_archived boolean Archive status
     * @responseField sessions[].archived_at string Archive timestamp (ISO 8601) or null
     * @responseField sessions[].interactions_count integer Number of interactions in session
     * @responseField sessions[].attachments_count integer Number of file attachments
     * @responseField sessions[].artifacts_count integer Number of generated artifacts (code, documents)
     * @responseField sessions[].sources_count integer Number of knowledge sources referenced
     * @responseField sessions[].created_at string Creation timestamp (ISO 8601)
     * @responseField sessions[].updated_at string Last update timestamp (ISO 8601)
     * @responseField filters object Applied filters for this request
     */
    public function index(Request $request)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:view')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:view ability',
                ], 403);
            }

            // Get registered source types for validation
            $sourceRegistry = app(\App\Services\Chat\SourceTypeRegistry::class);
            $validSourceTypes = array_keys($sourceRegistry->all());
            $validSourceTypes[] = 'all'; // Add 'all' as valid option

            // Validate request parameters
            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:200',
                'source_type' => 'nullable|string|in:'.implode(',', $validSourceTypes),
                'include_archived' => 'nullable|boolean',
                'kept_only' => 'nullable|boolean',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation Failed',
                    'message' => 'Invalid query parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $search = $request->input('search', '');
            $perPage = $request->input('per_page', 50);

            // Build filters array
            $filters = [
                'source_type' => $request->input('source_type', 'all'),
                'include_archived' => $request->boolean('include_archived', false),
                'kept_only' => $request->boolean('kept_only', false),
                'limit' => $perPage,
            ];

            // Use search service if search query provided
            if (! empty($search) && config('chat.search_enabled', true)) {
                $sessions = $this->sessionSearchService->search(
                    $request->user(),
                    $search,
                    $filters
                );
            } else {
                // Build query with filters
                $query = ChatSession::where('user_id', Auth::id());

                // Apply source type filter
                if (! empty($filters['source_type']) && $filters['source_type'] !== 'all') {
                    $query->bySourceType($filters['source_type']);
                }

                // Apply archived filter
                if (! $filters['include_archived']) {
                    $query->active();
                }

                // Apply kept filter
                if ($filters['kept_only']) {
                    $query->kept();
                }

                $sessions = $query
                    ->orderBy('updated_at', 'desc')
                    ->limit($perPage)
                    ->get();
            }

            // Add counts using withCount to avoid N+1
            $sessions = $sessions->map(function ($session) {
                // Load counts if not already loaded
                if (! isset($session->interactions_count)) {
                    $session->loadCount('interactions');
                }

                return [
                    'id' => $session->id,
                    'name' => $session->name,
                    'title' => $session->title,
                    'uuid' => $session->uuid,
                    'is_public' => $session->is_public,
                    'source_type' => $session->source_type,
                    'is_kept' => $session->is_kept,
                    'is_archived' => $session->isArchived(),
                    'archived_at' => $session->archived_at,
                    'interactions_count' => $session->interactions_count ?? 0,
                    'attachments_count' => $session->attachments_count,
                    'artifacts_count' => $session->artifacts_count,
                    'sources_count' => $session->sources_count,
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'sessions' => $sessions,
                'filters' => $filters,
            ], 200);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to list sessions', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while retrieving sessions',
            ], 500);
        }
    }

    /**
     * Toggle keep flag on a session
     *
     * Mark a session as "kept" to protect it from automatic deletion, or remove the keep flag.
     * Kept sessions are excluded from cleanup routines and remain permanently until manually deleted.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/session-api.js`) - Session management:
     * - Toggles keep flag to protect important conversations
     * - Prevents automatic cleanup of marked sessions
     * - Supports bulk operations on multiple sessions
     * - Requires `chat:manage` token ability
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "session": {"id": 123, "is_kept": true}}
     * @response 403 scenario="Missing chat:manage ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:manage ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to modify this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while updating the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session object Updated session details
     * @responseField session.id integer Session ID
     * @responseField session.is_kept boolean New kept status (toggled)
     */
    public function toggleKeep(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:manage')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:manage ability',
                ], 403);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to modify this session',
                ], 403);
            }

            $session->toggleKeep();

            Log::info('ApiChatController: Toggled keep flag', [
                'session_id' => $session->id,
                'user_id' => Auth::id(),
                'is_kept' => $session->is_kept,
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'id' => $session->id,
                    'is_kept' => $session->is_kept,
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to toggle keep flag', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while updating the session',
            ], 500);
        }
    }

    /**
     * Archive a chat session
     *
     * Move a session to the archive. Archived sessions are hidden from the default session list
     * and can be restored later. Cannot archive sessions marked as kept.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/session-api.js`) - Session organization:
     * - Archives old or completed conversations
     * - Keeps chat history clean without permanent deletion
     * - Bulk operation support for multiple sessions
     * - Requires `chat:manage` token ability
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "session": {"id": 123, "is_archived": true, "archived_at": "2024-01-01T12:00:00Z"}}
     * @response 403 scenario="Missing chat:manage ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:manage ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to modify this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 422 scenario="Cannot Archive Kept Session" {"success": false, "error": "Cannot Archive Kept Session", "message": "Cannot archive a session marked as kept. Remove the keep flag first."}
     * @response 422 scenario="Already Archived" {"success": false, "error": "Already Archived", "message": "This session is already archived"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while archiving the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session object Updated session details
     * @responseField session.id integer Session ID
     * @responseField session.is_archived boolean Archive status (true)
     * @responseField session.archived_at string Archive timestamp (ISO 8601)
     */
    public function archive(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:manage')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:manage ability',
                ], 403);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to modify this session',
                ], 403);
            }

            // Check if session is kept
            if ($session->is_kept) {
                return response()->json([
                    'success' => false,
                    'error' => 'Cannot Archive Kept Session',
                    'message' => 'Cannot archive a session marked as kept. Remove the keep flag first.',
                ], 422);
            }

            // Check if already archived
            if ($session->isArchived()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Already Archived',
                    'message' => 'This session is already archived',
                ], 422);
            }

            $session->archive();

            Log::info('ApiChatController: Archived session', [
                'session_id' => $session->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'id' => $session->id,
                    'is_archived' => true,
                    'archived_at' => $session->archived_at,
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to archive session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while archiving the session',
            ], 500);
        }
    }

    /**
     * Unarchive a chat session
     *
     * Restore an archived session back to the active session list.
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "session": {"id": 123, "is_archived": false, "archived_at": null}}
     * @response 403 scenario="Missing chat:manage ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:manage ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to modify this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 422 scenario="Not Archived" {"success": false, "error": "Not Archived", "message": "This session is not archived"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while unarchiving the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session object Updated session details
     * @responseField session.id integer Session ID
     * @responseField session.is_archived boolean Archive status (false)
     * @responseField session.archived_at null Always null after unarchiving
     */
    public function unarchive(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:manage')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:manage ability',
                ], 403);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to modify this session',
                ], 403);
            }

            // Check if not archived
            if (! $session->isArchived()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not Archived',
                    'message' => 'This session is not archived',
                ], 422);
            }

            $session->unarchive();

            Log::info('ApiChatController: Unarchived session', [
                'session_id' => $session->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'id' => $session->id,
                    'is_archived' => false,
                    'archived_at' => null,
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to unarchive session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while unarchiving the session',
            ], 500);
        }
    }

    /**
     * Share a chat session publicly
     *
     * Make a session publicly accessible via a unique URL. Optionally set an expiration period
     * after which the public link will no longer work. Public sessions can be viewed by anyone
     * with the link without authentication.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/session-api.js`) - Public sharing:
     * - Generates public shareable URLs for conversations
     * - Optional TTL-based expiration (1-365 days)
     * - Returns full public URL for easy sharing
     * - Requires `chat:manage` token ability
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @bodyParam expires_in_days integer Optional expiration period in days (1-365). If not provided, link never expires. Example: 30
     *
     * @response 200 scenario="Success" {"success": true, "session": {"id": 123, "uuid": "550e8400-e29b-41d4-a716-446655440000", "is_public": true, "public_url": "https://promptlyagent.com/share/550e8400-e29b-41d4-a716-446655440000", "public_shared_at": "2024-01-01T12:00:00Z", "public_expires_at": "2024-01-31T12:00:00Z"}}
     * @response 403 scenario="Missing chat:manage ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:manage ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to modify this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 422 scenario="Already Public" {"success": false, "error": "Already Public", "message": "This session is already publicly shared"}
     * @response 422 scenario="Invalid Expiration" {"success": false, "error": "Validation Failed", "message": "Invalid request parameters", "errors": {"expires_in_days": ["The expires in days must be between 1 and 365."]}}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while sharing the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session object Updated session details
     * @responseField session.id integer Session ID
     * @responseField session.uuid string Unique session identifier used in public URL
     * @responseField session.is_public boolean Public sharing status (true)
     * @responseField session.public_url string Full public URL for viewing the session
     * @responseField session.public_shared_at string Timestamp when session was made public (ISO 8601)
     * @responseField session.public_expires_at string Expiration timestamp (ISO 8601) or null if no expiration
     */
    public function share(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:manage')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:manage ability',
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'expires_in_days' => 'nullable|integer|min:1|max:365',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation Failed',
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to modify this session',
                ], 403);
            }

            // Check if already public
            if ($session->isPublic()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Already Public',
                    'message' => 'This session is already publicly shared',
                ], 422);
            }

            // Share the session
            $expiresInDays = $request->input('expires_in_days');
            $session->makePublic($expiresInDays);

            Log::info('ApiChatController: Shared session publicly', [
                'session_id' => $session->id,
                'user_id' => Auth::id(),
                'expires_in_days' => $expiresInDays,
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'id' => $session->id,
                    'uuid' => $session->uuid,
                    'is_public' => true,
                    'public_url' => $session->getPublicUrl(),
                    'public_shared_at' => $session->public_shared_at,
                    'public_expires_at' => $session->public_expires_at,
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to share session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while sharing the session',
            ], 500);
        }
    }

    /**
     * Unshare a chat session (make private)
     *
     * Remove public access from a session. The public URL will no longer work and the session
     * will only be accessible to the owner when authenticated.
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "session": {"id": 123, "is_public": false, "public_shared_at": null, "public_expires_at": null}}
     * @response 403 scenario="Missing chat:manage ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:manage ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to modify this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 422 scenario="Not Public" {"success": false, "error": "Not Public", "message": "This session is not publicly shared"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while unsharing the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField session object Updated session details
     * @responseField session.id integer Session ID
     * @responseField session.is_public boolean Public sharing status (false)
     * @responseField session.public_shared_at null Always null after unsharing
     * @responseField session.public_expires_at null Always null after unsharing
     */
    public function unshare(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:manage')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:manage ability',
                ], 403);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to modify this session',
                ], 403);
            }

            // Check if not public
            if (! $session->is_public) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not Public',
                    'message' => 'This session is not publicly shared',
                ], 422);
            }

            $session->makePrivate();

            Log::info('ApiChatController: Unshared session', [
                'session_id' => $session->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'id' => $session->id,
                    'is_public' => false,
                    'public_shared_at' => null,
                    'public_expires_at' => null,
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to unshare session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while unsharing the session',
            ], 500);
        }
    }

    /**
     * Delete a chat session
     *
     * Permanently delete a session and all associated interactions, attachments, and artifacts.
     * This action cannot be undone. Only the session owner can delete their sessions.
     *
     * ## Example Usage
     *
     * **PWA** (`resources/js/pwa/session-api.js`) - Session deletion:
     * - Permanently removes sessions and all related data
     * - Supports bulk deletion of multiple sessions
     * - Progress tracking for bulk operations
     * - Requires `chat:delete` token ability
     *
     * @authenticated
     *
     * @urlParam id integer required The session ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "message": "Session deleted successfully"}
     * @response 403 scenario="Missing chat:delete ability" {"success": false, "error": "Unauthorized", "message": "Your API token does not have the chat:delete ability"}
     * @response 403 scenario="Not Session Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to delete this session"}
     * @response 404 scenario="Session Not Found" {"success": false, "error": "Not Found", "message": "Chat session not found"}
     * @response 500 scenario="Server Error" {"success": false, "error": "Server Error", "message": "An error occurred while deleting the session"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField message string Confirmation message
     */
    public function destroy(Request $request, int $id)
    {
        try {
            // Validate token abilities
            if (! $request->user()->tokenCan('chat:delete')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized',
                    'message' => 'Your API token does not have the chat:delete ability',
                ], 403);
            }

            $session = ChatSession::findOrFail($id);

            // Verify ownership
            if ($session->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to delete this session',
                ], 403);
            }

            // Delete associated interactions
            ChatInteraction::where('chat_session_id', $id)->delete();

            // Delete the session
            $session->delete();

            Log::info('ApiChatController: Deleted session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Session deleted successfully',
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Chat session not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('ApiChatController: Failed to delete session', [
                'session_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'message' => 'An error occurred while deleting the session',
            ], 500);
        }
    }
}
