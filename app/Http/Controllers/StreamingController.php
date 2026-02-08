<?php

namespace App\Http\Controllers;

use App\Livewire\Traits\HasToolManagement;
use App\Services\AttachmentProcessor;
use App\Services\EventStreamNotifier;
use App\Services\SourceLinkExtractor;
use App\Services\StatusReporter;
use App\Traits\HandlesExecutionFailures;
use App\Traits\UsesAIModels;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Log;

/**
 * Handles real-time SSE streaming for direct chat and agent executions.
 *
 * This controller manages two primary streaming flows:
 * 1. Direct Chat: Immediate AI responses without queue system
 * 2. Agent Execution: Long-running research agents with status polling
 *
 * Key responsibilities:
 * - SSE event formatting and UTF-8 sanitization
 * - Tool management and execution context
 * - Source link extraction and persistence
 * - Session title generation
 * - Error recovery with retry logic
 *
 * @see \App\Services\AI\PrismWrapper For AI provider abstraction
 * @see \App\Services\StatusReporter For real-time status updates
 */
class StreamingController extends Controller
{
    use HandlesExecutionFailures, HasToolManagement, UsesAIModels;

    protected SourceLinkExtractor $sourceLinkExtractor;

    protected AttachmentProcessor $attachmentProcessor;

    public function __construct(
        SourceLinkExtractor $sourceLinkExtractor,
        AttachmentProcessor $attachmentProcessor
    ) {
        $this->sourceLinkExtractor = $sourceLinkExtractor;
        $this->attachmentProcessor = $attachmentProcessor;
    }

    /**
     * Stream direct chat responses with real-time AI interaction
     * Bypasses queue system for immediate streaming feedback
     */
    public function streamDirectChat(Request $request)
    {
        \Log::info('StreamingController: streamDirectChat route hit', [
            'url' => $request->fullUrl(),
            'query' => $request->query('query'),
            'interactionId' => $request->query('interactionId'),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        $query = $request->query('query');
        $interactionId = $request->query('interactionId');

        if (! $query || ! $interactionId) {
            return response()->stream(function () {
                echo $this->formatSSE('update', json_encode([
                    'content' => 'Missing query or interaction ID',
                    'type' => 'error',
                ]));

                echo $this->formatSSE('update', '</stream>');
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        return response()->stream(function () use ($query, $interactionId) {
            yield from $this->streamDirectChatResponse($query, (int) $interactionId);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Helper method to format SSE (Server-Sent Events) string
     */
    protected function formatSSE(string $event, string $data): string
    {
        return "event: {$event}\ndata: {$data}\n\n";
    }

    /**
     * Generate direct chat streaming response with tool execution support.
     *
     * This generator handles the complete lifecycle of a direct chat interaction:
     * - Creates AgentExecution record for tool context
     * - Manages conversation history (last 8 interactions)
     * - Processes file attachments (text injection or Prism objects)
     * - Streams AI responses with real-time updates
     * - Extracts and persists source links from tool results
     * - Generates session title if needed
     *
     * @param  string  $query  User's input message
     * @param  int  $interactionId  ChatInteraction ID for this conversation turn
     * @return \Generator<string> SSE-formatted event strings
     *
     * @throws \Exception On critical failures (AI errors, database issues, etc.)
     */
    protected function streamDirectChatResponse(string $query, int $interactionId): \Generator
    {
        $streamingSessionId = uniqid('stream_', true);
        $sessionStartTime = microtime(true);

        app()->instance('streaming_session_id', $streamingSessionId);

        \Log::info('StreamingController: Starting direct chat streaming session', [
            'streaming_session_id' => $streamingSessionId,
            'interaction_id' => $interactionId,
            'query_length' => strlen($query),
            'query_preview' => substr($query, 0, 100),
            'user_id' => auth()->id(),
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip(),
            'request_id' => request()->header('X-Request-ID'),
        ]);

        try {
            $interaction = \App\Models\ChatInteraction::find($interactionId);
            if (! $interaction) {
                yield $this->formatSSE('update', json_encode([
                    'content' => 'Interaction not found',
                    'type' => 'error',
                ]));
                yield $this->formatSSE('update', '</stream>');

                return;
            }

            $directAgent = \App\Models\Agent::directType()->first();
            if (! $directAgent) {
                throw new \Exception('Direct Chat Agent not found. Please run database seeder.');
            }

            $stuckExecutions = \App\Models\AgentExecution::where('agent_id', $directAgent->id)
                ->where('user_id', $interaction->user_id)
                ->where('chat_session_id', $interaction->chat_session_id)
                ->where('status', 'running')
                ->get();

            if ($stuckExecutions->count() > 0) {
                \Log::info('StreamingController: Cleaning up stuck Direct Chat executions', [
                    'streaming_session_id' => $streamingSessionId,
                    'interaction_id' => $interactionId,
                    'stuck_count' => $stuckExecutions->count(),
                    'stuck_ids' => $stuckExecutions->pluck('id')->toArray(),
                ]);

                foreach ($stuckExecutions as $stuck) {
                    $stuck->update([
                        'state' => \App\Models\AgentExecution::STATE_CANCELLED,
                        'completed_at' => now(),
                        'error_message' => 'Superseded by new direct chat request',
                    ]);
                }
            }

            try {
                $execution = new \App\Models\AgentExecution([
                    'agent_id' => $directAgent->id,
                    'user_id' => $interaction->user_id,
                    'chat_session_id' => $interaction->chat_session_id,
                    'input' => $query,
                    'max_steps' => $directAgent->max_steps,
                    'state' => \App\Models\AgentExecution::STATE_EXECUTING,
                ]);
                $execution->save();
            } catch (\Illuminate\Database\QueryException $e) {
                $errorMessage = $e->getMessage();
                $isDuplicateConstraint = false;

                if (strpos($errorMessage, 'agent_executions_duplicate_prevention') !== false) {
                    $isDuplicateConstraint = true;
                }

                if ($isDuplicateConstraint) {
                    \Log::warning('StreamingController: Duplicate direct chat execution prevented', [
                        'agent_id' => $directAgent->id,
                        'user_id' => $interaction->user_id,
                        'chat_session_id' => $interaction->chat_session_id,
                        'interaction_id' => $interactionId,
                        'constraint_name' => 'agent_executions_duplicate_prevention',
                        'ip' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                    ]);

                    $existingExecution = \App\Models\AgentExecution::where('chat_session_id', $interaction->chat_session_id)
                        ->where('agent_id', $directAgent->id)
                        ->where('user_id', $interaction->user_id)
                        ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
                        ->first();

                    if ($existingExecution) {
                        \Log::info('StreamingController: Using existing active execution instead of creating duplicate', [
                            'existing_execution_id' => $existingExecution->id,
                            'status' => $existingExecution->status,
                        ]);

                        $execution = $existingExecution;
                    } else {
                        throw $e;
                    }
                } else {
                    throw $e;
                }
            }

            $interaction->update(['agent_execution_id' => $execution->id]);

            \App\Models\StatusStream::report(
                $interactionId,
                'system',
                'Direct Chat execution started',
                [
                    'step_type' => 'direct_chat_start',
                    'agent_id' => $directAgent->id,
                    'agent_name' => $directAgent->name,
                ],
                true, // create_event
                true, // is_significant
                $execution->id
            );

            app()->instance('current_user_id', $interaction->user_id);
            app()->instance('current_agent_id', $directAgent->id);
            app()->instance('current_interaction_id', $interactionId);

            \Log::info('StreamingController: Created AgentExecution for Direct Chat', [
                'streaming_session_id' => $streamingSessionId,
                'execution_id' => $execution->id,
                'interaction_id' => $interactionId,
                'user_id' => $interaction->user_id,
                'agent_id' => $directAgent->id,
            ]);

            $statusReporter = new StatusReporter($interactionId, $execution->id);
            app()->instance('status_reporter', $statusReporter);

            $systemPrompt = \App\Services\AiPersonaService::injectIntoSystemPrompt($directAgent->system_prompt, $interaction->user);

            $messages = [];

            if ($interaction->chat_session_id) {
                $previousInteractions = \App\Models\ChatInteraction::where('chat_session_id', $interaction->chat_session_id)
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

                \Log::info('StreamingController: Direct chat - added conversation history', [
                    'session_id' => $interaction->chat_session_id,
                    'previous_interactions' => count($previousInteractions),
                    'total_messages' => count($messages),
                ]);
            }

            $attachments = \App\Models\ChatInteractionAttachment::where('chat_interaction_id', $interactionId)->get();
            $processed = $this->attachmentProcessor->process($attachments, "interaction_{$interactionId}");

            $userMessage = $query;
            if (! empty($processed['text_content'])) {
                $userMessage .= $processed['text_content'];
            }

            if (! empty($processed['prism_objects'])) {
                foreach ($processed['prism_objects'] as $prismObject) {
                    $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($userMessage, [$prismObject]);
                }
            } else {
                $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($userMessage);
            }

            $toolOverrideService = app(\App\Services\Agents\ToolOverrideService::class);

            $toolOverrides = $interaction->metadata['tool_overrides'] ?? null;

            $toolResult = $toolOverrideService->loadToolsWithOverrides(
                $toolOverrides,
                $directAgent->tools,
                "interaction_{$interaction->id}"
            );

            $enabledTools = $toolResult['tools'];

            Log::info('StreamingController: Loaded tools for Direct Chat', [
                'interaction_id' => $interaction->id,
                'override_enabled' => ($toolOverrides['override_enabled'] ?? false),
                'tools_count' => count($enabledTools),
                'available_tools' => $toolResult['available_names'],
                'failed_tools' => $toolResult['failed_names'],
            ]);

            $prismRequest = app(\App\Services\AI\PrismWrapper::class)
                ->text()
                ->using($directAgent->ai_provider, $directAgent->ai_model)
                ->withMaxSteps($directAgent->max_steps)
                ->withSystemPrompt($systemPrompt)
                ->withMessages($messages)
                ->withContext([
                    'interaction_id' => $interactionId,
                    'user_id' => $interaction->user_id,
                    'agent_id' => $directAgent->id,
                    'execution_id' => $execution->id,
                    'mode' => 'direct_chat_streaming',
                ]);

            if (! empty($enabledTools)) {
                $prismRequest = $prismRequest->withTools($enabledTools);
            }

            $response = $prismRequest->asStream();
            $answer = '';
            $toolResults = [];

            foreach ($response as $chunk) {
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

                if (isset($chunk->text)) {
                    $answer .= $chunk->text;

                    yield $this->formatSSE('update', $this->safeJsonEncode([
                        'content' => $this->sanitizeForJson($answer),
                        'type' => 'answer_stream',
                    ]));
                }

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

            $interaction->updateQuietly(['answer' => $answer]);

            $execution->markAsCompleted($answer, [
                'tool_results' => $toolResults,
                'streaming_enabled' => true,
            ]);

            \App\Models\StatusStream::report(
                $interactionId,
                'system',
                'Direct Chat execution completed',
                [
                    'step_type' => 'direct_chat_complete',
                    'agent_id' => $directAgent->id,
                    'agent_name' => $directAgent->name,
                    'answer_length' => strlen($answer),
                    'tool_results_count' => count($toolResults),
                ],
                true,
                true,
                $execution->id
            );

            \Log::info('StreamingController: Direct Chat execution completed', [
                'execution_id' => $execution->id,
                'interaction_id' => $interactionId,
                'answer_length' => strlen($answer),
            ]);

            \App\Events\ChatInteractionCompleted::dispatch(
                $interaction,
                $toolResults,
                'streaming_controller'
            );

        } catch (\Exception $e) {
            \Log::error('StreamingController: Direct chat error', [
                'streaming_session_id' => $streamingSessionId ?? 'unknown',
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'interaction_id' => $interactionId,
                'execution_id' => $execution->id ?? null,
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
            ]);

            yield $this->formatSSE('update', $this->safeJsonEncode([
                'content' => 'An error occurred during the conversation. Please try again.',
                'type' => 'answer_stream',
            ]));

            if (isset($interaction)) {
                $interaction->update(['answer' => 'Error occurred - please try again']);
            }

            if (isset($execution) && $execution->exists) {
                $this->safeMarkAsFailed(
                    $execution,
                    $e->getMessage(),
                    [
                        'context' => 'direct_chat_streaming',
                        'interaction_id' => $interactionId,
                        'agent_id' => $directAgent->id ?? null,
                    ],
                    false
                );

                \App\Models\StatusStream::report(
                    $interactionId,
                    'system',
                    'Direct Chat execution failed',
                    [
                        'step_type' => 'direct_chat_failed',
                        'agent_id' => $directAgent->id ?? null,
                        'agent_name' => $directAgent->name ?? 'Direct Chat',
                        'error_message' => $e->getMessage(),
                    ],
                    true,
                    true,
                    $execution->id
                );
            } else {
                \Log::warning('StreamingController: Execution not saved, cannot mark as failed', [
                    'interaction_id' => $interactionId,
                    'has_execution' => isset($execution),
                    'execution_exists' => isset($execution) ? $execution->exists : false,
                ]);
            }
        } finally {
            if (app()->has('status_reporter')) {
                app()->forgetInstance('status_reporter');
            }
            if (app()->has('current_user_id')) {
                app()->forgetInstance('current_user_id');
            }
            if (app()->has('current_agent_id')) {
                app()->forgetInstance('current_agent_id');
            }
            if (app()->has('current_interaction_id')) {
                app()->forgetInstance('current_interaction_id');
            }
            if (app()->has('streaming_session_id')) {
                app()->forgetInstance('streaming_session_id');
            }
        }

        \Log::info('StreamingController: Streaming session completed', [
            'streaming_session_id' => $streamingSessionId ?? 'unknown',
            'interaction_id' => $interactionId,
            'total_duration_ms' => round((microtime(true) - ($sessionStartTime ?? microtime(true))) * 1000, 2),
            'answer_length' => isset($answer) ? strlen($answer) : 0,
            'sources_extracted' => isset($sourceLinks) ? count($sourceLinks) : 0,
        ]);

        yield $this->formatSSE('update', '</stream>');
    }

    /**
     * Stream agent execution progress in real-time
     */
    private function streamAgentExecution(string $agent, string $query, int $interactionId): \Generator
    {
        yield new StreamedEvent(
            event: 'update',
            data: $this->safeJsonEncode([
                'status' => 'Initializing agent workflow...',
                'type' => 'research_step',
            ])
        );

        // Get the interaction to pass to executeAgent
        $interaction = \App\Models\ChatInteraction::find($interactionId);
        if (! $interaction) {
            yield new StreamedEvent(
                event: 'update',
                data: $this->safeJsonEncode([
                    'status' => 'Interaction not found',
                    'type' => 'error',
                ])
            );
            yield new StreamedEvent(event: 'update', data: '</stream>');

            return;
        }

        $existingStatusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

        if (! $existingStatusReporter ||
            ! $existingStatusReporter instanceof StatusReporter) {
            $statusReporter = new StatusReporter($interactionId);
            app()->instance('status_reporter', $statusReporter);

            Log::info('StreamingController: Created new StatusReporter instance', [
                'interaction_id' => $interactionId,
                'had_existing' => $existingStatusReporter !== null,
            ]);
        } else {
            Log::info('StreamingController: Preserving existing StatusReporter', [
                'interaction_id' => $interactionId,
            ]);
        }

        $livewireInstance = new \App\Livewire\ChatResearchInterface;

        $livewireInstance->selectedAgent = $agent;
        $livewireInstance->currentSessionId = $interaction->chat_session_id;
        $livewireInstance->currentInteractionId = $interactionId;

        if ($interaction->agent_execution_id) {
            $existingExecution = \App\Models\AgentExecution::find($interaction->agent_execution_id);
            if ($existingExecution) {
                Log::info('StreamingController: Using existing execution for interaction', [
                    'interaction_id' => $interactionId,
                    'execution_id' => $existingExecution->id,
                    'execution_status' => $existingExecution->status,
                ]);

                $execution = $existingExecution;
            } else {
                $livewireInstance->executeAgent($interaction);

                $interaction->refresh();
                $execution = $interaction->agent_execution_id ?
                    \App\Models\AgentExecution::find($interaction->agent_execution_id) :
                    null;
            }
        } else {
            $livewireInstance->executeAgent($interaction);

            $interaction->refresh();
            $execution = $interaction->agent_execution_id ?
                \App\Models\AgentExecution::find($interaction->agent_execution_id) :
                null;
        }

        if (! $execution) {
            yield new StreamedEvent(
                event: 'update',
                data: json_encode([
                    'status' => 'Failed to start agent execution',
                    'type' => 'error',
                ])
            );
            yield new StreamedEvent(event: 'update', data: '</stream>');

            return;
        }

        yield new StreamedEvent(
            event: 'update',
            data: json_encode([
                'status' => 'Agent execution started, monitoring progress...',
                'type' => 'research_step',
            ])
        );

        $maxWaitTime = 900;
        $elapsed = 0;
        $pollInterval = 5;
        $lastProgressCount = 0;

        while ($elapsed < $maxWaitTime) {
            $execution->refresh();

            $events = EventStreamNotifier::getAndClearEvents($interactionId);
            foreach ($events as $event) {
                yield new StreamedEvent(
                    event: 'update',
                    data: json_encode($event)
                );
            }

            if (in_array($execution->status, ['completed', 'failed', 'cancelled'])) {
                if ($execution->status === 'completed') {
                    $interaction = \App\Models\ChatInteraction::find($interactionId);
                    if ($interaction && $interaction->answer) {
                        yield new StreamedEvent(
                            event: 'update',
                            data: json_encode([
                                'content' => $interaction->answer,
                                'type' => 'answer_stream',
                            ])
                        );
                    }

                    if (isset($execution->metadata['execution_steps'])) {
                        yield new StreamedEvent(
                            event: 'update',
                            data: json_encode([
                                'steps' => $execution->metadata['execution_steps'],
                                'type' => 'execution_steps',
                            ])
                        );
                    }

                    yield new StreamedEvent(
                        event: 'update',
                        data: json_encode([
                            'status' => 'Agent execution completed successfully',
                            'type' => 'research_step',
                        ])
                    );
                } else {
                    yield new StreamedEvent(
                        event: 'update',
                        data: json_encode([
                            'status' => 'Agent execution '.$execution->status,
                            'type' => 'error',
                        ])
                    );
                }
                break;
            }

            sleep($pollInterval);
            $elapsed += $pollInterval;
        }

        if ($elapsed >= $maxWaitTime) {
            yield new StreamedEvent(
                event: 'update',
                data: json_encode([
                    'status' => 'Agent execution timeout - please check results manually',
                    'type' => 'error',
                ])
            );
        }

        // Clean up StatusReporter for agent execution
        if (app()->has('status_reporter')) {
            app()->forgetInstance('status_reporter');
        }

        yield new StreamedEvent(event: 'update', data: '</stream>');
    }

    /**
     * Load user tool preferences from session or use defaults from trait
     */
    protected function loadUserToolPreferences(): void
    {
        $userId = auth()->id();

        $sessionKey = "user_tool_preferences.{$userId}";
        $preferences = session($sessionKey, [
            'enabledTools' => $this->enabledTools,
            'enabledServers' => $this->enabledServers,
            'showToolResults' => $this->showToolResults ?? false,
        ]);

        $this->enabledTools = $preferences['enabledTools'] ?? $this->enabledTools;
        $this->enabledServers = $preferences['enabledServers'] ?? $this->enabledServers;

        if (isset($preferences['showToolResults'])) {
            $this->showToolResults = $preferences['showToolResults'];
        }

        \Log::info('StreamingController: Loaded user tool preferences', [
            'user_id' => $userId,
            'enabled_tools' => $this->enabledTools,
            'enabled_servers' => $this->enabledServers,
            'show_tool_results' => $this->showToolResults ?? false,
            'session_key' => $sessionKey,
        ]);
    }

    /**
     * Save user tool preferences to session
     */
    protected function saveUserToolPreferences(): void
    {
        $userId = auth()->id();

        if (! $userId) {
            return;
        }

        $sessionKey = "user_tool_preferences.{$userId}";
        $preferences = [
            'enabledTools' => $this->enabledTools,
            'enabledServers' => $this->enabledServers,
            'showToolResults' => $this->showToolResults ?? false,
        ];

        session([$sessionKey => $preferences]);

        \Log::info('StreamingController: Saved user tool preferences', [
            'user_id' => $userId,
            'preferences' => $preferences,
            'session_key' => $sessionKey,
        ]);
    }

    /**
     * Extract source links from chat tool results and persist to database
     */
    protected function extractAndPersistChatSourceLinks(\App\Models\ChatInteraction $interaction, array $toolResults): void
    {
        try {
            if (empty($toolResults)) {
                \Log::info('No tool results available for extracting source links', [
                    'interaction_id' => $interaction->id,
                ]);

                return;
            }

            \Log::info('Extracting source links from chat tool results', [
                'interaction_id' => $interaction->id,
                'tool_results_count' => count($toolResults),
                'tool_names' => array_map(function ($result) {
                    return is_object($result) ? ($result->toolName ?? 'unknown') :
                          (is_array($result) ? ($result['toolName'] ?? 'unknown') : 'unknown');
                }, $toolResults),
            ]);

            foreach ($toolResults as $index => $toolResult) {
                // Log details about this tool result for debugging
                $toolName = 'unknown';
                if (is_object($toolResult)) {
                    $toolName = $toolResult->toolName ?? 'unknown';
                } elseif (is_array($toolResult)) {
                    $toolName = $toolResult['toolName'] ?? 'unknown';
                }

                \Log::debug('Processing tool result for source extraction', [
                    'interaction_id' => $interaction->id,
                    'tool_index' => $index,
                    'tool_name' => $toolName,
                    'result_type' => gettype($toolResult),
                ]);

                $sourceLinks = $this->sourceLinkExtractor->extractFromToolResult($toolResult);

                \Log::debug('Source links extraction result', [
                    'links_count' => count($sourceLinks),
                    'tool_name' => $toolName,
                ]);

                foreach ($sourceLinks as $sourceLink) {
                    try {
                        $url = $sourceLink['url'] ?? '';
                        if (empty($url)) {
                            continue;
                        }

                        $linkValidator = app(\App\Services\LinkValidator::class);
                        $linkInfo = $linkValidator->validateAndExtractLinkInfo($url);

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

                                \Log::debug('Persisted source link from chat execution', [
                                    'interaction_id' => $interaction->id,
                                    'source_id' => $source->id,
                                    'url' => $sourceLink['url'],
                                    'tool' => $sourceLink['tool'] ?? 'unknown',
                                ]);
                            }
                        }

                    } catch (\Exception $e) {
                        \Log::error('Failed to persist individual chat source link', [
                            'interaction_id' => $interaction->id,
                            'source_url' => $sourceLink['url'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to extract source links from chat tool results', [
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Generate title for session if needed (first interaction with answer)
     */
    protected function generateTitleIfNeeded(\App\Models\ChatInteraction $interaction): void
    {
        \App\Services\SessionTitleService::generateTitleIfNeeded($interaction);
    }

    /**
     * Generate a session title using AI or fallback to question excerpt
     */
    protected function generateTitle(int $sessionId, string $question, string $answer): void
    {
        $session = \App\Models\ChatSession::find($sessionId);
        if (! $session || $session->title) {
            return;
        }

        try {
            $titleGenerator = new \App\Services\TitleGenerator;
            $title = $titleGenerator->generateFromContent($question, $answer);

            if ($title) {
                $session->update(['title' => $title]);

                \Log::info('StreamingController: Generated title using TitleGenerator', [
                    'session_id' => $sessionId,
                    'title' => $title,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('StreamingController: Failed to generate title using TitleGenerator', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            $title = \Illuminate\Support\Str::words($question, 5, '');
            $title = trim($title);

            if ($title) {
                $session->update(['title' => $title]);
            }
        }
    }

    /**
     * UTF-8 safe JSON encoding with error handling
     */
    protected function safeJsonEncode($data): string
    {
        try {
            // First attempt: standard JSON encoding
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                // Handle JSON encoding errors
                $error = json_last_error_msg();
                \Log::warning('JSON encoding failed, attempting cleanup', [
                    'error' => $error,
                    'data_type' => gettype($data),
                ]);

                $cleanData = $this->cleanDataForJson($data);
                $json = json_encode($cleanData, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

                if ($json === false) {
                    \Log::error('Safe JSON encoding failed completely', [
                        'error' => json_last_error_msg(),
                        'fallback_used' => true,
                    ]);

                    return json_encode([
                        'content' => 'Content encoding error - streaming continues',
                        'type' => 'system_error',
                    ]);
                }
            }

            return $json;

        } catch (\Exception $e) {
            \Log::error('Exception in safeJsonEncode', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
    protected function sanitizeForJson(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        $text = preg_replace('/[\x{E2}\x{80}][\x{99}\x{9C}\x{9D}\x{A6}]/u', "'", $text);
        $text = preg_replace('/[\x{E2}\x{80}][\x{93}\x{94}]/u', '-', $text);
        $text = preg_replace('/[\x{E2}\x{80}][\x{A6}]/u', '...', $text);

        return $text;
    }

    /**
     * Determine if an API error is retriable
     */
    protected function isRetriableError(string $errorMessage): bool
    {
        $retriableErrors = [
            'status code 503', // Service Unavailable
            'status code 502', // Bad Gateway
            'status code 500', // Internal Server Error
            'status code 429', // Rate Limited
            'status code 504', // Gateway Timeout
            'connection timeout',
            'connection refused',
            'temporarily_overloaded',
            'server_error',
            'network error',
        ];

        foreach ($retriableErrors as $error) {
            if (str_contains(strtolower($errorMessage), $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify the type of error for better user messaging
     */
    protected function getErrorType(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);

        if (str_contains($errorMessage, 'status code 503') ||
            str_contains($errorMessage, 'temporarily_overloaded')) {
            return 'service_unavailable';
        }

        if (str_contains($errorMessage, 'status code 429')) {
            return 'rate_limited';
        }

        if (str_contains($errorMessage, 'status code 502') ||
            str_contains($errorMessage, 'status code 504') ||
            str_contains($errorMessage, 'gateway')) {
            return 'gateway_error';
        }

        if (str_contains($errorMessage, 'timeout') ||
            str_contains($errorMessage, 'connection')) {
            return 'connection_error';
        }

        if (str_contains($errorMessage, 'status code 500') ||
            str_contains($errorMessage, 'server_error')) {
            return 'server_error';
        }

        return 'unknown_error';
    }

    /**
     * Generate user-friendly retry messages based on error type
     */
    protected function getUserFriendlyRetryMessage(string $errorType, int $attempt, int $maxAttempts): string
    {
        $remainingAttempts = $maxAttempts - $attempt;

        return match ($errorType) {
            'service_unavailable' => "OpenAI service temporarily busy, retrying... ({$remainingAttempts} attempts remaining)",
            'rate_limited' => "Request rate limited, waiting before retry... ({$remainingAttempts} attempts remaining)",
            'gateway_error' => "Network gateway issue, reconnecting... ({$remainingAttempts} attempts remaining)",
            'connection_error' => "Connection timeout, retrying connection... ({$remainingAttempts} attempts remaining)",
            'server_error' => "Server error detected, attempting recovery... ({$remainingAttempts} attempts remaining)",
            default => "Temporary service issue, retrying... ({$remainingAttempts} attempts remaining)"
        };
    }

    /**
     * Generate final error message when all retries are exhausted
     */
    protected function getFinalErrorMessage(string $errorType, bool $isRetriable): string
    {
        if (! $isRetriable) {
            return match ($errorType) {
                'unknown_error' => 'I encountered an API error while processing your query. Please try rephrasing your question or try again later.',
                default => 'Unable to process your request due to a service configuration issue. Please contact support if this persists.'
            };
        }

        return match ($errorType) {
            'service_unavailable' => 'The AI service is currently experiencing high demand and is temporarily unavailable. This usually resolves within a few minutes. Please try again shortly.',
            'rate_limited' => 'API rate limits have been exceeded. Please wait a moment before submitting another request.',
            'gateway_error' => 'We\'re experiencing network connectivity issues. Please check your connection and try again in a few minutes.',
            'connection_error' => 'Connection to the AI service timed out. This may be due to high demand or network issues. Please try again.',
            'server_error' => 'The AI service encountered an internal error. These typically resolve quickly. Please try again in a moment.',
            default => 'The AI service is temporarily unavailable. This usually resolves quickly. Please try again in a few minutes.'
        };
    }

    /**
     * Generate error suggestions for users
     */
    protected function getErrorSuggestion(string $errorType): string
    {
        return match ($errorType) {
            'service_unavailable' => 'Try again in 2-5 minutes when demand is lower',
            'rate_limited' => 'Wait 1-2 minutes before submitting another request',
            'gateway_error' => 'Check your internet connection and try again',
            'connection_error' => 'Retry your request or try a shorter query',
            'server_error' => 'Try again in a few moments',
            default => 'Wait a moment and try again with a shorter or rephrased query'
        };
    }

    /**
     * Generate error message for database storage
     */
    protected function getInteractionErrorMessage(string $errorType, bool $isRetriable): string
    {
        if (! $isRetriable) {
            return 'Error processing request - please try again';
        }

        return match ($errorType) {
            'service_unavailable' => 'AI service temporarily unavailable due to high demand - please try again',
            'rate_limited' => 'Rate limit exceeded - please wait before trying again',
            'gateway_error' => 'Network connectivity issue - please try again',
            'connection_error' => 'Connection timeout - please try again',
            'server_error' => 'AI service error - please try again',
            default => 'Temporary service issue - please try again'
        };
    }
}
