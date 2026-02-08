<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\User;
use App\Services\AiPersonaService;
use App\Services\AttachmentProcessor;
use App\Services\SourceLinkExtractor;
use App\Services\StatusReporter;
use App\Traits\HandlesExecutionFailures;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Core agent execution orchestrator managing the complete AI agent lifecycle.
 *
 * Responsibilities:
 * - Loads and validates MCP tools and agent-specific tool configurations
 * - Assembles execution context (knowledge scope, attachments, user persona)
 * - Orchestrates Prism AI requests with streaming support
 * - Executes tool calls and processes results
 * - Manages workflow orchestration for complex multi-agent scenarios
 * - Reports real-time status updates via StatusReporter
 * - Handles execution depth limits and error recovery
 *
 * Execution Flow:
 * 1. Load user-specific MCP tools and validate agent tool assignments
 * 2. Extract knowledge scope tags and store in container for tool access
 * 3. Assemble Prism messages with system instructions and user query
 * 4. Execute via Prism with streaming (or fallback to non-streaming)
 * 5. Process tool calls iteratively until completion or depth limit
 * 6. For workflow agents, orchestrate sub-agent execution via WorkflowOrchestrator
 *
 * Error Handling:
 * - Catches PrismException for AI model errors and max depth exceeded
 * - Logs comprehensive error context with execution metadata
 * - Gracefully falls back from streaming to non-streaming on failures
 * - Reports errors to StatusReporter for real-time user feedback
 *
 * @see \App\Services\Agents\ToolRegistry
 * @see \App\Services\StatusReporter
 * @see \App\Services\Agents\WorkflowOrchestrator
 */
class AgentExecutor
{
    use HandlesExecutionFailures;

    protected ToolRegistry $toolRegistry;

    protected ?StatusReporter $statusReporter = null;

    protected DynamicPromptService $dynamicPromptService;

    protected AgentKnowledgeService $agentKnowledgeService;

    protected SourceLinkExtractor $sourceLinkExtractor;

    protected AttachmentProcessor $attachmentProcessor;

    public function __construct(
        ToolRegistry $toolRegistry,
        SourceLinkExtractor $sourceLinkExtractor,
        AttachmentProcessor $attachmentProcessor
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->sourceLinkExtractor = $sourceLinkExtractor;
        $this->attachmentProcessor = $attachmentProcessor;
        $this->statusReporter = app()->has('status_reporter') ? app('status_reporter') : app(StatusReporter::class);
        $this->dynamicPromptService = new DynamicPromptService;
        $this->agentKnowledgeService = app(AgentKnowledgeService::class);
    }

    /**
     * Execute an agent with full lifecycle management.
     *
     * Orchestrates the complete agent execution flow including:
     * - MCP tool loading and validation
     * - Context assembly (knowledge scope, attachments, user persona)
     * - Prism AI request with streaming support
     * - Tool call execution and result processing
     * - Workflow orchestration for complex multi-agent scenarios
     *
     * The method handles both single-agent and workflow executions, with automatic
     * fallback to non-streaming mode if streaming fails. Real-time status updates
     * are broadcast via StatusReporter for interactive user feedback.
     *
     * @param  AgentExecution  $execution  The execution instance to run
     * @param  int|null  $interactionId  Optional chat interaction ID for status reporting
     * @return string The agent's final response text
     *
     * @throws PrismException When AI model returns errors or max depth exceeded
     * @throws \Exception For workflow orchestration failures
     */
    public function execute(AgentExecution $execution, ?int $interactionId = null): string
    {
        // Track execution start time for performance monitoring
        $executionStartTime = microtime(true);

        Log::info('AgentExecutor: Starting execution', [
            'execution_id' => $execution->id,
            'agent_id' => $execution->agent_id,
            'user_id' => $execution->user_id,
            'agent_name' => $execution->agent->name,
        ]);

        // Store user context in container for tools to access
        app()->instance('current_user_id', $execution->user_id);

        // Load user-specific MCP tools before execution
        Log::debug('AgentExecutor: About to load MCP tools', [
            'execution_id' => $execution->id,
            'user_id' => $execution->user_id,
        ]);

        $this->toolRegistry->loadMcpTools($execution->user_id);

        // Debug: Check what MCP tools are available after loading
        $availableTools = $this->toolRegistry->getAvailableTools($execution->user_id);
        $mcpTools = array_filter($availableTools, fn ($tool) => ($tool['category'] ?? '') === 'mcp');

        Log::debug('AgentExecutor: MCP tools loaded during execution', [
            'execution_id' => $execution->id,
            'user_id' => $execution->user_id,
            'mcp_tools_count' => count($mcpTools),
            'mcp_tool_names' => array_keys($mcpTools),
            'all_tools_count' => count($availableTools),
        ]);

        // Store agent ID in container for knowledge tools to access
        app()->instance('current_agent_id', $execution->agent_id);
        Log::debug('AgentExecutor: Stored agent ID in container for tool access', [
            'execution_id' => $execution->id,
            'agent_id' => $execution->agent_id,
        ]);

        /**
         * Store interaction ID in container for knowledge tools to access during execution.
         *
         * Knowledge tools (RAG search, document retrieval) need the interaction ID to:
         * - Associate sources with chat interactions
         * - Track document usage across workflow child executions
         * - Provide context for real-time status updates
         *
         * Priority resolution order:
         * 1. $interactionId parameter (explicit override)
         * 2. $execution->chatInteraction relationship (standard path)
         * 3. StatusReporter instance (fallback for workflows)
         *
         * @see \App\Services\Agents\Tools\KnowledgeSearchTool
         * @see \App\Services\StatusReporter
         */
        $resolvedInteractionId = $interactionId;
        if (! $resolvedInteractionId && $execution->chatInteraction) {
            $resolvedInteractionId = $execution->chatInteraction->id;
        }
        if ($resolvedInteractionId) {
            app()->instance('current_interaction_id', $resolvedInteractionId);

            Log::debug('AgentExecutor: Resolved interaction ID for tool context', [
                'execution_id' => $execution->id,
                'interaction_id' => $resolvedInteractionId,
                'resolution_method' => $interactionId ? 'parameter' :
                                      ($execution->chatInteraction ? 'relationship' : 'status_reporter'),
            ]);
        }

        // Extract and store knowledge scope tags for filtering
        $knowledgeScopeTags = $this->extractKnowledgeScopeTags($execution);
        if (! empty($knowledgeScopeTags)) {
            // Store in container for tool access
            app()->instance('knowledge_scope_tags', $knowledgeScopeTags);

            // Persist to execution metadata for visibility and debugging
            $metadata = $execution->metadata ?? [];
            $metadata['knowledge_scope_tags'] = $knowledgeScopeTags;
            $execution->update(['metadata' => $metadata]);

            Log::info('AgentExecutor: Stored knowledge scope tags in container and metadata', [
                'execution_id' => $execution->id,
                'tags' => $knowledgeScopeTags,
                'source' => $this->getKnowledgeScopeTagsSource($execution),
            ]);
        }

        // Set up status reporter for this execution with agent_execution_id
        // Check if there's already a status reporter in the container (from ExecuteAgentJob)
        if (app()->has('status_reporter')) {
            $this->statusReporter = app('status_reporter');
            // Update the existing status reporter with the correct interaction and execution IDs
            $this->statusReporter->setInteractionId($interactionId);
            $this->statusReporter->setAgentExecutionId($execution->id);
        } else {
            $this->statusReporter = new StatusReporter($interactionId, $execution->id);
            app()->instance('status_reporter', $this->statusReporter);
        }

        try {
            // Mark execution as started
            $execution->markAsStarted();
            $this->reportStatus('agent_execution_started', "Starting {$execution->agent->name} execution", true, false);

            // Execute single agent
            $result = $this->executeSingleAgent($execution);

            // Check if this agent produces structured workflow plans (e.g., Research Planner/Deeply Agent)
            if ($this->shouldOrchestrateWorkflowPlan($execution)) {
                Log::info('AgentExecutor: Agent produced WorkflowPlan, orchestrating execution', [
                    'execution_id' => $execution->id,
                    'agent_name' => $execution->agent->name,
                ]);

                return $this->orchestrateWorkflowPlan($execution, $result, $interactionId);
            }

            return $result;

        } catch (PrismException $e) {
            // Handle Prism-specific exceptions like maximum tool call chain depth exceeded
            if (str_contains($e->getMessage(), 'Maximum tool call chain depth exceeded')) {
                // Gather metadata for debugging depth limit issues
                $metadata = $execution->metadata ?? [];
                $executionTimeSeconds = microtime(true) - $executionStartTime;

                Log::warning('AgentExecutor: Maximum tool call depth reached, workflow completing with partial results', [
                    'execution_id' => $execution->id,
                    'agent_id' => $execution->agent_id,
                    'agent_name' => $execution->agent->name,
                    'user_id' => $execution->user_id,
                    'max_steps' => $execution->max_steps,
                    'configured_max_steps' => $execution->agent->max_steps,
                    'tool_calls_executed' => $metadata['tool_calls_count'] ?? 'unknown',
                    'last_tool_used' => $metadata['last_tool_name'] ?? 'unknown',
                    'has_partial_result' => ! empty($result ?? ''),
                    'result_length' => strlen($result ?? ''),
                    'execution_time_seconds' => round($executionTimeSeconds, 2),
                    'exception_message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Mark as completed with a warning message about depth limit
                $partialResult = "Agent execution reached the maximum tool call depth limit ({$execution->max_steps} steps). The workflow completed with partial results.";

                try {
                    $execution->markAsCompleted($partialResult, [
                        'warning' => 'Maximum tool call depth exceeded',
                        'max_steps' => $execution->max_steps,
                        'completion_reason' => 'depth_limit_reached',
                    ]);
                    $this->reportStatus('agent_execution_completed_with_warning',
                        "Execution completed with warning: Maximum depth reached for {$execution->agent->name}", true, false);
                } catch (\Exception $markingException) {
                    Log::error('AgentExecutor: Failed to mark execution as completed after depth limit', [
                        'execution_id' => $execution->id,
                        'marking_error' => $markingException->getMessage(),
                        'original_error' => $e->getMessage(),
                    ]);

                    // Fallback to failed status if we can't mark as completed
                    $this->safeMarkAsFailed(
                        $execution,
                        'Maximum tool call depth exceeded (completion failed): '.$e->getMessage(),
                        [
                            'context' => 'completion_failed_after_depth_limit',
                            'original_error' => $e->getMessage(),
                        ],
                        false // Don't refresh - we just tried to update
                    );
                }

                return $partialResult;
            }

            // Handle other Prism exceptions normally
            Log::error('AgentExecutor: Prism exception occurred', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // SECURITY: Sanitize error message to prevent information disclosure
            $sanitizedError = $this->sanitizeErrorMessage($e, $execution);
            $this->safeMarkAsFailed(
                $execution,
                $sanitizedError,
                [
                    'context' => 'prism_exception',
                    'error_class' => get_class($e),
                ],
                false // Don't refresh - we're in the middle of execution
            );
            $this->reportStatus('agent_execution_failed', "Execution failed: {$sanitizedError}", true, false);

            throw $e;
        } catch (\Illuminate\Broadcasting\BroadcastException $e) {
            // Handle broadcast exceptions specifically - these shouldn't fail the entire execution
            Log::warning('AgentExecutor: Broadcast failed but continuing execution', [
                'execution_id' => $execution->id,
                'broadcast_error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // Mark as failed without causing another broadcast error
            $this->safeMarkAsFailed(
                $execution,
                'Execution completed but status broadcasting failed: '.$e->getMessage(),
                [
                    'context' => 'broadcast_exception',
                    'broadcast_error' => $e->getMessage(),
                ],
                false // Don't refresh - we just completed execution
            );

            // Try to report status without broadcasting (will fallback gracefully)
            try {
                $this->reportStatus('agent_execution_failed', 'Execution failed due to broadcast issue', true, false);
            } catch (\Exception $reportException) {
                // Ignore reporting failures at this point
                Log::debug('AgentExecutor: Status reporting also failed, ignoring');
            }

            // Don't re-throw broadcast exceptions - the execution might have actually succeeded
            return 'Execution completed but status broadcasting failed. Check logs for details.';

        } catch (\Exception $e) {
            Log::error('AgentExecutor: Execution failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // SECURITY: Sanitize error message to prevent information disclosure
            $sanitizedError = $this->sanitizeErrorMessage($e, $execution);
            $this->safeMarkAsFailed(
                $execution,
                $sanitizedError,
                [
                    'context' => 'general_exception',
                    'error_class' => get_class($e),
                ],
                false // Don't refresh - we're in the middle of execution
            );
            $this->reportStatus('agent_execution_failed', "Execution failed: {$sanitizedError}", true, false);

            throw $e;
        } finally {
            // SECURITY: Clean up container bindings to prevent context leakage between requests
            // This ensures user context doesn't persist across different executions
            app()->forgetInstance('current_user_id');
            app()->forgetInstance('current_agent_id');
            app()->forgetInstance('current_interaction_id');
            app()->forgetInstance('knowledge_scope_tags');
            app()->forgetInstance('status_reporter');

            Log::debug('AgentExecutor: Cleaned up container instances', [
                'execution_id' => $execution->id,
                'cleaned_instances' => [
                    'current_user_id',
                    'current_agent_id',
                    'current_interaction_id',
                    'knowledge_scope_tags',
                    'status_reporter',
                ],
            ]);
        }
    }

    /**
     * Execute holistic research workflow with intelligent routing
     */
    public function executeHolisticWorkflow(AgentExecution $execution): array
    {
        try {
            $this->reportStatus('holistic_research_start',
                'Starting holistic research analysis', true, true);

            // Step 1: Create research plan using structured output
            $plannerAgent = Agent::where('name', 'Research Planner')->first();
            if (! $plannerAgent) {
                throw new \Exception('Research Planner agent not found. Please run seeder to create holistic agents.');
            }

            $holisticService = new HolisticResearchService(app(AgentService::class));

            // Prepare input with available agents for AI selection
            $plannerInput = $holisticService->prepareResearchPlannerInput($execution->input);

            // Execute Research Planner with structured output for reliable JSON parsing
            $plan = $holisticService->executeResearchPlannerWithStructuredOutput($plannerAgent, $plannerInput, $execution->id);

            // Check which type of plan was returned
            if ($plan instanceof WorkflowPlan) {
                // NEW SYSTEM: WorkflowPlan with multiple strategy types (simple, sequential, parallel, mixed)
                Log::info('AgentExecutor: Using WorkflowPlan with WorkflowOrchestrator', [
                    'strategy_type' => $plan->strategyType,
                    'stages_count' => count($plan->stages),
                    'requires_synthesis' => $plan->requiresSynthesis(),
                ]);

                $this->reportStatus('workflow_plan_created',
                    "Strategy: {$plan->strategyType} | Stages: ".count($plan->stages), true, true);

                // Store workflow plan for UI display
                $execution->update([
                    'workflow_plan' => [
                        'type' => 'workflow_plan',
                        'strategy_type' => $plan->strategyType,
                        'stages' => array_map(function ($stage) {
                            return [
                                'type' => $stage->type,
                                'nodes' => array_map(function ($node) {
                                    return [
                                        'agent_id' => $node->agentId,
                                        'agent_name' => $node->agentName,
                                        'input' => $node->input,
                                        'rationale' => $node->rationale,
                                    ];
                                }, $stage->nodes),
                            ];
                        }, $plan->stages),
                        'original_query' => $plan->originalQuery,
                        'synthesizer_agent_id' => $plan->synthesizerAgentId,
                        'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ]);

                // Execute workflow using WorkflowOrchestrator
                // Resolve interaction ID from execution's chatInteraction relationship
                $interactionId = $execution->chatInteraction ? $execution->chatInteraction->id : null;

                $orchestrator = new WorkflowOrchestrator;
                $batchId = $orchestrator->execute($plan, $execution, $interactionId);

                $finalResult = "Workflow execution started (Batch ID: {$batchId}). Results will be synthesized upon completion.";

                $this->reportStatus('workflow_execution_complete',
                    "Workflow dispatched: {$plan->strategyType}", true, true);

                return [
                    'success' => true,
                    'final_answer' => $finalResult,
                    'metadata' => [
                        'execution_strategy' => $plan->strategyType,
                        'research_threads' => $plan->getTotalJobs(),
                        'total_sources' => 0, // Will be updated by synthesis
                        'workflow_batch_id' => $batchId,
                        'estimated_duration' => $plan->estimatedDurationSeconds,
                    ],
                ];

            } else {
                // OLD SYSTEM: ResearchPlan with parallel research
                Log::info('AgentExecutor: Using ResearchPlan with ParallelResearchCoordinator', [
                    'execution_strategy' => $plan->executionStrategy,
                    'sub_queries_count' => count($plan->subQueries),
                ]);

                $this->reportStatus('research_plan_created',
                    "Strategy: {$plan->executionStrategy} | Threads: ".count($plan->subQueries), true, true);

                // Store workflow plan for UI display
                $execution->update([
                    'workflow_plan' => [
                        'type' => 'parallel_research',
                        'execution_strategy' => $plan->executionStrategy,
                        'sub_queries' => $plan->subQueries,
                        'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ]);

                // Step 2: Execute research (simple or parallel)
                $coordinator = new ParallelResearchCoordinator($this->statusReporter, app(AgentService::class));
                $researchResults = $coordinator->executeResearchPlan($plan, $execution);

                // Step 3: Synthesize findings (if multiple threads)
                if (count($researchResults) > 1) {
                    $this->reportStatus('synthesis_start',
                        'Synthesizing findings from '.count($researchResults).' research threads', true, true);

                    $synthesizerAgent = Agent::where('name', 'Research Synthesizer')->first();
                    if (! $synthesizerAgent) {
                        throw new \Exception('Research Synthesizer agent not found. Please run seeder to create holistic agents.');
                    }

                    $synthesisInput = $holisticService->prepareSynthesisInput($plan, $researchResults);
                    $finalResult = $this->executeAgentWithQuery($synthesizerAgent, $synthesisInput, $execution->id);
                } else {
                    // Single thread result doesn't need synthesis
                    $finalResult = $researchResults[0]['findings'];
                }

                $this->reportStatus('holistic_research_complete',
                    'Research analysis completed successfully', true, true);

                return [
                    'success' => true,
                    'final_answer' => $finalResult,
                    'metadata' => [
                        'execution_strategy' => $plan->executionStrategy,
                        'research_threads' => count($plan->subQueries),
                        'total_sources' => array_sum(array_column($researchResults, 'source_count')),
                        'research_plan' => $plan->subQueries,
                        'estimated_duration' => $plan->estimatedDurationSeconds,
                    ],
                ];
            }

        } catch (\Exception $e) {
            Log::error('Holistic workflow failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'final_answer' => "Research analysis failed: {$e->getMessage()}",
                'error' => $e->getMessage(),
                'metadata' => [
                    'execution_strategy' => 'failed',
                    'research_threads' => 0,
                    'total_sources' => 0,
                ],
            ];
        }
    }

    /**
     * Execute single agent mode - unified execution for all individual agents
     */
    public function executeSingleAgentMode(AgentExecution $execution): array
    {
        try {
            $this->reportStatus('single_agent_execution_start',
                'Starting single agent execution', true, true);

            // Get the agent for this execution
            $agent = $execution->agent;

            if (! $agent) {
                throw new \Exception('Agent not found for execution');
            }

            // Execute the agent directly with the query
            $result = $this->executeAgentWithQuery($agent, $execution->input, $execution->id);

            $this->reportStatus('single_agent_execution_complete',
                'Single agent execution completed successfully', true, true);

            return [
                'success' => true,
                'final_answer' => $result,
                'metadata' => [
                    'execution_strategy' => 'single_agent',
                    'agent_name' => $agent->name,
                    'agent_id' => $agent->id,
                    'research_threads' => 1,
                    'total_sources' => 0, // Will be updated by agent execution
                    'estimated_duration' => 30, // Efficient execution expected
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Single agent mode execution failed', [
                'execution_id' => $execution->id,
                'agent_id' => $execution->agent_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'final_answer' => "Single agent execution failed: {$e->getMessage()}",
                'error' => $e->getMessage(),
                'metadata' => [
                    'execution_strategy' => 'failed',
                    'agent_name' => $execution->agent->name ?? 'Unknown',
                    'research_threads' => 0,
                    'total_sources' => 0,
                ],
            ];
        }
    }

    /**
     * Helper method to execute an agent with a query string (for holistic workflow)
     */
    private function executeAgentWithQuery(Agent $agent, string $query, int $parentExecutionId): string
    {
        // Create a temporary execution for this agent
        // Get parent execution's user to maintain proper attribution chain
        $parentExecution = AgentExecution::find($parentExecutionId);
        if (! $parentExecution) {
            throw new \Exception("Parent execution {$parentExecutionId} not found - cannot determine user context");
        }

        // SECURITY: Verify the parent execution belongs to the authenticated user
        // This prevents privilege escalation via crafted parent execution IDs
        if (auth()->check() && $parentExecution->user_id !== auth()->id()) {
            Log::warning('AgentExecutor: Unauthorized attempt to access parent execution', [
                'parent_execution_id' => $parentExecutionId,
                'parent_user_id' => $parentExecution->user_id,
                'authenticated_user_id' => auth()->id(),
                'agent_id' => $agent->id,
            ]);

            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Unauthorized access to parent execution'
            );
        }

        $userId = $parentExecution->user_id;

        $execution = new AgentExecution([
            'agent_id' => $agent->id,
            'user_id' => $userId,
            'chat_session_id' => $parentExecution->chat_session_id, // Include chat session for context
            'input' => $query,
            'max_steps' => $agent->max_steps,
            'status' => 'running',
            'parent_agent_execution_id' => $parentExecutionId,
        ]);
        $execution->save();

        // Link to original ChatInteraction for attachment access and context
        if ($parentExecution->chatInteraction) {
            $execution->setRelation('chatInteraction', $parentExecution->chatInteraction);

            Log::info('AgentExecutor: Linked holistic agent execution to ChatInteraction', [
                'child_execution_id' => $execution->id,
                'parent_execution_id' => $parentExecutionId,
                'interaction_id' => $parentExecution->chatInteraction->id,
                'agent_name' => $agent->name,
                'attachments_count' => $parentExecution->chatInteraction->attachments ? $parentExecution->chatInteraction->attachments->count() : 0,
            ]);
        }

        // Execute the agent (this will now properly store metadata and inject conversation history)
        return $this->executeSingleAgent($execution);
    }

    /**
     * Build input for workflow agents, including context from previous agents and conversation history
     */
    protected function buildWorkflowInput(string $originalInput, array $previousResults, ?int $chatSessionId = null): string
    {
        $contextInput = '';

        // Add conversation history if available
        if ($chatSessionId) {
            $contextInput .= $this->buildConversationContext($chatSessionId);
        }

        $contextInput .= "Original Request: {$originalInput}\n\n";

        // Add previous agent results if available
        if (! empty($previousResults)) {
            $contextInput .= "Previous Agent Results:\n";

            // Collect all source links from previous agents
            $allSourceLinks = [];
            foreach ($previousResults as $result) {
                // Pass full results to subsequent agents - knowledge content should not be truncated
                $contextInput .= "- {$result['agent_name']}: ".$result['result']."\n";

                // Collect source links from this agent's result
                if (isset($result['source_links']) && is_array($result['source_links'])) {
                    $allSourceLinks = array_merge($allSourceLinks, $result['source_links']);
                }
            }

            // Add source links information if available
            if (! empty($allSourceLinks)) {
                $contextInput .= "\n**Available Source Links from Previous Agents**:\n";
                foreach ($allSourceLinks as $index => $sourceLink) {
                    $num = $index + 1;
                    $title = $sourceLink['title'] ?? 'Untitled';
                    $url = $sourceLink['url'] ?? '';
                    $tool = $sourceLink['tool'] ?? 'unknown';
                    $contextInput .= "{$num}. **{$title}** - {$url} (via {$tool})\n";
                }
                $contextInput .= "\n**IMPORTANT**: Use these source links in your analysis and citations. Include inline markdown links: [claim text](source-url)\n";
            } else {
                $contextInput .= "\n**NOTE**: No source links available from previous agents.\n";
            }
        }

        $contextInput .= "\nPlease build upon the previous results to fulfill the original request.";

        return $contextInput;
    }

    /**
     * Build conversation context from chat session history
     */
    protected function buildConversationContext(?int $chatSessionId): string
    {
        if (! $chatSessionId) {
            return '';
        }

        // Get recent conversation history
        $recentInteractions = ChatInteraction::where('chat_session_id', $chatSessionId)
            ->where('answer', '!=', null) // Only include completed interactions
            ->where('answer', '!=', '') // Only include non-empty answers
            ->orderBy('created_at', 'desc')
            ->limit(5) // Smaller limit for workflow agents for relevance
            ->get()
            ->reverse(); // Oldest first for chronological order

        if ($recentInteractions->isEmpty()) {
            return '';
        }

        $contextInput = "## Conversation History\n\n";
        $contextInput .= "Here is the recent conversation history for context:\n\n";

        foreach ($recentInteractions as $interaction) {
            // Add user question
            $contextInput .= '**User**: '.trim($interaction->question);

            // Add attachment information if present
            if ($interaction->attachments && $interaction->attachments->count() > 0) {
                $contextInput .= "\n*Attachments uploaded with this message:*\n";
                foreach ($interaction->attachments as $attachment) {
                    $contextInput .= '- '.$attachment->filename.' ('.$attachment->type.', '.
                        number_format($attachment->file_size / 1024, 1)."KB)\n";
                }
            }
            $contextInput .= "\n\n";

            // Add assistant response (full content for proper context)
            if ($interaction->answer) {
                $contextInput .= '**Assistant**: '.trim($interaction->answer)."\n\n";
            }
        }

        $contextInput .= "---\n\n";

        return $contextInput;
    }

    protected function synthesizeWorkflowResults(AgentExecution $execution, array $results, string $combinedOutput): string
    {
        try {
            // Use the workflow's system prompt to guide synthesis
            $systemPrompt = $this->injectAvailableAgents($execution->agent->system_prompt, $execution->agent->workflow_config);

            // Collect all source links from agent results
            $allSourceLinks = [];
            $totalSearchResults = 0;
            foreach ($results as $result) {
                if (isset($result['source_links']) && is_array($result['source_links'])) {
                    $agentSourceCount = count($result['source_links']);
                    $totalSearchResults += $agentSourceCount;
                    $allSourceLinks = array_merge($allSourceLinks, $result['source_links']);

                    Log::info('Synthesis: Collecting source links from agent', [
                        'agent_name' => $result['agent_name'] ?? 'unknown',
                        'source_links_count' => $agentSourceCount,
                        'total_accumulated' => count($allSourceLinks),
                    ]);
                }
            }

            // PERFORMANCE: Remove duplicate source links based on URL using O(1) lookup
            // Using associative array (isset) instead of in_array for O(n) instead of O(n²)
            $uniqueSourceLinks = [];
            $seenUrls = [];
            foreach ($allSourceLinks as $sourceLink) {
                $url = $sourceLink['url'] ?? '';
                if (! empty($url) && ! isset($seenUrls[$url])) {
                    $seenUrls[$url] = true;
                    $uniqueSourceLinks[] = $sourceLink;
                }
            }

            Log::info('Synthesis: Source link aggregation summary', [
                'total_raw_results' => $totalSearchResults,
                'total_source_links' => count($allSourceLinks),
                'unique_source_links' => count($uniqueSourceLinks),
                'agents_processed' => count($results),
            ]);

            // Build context for the AI synthesizer
            $synthesisInput = "# Workflow Execution Results\n\n";
            $synthesisInput .= "**Original Request**: {$execution->input}\n\n";
            $synthesisInput .= "**Workflow**: {$execution->agent->name}\n\n";
            $synthesisInput .= "**Agent Results**:\n\n{$combinedOutput}\n\n";

            // Add comprehensive source links information if available
            if (! empty($uniqueSourceLinks)) {
                $synthesisInput .= "**COMPREHENSIVE SOURCE COLLECTION**:\n\n";
                $synthesisInput .= '**CRITICAL**: This workflow collected **'.count($uniqueSourceLinks).' unique sources** from '.count($results)." specialized agents through **{$totalSearchResults} total search operations**.\n\n";
                $synthesisInput .= "**Available Source Links**:\n\n";
                foreach ($uniqueSourceLinks as $index => $sourceLink) {
                    $num = $index + 1;
                    $title = $sourceLink['title'] ?? 'Untitled';
                    $url = $sourceLink['url'] ?? '';
                    $tool = $sourceLink['tool'] ?? 'unknown';
                    $synthesisInput .= "{$num}. **{$title}** - {$url} (via {$tool})\n";
                }
                $synthesisInput .= "\n**COMPREHENSIVE EVALUATION REQUIREMENTS**:\n";
                $synthesisInput .= '1. **ANALYZE ALL '.count($uniqueSourceLinks)." SOURCES**: Review and synthesize information from every single source listed above\n";
                $synthesisInput .= "2. **DO NOT** focus only on the most recent or final sources - evaluate the full collection comprehensively\n";
                $synthesisInput .= "3. **CROSS-REFERENCE**: Look for patterns, confirmations, and contradictions across all sources\n";
                $synthesisInput .= "4. **PRIORITIZE COVERAGE**: Ensure your analysis draws from the breadth of sources, not just a subset\n";
                $synthesisInput .= "5. **CITE COMPREHENSIVELY**: Use inline markdown links [specific claim](exact-source-url) for all factual statements\n";
                $synthesisInput .= "6. **NEVER** create fake URLs like 'https://example.com' - ONLY use the exact URLs provided above\n";
                $synthesisInput .= "7. **SYSTEMATIC ANALYSIS**: Address different aspects of the original request using different sources where applicable\n";
                $synthesisInput .= "8. **SOURCE DIVERSITY**: Acknowledge when multiple sources confirm the same information\n";
                $synthesisInput .= "9. **COMPREHENSIVE SOURCES SECTION**: Include a complete list of all sources used in your analysis\n\n";
                $synthesisInput .= "**REMINDER**: You have access to **{$totalSearchResults} search results** across **".count($uniqueSourceLinks)." unique sources**. Use this comprehensive information to provide a thorough, well-sourced response.\n\n";
            } else {
                $synthesisInput .= "**NOTE**: No source links were collected from the workflow agents.\n\n";
            }

            $synthesisInput .= 'Please synthesize these results according to the workflow requirements, ensuring comprehensive evaluation of ALL collected sources.';

            // Create a synthesis agent execution using the workflow's AI model and prompt
            $synthesisExecution = new AgentExecution([
                'agent_id' => $execution->agent_id,
                'user_id' => $execution->user_id,
                'chat_session_id' => $execution->chat_session_id,
                'input' => $synthesisInput,
                'max_steps' => 1, // Simple synthesis, no tool usage needed
                'status' => 'running',
            ]);

            // Add explanatory message for AI handover during workflow synthesis
            $sourceCount = count($uniqueSourceLinks);
            $this->reportStatus('ai_synthesis_handover',
                'Handing over to AI to synthesize and analyze results from '.count($results)." completed workflow agents with {$sourceCount} unique sources", true, false);

            // Execute AI synthesis using PrismWrapper with enhanced error logging
            // Use withSystemPrompt() for provider interoperability (per Prism best practices)
            $prismRequest = app(\App\Services\AI\PrismWrapper::class)
                ->text()
                ->using($execution->agent->getProviderEnum(), $execution->agent->ai_model)
                ->withMaxSteps(1) // Simple synthesis, no tool usage needed
                ->withMaxTokens(16384) // Allow comprehensive synthesis responses
                ->withSystemPrompt($systemPrompt) // Use withSystemPrompt() instead of SystemMessage in messages array
                ->withMessages([
                    new UserMessage($synthesisInput),
                ])
                ->withContext([
                    'execution_id' => $execution->id,
                    'agent_id' => $execution->agent_id,
                    'user_id' => $execution->user_id,
                    'mode' => 'workflow_synthesis',
                    'source_count' => $sourceCount,
                ]);

            $response = $prismRequest->asStream();
            $synthesisResult = '';

            // Add message to indicate AI synthesis is now processing
            $this->reportStatus('ai_synthesis_processing',
                "AI is now analyzing and synthesizing the collected research findings from {$sourceCount} sources into a comprehensive response", true, true);

            foreach ($response as $chunk) {
                if (isset($chunk->text)) {
                    $synthesisResult .= $chunk->text;
                }
            }

            // Validate synthesis result for fake URLs
            $synthesisResult = $this->validateSynthesisOutput($synthesisResult, $uniqueSourceLinks);

            // Extract and track all URLs that appear in the synthesis result
            $this->trackSynthesisUrls($synthesisResult, $execution);

            Log::info('Workflow synthesis completed', [
                'workflow_name' => $execution->agent->name,
                'synthesis_length' => strlen($synthesisResult),
                'sources_processed' => count($uniqueSourceLinks),
                'total_search_operations' => $totalSearchResults,
            ]);

            return $synthesisResult;

        } catch (\Exception $e) {
            Log::error('Workflow synthesis failed, using fallback', [
                'workflow_name' => $execution->agent->name,
                'error' => $e->getMessage(),
            ]);

            // Fallback to basic summary if AI synthesis fails
            return $this->generateBasicWorkflowSummary($execution->agent->name, $results, $combinedOutput);
        }
    }

    /**
     * Inject available agents information into workflow system prompt
     */
    protected function injectAvailableAgents(string $systemPrompt, array $workflowConfig): string
    {
        if (! str_contains($systemPrompt, '{available_agents}')) {
            return $systemPrompt;
        }

        $agentsInfo = '';

        if (! empty($workflowConfig['agents'])) {
            foreach ($workflowConfig['agents'] as $index => $agentConfig) {
                if (! ($agentConfig['enabled'] ?? true)) {
                    continue; // Skip disabled agents
                }

                $agentInfo = '**Agent '.($index + 1).": {$agentConfig['name']}**\n";
                $agentInfo .= "- **Description**: {$agentConfig['description']}\n";
                $agentInfo .= "- **Execution Order**: {$agentConfig['execution_order']}\n";

                // Try to get additional tool information if available
                try {
                    $agent = Agent::find($agentConfig['id']);
                    if ($agent && $agent->tools->isNotEmpty()) {
                        $tools = $agent->tools->where('enabled', true)->pluck('tool_name')->toArray();
                        $agentInfo .= '- **Available Tools**: '.implode(', ', $tools)."\n";
                    }
                } catch (\Exception $e) {
                    // Silently continue if agent lookup fails
                }

                $agentInfo .= "\n";
                $agentsInfo .= $agentInfo;
            }
        }

        if (empty($agentsInfo)) {
            $agentsInfo = "No agents configured in this workflow.\n";
        }

        return str_replace('{available_agents}', $agentsInfo, $systemPrompt);
    }

    /**
     * Generate a basic summary as fallback when AI synthesis fails
     */
    protected function generateBasicWorkflowSummary(string $workflowName, array $results, string $combinedOutput): string
    {
        $summary = "# {$workflowName} Results\n\n";
        $summary .= 'This workflow executed '.count($results)." specialized agents in sequence.\n\n";
        $summary .= $combinedOutput;
        $summary .= "## Workflow Summary\n\n";
        $summary .= '- **Agents executed**: '.count($results)."\n";
        $summary .= '- **Total processing time**: '.array_sum(array_column($results, 'duration_ms'))."ms\n";

        return $summary;
    }

    public function executeSingleAgent(AgentExecution $execution): string
    {
        // Load user-specific MCP tools before execution
        Log::info('AgentExecutor: About to load MCP tools in executeSingleAgent', [
            'execution_id' => $execution->id,
            'user_id' => $execution->user_id,
        ]);

        $this->toolRegistry->loadMcpTools($execution->user_id);

        // Debug: Check what MCP tools are available after loading
        $availableTools = $this->toolRegistry->getAvailableTools($execution->user_id);
        $mcpTools = array_filter($availableTools, fn ($tool) => ($tool['category'] ?? '') === 'mcp');

        Log::info('AgentExecutor: MCP tools loaded in executeSingleAgent', [
            'execution_id' => $execution->id,
            'user_id' => $execution->user_id,
            'mcp_tools_count' => count($mcpTools),
            'mcp_tool_names' => array_keys($mcpTools),
            'all_tools_count' => count($availableTools),
        ]);

        if (app()->has('status_reporter')) {
            $this->statusReporter = app('status_reporter');
            $this->statusReporter->setAgentExecutionId($execution->id);
        } else {
            $this->statusReporter = new StatusReporter(null, $execution->id);
            app()->instance('status_reporter', $this->statusReporter);
        }

        // Check streaming capabilities for this agent
        $agent = $execution->agent;
        $hasResponseStreaming = $agent->isStreamingEnabled();
        $hasThinkingStreaming = $agent->isThinkingEnabled();

        // Temporarily disable streaming paths - focus on fixing standard execution
        if ($hasResponseStreaming || $hasThinkingStreaming) {
            Log::info('AgentExecutor: Streaming requested but temporarily disabled', [
                'execution_id' => $execution->id,
                'response_streaming' => $hasResponseStreaming,
                'thinking_streaming' => $hasThinkingStreaming,
            ]);
        }

        return $this->executeWithoutStreaming($execution);
    }

    /**
     * Update status messages specific to thinking-only execution
     */
    private function updateThinkingOnlyStatusMessages(string $agentName, int $toolCount): void
    {
        if ($toolCount > 0) {
            $this->reportStatus('ai_agent_handover',
                "Handing over to AI agent '{$agentName}' with {$toolCount} available tools for thinking process streaming", true, false);
        } else {
            $this->reportStatus('ai_agent_handover',
                "Handing over to AI agent '{$agentName}' for thinking process streaming", true, false);
        }
    }

    /**
     * Load and validate tools for agent execution
     *
     * Uses ToolOverrideService to centralize tool loading logic and handle per-execution
     * tool overrides when enabled. Tool overrides allow users to enable/disable specific
     * tools or MCP servers for individual execution contexts.
     *
     * @param  AgentExecution  $execution  The execution context with agent tool configuration
     * @return array{instances: array, failed_names: array, available_names: array} Tool loading result with:
     *                                                                              - instances: Successfully loaded tool instances
     *                                                                              - failed_names: Names of tools that failed to load
     *                                                                              - available_names: Names of all available tools for this execution
     */
    private function loadAndValidateTools(AgentExecution $execution): array
    {
        // Use ToolOverrideService to centralize tool loading logic
        $toolOverrideService = app(ToolOverrideService::class);

        // Check for enabled tool overrides first (only applies if toggle was on)
        $toolOverrides = $execution->getEnabledToolOverrides();

        // Load tools with override support using the shared service
        $toolResult = $toolOverrideService->loadToolsWithOverrides(
            $toolOverrides,
            $execution->agent->enabledTools,
            "execution_{$execution->id}"
        );

        return $toolResult;
    }

    /**
     * Prepare system prompt with dynamic tool instructions and knowledge enhancement
     */
    private function prepareSystemPrompt(AgentExecution $execution, array $failedToolNames, array $availableToolNames): string
    {
        // Check for enabled tool overrides from the execution (only applies if toggle was on)
        $toolOverrides = $execution->getEnabledToolOverrides();

        if ($toolOverrides) {
            Log::info('AgentExecutor: Using enabled tool overrides for system prompt', [
                'execution_id' => $execution->id,
                'enabled_tools' => $toolOverrides['enabled_tools'] ?? [],
                'enabled_servers' => $toolOverrides['enabled_servers'] ?? [],
                'override_enabled' => $toolOverrides['override_enabled'] ?? false,
            ]);
        }

        // Start with base system prompt
        $baseSystemPrompt = $execution->agent->system_prompt;

        // Inject trigger context if this execution was triggered from an external integration
        if (isset($execution->metadata['trigger_context'])) {
            $baseSystemPrompt = $this->injectTriggerContext($baseSystemPrompt, $execution->metadata['trigger_context']);
        }

        // Extract knowledge scope tags for this execution
        $knowledgeScopeTags = $this->extractKnowledgeScopeTags($execution);

        // Prepare system prompt with dynamic tool instructions (including overrides and scope tags)
        $systemPrompt = $this->dynamicPromptService->injectToolInstructions(
            $baseSystemPrompt,
            $execution->agent,
            $execution->chat_session_id,
            $toolOverrides,
            $knowledgeScopeTags
        );

        // Inject AI Persona context
        $systemPrompt = AiPersonaService::injectIntoSystemPrompt($systemPrompt, $execution->user);

        if (! empty($failedToolNames)) {
            $systemPrompt .= "\n\n## CURRENT EXECUTION CONTEXT\n";
            $systemPrompt .= '**Available tools for this execution:** '.implode(', ', $availableToolNames)."\n";
            $systemPrompt .= '**Unavailable tools (connection failed):** '.implode(', ', $failedToolNames)."\n";
            $systemPrompt .= '**Adaptation Required:** Adjust your research strategy to use only the available tools listed above. Follow the resilience guidelines in your workflow.';
        }

        return $systemPrompt;
    }

    /**
     * Inject trigger context (from external integrations) into system prompt
     * This makes external conversation context available to the agent
     */
    private function injectTriggerContext(string $originalPrompt, array $context): string
    {
        $triggerContext = '';

        // Slack-specific context
        if (isset($context['slack_user']) || isset($context['slack_channel']) || isset($context['slack_thread'])) {
            $triggerContext .= "## Slack Context\n\nYou are being invoked from Slack with the following context:\n\n";

            if (isset($context['slack_user'])) {
                $user = $context['slack_user'];
                $triggerContext .= "**User**: {$user['real_name']} (@{$user['name']})\n";
                if (! empty($user['title'])) {
                    $triggerContext .= "- Title: {$user['title']}\n";
                }
                if (! empty($user['email'])) {
                    $triggerContext .= "- Email: {$user['email']}\n";
                }
                if (! empty($user['timezone'])) {
                    $triggerContext .= "- Timezone: {$user['timezone']}\n";
                }
                $triggerContext .= "\n";
            }

            if (isset($context['slack_channel'])) {
                $channel = $context['slack_channel'];
                $triggerContext .= "**Channel**: #{$channel['name']}\n";
                if (! empty($channel['topic'])) {
                    $triggerContext .= "- Topic: {$channel['topic']}\n";
                }
                if (! empty($channel['purpose'])) {
                    $triggerContext .= "- Purpose: {$channel['purpose']}\n";
                }
                $triggerContext .= "- Members: {$channel['member_count']} people\n\n";
            }

            if (isset($context['slack_thread'])) {
                $thread = $context['slack_thread'];
                $triggerContext .= "**Thread Context**:\nThis is part of an ongoing thread with {$thread['message_count']} messages.\n";
                $triggerContext .= "Recent conversation:\n";
                foreach ($thread['recent_messages'] as $msg) {
                    $triggerContext .= "- {$msg['user_name']}: {$msg['text']}\n";
                }
                $triggerContext .= "\n";
            }

            $triggerContext .= "When responding, keep in mind:\n";
            $triggerContext .= "- Use Slack-appropriate formatting (bold with *, code with `)\n";
            $triggerContext .= "- Keep responses conversational and concise\n";
        }

        // Future integrations can add their own context structures here
        // e.g., Discord, Teams, email, etc.

        if (empty($triggerContext)) {
            return $originalPrompt;
        }

        return $originalPrompt."\n\n".$triggerContext;
    }

    /**
     * Get conversation history as message objects for proper Prism conversation flow
     * Only returns the most recent question-answer pair as message objects
     */
    private function getConversationHistoryMessages(?int $chatSessionId): array
    {
        if (! $chatSessionId) {
            return [];
        }

        // Get only the most recent interaction from this chat session
        // Only select needed columns to avoid MySQL sort buffer memory issues
        $lastInteraction = ChatInteraction::where('chat_session_id', $chatSessionId)
            ->whereNotNull('answer')
            ->where('answer', '!=', '')
            ->select(['id', 'question', 'answer', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $lastInteraction) {
            return [];
        }

        $historyMessage = [];

        // Add the last question-answer pair as proper message objects
        if ($lastInteraction->question) {
            $historyMessage[] = new UserMessage($lastInteraction->question);
        }

        if ($lastInteraction->answer) {
            $historyMessage[] = new \Prism\Prism\ValueObjects\Messages\AssistantMessage($lastInteraction->answer);
        }

        return $historyMessage;
    }

    /**
     * Process attachments from current interaction and conversation history
     */
    private function processAttachments(AgentExecution $execution, string $systemPrompt): array
    {
        // Add SystemMessage to messages array - it will be extracted by createPrismRequest()
        // and passed via withSystemPrompt() for provider interoperability
        $messages = [new SystemMessage($systemPrompt)];

        // Add last interaction as actual message objects (before current input)
        $conversationHistory = $this->getConversationHistoryMessages($execution->chat_session_id);
        if (! empty($conversationHistory)) {
            $messages = array_merge($messages, $conversationHistory);

            Log::info('AgentExecutor: Added last interaction to message chain', [
                'execution_id' => $execution->id,
                'history_messages_count' => count($conversationHistory),
                'chat_session_id' => $execution->chat_session_id,
            ]);
        }

        // Collect attachments from current interaction and recent conversation history
        $allAttachments = collect();

        // Get the ChatInteraction associated with this execution
        // Try multiple sources: direct relationship, container binding, or parent execution
        $chatInteraction = $execution->chatInteraction;

        /**
         * For workflow child executions, check container for interaction ID.
         *
         * Workflow child executions do not have a direct chatInteraction relationship
         * because they are spawned from a parent workflow execution. The interaction ID
         * is stored in the application container to provide context for:
         * - Attachment access (files uploaded with the original question)
         * - Source tracking (linking knowledge documents to the user interaction)
         * - Real-time status updates (broadcasting progress to the correct Livewire component)
         *
         * Resolution strategy:
         * 1. Check direct relationship (standard single-agent path)
         * 2. Check container binding (workflow child execution path)
         * 3. Check parent execution relationship (fallback)
         *
         * @see \App\Services\Agents\AgentExecutor::executeWithoutStreaming() (line 141)
         */
        if (! $chatInteraction && app()->has('current_interaction_id')) {
            $interactionId = app('current_interaction_id');
            $chatInteraction = ChatInteraction::with('attachments')->find($interactionId);

            Log::info('AgentExecutor: Retrieved interaction from container binding for workflow child', [
                'execution_id' => $execution->id,
                'interaction_id' => $interactionId,
                'is_workflow_child' => $execution->parent_agent_execution_id !== null,
            ]);
        }

        // Fallback: Check parent execution's interaction for workflow children
        if (! $chatInteraction && $execution->parent_agent_execution_id) {
            $parentExecution = $execution->parentExecution;
            if ($parentExecution && $parentExecution->chatInteraction) {
                $chatInteraction = $parentExecution->chatInteraction;

                Log::info('AgentExecutor: Retrieved interaction from parent execution for workflow child', [
                    'execution_id' => $execution->id,
                    'parent_execution_id' => $parentExecution->id,
                    'interaction_id' => $chatInteraction->id,
                ]);
            }
        }

        // Add current interaction's attachments
        if ($chatInteraction && $chatInteraction->attachments && $chatInteraction->attachments->count() > 0) {
            $allAttachments = $allAttachments->merge($chatInteraction->attachments);
        }

        // Add attachments from recent conversation history (for comparison tasks)
        if ($chatInteraction && $chatInteraction->chat_session_id) {
            // PERFORMANCE: Eager load attachments to prevent N+1 query
            $recentInteractions = ChatInteraction::where('chat_session_id', $chatInteraction->chat_session_id)
                ->where('id', '!=', $chatInteraction->id) // Exclude current interaction
                ->where('created_at', '>=', now()->subHours(24)) // Only last 24 hours
                ->whereHas('attachments') // Only interactions with attachments
                ->with('attachments') // Eager load to avoid N+1 query
                ->orderBy('created_at', 'desc')
                ->limit(3) // Limit to avoid too many attachments
                ->get();

            foreach ($recentInteractions as $interaction) {
                $allAttachments = $allAttachments->merge($interaction->attachments);
            }
        }

        if ($allAttachments->count() > 0) {
            Log::info('AgentExecutor: Processing attachments for agent execution', [
                'execution_id' => $execution->id,
                'interaction_id' => $chatInteraction ? $chatInteraction->id : null,
                'current_attachments' => $chatInteraction ? $chatInteraction->attachments->count() : 0,
                'history_attachments' => $allAttachments->count() - ($chatInteraction ? $chatInteraction->attachments->count() : 0),
                'total_attachments' => $allAttachments->count(),
            ]);

            // Process attachments using AttachmentProcessor service
            $processed = $this->attachmentProcessor->process($allAttachments, "execution_{$execution->id}");

            // Build the user input with injected text content
            $userInput = $execution->input;

            // Append text attachments
            if (! empty($processed['text_content'])) {
                $userInput .= $processed['text_content'];
            }

            // Append image URLs for tool reference (like create_github_issue)
            if (! empty($processed['image_urls'])) {
                $userInput .= $this->attachmentProcessor->buildImageUrlsSection($processed['image_urls']);

                Log::info('AgentExecutor: Added image attachment URLs to user input', [
                    'execution_id' => $execution->id,
                    'image_count' => count($processed['image_urls']),
                ]);
            }

            // Create UserMessage with binary attachments if any exist
            if (! empty($processed['prism_objects'])) {
                Log::info('AgentExecutor: Creating UserMessage with binary attachments', [
                    'execution_id' => $execution->id,
                    'attachment_objects_count' => count($processed['prism_objects']),
                ]);
                $messages[] = new UserMessage($userInput, $processed['prism_objects']);
            } else {
                // No binary attachments, use simple UserMessage (may contain injected text and knowledge context)
                $messages[] = new UserMessage($userInput);
            }
        } else {
            // No file attachments - create simple UserMessage
            $userInput = $execution->input;
            $messages[] = new UserMessage($userInput);
        }

        return $messages;
    }

    /**
     * Create and configure Prism request for agent execution
     */
    private function createPrismRequest(AgentExecution $execution, array $messages, array $tools, bool $skipHandoverMessage = false): mixed
    {
        // Store messages as json_encoded ai_prompt string in execution metadata
        // Refresh execution to get latest metadata from database (including tool_overrides)
        $execution->refresh();
        $currentMetadata = $execution->metadata ?? [];
        $currentMetadata['ai_prompt'] = json_encode($messages);

        // Save metadata immediately to database
        $execution->update(['metadata' => $currentMetadata]);

        /**
         * Extract SystemMessage and use withSystemPrompt() for provider interoperability.
         *
         * Prism best practice: Always use withSystemPrompt() instead of including
         * SystemMessage objects in the messages array. This ensures compatibility
         * across all providers:
         *
         * - OpenAI: Requires system messages in specific format
         * - Anthropic: Uses dedicated system parameter
         * - Bedrock: Varies by model (Claude vs others)
         * - Ollama: Local model variations
         *
         * Without this extraction, some providers will reject requests or silently
         * ignore the system prompt, leading to inconsistent agent behavior.
         *
         * @see https://prism-php.com/docs/messages#system-messages
         * @see \EchoLabs\Prism\Prism::withSystemPrompt()
         */
        $systemPromptText = null;
        $filteredMessages = [];

        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $systemPromptText = $message->content; // Access property, not method

                Log::debug('AgentExecutor: Extracted SystemMessage for provider interoperability', [
                    'execution_id' => $execution->id,
                    'provider' => $execution->agent->ai_provider,
                    'system_prompt_length' => strlen($systemPromptText),
                ]);
            } else {
                $filteredMessages[] = $message;
            }
        }

        // Create Prism request using PrismWrapper for enhanced error logging
        $prismRequest = app(\App\Services\AI\PrismWrapper::class)
            ->text()
            ->using($execution->agent->getProviderEnum(), $execution->agent->ai_model)
            ->withMaxSteps($execution->max_steps);

        // Add system prompt via withSystemPrompt() (best practice for all providers)
        if ($systemPromptText) {
            $prismRequest = $prismRequest->withSystemPrompt($systemPromptText);
        }

        // Add messages (without SystemMessage) and context
        $prismRequest = $prismRequest->withMessages($filteredMessages)
            ->withContext([
                'execution_id' => $execution->id,
                'agent_id' => $execution->agent_id,
                'user_id' => $execution->user_id,
                'agent_name' => $execution->agent->name,
                'mode' => 'agent_execution',
            ]);

        // Add tools if available (with priority-based execution)
        if (! empty($tools)) {
            $prismRequest = $prismRequest->withTools($tools);
            $this->reportStatus('agent_tools_loaded', 'Loaded '.count($tools).' tools for agent execution', true, false);
        }

        // Configure max output tokens (default: 16384 for comprehensive responses)
        // Note: This is OUTPUT tokens, not input context tokens
        // Set higher default to avoid truncating agent responses
        $maxOutputTokens = 16384; // Higher default for comprehensive agent responses
        if ($execution->agent->ai_config && isset($execution->agent->ai_config['max_output_tokens'])) {
            $maxOutputTokens = (int) $execution->agent->ai_config['max_output_tokens'];
        }
        $prismRequest = $prismRequest->withMaxTokens($maxOutputTokens);

        Log::info('AgentExecutor: Configured Prism request', [
            'execution_id' => $execution->id,
            'agent_name' => $execution->agent->name,
            'model' => $execution->agent->ai_model,
            'max_steps' => $execution->max_steps,
            'max_output_tokens' => $maxOutputTokens,
            'tools_count' => count($tools),
            'messages_count' => count($messages),
        ]);

        // Configure AI settings from agent config
        if ($execution->agent->ai_config) {
            $this->applyAiConfig($prismRequest, $execution->agent->ai_config);
        }

        // Note: For agents that produce structured output (like Research Planner),
        // the agent's system prompt instructs it to return JSON in the text response.
        // We parse this JSON after execution completes in orchestrateWorkflowPlan().

        // Add explanatory message for AI handover (unless skipped for streaming)
        if (! $skipHandoverMessage) {
            $toolCount = ! empty($tools) ? count($tools) : 0;
            if ($toolCount > 0) {
                $this->reportStatus('ai_agent_handover',
                    "Handing over to AI agent '{$execution->agent->name}' with {$toolCount} available tools for autonomous task execution", true, false);
            } else {
                $this->reportStatus('ai_agent_handover',
                    "Handing over to AI agent '{$execution->agent->name}' for direct response generation", true, false);
            }
        }

        return $prismRequest;
    }

    /**
     * Finalize execution with metadata, source links and completion status
     */
    private function finalizeExecution(AgentExecution $execution, string $answer, array $toolResults, array $collectedToolResults, int $stepCount, bool $isStreaming = false): void
    {

        // Convert Prism value objects to arrays for JSON storage
        $serializedToolCalls = array_map(function ($toolCall) {
            return [
                'name' => $toolCall->name ?? 'unknown',
                'arguments' => $toolCall->arguments() ?? [],
                'id' => property_exists($toolCall, 'id') ? $toolCall->id : null,
            ];
        }, $toolResults);

        $serializedToolResults = array_map(function ($toolResult) {
            return [
                'toolName' => $toolResult->toolName ?? 'unknown',
                'toolCallId' => $toolResult->toolCallId ?? null,
                'result' => $toolResult->result ?? '',
            ];
        }, $collectedToolResults);

        // Store tool results in metadata for source link extraction
        // Preserve existing metadata (like ai_prompt) by merging with new metadata
        $existingMetadata = $execution->metadata ?? [];
        $newMetadata = [
            'tool_results' => $serializedToolResults,
            'tool_calls' => $serializedToolCalls,
            'steps_executed' => $stepCount,
            'streaming_enabled' => $isStreaming,
        ];
        $metadata = array_merge($existingMetadata, $newMetadata);

        // Log what we're storing for debugging
        Log::info('AgentExecutor: Finalizing execution with metadata', [
            'execution_id' => $execution->id,
            'tool_calls_count' => count($serializedToolCalls),
            'tool_results_count' => count($serializedToolResults),
            'steps_executed' => $stepCount,
            'tool_calls_sample' => ! empty($serializedToolCalls) ? array_slice($serializedToolCalls, 0, 2) : [],
            'tool_results_sample' => ! empty($serializedToolResults) ? array_slice($serializedToolResults, 0, 2) : [],
        ]);

        // Mark as completed with metadata (this must happen before source extraction)
        $execution->markAsCompleted($answer, $metadata);

        // Extract and persist source links from tool results after completion
        $extractedSourceLinks = $this->extractSourceLinksFromExecution($execution);
        if (! empty($extractedSourceLinks)) {
            $this->persistSourceLinksFromExecution($execution, $extractedSourceLinks);

            /**
             * Refresh execution to get latest metadata from database before updating.
             *
             * This prevents a race condition where:
             * 1. markAsCompleted() saves metadata (tool_results, tool_calls, etc.)
             * 2. Source extraction happens (takes time)
             * 3. update() would use stale in-memory metadata, overwriting database changes
             *
             * Without refresh(), we'd lose tool_results and other metadata that was
             * persisted during markAsCompleted(). This caused source links to appear
             * but tool execution history to vanish from the UI.
             *
             * Database sync pattern: Always refresh before subsequent updates to the
             * same model within a single request lifecycle.
             */
            $execution->refresh();
            $currentMetadata = $execution->metadata ?? [];
            $currentMetadata['source_links'] = $extractedSourceLinks;
            $execution->update(['metadata' => $currentMetadata]);
        }

        $executionType = $isStreaming ? 'Streaming agent' : 'Agent';
        $this->reportStatus('agent_execution_completed', "{$executionType} execution completed: {$execution->agent->name} ({$stepCount} steps)", true, false);

        Log::info('AgentExecutor: Execution completed successfully', [
            'execution_id' => $execution->id,
            'steps_executed' => $stepCount,
            'answer_length' => strlen($answer),
            'streaming_enabled' => $isStreaming,
        ]);
    }

    /**
     * Handle execution errors with appropriate logging and status reporting
     */
    private function handleExecutionError(AgentExecution $execution, \Exception $e, string $executionType): string
    {
        if ($e instanceof PrismException) {
            // Handle unknown finish reason error
            if (str_contains($e->getMessage(), 'unknown finish reason')) {
                Log::error('AgentExecutor: Unknown finish reason from OpenAI API', [
                    'execution_id' => $execution->id,
                    'agent_name' => $execution->agent->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errorMessage = 'AI model returned unexpected response. Possible causes: content limits, filtering, or rate limits. '.
                    'Try: simplifying request, breaking into smaller parts, or retrying shortly.';

                $this->safeMarkAsFailed(
                    $execution,
                    $errorMessage,
                    [
                        'context' => 'unknown_finish_reason',
                        'agent_name' => $execution->agent->name,
                    ],
                    false // Don't refresh
                );

                return $errorMessage;
            }

            // Handle Prism-specific exceptions like maximum tool call chain depth exceeded
            if (str_contains($e->getMessage(), 'Maximum tool call chain depth exceeded')) {
                Log::warning("AgentExecutor: Maximum tool call depth reached in {$executionType} execution", [
                    'execution_id' => $execution->id,
                    'agent_name' => $execution->agent->name,
                    'max_steps' => $execution->max_steps,
                ]);

                $partialResult = "{$executionType} execution reached the maximum tool call depth limit ({$execution->max_steps} steps). ".
                    'The agent completed with partial results. Consider increasing max_steps if more comprehensive results are needed.';

                try {
                    $execution->markAsCompleted($partialResult, [
                        'warning' => 'Maximum tool call depth exceeded',
                        'max_steps' => $execution->max_steps,
                        'completion_reason' => 'depth_limit_reached',
                        'streaming_enabled' => str_contains(strtolower($executionType), 'streaming'),
                    ]);
                    $this->reportStatus('agent_execution_completed_with_warning',
                        "{$executionType} execution completed with warning: Maximum depth reached for {$execution->agent->name}", true, false);
                } catch (\Exception $markingException) {
                    Log::error("AgentExecutor: Failed to mark {$executionType} execution as completed after depth limit", [
                        'execution_id' => $execution->id,
                        'marking_error' => $markingException->getMessage(),
                    ]);

                    $this->safeMarkAsFailed(
                        $execution,
                        'Maximum tool call depth exceeded: '.$e->getMessage(),
                        [
                            'context' => 'depth_limit_completion_failed',
                            'execution_type' => $executionType,
                        ],
                        false // Don't refresh - we just tried to update
                    );
                    $this->reportStatus('agent_execution_failed', "{$executionType} execution failed due to depth limit", true, false);
                }

                return $partialResult;
            }

            // Handle other Prism exceptions normally
            Log::error("AgentExecutor: Prism exception in {$executionType} execution", [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } else {
            Log::error("AgentExecutor: {$executionType} execution failed", [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // SECURITY: Sanitize error message to prevent information disclosure
        $sanitizedError = $this->sanitizeErrorMessage($e, $execution);
        $this->safeMarkAsFailed(
            $execution,
            $sanitizedError,
            [
                'context' => 'execution_failure',
                'execution_type' => $executionType,
            ],
            false // Don't refresh
        );
        $this->reportStatus('agent_execution_failed', "{$executionType} execution failed: {$execution->agent->name} - {$sanitizedError}", true, false);

        throw $e;
    }

    /**
     * Execute agent without streaming - uses existing execution logic
     */
    protected function executeWithoutStreaming(AgentExecution $execution): string
    {
        try {
            // Step 1: Load and validate tools
            $toolsData = $this->loadAndValidateTools($execution);
            $tools = $toolsData['tools'];
            $failedToolNames = $toolsData['failed_names'];
            $availableToolNames = $toolsData['available_names'];

            // Step 2: Prepare system prompt
            $systemPrompt = $this->prepareSystemPrompt($execution, $failedToolNames, $availableToolNames);

            // Step 3: Process attachments and create messages
            $messages = $this->processAttachments($execution, $systemPrompt);

            // Step 4: Create Prism request
            $prismRequest = $this->createPrismRequest($execution, $messages, $tools);

            // Step 5: Execute the agent (non-streaming) with retry for file type errors
            $retryCount = 0;
            $maxRetries = 2;

            while ($retryCount <= $maxRetries) {
                try {
                    $response = $prismRequest->generate();
                    break; // Success - exit retry loop
                } catch (\Exception $e) {
                    // Log detailed information about unknown finish reason errors
                    if (str_contains($e->getMessage(), 'unknown finish reason')) {
                        Log::error('AgentExecutor: Captured unknown finish reason in generate()', [
                            'execution_id' => $execution->id,
                            'agent_name' => $execution->agent->name,
                            'error_message' => $e->getMessage(),
                            'error_class' => get_class($e),
                            'available_tools_count' => count($tools),
                            'max_steps' => $execution->max_steps,
                            'retry_count' => $retryCount,
                        ]);

                        // For unknown finish reason, reduce max_steps and retry
                        if ($retryCount < $maxRetries) {
                            $retryCount++;
                            $reducedMaxSteps = max(5, (int) ($execution->max_steps * 0.5));

                            Log::info('AgentExecutor: Retrying with reduced max_steps', [
                                'execution_id' => $execution->id,
                                'original_max_steps' => $execution->max_steps,
                                'reduced_max_steps' => $reducedMaxSteps,
                                'retry_attempt' => $retryCount,
                            ]);

                            $this->reportStatus('agent_retry_reduced_complexity',
                                "Retrying with reduced complexity (max_steps: {$reducedMaxSteps})", true, false);

                            // Recreate request with reduced max_steps using PrismWrapper
                            $prismRequest = app(\App\Services\AI\PrismWrapper::class)
                                ->text()
                                ->using($execution->agent->ai_provider, $execution->agent->ai_model)
                                ->withMessages($messages)
                                ->withTools($tools)
                                ->withMaxSteps($reducedMaxSteps)
                                ->withMaxTokens(16384) // Maintain max output tokens on retry
                                ->withContext([
                                    'execution_id' => $execution->id,
                                    'agent_id' => $execution->agent_id,
                                    'user_id' => $execution->user_id,
                                    'mode' => 'agent_retry_reduced_complexity',
                                    'retry_attempt' => $retryCount,
                                    'reduced_max_steps' => $reducedMaxSteps,
                                ]);

                            continue; // Retry with new request
                        }
                    }

                    // Check if it's a file type validation error from OpenAI
                    if ($this->isFileTypeValidationError($e)) {
                        Log::warning('AgentExecutor: File type validation error detected, retrying without attachments', [
                            'execution_id' => $execution->id,
                            'error' => $e->getMessage(),
                        ]);

                        // Retry without attachments/knowledge documents
                        $messagesWithoutAttachments = $this->processAttachmentsForRetry($execution, $systemPrompt);
                        $prismRequest = $this->createPrismRequest($execution, $messagesWithoutAttachments, $tools);

                        $this->reportStatus('agent_retry_without_attachments',
                            'Retrying request without file attachments due to compatibility issues', true, false);

                        continue; // Retry with new request
                    }

                    throw $e; // Re-throw if it's not a recoverable error
                }
            }

            // Count tool results from steps (where they actually are)
            // Note: Prism returns steps as object (ArrayObject/Collection), not plain array
            $toolResultsInSteps = 0;
            if (isset($response->steps)) {
                foreach ($response->steps as $step) {
                    if (isset($step->toolResults)) {
                        $toolResultsInSteps += is_countable($step->toolResults) ? count($step->toolResults) : 0;
                    }
                }
            }

            // Debug log response structure
            Log::info('AgentExecutor: Prism response received', [
                'execution_id' => $execution->id,
                'response_text_length' => strlen($response->text ?? ''),
                'has_steps' => isset($response->steps),
                'steps_count' => isset($response->steps) ? count($response->steps) : 0,
                'tool_results_in_steps' => $toolResultsInSteps,
                'top_level_tool_results' => isset($response->toolResults) ? count($response->toolResults) : 0,
                'finish_reason' => $response->finishReason->name ?? 'unknown',
            ]);

            // Debug log each step structure (using INFO level so it shows up)
            if (isset($response->steps)) {
                Log::info('AgentExecutor: About to iterate through steps', [
                    'execution_id' => $execution->id,
                    'steps_count' => count($response->steps),
                ]);

                foreach ($response->steps as $index => $step) {
                    Log::info('AgentExecutor: Step structure', [
                        'execution_id' => $execution->id,
                        'step_index' => $index,
                        'has_tool_calls' => isset($step->toolCalls),
                        'tool_calls_count' => isset($step->toolCalls) ? count($step->toolCalls) : 0,
                        'step_type' => get_class($step),
                    ]);
                }
            } else {
                Log::warning('AgentExecutor: Response has no steps', [
                    'execution_id' => $execution->id,
                ]);
            }

            $answer = $response->text ?? '';
            $stepCount = 0;
            $toolResults = [];
            $collectedToolResults = [];

            // Add message to indicate AI is now processing
            $this->reportStatus('ai_processing_started',
                'AI is now processing the request and will use available tools as needed to complete the task', true, false);

            // Handle tool calls using documented pattern - iterate through steps
            if (isset($response->steps)) {
                Log::info('AgentExecutor: Processing response steps', [
                    'execution_id' => $execution->id,
                    'steps_count' => count($response->steps),
                ]);

                foreach ($response->steps as $stepIndex => $step) {
                    $stepClass = get_class($step);

                    // Log step details
                    Log::info('AgentExecutor: Processing step', [
                        'execution_id' => $execution->id,
                        'step_index' => $stepIndex,
                        'step_class' => $stepClass,
                        'has_tool_calls' => isset($step->toolCalls) && ! empty($step->toolCalls),
                        'has_tool_results' => isset($step->toolResults) && ! empty($step->toolResults),
                    ]);

                    // Check for tool calls in this step (UserMessage, SystemMessage with tool calls)
                    if (isset($step->toolCalls)) {
                        foreach ($step->toolCalls as $toolCall) {
                            $stepCount++;
                            $this->reportToolCall($toolCall, $stepCount, $execution->max_steps);
                            $toolResults[] = $toolCall;

                            Log::info('AgentExecutor: Tool call detected in step', [
                                'execution_id' => $execution->id,
                                'step_index' => $stepIndex,
                                'step_count' => $stepCount,
                                'tool_name' => $toolCall->name,
                                'tool_arguments' => $toolCall->arguments(),
                            ]);
                        }
                    }

                    // Check for tool results in this step (ToolResultMessage objects)
                    if (isset($step->toolResults)) {
                        Log::info('AgentExecutor: Found ToolResultMessage in step', [
                            'execution_id' => $execution->id,
                            'step_index' => $stepIndex,
                            'tool_results_count' => count($step->toolResults),
                        ]);

                        foreach ($step->toolResults as $toolResult) {
                            if ($this->processToolResult($execution, $toolResult, $stepIndex)) {
                                $collectedToolResults[] = $toolResult;
                            }
                        }
                    }
                }
            } else {
                Log::warning('AgentExecutor: No steps found in response', [
                    'execution_id' => $execution->id,
                ]);
            }

            // Handle tool results if present at top level in response (fallback/legacy pattern)
            if (isset($response->toolResults)) {
                Log::info('AgentExecutor: Processing top-level tool results', [
                    'execution_id' => $execution->id,
                    'tool_results_count' => count($response->toolResults),
                ]);

                foreach ($response->toolResults as $toolResult) {
                    // Avoid duplicates - check if already collected from steps
                    $alreadyCollected = false;
                    foreach ($collectedToolResults as $collected) {
                        if (($collected->toolCallId ?? '') === ($toolResult->toolCallId ?? '')) {
                            $alreadyCollected = true;
                            break;
                        }
                    }

                    if (! $alreadyCollected && $this->processToolResult($execution, $toolResult, 'top-level')) {
                        $collectedToolResults[] = $toolResult;
                    }
                }
            } else {
                Log::info('AgentExecutor: No top-level tool results in response (expected - results are in steps)', [
                    'execution_id' => $execution->id,
                ]);
            }

            // Step 6: Finalize execution
            $this->finalizeExecution($execution, $answer, $toolResults, $collectedToolResults, $stepCount, false);

            return $answer;

        } catch (\Exception $e) {
            return $this->handleExecutionError($execution, $e, 'Agent');
        }
    }

    /**
     * Process tool result and check for errors
     *
     * @param  AgentExecution  $execution  The execution context
     * @param  mixed  $toolResult  The tool result to process
     * @param  mixed  $context  Step context (number or string)
     * @return bool Always returns true to collect the result
     */
    protected function processToolResult(AgentExecution $execution, $toolResult, $context): bool
    {
        $toolName = $toolResult->toolName ?? 'unknown';
        $resultContent = $toolResult->result ?? '';
        $resultPreview = strlen($resultContent) > 500 ? substr($resultContent, 0, 500).'...' : $resultContent;

        // Track tool call metadata for depth limit debugging
        $metadata = $execution->metadata ?? [];
        $metadata['tool_calls_count'] = ($metadata['tool_calls_count'] ?? 0) + 1;
        $metadata['last_tool_name'] = $toolName;
        $execution->update(['metadata' => $metadata]);

        // CRITICAL: Check for tool execution errors
        $hasError = str_contains($resultContent, 'Tool execution error:');

        $contextLabel = is_int($context) ? "step {$context}" : $context;

        if ($hasError) {
            Log::error('AgentExecutor: Tool execution failed', [
                'execution_id' => $execution->id,
                'context' => $contextLabel,
                'tool_name' => $toolName,
                'error_message' => $resultContent,
                'tool_call_id' => $toolResult->toolCallId ?? 'unknown',
            ]);

            $this->reportStatus('tool_execution_error',
                "Tool '{$toolName}' failed: ".substr($resultContent, 0, 200),
                true, false);
        } else {
            Log::info('AgentExecutor: Tool result processed', [
                'execution_id' => $execution->id,
                'context' => $contextLabel,
                'tool_name' => $toolName,
                'result_preview' => $resultPreview,
                'has_error' => false,
            ]);
        }

        $this->reportToolResult($toolResult);

        // Check if enforce_link_validation is enabled for this agent
        if ($this->shouldEnforceUrlValidation($execution->agent)) {
            $this->processUrlValidation($toolResult, $execution);
        }

        return true; // Collect this result
    }

    /**
     * Apply AI configuration to Prism request
     *
     * @param  mixed  $prismRequest  The Prism request object
     * @param  array  $config  Configuration array
     */
    protected function applyAiConfig($prismRequest, array $config): void
    {
        // Apply any additional AI configuration
        if (isset($config['temperature'])) {
            // Note: Prism may not support all config options yet
            Log::debug('AgentExecutor: AI config available but not all options supported by Prism', [
                'config' => $config,
            ]);
        }
    }

    /**
     * Report tool call execution to StatusReporter
     *
     * @param  mixed  $toolCall  The tool call object
     * @param  int  $stepCount  Current step number
     * @param  int  $maxSteps  Maximum steps allowed
     */
    protected function reportToolCall($toolCall, int $stepCount, int $maxSteps): void
    {
        if (! $this->statusReporter) {
            return;
        }

        $toolName = $toolCall->name ?? 'unknown_tool';

        // Store start time for this tool call to track duration
        $this->reportStatusWithMetadata('tool_call', "Step {$stepCount}/{$maxSteps}: Executing {$toolName}", [
            'tool_name' => $toolName,
            'step_number' => $stepCount,
            'max_steps' => $maxSteps,
            'step_start_time' => microtime(true),
            'step_type' => 'tool_execution',
        ], false, false);
    }

    /**
     * Report tool result completion to StatusReporter
     *
     * @param  mixed  $toolResult  The tool result object
     */
    protected function reportToolResult($toolResult): void
    {
        if (! $this->statusReporter) {
            return;
        }

        $toolName = $toolResult->toolName ?? 'unknown_tool';

        // Calculate duration if we have a start time for this tool
        $endTime = microtime(true);
        $duration = null;

        // With the new WebSockets-only StatusReporter, we don't have access to previous entries directly
        // Instead, we'll use the tool execution metadata if available from the calling context
        $duration = null;

        // If we have tool execution metadata from the current execution context, use it
        $toolMetadata = $toolResult->metadata ?? null;
        if ($toolMetadata && isset($toolMetadata['execution_start_time'])) {
            $startTime = $toolMetadata['execution_start_time'];
            $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        }

        $this->reportStatusWithMetadata('tool_result', "Tool {$toolName} completed".($duration ? sprintf(' (%.2fms)', $duration) : ''), [
            'tool_name' => $toolName,
            'step_duration_ms' => $duration,
            'step_end_time' => $endTime,
            'step_type' => 'tool_completion',
        ], false, false);
    }

    protected function reportStatus(string $source, string $message, bool $createEvent = true, bool $isSignificant = false): void
    {
        if (! $this->statusReporter) {
            return;
        }

        $this->statusReporter->report($source, $message, $createEvent, $isSignificant);
    }

    /**
     * Report status with metadata
     *
     * @param  string  $source  The source of the status update
     * @param  string  $message  The status message
     * @param  array  $metadata  Additional metadata
     * @param  bool  $createEvent  Whether to create an event (default: true)
     * @param  bool  $isSignificant  Whether the update is significant (default: false)
     */
    protected function reportStatusWithMetadata(string $source, string $message, array $metadata = [], bool $createEvent = true, bool $isSignificant = false): void
    {
        if (! $this->statusReporter) {
            return;
        }

        $this->statusReporter->reportWithMetadata($source, $message, $metadata, $createEvent, $isSignificant);
    }

    /**
     * Extract source links from an AgentExecution by analyzing tool results.
     * This method parses the execution's tool results to extract source links.
     */
    protected function extractSourceLinksFromExecution(AgentExecution $execution): array
    {
        $sourceLinks = [];

        // Check if execution has tool results stored in metadata
        if ($execution->metadata && isset($execution->metadata['tool_results'])) {
            foreach ($execution->metadata['tool_results'] as $toolResult) {
                $sourceLinks = array_merge($sourceLinks, $this->sourceLinkExtractor->extractFromToolResult($toolResult));
            }
        }

        // Also check the execution's metadata for any stored source links
        if ($execution->metadata && isset($execution->metadata['source_links'])) {
            $sourceLinks = array_merge($sourceLinks, $execution->metadata['source_links']);
        }

        return array_unique($sourceLinks, SORT_REGULAR);
    }

    /**
     * Get summary of tool priority levels for reporting
     */
    protected function getToolPrioritySummary(Agent $agent): array
    {
        $enabledTools = $agent->enabledTools()->byPriority()->get();

        return [
            'preferred' => $enabledTools->where('priority_level', 'preferred')->count(),
            'standard' => $enabledTools->where('priority_level', 'standard')->count(),
            'fallback' => $enabledTools->where('priority_level', 'fallback')->count(),
            'total' => $enabledTools->count(),
        ];
    }

    /**
     * Validate synthesis output to prevent fake URLs
     */
    protected function validateSynthesisOutput(string $synthesisResult, array $allowedSourceLinks): string
    {
        // Extract all URLs from the synthesis result
        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $synthesisResult, $matches, PREG_SET_ORDER);

        $allowedUrls = array_map(function ($link) {
            return $link['url'] ?? '';
        }, $allowedSourceLinks);

        $fakeUrlsFound = [];

        foreach ($matches as $match) {
            $url = $match[2];

            // Check if URL is in allowed list
            if (! in_array($url, $allowedUrls)) {
                // Check for common fake URL patterns
                if (preg_match('/example\.com|example-source-link|fake-url|placeholder|dummy/', $url)) {
                    $fakeUrlsFound[] = $url;
                }
            }
        }

        if (! empty($fakeUrlsFound)) {
            Log::warning('Fake URLs detected in synthesis output', [
                'fake_urls' => $fakeUrlsFound,
                'allowed_urls_count' => count($allowedUrls),
            ]);

            // Remove fake URLs and replace with warning
            foreach ($fakeUrlsFound as $fakeUrl) {
                $synthesisResult = str_replace($fakeUrl, '[REMOVED - FAKE URL]', $synthesisResult);
            }

            // Add warning at the beginning
            $warning = "\n\n**⚠️ WARNING: Fake URLs were detected and removed from this synthesis. Only real source links should be used.**\n\n";
            $synthesisResult = $warning.$synthesisResult;
        }

        return $synthesisResult;
    }

    /**
     * Track all URLs found in synthesis result to ensure they appear in sources
     */
    protected function trackSynthesisUrls(string $synthesisResult, AgentExecution $execution): void
    {
        // Get the interaction for this execution
        $interaction = $execution->chatInteraction;
        if (! $interaction) {
            Log::warning('No interaction found for execution, cannot track synthesis URLs', [
                'execution_id' => $execution->id,
            ]);

            return;
        }

        // Dispatch event for side effect listeners (Phase 3: side effects via events only)
        // Listener: TrackAgentExecutionUrls
        \App\Events\AgentExecutionCompleted::dispatch(
            $execution,
            $interaction,
            $synthesisResult,
            'agent_executor'
        );
    }

    /**
     * Check if URL validation should be enforced for this agent
     */
    protected function shouldEnforceUrlValidation(Agent $agent): bool
    {
        $workflowConfig = $agent->workflow_config ?? [];

        return $workflowConfig['enforce_link_validation'] ?? false;
    }

    /**
     * Process URL validation for tool results
     */
    protected function processUrlValidation($toolResult, AgentExecution $execution): void
    {
        try {
            // Only process certain tool types that return URLs
            $toolsWithUrls = ['searxng_search', 'perplexity_research'];
            $toolName = $toolResult->name ?? '';

            if (! in_array($toolName, $toolsWithUrls)) {
                return;
            }

            Log::info('AgentExecutor: Processing URL validation', [
                'execution_id' => $execution->id,
                'tool_name' => $toolName,
            ]);

            // Extract URLs from tool result
            $urls = $this->extractUrlsFromToolResult($toolResult);

            if (empty($urls)) {
                Log::info('AgentExecutor: No URLs found in tool result', [
                    'tool_name' => $toolName,
                ]);

                return;
            }

            $this->reportStatus('url_validation', 'Found '.count($urls)." URLs to validate from {$toolName}", true, false);

            // Get the link validator tool
            $linkValidatorTool = $this->toolRegistry->getTool('link_validator');
            if (! $linkValidatorTool) {
                Log::warning('AgentExecutor: LinkValidator tool not available for automatic validation');

                return;
            }

            $validationResults = [];

            // Validate each URL
            foreach ($urls as $url) {
                try {
                    Log::info('AgentExecutor: Validating URL automatically', [
                        'url' => $url,
                        'execution_id' => $execution->id,
                    ]);

                    // Call the link validator
                    $validationResult = $linkValidatorTool(['url' => $url]);
                    $validationData = json_decode($validationResult, true);

                    if ($validationData) {
                        $validationResults[] = [
                            'url' => $url,
                            'validation_data' => $validationData,
                            'is_valid' => $validationData['is_valid'] ?? false,
                            'recommend_scraping' => $validationData['recommend_full_scraping'] ?? false,
                        ];

                        Log::info('AgentExecutor: URL validation completed', [
                            'url' => $url,
                            'is_valid' => $validationData['is_valid'] ?? false,
                            'recommend_scraping' => $validationData['recommend_full_scraping'] ?? false,
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::error('AgentExecutor: URL validation failed', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Report validation summary
            $validUrls = count(array_filter($validationResults, fn ($r) => $r['is_valid']));
            $recommendedUrls = count(array_filter($validationResults, fn ($r) => $r['recommend_scraping']));

            $this->reportStatus('url_validation_complete',
                'Validated '.count($validationResults)." URLs: {$validUrls} valid, {$recommendedUrls} recommended for scraping",
                true, false
            );

            // Store validation results in execution metadata for later use
            $currentMetadata = $execution->metadata ?? [];
            $currentMetadata['url_validations'] = ($currentMetadata['url_validations'] ?? []);
            $currentMetadata['url_validations'] = array_merge($currentMetadata['url_validations'], $validationResults);

            $execution->update(['metadata' => $currentMetadata]);

        } catch (\Exception $e) {
            Log::error('AgentExecutor: Error in processUrlValidation', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Extract URLs from tool result based on tool type
     */
    protected function extractUrlsFromToolResult($toolResult): array
    {
        $urls = [];

        try {
            $resultData = json_decode($toolResult->result ?? '', true);

            if (! $resultData || ! ($resultData['success'] ?? false)) {
                return $urls;
            }

            $data = $resultData['data'] ?? [];

            // Handle SearXNG results
            if ($toolResult->name === 'searxng_search' && isset($data['results'])) {
                foreach ($data['results'] as $result) {
                    if (! empty($result['url'])) {
                        $urls[] = $result['url'];
                    }
                }
            }

            // Handle Perplexity results (if they contain URLs)
            if ($toolResult->name === 'perplexity_research') {
                // Extract URLs from perplexity result content
                $content = $data['content'] ?? '';
                if (preg_match_all('/https?:\/\/[^\s\)]+/', $content, $matches)) {
                    $urls = array_merge($urls, $matches[0]);
                }
            }

            // Remove duplicates and filter valid URLs
            $urls = array_unique($urls);
            $urls = array_filter($urls, function ($url) {
                return filter_var($url, FILTER_VALIDATE_URL) !== false;
            });

        } catch (\Exception $e) {
            Log::error('AgentExecutor: Error extracting URLs from tool result', [
                'tool_name' => $toolResult->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }

        return array_values($urls);
    }

    /**
     * Persist knowledge sources used in prompt enhancement to database
     */
    protected function persistKnowledgeEnhancementSources(AgentExecution $execution, array $knowledgeSources): void
    {
        try {
            $interaction = $execution->chatInteraction;
            if (! $interaction) {
                Log::warning('No interaction found for execution, cannot persist knowledge enhancement sources', [
                    'execution_id' => $execution->id,
                ]);

                return;
            }

            Log::info('Persisting knowledge enhancement sources', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
                'knowledge_sources_count' => count($knowledgeSources),
                'sources_structure' => json_encode($knowledgeSources),
            ]);

            foreach ($knowledgeSources as $source) {
                try {
                    // Extract information from the knowledge source (from KnowledgeToolService.generateContext)
                    $documentId = $source['id'] ?? null; // The document ID
                    $relevanceScore = $source['score'] ?? 0.8; // The relevance score from search
                    $title = $source['title'] ?? 'Knowledge Document';
                    $contentExcerpt = $source['summary'] ?? $source['content_excerpt'] ?? 'Content from knowledge enhancement';

                    if (! $documentId) {
                        Log::warning('Knowledge source missing document ID, skipping', [
                            'source' => $source,
                        ]);

                        continue;
                    }

                    // Verify document exists
                    if (! \App\Models\KnowledgeDocument::where('id', $documentId)->exists()) {
                        Log::warning('Knowledge document not found in database, skipping', [
                            'document_id' => $documentId,
                            'title' => $title,
                        ]);

                        continue;
                    }

                    Log::info('Creating ChatInteractionKnowledgeSource for enhancement source', [
                        'interaction_id' => $interaction->id,
                        'document_id' => $documentId,
                        'relevance_score' => $relevanceScore,
                        'title' => $title,
                    ]);

                    // Create or update the knowledge source record
                    $record = \App\Models\ChatInteractionKnowledgeSource::createOrUpdate(
                        $interaction->id,
                        $documentId,
                        $relevanceScore,
                        $contentExcerpt,
                        'prompt_enhancement',
                        'AgentKnowledgeService',
                        [
                            'enhancement_type' => 'system_prompt',
                            'agent_id' => $execution->agent_id,
                            'agent_name' => $execution->agent->name,
                        ]
                    );

                    if ($record) {
                        Log::info('Successfully persisted knowledge enhancement source', [
                            'interaction_id' => $interaction->id,
                            'document_id' => $documentId,
                            'record_id' => $record->id,
                            'title' => $title,
                        ]);
                    } else {
                        Log::info('Knowledge enhancement source not tracked (below threshold)', [
                            'interaction_id' => $interaction->id,
                            'document_id' => $documentId,
                            'relevance_score' => $relevanceScore,
                            'title' => $title,
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::error('Failed to persist individual knowledge enhancement source', [
                        'execution_id' => $execution->id,
                        'source' => $source,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to persist knowledge enhancement sources', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Persist extracted source links to database for source tracking
     */
    protected function persistSourceLinksFromExecution(AgentExecution $execution, array $sourceLinks): void
    {
        try {
            // Get the interaction for this execution
            $interaction = $execution->chatInteraction;
            if (! $interaction) {
                Log::warning('No interaction found for execution, cannot persist sources', [
                    'execution_id' => $execution->id,
                ]);

                return;
            }

            Log::info('Persisting source links from single agent execution', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
                'source_links_count' => count($sourceLinks),
            ]);

            foreach ($sourceLinks as $sourceLink) {
                try {
                    $url = $sourceLink['url'] ?? '';
                    if (empty($url)) {
                        continue;
                    }

                    // Use LinkValidator to properly validate and create the source
                    $linkValidator = app(\App\Services\LinkValidator::class);
                    $linkInfo = $linkValidator->validateAndExtractLinkInfo($url);

                    if ($linkInfo && isset($linkInfo['status']) && $linkInfo['status'] >= 200 && $linkInfo['status'] < 400) {
                        // Find the source created by LinkValidator (it uses url_hash)
                        $urlHash = md5($url);
                        $source = \App\Models\Source::where('url_hash', $urlHash)->first();

                        if ($source) {
                            // Create ChatInteractionSource record using the properly validated source
                            \App\Models\ChatInteractionSource::createOrUpdate(
                                $interaction->id,
                                $source->id,
                                $interaction->question ?? 'agent execution', // Use question as user query
                                [
                                    'url' => $url,
                                    'title' => $source->title ?? ($sourceLink['title'] ?? 'Untitled'),
                                    'description' => $source->description ?? ($sourceLink['content'] ?? ''),
                                    'domain' => $source->domain ?? parse_url($url, PHP_URL_HOST) ?? 'unknown',
                                    'content_category' => $source->content_category ?? 'general',
                                    'http_status' => $source->http_status ?? 200,
                                ],
                                'agent_execution',
                                $sourceLink['tool'] ?? 'unknown'
                            );

                            Log::debug('Persisted source link from single agent', [
                                'execution_id' => $execution->id,
                                'source_id' => $source->id,
                                'url' => $sourceLink['url'],
                                'tool' => $sourceLink['tool'] ?? 'unknown',
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    Log::error('Failed to persist individual source link', [
                        'execution_id' => $execution->id,
                        'source_url' => $sourceLink['url'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to persist source links from execution', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Check if an exception is a file type validation error from OpenAI
     */
    private function isFileTypeValidationError(\Exception $e): bool
    {
        $errorMessage = $e->getMessage();

        // Check for OpenAI file type validation error patterns
        return str_contains($errorMessage, 'The file type you uploaded is not supported') ||
               str_contains($errorMessage, 'Please try again with a pdf') ||
               str_contains($errorMessage, 'file type is not supported') ||
               str_contains($errorMessage, 'unsupported file type');
    }

    /**
     * Process attachments for retry without problematic file attachments
     *
     * Creates a minimal message chain for retry attempts when initial execution fails
     * due to file type validation errors. Injects text-only attachments and skips
     * binary files that may have caused the original failure.
     *
     * @param  AgentExecution  $execution  The execution context containing chat history and attachments
     * @param  string  $systemPrompt  System prompt with AI persona injection
     * @return array<\EchoLabs\Prism\ValueObjects\Messages\Message> Array of Prism message objects for retry
     */
    private function processAttachmentsForRetry(AgentExecution $execution, string $systemPrompt): array
    {
        // Add SystemMessage to messages array - it will be extracted by createPrismRequest()
        // and passed via withSystemPrompt() for provider interoperability
        $messages = [new SystemMessage($systemPrompt)];

        // Add last interaction as actual message objects (before current input)
        $conversationHistory = $this->getConversationHistoryMessages($execution->chat_session_id);
        if (! empty($conversationHistory)) {
            $messages = array_merge($messages, $conversationHistory);

            Log::info('AgentExecutor: Added conversation history to retry message chain', [
                'execution_id' => $execution->id,
                'history_messages_count' => count($conversationHistory),
                'chat_session_id' => $execution->chat_session_id,
            ]);
        }

        // Collect attachments from current interaction and recent conversation history
        $allAttachments = collect();
        $chatInteraction = $execution->chatInteraction;

        if ($chatInteraction && $chatInteraction->attachments && $chatInteraction->attachments->count() > 0) {
            $allAttachments = $allAttachments->merge($chatInteraction->attachments);
        }

        if ($chatInteraction && $chatInteraction->chat_session_id) {
            // PERFORMANCE: Eager load attachments to prevent N+1 query
            $recentInteractions = ChatInteraction::where('chat_session_id', $chatInteraction->chat_session_id)
                ->where('id', '!=', $chatInteraction->id)
                ->where('created_at', '>=', now()->subHours(24))
                ->whereHas('attachments')
                ->with('attachments') // Eager load to avoid N+1 query
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get();

            foreach ($recentInteractions as $interaction) {
                $allAttachments = $allAttachments->merge($interaction->attachments);
            }
        }

        $textAttachments = '';
        $validAttachmentObjects = [];

        // Knowledge context will be injected into the user input instead of as binary attachments

        // Only process text attachments (skip binary attachments that might cause issues)
        if ($allAttachments->count() > 0) {
            Log::info('AgentExecutor: Processing retry with text-only attachments', [
                'execution_id' => $execution->id,
                'total_attachments' => $allAttachments->count(),
            ]);

            foreach ($allAttachments as $attachment) {
                try {
                    // Only inject as text (avoid problematic binary uploads)
                    if ($attachment->shouldInjectAsText()) {
                        $textContent = $attachment->getTextContent();
                        if ($textContent) {
                            $textAttachments .= "\n\n--- Attached File: {$attachment->filename} ---\n{$textContent}\n--- End of {$attachment->filename} ---\n";
                            Log::info('AgentExecutor: Injected text attachment in retry', [
                                'execution_id' => $execution->id,
                                'attachment_id' => $attachment->id,
                                'filename' => $attachment->filename,
                                'content_length' => strlen($textContent),
                            ]);
                        }
                    } else {
                        // Skip binary attachments in retry
                        Log::info('AgentExecutor: Skipping binary attachment in retry', [
                            'execution_id' => $execution->id,
                            'attachment_id' => $attachment->id,
                            'filename' => $attachment->filename,
                            'mime_type' => $attachment->mime_type,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('AgentExecutor: Error processing attachment in retry, skipping', [
                        'attachment_id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Build user input with text attachments
        $userInput = $execution->input;

        // Append text attachments
        if (! empty($textAttachments)) {
            $userInput .= $textAttachments;
        }

        // Create simple UserMessage
        Log::info('AgentExecutor: Creating retry UserMessage with text', [
            'execution_id' => $execution->id,
            'text_attachments_count' => substr_count($textAttachments, '--- Attached File:'),
        ]);
        $messages[] = new UserMessage($userInput);

        return $messages;
    }

    /**
     * Check if agent execution should trigger workflow orchestration
     */
    protected function shouldOrchestrateWorkflowPlan(AgentExecution $execution): bool
    {
        $workflowConfig = $execution->agent->workflow_config;

        if (empty($workflowConfig)) {
            return false;
        }

        // Check if agent is configured to produce WorkflowPlan structured output
        return isset($workflowConfig['schema_class'])
            && $workflowConfig['schema_class'] === \App\Services\Agents\Schemas\WorkflowPlanSchema::class
            && ($workflowConfig['output_format'] ?? null) === 'structured';
    }

    /**
     * Parse structured WorkflowPlan output and orchestrate execution
     *
     * Parses JSON output from a workflow-enabled agent, validates the workflow plan structure,
     * and dispatches execution to WorkflowOrchestrator. Workflow plans define multi-agent
     * orchestration strategies (sequential, parallel, or hybrid) with synthesis requirements.
     *
     * @param  AgentExecution  $execution  The execution context for the orchestrator agent
     * @param  string  $structuredOutput  JSON-encoded WorkflowPlan from agent response
     * @param  int|null  $interactionId  Optional chat interaction ID for result linkage
     * @return string Empty string marker indicating workflow was orchestrated (not a final answer)
     *
     * @throws \Exception When JSON parsing fails or WorkflowPlan validation fails
     */
    protected function orchestrateWorkflowPlan(AgentExecution $execution, string $structuredOutput, ?int $interactionId): string
    {
        try {
            // Parse JSON output from the agent
            $structuredData = json_decode($structuredOutput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse WorkflowPlan JSON: '.json_last_error_msg());
            }

            // Store raw plan immediately for debugging (before validation)
            $metadata = $execution->metadata ?? [];
            $metadata['raw_workflow_plan'] = $structuredData;
            $metadata['raw_workflow_plan_received_at'] = now()->toIso8601String();
            $execution->update(['metadata' => $metadata]);

            // Convert to WorkflowPlan object using schema helper (includes validation)
            $workflowPlan = \App\Services\Agents\Schemas\WorkflowPlanSchema::toWorkflowPlan($structuredData);

            Log::info('AgentExecutor: WorkflowPlan parsed successfully', [
                'execution_id' => $execution->id,
                'strategy_type' => $workflowPlan->strategyType,
                'total_stages' => count($workflowPlan->stages),
                'total_jobs' => $workflowPlan->getTotalJobs(),
                'requires_synthesis' => $workflowPlan->requiresSynthesis(),
                'interaction_id' => $interactionId,
            ]);

            // Store workflow plan for UI display
            $execution->update([
                'workflow_plan' => [
                    'type' => 'workflow_plan',
                    'original_query' => $workflowPlan->originalQuery,
                    'strategy_type' => $workflowPlan->strategyType,
                    'stages' => array_map(fn ($stage) => [
                        'type' => $stage->type,
                        'nodes' => array_map(fn ($node) => [
                            'agent_id' => $node->agentId,
                            'agent_name' => $node->agentName,
                            'input' => $node->input,
                            'rationale' => $node->rationale,
                        ], $stage->nodes),
                    ], $workflowPlan->stages),
                    'synthesizer_agent_id' => $workflowPlan->synthesizerAgentId,
                    'estimated_duration_seconds' => $workflowPlan->estimatedDurationSeconds,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);

            // Execute workflow using WorkflowOrchestrator
            $orchestrator = new WorkflowOrchestrator;
            $batchId = $orchestrator->execute($workflowPlan, $execution, $interactionId);

            // Build status message for UI
            $message = "🔄 Workflow orchestration initiated\n\n";
            $message .= "**Strategy**: {$workflowPlan->strategyType}\n";
            $message .= "**Total Agents**: {$workflowPlan->getTotalJobs()}\n";
            $message .= "**Estimated Duration**: {$workflowPlan->estimatedDurationSeconds}s\n";

            if ($batchId) {
                $message .= "**Batch ID**: {$batchId}\n\n";
                $message .= 'The workflow is now running. Results will be synthesized when all agents complete.';
            } else {
                $message .= "\nSimple workflow dispatched directly (no batch tracking).";
            }

            // Report status to user via WebSocket
            $this->reportStatus('workflow_orchestration_started', $message, false, false);

            // Mark execution as orchestrating (don't complete it - synthesis will complete it)
            $execution->update([
                'status' => 'running', // Keep as running, not completed
                'metadata' => array_merge($execution->metadata ?? [], [
                    'orchestration_message' => $message,
                    'workflow_orchestrated' => true,
                    'awaiting_synthesis' => true,
                ]),
            ]);

            Log::info('AgentExecutor: Workflow orchestrated, execution will complete after synthesis', [
                'execution_id' => $execution->id,
                'batch_id' => $batchId,
                'strategy' => $workflowPlan->strategyType,
            ]);

            // Return special marker to indicate workflow was orchestrated (not a final answer)
            // The calling code should check for this and NOT complete the execution
            return ''; // Empty string signals "workflow orchestrated, no immediate result"

        } catch (\Exception $e) {
            Log::error('AgentExecutor: Failed to orchestrate WorkflowPlan', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed
            $this->safeMarkAsFailed(
                $execution,
                "Failed to orchestrate workflow: {$e->getMessage()}",
                [
                    'context' => 'workflow_orchestration_failure',
                ],
                false // Don't refresh
            );

            throw $e;
        }
    }

    /**
     * Extract knowledge scope tags from execution context
     *
     * Implements priority-based tag extraction with fallback mechanisms to support
     * knowledge filtering in different execution contexts (chat, API, integrations).
     *
     * Priority Order:
     * 1. Execution metadata (highest priority) - Per-execution overrides
     * 2. ChatSession metadata - Session-level defaults
     * 3. Integration config - External trigger contexts (Slack, webhooks)
     *
     * Fallback Mechanism:
     * Returns first non-empty tag array found in priority order. If no source
     * contains tags, returns empty array (no filtering applied).
     *
     * @param  AgentExecution  $execution  The execution context to extract tags from
     * @return array<string> Array of tag names (strings) to filter knowledge searches.
     *                       Empty array means no tag filtering (search all documents).
     */
    protected function extractKnowledgeScopeTags(AgentExecution $execution): array
    {
        $tags = [];

        // Priority 1: Check execution metadata
        if (isset($execution->metadata['knowledge_scope_tags']) && is_array($execution->metadata['knowledge_scope_tags'])) {
            $tags = $execution->metadata['knowledge_scope_tags'];
            Log::debug('AgentExecutor: Knowledge scope tags from execution metadata', [
                'execution_id' => $execution->id,
                'tags' => $tags,
            ]);

            return $tags;
        }

        // Priority 2: Check ChatSession metadata
        if ($execution->chat_session_id) {
            $session = $execution->chatSession()->first();
            if ($session && isset($session->metadata['knowledge_scope_tags']) && is_array($session->metadata['knowledge_scope_tags'])) {
                $tags = $session->metadata['knowledge_scope_tags'];
                Log::debug('AgentExecutor: Knowledge scope tags from chat session', [
                    'execution_id' => $execution->id,
                    'session_id' => $session->id,
                    'tags' => $tags,
                ]);

                return $tags;
            }
        }

        // Priority 3: Check Integration config (for Slack/external triggers)
        if (isset($execution->metadata['integration_id'])) {
            $integration = \App\Models\Integration::find($execution->metadata['integration_id']);
            if ($integration && isset($integration->config['knowledge_scope_tags']) && is_array($integration->config['knowledge_scope_tags'])) {
                $tags = $integration->config['knowledge_scope_tags'];
                Log::debug('AgentExecutor: Knowledge scope tags from integration config', [
                    'execution_id' => $execution->id,
                    'integration_id' => $integration->id,
                    'tags' => $tags,
                ]);

                return $tags;
            }
        }

        // No tags found in any source
        return [];
    }

    /**
     * Get the source of knowledge scope tags for logging/debugging
     */
    protected function getKnowledgeScopeTagsSource(AgentExecution $execution): string
    {
        // Check in priority order
        if (isset($execution->metadata['knowledge_scope_tags']) && is_array($execution->metadata['knowledge_scope_tags'])) {
            return 'execution_metadata';
        }

        if ($execution->chat_session_id) {
            $session = $execution->chatSession()->first();
            if ($session && isset($session->metadata['knowledge_scope_tags']) && is_array($session->metadata['knowledge_scope_tags'])) {
                return 'chat_session';
            }
        }

        if (isset($execution->metadata['integration_id'])) {
            $integration = \App\Models\Integration::find($execution->metadata['integration_id']);
            if ($integration && isset($integration->config['knowledge_scope_tags']) && is_array($integration->config['knowledge_scope_tags'])) {
                return 'integration_config';
            }
        }

        return 'none';
    }

    /**
     * Sanitize error messages to prevent information disclosure
     *
     * SECURITY: Raw exception messages may contain sensitive information including:
     * - Stack traces with file paths revealing application structure
     * - Database credentials or connection strings
     * - API keys, tokens, or secrets
     * - Internal system architecture details
     *
     * @param  \Throwable  $e  The exception to sanitize
     * @param  AgentExecution  $execution  The execution for logging context
     * @return string Sanitized error message safe for user display
     */
    protected function sanitizeErrorMessage(\Throwable $e, AgentExecution $execution): string
    {
        // SECURITY: Log full exception details securely for debugging
        // These logs are not exposed to end users
        Log::error('AgentExecutor: Execution failed with full exception details', [
            'execution_id' => $execution->id,
            'agent_id' => $execution->agent_id,
            'user_id' => $execution->user_id,
            'exception_class' => get_class($e),
            'exception_message' => $e->getMessage(),
            'exception_code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $message = $e->getMessage();

        // Remove absolute file paths (Unix and Windows)
        $message = preg_replace('/\/(?:[^\/\s]+\/)+[^\/\s]+/', '[path]', $message);
        $message = preg_replace('/[A-Z]:\\\\(?:[^\\\\]+\\\\)+[^\\\\]+/', '[path]', $message);

        // Redact sensitive keywords and their values
        $sensitivePatterns = [
            '/(password|passwd|pwd)[\s=:]+([^\s&\'"]+)/i' => '$1=[REDACTED]',
            '/(token|api[_-]?key|secret|auth)[\s=:]+([^\s&\'"]+)/i' => '$1=[REDACTED]',
            '/(bearer\s+)([^\s&\'"]+)/i' => '$1[REDACTED]',
            '/([a-zA-Z0-9_\-]+@[a-zA-Z0-9_\-]+\.[a-zA-Z]{2,})/i' => '[email]',
        ];

        foreach ($sensitivePatterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message);
        }

        // Limit length to prevent excessive error storage
        $message = \Illuminate\Support\Str::limit($message, 500);

        return $message;
    }
}
