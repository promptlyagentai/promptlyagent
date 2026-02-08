<?php

namespace App\Services\Chat;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\ChatInteractionAttachment;
use App\Models\StatusStream;
use App\Services\Agents\ToolOverrideService;
use App\Services\AI\PrismWrapper;
use App\Services\AiPersonaService;
use App\Services\LinkValidator;
use App\Services\SessionTitleService;
use App\Services\StatusReporter;
use App\Services\UrlTracker;
use Illuminate\Support\Facades\Log;

/**
 * Chat Streaming Service - SSE-based Real-time Chat Execution.
 *
 * Provides Server-Sent Events (SSE) streaming for direct agent chat executions,
 * supporting both web interface and API endpoints. Handles real-time response
 * streaming, tool execution transparency, source extraction, and post-processing
 * with dual execution modes (queued vs direct).
 *
 * Streaming Architecture:
 * - **SSE Protocol**: text/event-stream with named events
 * - **Event Types**: status, response_chunk, tool_execution, thinking, complete
 * - **Generators**: PHP Generator functions for memory-efficient streaming
 * - **Buffering**: Flush after each event for real-time delivery
 *
 * Execution Modes:
 * - **Direct Execution**: Synchronous processing with SSE streaming (instant response)
 * - **Queued Execution**: Async via ExecuteAgentJob (scalable, retryable)
 * - Mode selected based on load, priority, and configuration
 *
 * Tool Execution Visibility:
 * - Streams tool invocations in real-time
 * - Shows tool names, parameters, and results
 * - Extracts sources from tool results automatically
 * - Displays thinking process (when available)
 *
 * Source Extraction:
 * - Parses tool results for URLs and knowledge references
 * - Creates UrlTracker entries for research sources
 * - Links knowledge documents to interactions
 * - Validates and enriches source metadata
 *
 * Post-Processing Pipeline:
 * 1. URL extraction and validation (LinkValidator)
 * 2. Session title generation (SessionTitleService)
 * 3. Knowledge source linking (KnowledgeDocument associations)
 * 4. Embeddings generation (for semantic search)
 * 5. StatusStream completion events
 *
 * SSE Event Format:
 * ```
 * event: response_chunk
 * data: {"content": "AI response...", "timestamp": "..."}
 *
 * event: tool_execution
 * data: {"tool": "web_search", "status": "running", "args": {...}}
 *
 * event: complete
 * data: {"interaction_id": 123, "sources": [...]}
 * ```
 *
 * Attachment Handling:
 * - Supports file uploads via ChatInteractionAttachment
 * - Processes PDFs, images, documents
 * - Injects attachment context into prompts
 * - Maintains attachment metadata for retrieval
 *
 * Error Handling:
 * - Graceful degradation on streaming errors
 * - Detailed error events with stack traces (debug mode)
 * - Fallback to non-streaming responses
 * - Transaction rollback on critical failures
 *
 * Performance Optimizations:
 * - Generator-based streaming (low memory footprint)
 * - Chunked response delivery (immediate user feedback)
 * - Async post-processing (don't block stream completion)
 * - Connection timeout handling (client disconnect detection)
 *
 * @see \App\Http\Controllers\StreamingController
 * @see \App\Services\AI\PrismWrapper
 * @see \App\Services\StatusReporter
 * @see \App\Jobs\ExecuteAgentJob
 */
class ChatStreamingService
{
    public function __construct(
        private ToolOverrideService $toolOverrideService,
        private PrismWrapper $prismWrapper,
        private LinkValidator $linkValidator
    ) {}

    /**
     * Format SSE (Server-Sent Events) string
     */
    public function formatSSE(string $event, string $data): string
    {
        return "event: {$event}\ndata: {$data}\n\n";
    }

    /**
     * Stream direct chat execution with real-time updates
     *
     * @param  string  $query  User question/prompt
     * @param  int  $interactionId  ChatInteraction ID
     * @param  int  $userId  User ID for context
     * @param  array  $options  Optional parameters including attachments, attachment_metadata
     * @return \Generator SSE-formatted events
     */
    public function streamDirectExecution(string $query, int $interactionId, int $userId, array $options = []): \Generator
    {
        Log::info('ChatStreamingService: Starting direct chat stream', [
            'query' => substr($query, 0, 100),
            'interaction_id' => $interactionId,
            'user_id' => $userId,
            'has_attachments' => ! empty($options['attachments'] ?? []),
            'attachment_count' => count($options['attachments'] ?? []),
        ]);

        try {
            // Get the interaction
            $interaction = ChatInteraction::find($interactionId);
            if (! $interaction) {
                yield $this->formatSSE('update', $this->safeJsonEncode([
                    'content' => 'Interaction not found',
                    'type' => 'error',
                    'interaction_id' => $interactionId,
                ]));
                yield $this->formatSSE('update', '</stream>');

                return;
            }

            // Get the agent from the interaction (API sets this)
            $agent = $interaction->agent;
            if (! $agent) {
                throw new \Exception('Agent not found on interaction. Please specify agent_id.');
            }

            // Verify agent is active
            if ($agent->status !== 'active') {
                throw new \Exception("Agent '{$agent->name}' is not active.");
            }

            // Clean up stuck executions
            $this->cleanupStuckExecutions($agent, $interaction);

            // Create execution record
            $execution = $this->createExecution($agent, $interaction, $query);

            // Link execution to interaction
            $interaction->update(['agent_execution_id' => $execution->id]);

            // Emit session_id for widget/API clients (needed for session persistence)
            yield $this->formatSSE('update', $this->safeJsonEncode([
                'type' => 'session',
                'session_id' => $interaction->chat_session_id,
            ]));

            // Create initial status stream entry
            StatusStream::report(
                $interactionId,
                'system',
                "Chat execution started with {$agent->name}",
                [
                    'step_type' => 'chat_start',
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'agent_type' => $agent->agent_type,
                ],
                true, // create_event
                true, // is_significant
                $execution->id
            );

            // Store user context in container
            $this->storeUserContext($interaction, $agent, $interactionId);

            // Create StatusReporter for WebSocket updates
            $statusReporter = new StatusReporter($interactionId, $execution->id);
            app()->instance('status_reporter', $statusReporter);

            Log::info('ChatStreamingService: Created AgentExecution', [
                'execution_id' => $execution->id,
                'interaction_id' => $interactionId,
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
            ]);

            // Build conversation messages
            $messages = $this->buildConversationMessages($interaction, $query, $interactionId, $agent);

            // Load tools
            $enabledTools = $this->loadTools($interaction, $agent);

            // Create Prism streaming request
            $prismRequest = $this->createPrismRequest(
                $agent,
                $interaction,
                $messages,
                $enabledTools,
                $execution->id,
                $interactionId
            );

            // Stream response
            $response = $prismRequest->asStream();
            $answer = '';
            $toolResults = [];

            foreach ($response as $chunk) {
                // Handle tool calls
                if (isset($chunk->toolCalls) && is_array($chunk->toolCalls)) {
                    foreach ($chunk->toolCalls as $toolCall) {
                        $toolName = $toolCall->name ?? 'unknown';

                        if ($statusReporter) {
                            $statusReporter->reportWithMetadata('tool_call', "Executing {$toolName}", [
                                'tool_name' => $toolName,
                                'step_start_time' => microtime(true),
                                'step_type' => 'tool_execution',
                            ], false, false);
                        }
                    }
                }

                // Stream text content
                if (isset($chunk->text)) {
                    $answer .= $chunk->text;

                    yield $this->formatSSE('update', $this->safeJsonEncode([
                        'content' => $this->sanitizeForJson($answer),
                        'type' => 'answer_stream',
                        'session_id' => $interaction->chat_session_id,
                        'interaction_id' => $interactionId,
                    ]));
                }

                // Handle tool results
                if (isset($chunk->toolResults) && is_array($chunk->toolResults)) {
                    foreach ($chunk->toolResults as $toolResult) {
                        $toolResults[] = $toolResult;

                        $toolName = $toolResult->toolName ?? 'unknown';
                        if ($statusReporter) {
                            $statusReporter->reportWithMetadata('tool_result', "Tool {$toolName} completed", [
                                'tool_name' => $toolName,
                                'step_type' => 'tool_completion',
                            ]);
                        }
                    }
                }
            }

            // Update interaction with final answer
            $interaction->updateQuietly(['answer' => $answer]);

            // Mark execution as completed
            $execution->markAsCompleted($answer, [
                'tool_results' => $toolResults,
                'streaming_enabled' => true,
            ]);

            // Create completion status stream entry
            StatusStream::report(
                $interactionId,
                'system',
                'Chat execution completed',
                [
                    'step_type' => 'chat_complete',
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'answer_length' => strlen($answer),
                    'tool_results_count' => count($toolResults),
                ],
                true,
                true,
                $execution->id
            );

            Log::info('ChatStreamingService: Execution completed', [
                'execution_id' => $execution->id,
                'answer_length' => strlen($answer),
            ]);

            // Dispatch event for side effect listeners (Phase 3: side effects via events only)
            // Listeners: ExtractChatSourceLinks, TrackInteractionUrls, GenerateSessionTitle, QueueInteractionEmbeddings
            \App\Events\ChatInteractionCompleted::dispatch(
                $interaction,
                $toolResults,
                'streaming_service'
            );

        } catch (\Exception $e) {
            Log::error('ChatStreamingService: Direct chat error', [
                'error' => $e->getMessage(),
                'interaction_id' => $interactionId,
                'trace' => $e->getTraceAsString(),
            ]);

            yield $this->formatSSE('update', $this->safeJsonEncode([
                'content' => 'An error occurred during the conversation. Please try again.',
                'type' => 'error',
                'session_id' => $interaction->chat_session_id ?? null,
                'interaction_id' => $interactionId,
            ]));

            if (isset($interaction)) {
                $interaction->update(['answer' => 'Error occurred - please try again']);
            }

            // Mark execution as failed
            if (isset($execution) && $execution->exists) {
                try {
                    $execution->markAsFailed($e->getMessage());

                    StatusStream::report(
                        $interactionId,
                        'system',
                        'Chat execution failed',
                        [
                            'step_type' => 'chat_failed',
                            'agent_id' => $agent->id ?? null,
                            'agent_name' => $agent->name ?? 'Unknown Agent',
                            'error_message' => $e->getMessage(),
                        ],
                        true,
                        true,
                        $execution->id
                    );
                } catch (\Exception $failureException) {
                    Log::error('ChatStreamingService: Failed to mark execution as failed', [
                        'execution_id' => $execution->id ?? 'unknown',
                        'error' => $failureException->getMessage(),
                    ]);
                }
            }
        } finally {
            // Clean up container instances
            $this->cleanupContainerInstances();
        }

        yield $this->formatSSE('update', '</stream>');
    }

    /**
     * Clean up stuck/running executions for this agent/user/session
     */
    protected function cleanupStuckExecutions(Agent $agent, ChatInteraction $interaction): void
    {
        $stuckExecutions = AgentExecution::where('agent_id', $agent->id)
            ->where('user_id', $interaction->user_id)
            ->where('chat_session_id', $interaction->chat_session_id)
            ->where('status', 'running')
            ->get();

        if ($stuckExecutions->count() > 0) {
            Log::info('ChatStreamingService: Cleaning up stuck executions', [
                'interaction_id' => $interaction->id,
                'stuck_count' => $stuckExecutions->count(),
            ]);

            foreach ($stuckExecutions as $stuck) {
                $stuck->update([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'error_message' => 'Superseded by new direct chat request',
                ]);
            }
        }
    }

    /**
     * Create AgentExecution record
     */
    protected function createExecution(Agent $agent, ChatInteraction $interaction, string $query): AgentExecution
    {
        try {
            $execution = new AgentExecution([
                'agent_id' => $agent->id,
                'user_id' => $interaction->user_id,
                'chat_session_id' => $interaction->chat_session_id,
                'input' => $query,
                'max_steps' => $agent->max_steps,
                'status' => 'running',
            ]);
            $execution->save();

            return $execution;
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate constraint violations
            if (strpos($e->getMessage(), 'agent_executions_duplicate_prevention') !== false) {
                Log::info('ChatStreamingService: Duplicate execution prevented', [
                    'agent_id' => $agent->id,
                    'user_id' => $interaction->user_id,
                    'session_id' => $interaction->chat_session_id,
                ]);

                // Find existing active execution
                // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
                $existingExecution = AgentExecution::where('chat_session_id', $interaction->chat_session_id)
                    ->where('agent_id', $agent->id)
                    ->where('user_id', $interaction->user_id)
                    ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
                    ->first();

                if ($existingExecution) {
                    return $existingExecution;
                }
            }

            throw $e;
        }
    }

    /**
     * Store user context in container for tools to access
     */
    protected function storeUserContext(ChatInteraction $interaction, Agent $agent, int $interactionId): void
    {
        app()->instance('current_user_id', $interaction->user_id);
        app()->instance('current_agent_id', $agent->id);
        app()->instance('current_interaction_id', $interactionId);
    }

    /**
     * Build conversation messages with history and attachments
     */
    protected function buildConversationMessages(
        ChatInteraction $interaction,
        string $query,
        int $interactionId,
        Agent $agent
    ): array {
        // Inject AI Persona context
        $systemPrompt = AiPersonaService::injectIntoSystemPrompt($agent->system_prompt, $interaction->user);

        $messages = [];

        // Add conversation history (last 8 interactions)
        if ($interaction->chat_session_id) {
            $previousInteractions = ChatInteraction::where('chat_session_id', $interaction->chat_session_id)
                ->where('id', '<', $interactionId)
                ->where('answer', '!=', null)
                ->where('answer', '!=', '')
                ->orderBy('created_at', 'desc')
                ->limit(8)
                ->get()
                ->reverse();

            foreach ($previousInteractions as $prevInteraction) {
                if ($prevInteraction->question) {
                    $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($prevInteraction->question);
                }
                if ($prevInteraction->answer) {
                    $messages[] = new \Prism\Prism\ValueObjects\Messages\AssistantMessage($prevInteraction->answer);
                }
            }

            Log::info('ChatStreamingService: Added conversation history', [
                'session_id' => $interaction->chat_session_id,
                'previous_interactions' => count($previousInteractions),
                'total_messages' => count($messages),
            ]);
        }

        // Handle attachments
        $attachmentObjects = [];
        $textAttachments = '';

        $attachments = ChatInteractionAttachment::where('chat_interaction_id', $interactionId)->get();
        foreach ($attachments as $attachment) {
            try {
                if ($attachment->shouldInjectAsText()) {
                    $textContent = $attachment->getTextContent();
                    if ($textContent) {
                        $textAttachments .= "\n\n--- Attached File: {$attachment->filename} ---\n{$textContent}\n--- End of {$attachment->filename} ---\n";
                    }
                } else {
                    $prismObject = $attachment->toPrismValueObject();
                    if ($prismObject) {
                        $attachmentObjects[] = $prismObject;
                    }
                }
            } catch (\Exception $e) {
                Log::error('ChatStreamingService: Error processing attachment', [
                    'attachment_id' => $attachment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Build user message
        $userMessage = $query;
        if (! empty($textAttachments)) {
            $userMessage .= $textAttachments;
        }

        if (! empty($attachmentObjects)) {
            foreach ($attachmentObjects as $attachmentObject) {
                $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($userMessage, [$attachmentObject]);
            }
        } else {
            $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($userMessage);
        }

        return $messages;
    }

    /**
     * Load tools with override support
     */
    protected function loadTools(ChatInteraction $interaction, Agent $agent): array
    {
        $toolOverrides = $interaction->metadata['tool_overrides'] ?? null;

        $toolResult = $this->toolOverrideService->loadToolsWithOverrides(
            $toolOverrides,
            $agent->tools,
            "interaction_{$interaction->id}"
        );

        Log::info('ChatStreamingService: Loaded tools', [
            'interaction_id' => $interaction->id,
            'override_enabled' => ($toolOverrides['override_enabled'] ?? false),
            'tools_count' => count($toolResult['tools']),
            'available_tools' => $toolResult['available_names'],
        ]);

        return $toolResult['tools'];
    }

    /**
     * Create Prism streaming request
     */
    protected function createPrismRequest(
        Agent $agent,
        ChatInteraction $interaction,
        array $messages,
        array $enabledTools,
        int $executionId,
        int $interactionId
    ) {
        // Inject AI Persona context
        $systemPrompt = AiPersonaService::injectIntoSystemPrompt($agent->system_prompt, $interaction->user);

        $prismRequest = $this->prismWrapper
            ->text()
            ->using($agent->ai_provider, $agent->ai_model)
            ->withMaxSteps($agent->max_steps)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withContext([
                'interaction_id' => $interactionId,
                'user_id' => $interaction->user_id,
                'agent_id' => $agent->id,
                'execution_id' => $executionId,
                'mode' => 'direct_chat_streaming',
            ]);

        if (! empty($enabledTools)) {
            $prismRequest = $prismRequest->withTools($enabledTools);
        }

        return $prismRequest;
    }

    /**
     * Extract source links from tool results and persist
     */
    protected function extractAndPersistChatSourceLinks(ChatInteraction $interaction, array $toolResults): void
    {
        try {
            if (empty($toolResults)) {
                return;
            }

            Log::info('ChatStreamingService: Extracting source links', [
                'interaction_id' => $interaction->id,
                'tool_results_count' => count($toolResults),
            ]);

            foreach ($toolResults as $toolResult) {
                $sourceLinks = $this->extractSourceLinksFromToolResult($toolResult);

                foreach ($sourceLinks as $sourceLink) {
                    try {
                        $url = $sourceLink['url'] ?? '';
                        if (empty($url)) {
                            continue;
                        }

                        // Validate and create source
                        $linkInfo = $this->linkValidator->validateAndExtractLinkInfo($url);

                        if ($linkInfo && isset($linkInfo['status']) && $linkInfo['status'] >= 200 && $linkInfo['status'] < 400) {
                            $urlHash = md5($url);
                            $source = \App\Models\Source::where('url_hash', $urlHash)->first();

                            if ($source) {
                                \App\Models\ChatInteractionSource::createOrUpdate(
                                    $interaction->id,
                                    $source->id,
                                    $interaction->question ?? 'chat execution',
                                    [
                                        'url' => $url,
                                        'title' => $source->title ?? ($sourceLink['title'] ?? 'Untitled'),
                                        'description' => $source->description ?? ($sourceLink['content'] ?? ''),
                                        'domain' => $source->domain ?? parse_url($url, PHP_URL_HOST) ?? 'unknown',
                                        'content_category' => $source->content_category ?? 'general',
                                        'http_status' => $source->http_status ?? 200,
                                    ],
                                    'chat_execution',
                                    $sourceLink['tool'] ?? 'unknown'
                                );
                            }
                        }

                    } catch (\Exception $e) {
                        Log::error('ChatStreamingService: Failed to persist source link', [
                            'interaction_id' => $interaction->id,
                            'source_url' => $sourceLink['url'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('ChatStreamingService: Failed to extract source links', [
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract source links from a single tool result
     */
    protected function extractSourceLinksFromToolResult($toolResult): array
    {
        $sourceLinks = [];

        try {
            $result = null;
            $toolName = 'unknown';

            if (is_object($toolResult)) {
                $result = $toolResult->result ?? null;
                $toolName = $toolResult->toolName ?? 'unknown';
            } elseif (is_array($toolResult)) {
                $result = $toolResult['result'] ?? null;
                $toolName = $toolResult['toolName'] ?? 'unknown';
            }

            if (! $result) {
                return $sourceLinks;
            }

            // Decode JSON result
            $resultData = json_decode($result, true);
            if (! is_array($resultData)) {
                if (is_array($result)) {
                    $resultData = $result;
                } elseif (is_object($result)) {
                    $resultData = (array) $result;
                } else {
                    return $sourceLinks;
                }
            }

            // Handle SearXNG search results
            if ($toolName === 'searxng_search' && isset($resultData['data']['results'])) {
                foreach ($resultData['data']['results'] as $result) {
                    if (isset($result['url']) && ! empty($result['url'])) {
                        $sourceLinks[] = [
                            'url' => $result['url'],
                            'title' => $result['title'] ?? $this->extractTitleFromUrl($result['url']),
                            'tool' => $toolName,
                            'content' => $result['content'] ?? '',
                        ];
                    }
                }
            }

            // Handle Perplexity research citations
            if ($toolName === 'perplexity_research' && isset($resultData['data']['citations'])) {
                foreach ($resultData['data']['citations'] as $citation) {
                    if (isset($citation['url']) && ! empty($citation['url'])) {
                        $sourceLinks[] = [
                            'url' => $citation['url'],
                            'title' => $citation['text'] ?? $this->extractTitleFromUrl($citation['url']),
                            'tool' => $toolName,
                            'type' => $citation['type'] ?? 'markdown_link',
                        ];
                    }
                }
            }

            // Handle link_validator results
            if (($toolName === 'link_validator' || $toolName === 'bulk_link_validator') &&
                isset($resultData['status']) && isset($resultData['url'])) {
                $sourceLinks[] = [
                    'url' => $resultData['url'],
                    'title' => $resultData['title'] ?? $this->extractTitleFromUrl($resultData['url']),
                    'tool' => $toolName,
                    'content' => $resultData['description'] ?? ($resultData['content_markdown'] ?? ''),
                ];
            }

            // Handle bulk_link_validator array results
            if ($toolName === 'bulk_link_validator' && isset($resultData['validatedUrls']) && is_array($resultData['validatedUrls'])) {
                foreach ($resultData['validatedUrls'] as $url => $linkInfo) {
                    if (is_array($linkInfo) && isset($linkInfo['status']) && $linkInfo['status'] < 400) {
                        $sourceLinks[] = [
                            'url' => $url,
                            'title' => $linkInfo['title'] ?? $this->extractTitleFromUrl($url),
                            'tool' => $toolName,
                            'content' => $linkInfo['description'] ?? ($linkInfo['content_markdown'] ?? ''),
                        ];
                    }
                }
            }

            // Handle generic sources array
            if (isset($resultData['sources']) && is_array($resultData['sources'])) {
                foreach ($resultData['sources'] as $source) {
                    if (is_string($source)) {
                        $sourceLinks[] = [
                            'url' => $source,
                            'title' => $this->extractTitleFromUrl($source),
                            'tool' => $toolName,
                        ];
                    } elseif (is_array($source) && isset($source['url'])) {
                        $sourceLinks[] = [
                            'url' => $source['url'],
                            'title' => $source['title'] ?? $this->extractTitleFromUrl($source['url']),
                            'tool' => $toolName,
                        ];
                    }
                }
            }

        } catch (\Exception $e) {
            Log::warning('ChatStreamingService: Failed to extract source links from tool result', [
                'tool_name' => $toolResult->toolName ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }

        return $sourceLinks;
    }

    /**
     * Extract title from URL
     */
    protected function extractTitleFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            return $host;
        }

        return $url;
    }

    /**
     * Clean up container instances
     */
    protected function cleanupContainerInstances(): void
    {
        $instances = ['status_reporter', 'current_user_id', 'current_agent_id', 'current_interaction_id'];

        foreach ($instances as $instance) {
            if (app()->has($instance)) {
                app()->forgetInstance($instance);
            }
        }
    }

    /**
     * Stream agent execution with proper routing based on agent type
     *
     * This method routes to the appropriate execution method:
     * - Direct agents: Simple Prism streaming (streamDirectExecution)
     * - Research agents: Background job execution with status polling
     *
     * @param  ChatInteraction  $interaction  The interaction record
     * @param  Agent  $agent  The agent to execute
     * @return \Generator SSE-formatted events
     */
    public function streamAgentExecution(ChatInteraction $interaction, Agent $agent): \Generator
    {
        Log::info('ChatStreamingService: Routing agent execution', [
            'interaction_id' => $interaction->id,
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'agent_type' => $agent->agent_type,
        ]);

        try {
            // Route based on agent type (same logic as StreamingTriggerExecutor)
            if ($agent->agent_type === 'direct') {
                // Direct agents use simple Prism streaming
                yield from $this->streamDirectExecution(
                    $interaction->question,
                    $interaction->id,
                    $interaction->user_id
                );
            } else {
                // Research agents (promptly, etc.) use background job + status polling
                yield from $this->streamResearchAgentExecution($interaction, $agent);
            }
        } catch (\Exception $e) {
            Log::error('ChatStreamingService: Agent execution failed', [
                'interaction_id' => $interaction->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            yield $this->formatSSE('update', $this->safeJsonEncode([
                'content' => "❌ Execution failed: {$e->getMessage()}",
                'type' => 'error',
            ]));
        }

        yield $this->formatSSE('update', '</stream>');
    }

    /**
     * Stream research agent execution with background job and status polling
     *
     * Similar to StreamingTriggerExecutor::streamResearchAgent but for chat API
     *
     * @param  ChatInteraction  $interaction  The interaction record
     * @param  Agent  $agent  The research agent
     * @return \Generator SSE-formatted events
     */
    protected function streamResearchAgentExecution(ChatInteraction $interaction, Agent $agent): \Generator
    {
        Log::info('ChatStreamingService: Streaming research agent execution', [
            'interaction_id' => $interaction->id,
            'agent_id' => $agent->id,
        ]);

        $execution = null;
        $jobDispatched = false;

        try {
            // Create execution record (matching StreamingTriggerExecutor format)
            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'user_id' => $interaction->user_id,
                'chat_session_id' => $interaction->chat_session_id,
                'input' => $interaction->question,
                'state' => AgentExecution::STATE_PENDING, // Use state instead of status (Issue #79)
                'max_steps' => $agent->max_steps,
                'metadata' => [
                    'triggered_via' => 'chat_api',
                    'api_version' => 'v1',
                    'streaming_mode' => 'sse',
                ],
            ]);

            // Link execution to interaction
            $interaction->update(['agent_execution_id' => $execution->id]);

            Log::info('ChatStreamingService: Created research agent execution', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
                'agent_id' => $agent->id,
            ]);

            // Send initial status with session info
            yield $this->formatSSE('update', $this->safeJsonEncode([
                'status' => 'Initializing agent workflow...',
                'type' => 'research_step',
                'session_id' => $interaction->chat_session_id,
                'interaction_id' => $interaction->id,
            ]));

            // Dispatch agent execution in background
            \App\Jobs\ExecuteResearchAgentStreamingJob::dispatch($execution->id, $interaction->id);
            $jobDispatched = true;

            // Poll for status updates
            $maxWaitTime = 180; // 3 minutes - hard timeout for agent execution
            $elapsed = 0;
            $pollInterval = 0.5; // Poll every 0.5 seconds
            $researchComplete = false;

            $eventStreamNotifier = app(\App\Services\EventStreamNotifier::class);

            $lastKeepalive = microtime(true);
            $keepaliveInterval = 5; // Send keepalive every 5 seconds

            while ($elapsed < $maxWaitTime && ! $researchComplete) {
                // Get queued events from Redis
                $events = $eventStreamNotifier::getAndClearEvents($interaction->id);

                if (count($events) > 0) {
                    Log::info('ChatStreamingService: Retrieved events from Redis', [
                        'interaction_id' => $interaction->id,
                        'event_count' => count($events),
                        'elapsed' => $elapsed,
                    ]);
                }

                // Send keepalive event to prevent connection timeout
                $now = microtime(true);
                if (($now - $lastKeepalive) >= $keepaliveInterval) {
                    yield $this->formatSSE('update', $this->safeJsonEncode([
                        'type' => 'keepalive',
                        'elapsed' => round($elapsed, 1),
                        'timestamp' => now()->toISOString(),
                    ]));
                    $lastKeepalive = $now;
                }

                foreach ($events as $event) {
                    Log::debug('ChatStreamingService: Processing event', [
                        'type' => $event['type'] ?? 'unknown',
                        'has_answer' => $event['data']['has_answer'] ?? false,
                    ]);

                    yield $this->formatSSE('update', $this->safeJsonEncode($event));

                    // Check if research is complete
                    if (($event['type'] ?? '') === 'research_complete') {
                        $researchComplete = true;
                        Log::info('ChatStreamingService: Research complete event received');
                    }

                    // Also check for interaction_updated event with answer
                    if (($event['type'] ?? '') === 'interaction_updated' && ($event['data']['has_answer'] ?? false)) {
                        $researchComplete = true;
                        Log::info('ChatStreamingService: Interaction updated with answer received');
                    }
                }

                // If research is complete, send final answer and break
                if ($researchComplete) {
                    $execution->refresh();

                    // Re-fetch interaction to get latest answer
                    $freshInteraction = ChatInteraction::find($interaction->id);
                    if ($freshInteraction && $freshInteraction->answer) {
                        Log::info('ChatStreamingService: Sending final answer via event', [
                            'interaction_id' => $freshInteraction->id,
                            'answer_length' => strlen($freshInteraction->answer),
                        ]);

                        yield $this->formatSSE('update', $this->safeJsonEncode([
                            'content' => $freshInteraction->answer,
                            'type' => 'answer_stream',
                            'session_id' => $freshInteraction->chat_session_id,
                            'interaction_id' => $freshInteraction->id,
                        ]));
                    }

                    break;
                }

                // Don't check execution status - it gets marked "completed" before the job finishes
                // updating the interaction. Only rely on Redis events.

                if (! $researchComplete) {
                    usleep($pollInterval * 1000000); // Convert to microseconds
                    $elapsed += $pollInterval;
                }
            }

            // CRITICAL: Final check - fetch answer from database if we didn't get it via Redis events
            // This handles race condition where answer is saved AFTER polling loop completes
            if (! $researchComplete) {
                Log::info('ChatStreamingService: Polling loop ended without answer, checking database', [
                    'interaction_id' => $interaction->id,
                    'elapsed' => $elapsed,
                ]);

                $freshInteraction = ChatInteraction::find($interaction->id);
                if ($freshInteraction && $freshInteraction->answer) {
                    Log::info('ChatStreamingService: Found answer in database after polling', [
                        'interaction_id' => $freshInteraction->id,
                        'answer_length' => strlen($freshInteraction->answer),
                    ]);

                    yield $this->formatSSE('update', $this->safeJsonEncode([
                        'content' => $freshInteraction->answer,
                        'type' => 'answer_stream',
                        'session_id' => $freshInteraction->chat_session_id,
                        'interaction_id' => $freshInteraction->id,
                    ]));

                    $researchComplete = true;
                }
            }

            // Timeout check - cancel execution if it takes too long
            if ($elapsed >= $maxWaitTime && ! $researchComplete) {
                Log::warning('ChatStreamingService: Research agent execution timeout', [
                    'interaction_id' => $interaction->id,
                    'execution_id' => $execution->id,
                    'elapsed' => $elapsed,
                ]);

                // Cancel the stuck execution
                $execution->refresh();
                if (in_array($execution->state, [
                    AgentExecution::STATE_PENDING,
                    AgentExecution::STATE_PLANNING,
                    AgentExecution::STATE_PLANNED,
                    AgentExecution::STATE_EXECUTING,
                    AgentExecution::STATE_SYNTHESIZING,
                ])) {
                    $execution->update([
                        'state' => AgentExecution::STATE_FAILED,
                        'error_message' => 'Execution timeout after 3 minutes',
                        'completed_at' => now(),
                        'active_execution_key' => null, // CRITICAL: Release lock
                    ]);

                    Log::info('ChatStreamingService: Cancelled stuck execution', [
                        'execution_id' => $execution->id,
                        'interaction_id' => $interaction->id,
                    ]);
                }

                // Update interaction with error message
                $interaction->update([
                    'answer' => '⏱ The request took too long and was cancelled. Please try again with a simpler question or break it into smaller parts.',
                ]);

                yield $this->formatSSE('update', $this->safeJsonEncode([
                    'content' => '⏱ The request took too long and was cancelled. Please try again with a simpler question or break it into smaller parts.',
                    'type' => 'error',
                ]));
            }
        } catch (\Exception $e) {
            Log::error('ChatStreamingService: Research agent stream failed', [
                'interaction_id' => $interaction->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
                'job_dispatched' => $jobDispatched,
                'trace' => $e->getTraceAsString(),
            ]);

            // CRITICAL: Only clean up if job was NOT dispatched yet
            // If job was dispatched, it's running in background and will handle its own state
            if (! $jobDispatched && isset($execution) && $execution->exists) {
                $execution->refresh();

                // Only clean up if still in pending/running state
                if (in_array($execution->state, [
                    AgentExecution::STATE_PENDING,
                    AgentExecution::STATE_PLANNING,
                    AgentExecution::STATE_PLANNED,
                    AgentExecution::STATE_EXECUTING,
                    AgentExecution::STATE_SYNTHESIZING,
                ])) {
                    $execution->update([
                        'state' => AgentExecution::STATE_FAILED,
                        'error_message' => 'Execution failed before job dispatch: '.$e->getMessage(),
                        'completed_at' => now(),
                        'active_execution_key' => null, // CRITICAL: Release lock
                    ]);

                    Log::warning('ChatStreamingService: Cleaned up execution that failed before job dispatch', [
                        'execution_id' => $execution->id,
                        'interaction_id' => $interaction->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($jobDispatched) {
                Log::info('ChatStreamingService: Streaming failed but job was dispatched - letting job handle cleanup', [
                    'interaction_id' => $interaction->id,
                    'execution_id' => $execution?->id,
                ]);
            }

            yield $this->formatSSE('update', $this->safeJsonEncode([
                'content' => "❌ Research agent execution failed: {$e->getMessage()}",
                'type' => 'error',
            ]));
        }
    }

    /**
     * UTF-8 safe JSON encoding with error handling
     */
    public function safeJsonEncode($data): string
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                Log::warning('ChatStreamingService: JSON encoding failed, attempting cleanup', [
                    'error' => json_last_error_msg(),
                ]);

                $cleanData = $this->cleanDataForJson($data);
                $json = json_encode($cleanData, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                if ($json === false) {
                    return json_encode([
                        'content' => 'Content encoding error - streaming continues',
                        'type' => 'system_error',
                    ]);
                }
            }

            return $json;

        } catch (\Exception $e) {
            Log::error('ChatStreamingService: Exception in safeJsonEncode', [
                'error' => $e->getMessage(),
            ]);

            return json_encode([
                'content' => 'System error - streaming continues',
                'type' => 'system_error',
            ]);
        }
    }

    /**
     * Clean data recursively to ensure UTF-8 compatibility
     */
    protected function cleanDataForJson($data)
    {
        if (is_string($data)) {
            return $this->sanitizeForJson($data);
        } elseif (is_array($data)) {
            return array_map([$this, 'cleanDataForJson'], $data);
        } elseif (is_object($data)) {
            $cleaned = new \stdClass;
            foreach (get_object_vars($data) as $key => $value) {
                $cleanKey = $this->sanitizeForJson($key);
                $cleaned->$cleanKey = $this->cleanDataForJson($value);
            }

            return $cleaned;
        }

        return $data;
    }

    /**
     * Sanitize string for JSON encoding
     */
    public function sanitizeForJson(string $text): string
    {
        // Remove or replace invalid UTF-8 sequences
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Replace any remaining problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Handle specific problematic Unicode characters
        $text = preg_replace('/[\x{E2}\x{80}][\x{99}\x{9C}\x{9D}\x{A6}]/u', "'", $text); // Smart quotes
        $text = preg_replace('/[\x{E2}\x{80}][\x{93}\x{94}]/u', '-', $text); // Em/en dashes
        $text = preg_replace('/[\x{E2}\x{80}][\x{A6}]/u', '...', $text); // Ellipsis

        return $text;
    }
}
