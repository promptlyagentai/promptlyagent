<?php

namespace App\Services\Agents;

use App\Jobs\ExecuteAgentJob;
use App\Jobs\SynthesizeWorkflowJob;
use App\Models\AgentExecution;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Workflow Orchestrator - Multi-agent workflow execution with Laravel job batching.
 *
 * Orchestrates complex multi-agent workflows using Laravel's native job batching
 * and chaining infrastructure. Supports four execution strategies with optional
 * synthesis for result aggregation.
 *
 * Execution Strategies:
 * - **Simple**: Single agent execution (direct dispatch or chained with synthesizer)
 * - **Sequential**: Chain of agents executed in order (A → B → C)
 * - **Parallel**: Multiple agents executed simultaneously (A, B, C in single batch)
 * - **Mixed**: Complex workflows with parallel stages and sequential steps
 *
 * Architecture:
 * - Uses Laravel Bus::batch() for parallel execution with progress tracking
 * - Uses Laravel Bus::chain() for sequential execution with dependency management
 * - Each agent execution gets a unique AgentExecution record for audit trail
 * - Synthesis job runs after all agents complete to aggregate results
 * - Real-time progress updates via job metadata and batch callbacks
 *
 * Workflow Lifecycle:
 * 1. Validate synthesizer agent if required
 * 2. Create AgentExecution records for each workflow node
 * 3. Dispatch jobs via appropriate strategy (chain/batch/mixed)
 * 4. Track progress via batch/chain IDs stored in parent execution
 * 5. Execute synthesis job to aggregate results (if configured)
 * 6. Broadcast completion event with synthesized response
 *
 * Synthesis:
 * - Optional final step that aggregates all agent outputs
 * - Uses dedicated synthesizer agent to combine results
 * - Configured per workflow plan via requiresSynthesis()
 * - Synthesis job receives all agent responses and original query
 *
 * @see \App\Services\Agents\WorkflowPlan
 * @see \App\Jobs\ExecuteAgentJob
 * @see \App\Jobs\SynthesizeWorkflowJob
 */
class WorkflowOrchestrator
{
    /**
     * Execute a workflow plan using the appropriate strategy.
     *
     * Routes workflow execution to the correct strategy handler based on plan configuration.
     * Returns a batch ID (for parallel/mixed) or chain ID (for sequential/simple) for
     * tracking workflow progress. The ID is stored in parent execution metadata.
     *
     * Return Value:
     * - Batch ID (string): For parallel and mixed strategies using Bus::batch()
     * - Chain ID (string): For sequential and simple strategies using Bus::chain()
     * - The ID enables progress tracking via Laravel's batch/chain infrastructure
     *
     * @param  WorkflowPlan  $plan  The workflow plan with strategy and agent nodes
     * @param  AgentExecution  $parentExecution  Parent execution tracking this workflow
     * @param  int|null  $interactionId  Chat interaction ID for real-time status updates
     * @return string|null Batch/chain ID for tracking (null should never occur but type allows it)
     *
     * @throws \InvalidArgumentException If strategy type is invalid or synthesizer validation fails
     * @throws \Exception If batch/chain creation fails
     */
    public function execute(WorkflowPlan $plan, AgentExecution $parentExecution, ?int $interactionId = null): ?string
    {
        // Validate synthesizer agent type at runtime (final safety check)
        if ($plan->requiresSynthesis()) {
            $this->validateSynthesizerAgent($plan->synthesizerAgentId);
        }

        Log::info('WorkflowOrchestrator: Starting workflow execution', [
            'parent_execution_id' => $parentExecution->id,
            'interaction_id' => $interactionId,
            'strategy_type' => $plan->strategyType,
            'total_stages' => count($plan->stages),
            'total_jobs' => $plan->getTotalJobs(),
            'requires_synthesis' => $plan->requiresSynthesis(),
            'initial_actions_count' => count($plan->initialActions),
            'final_actions_count' => count($plan->finalActions),
        ]);

        // Execute initial workflow actions (run once before any agents start)
        if (! empty($plan->initialActions)) {
            $this->executeInitialActions($plan, $parentExecution);
        }

        // Route to appropriate strategy handler
        return match ($plan->strategyType) {
            'simple' => $this->executeSimple($plan, $parentExecution, $interactionId),
            'sequential' => $this->executeSequential($plan, $parentExecution, $interactionId),
            'parallel' => $this->executeParallel($plan, $parentExecution, $interactionId),
            'mixed' => $this->executeMixed($plan, $parentExecution, $interactionId),
            default => throw new \InvalidArgumentException("Unknown strategy type: {$plan->strategyType}")
        };
    }

    /**
     * Execute simple workflow with a single agent
     *
     * Handles the simplest workflow pattern: one agent executes with optional synthesis.
     * Uses job chaining to ensure synthesis runs after agent completion if required.
     *
     * Flow:
     * 1. Create single AgentExecution
     * 2. Dispatch ExecuteAgentJob
     * 3. Optionally chain SynthesizeWorkflowJob if plan requires synthesis
     *
     * Use cases:
     * - Single-agent queries with QA validation
     * - Workflows needing synthesis/formatting of single agent output
     * - Simple workflows with final actions (notifications, webhooks)
     *
     * @param  WorkflowPlan  $plan  Must have exactly one node in first stage
     * @param  AgentExecution  $parentExecution  Parent workflow execution
     * @param  int|null  $interactionId  ChatInteraction ID for result linking
     * @return string|null Returns null (job handles completion asynchronously)
     */
    protected function executeSimple(WorkflowPlan $plan, AgentExecution $parentExecution, ?int $interactionId): ?string
    {
        if (empty($plan->stages) || empty($plan->stages[0]->nodes)) {
            throw new \InvalidArgumentException('Simple workflow must have at least one node');
        }

        $node = $plan->stages[0]->nodes[0];

        // Create agent execution with job index 0
        $execution = $this->createExecution($node, $parentExecution, 0);

        // Generate chain ID for tracking even in simple workflows
        $chainId = 'simple_'.uniqid();

        // Add workflow metadata for completion tracking
        $execution->update([
            'metadata' => array_merge($execution->metadata ?? [], [
                'workflow_type' => 'simple',
                'batch_id' => $chainId,
                'job_index' => 0,
                'stage_index' => 0,
                'total_stages' => 1,
            ]),
        ]);

        $jobs = [new ExecuteAgentJob($execution, $interactionId, 0)];

        /**
         * Add synthesis job if workflow requires it.
         *
         * This ensures proper completion broadcasting for simple workflows:
         * - Without synthesis: Single agent completes directly, broadcasts immediately
         * - With synthesis: Agent result → SynthesizeWorkflowJob → final broadcast
         *
         * The synthesis job:
         * 1. Waits for the agent execution to complete
         * 2. Optionally runs a QA validation step
         * 3. Executes any final actions (notifications, webhooks, etc.)
         * 4. Broadcasts workflow completion to the UI
         *
         * Chaining ensures synthesis only runs after successful agent execution.
         * If the agent fails, the chain stops and synthesis never runs.
         *
         * @see \App\Jobs\SynthesizeWorkflowJob
         */
        if ($plan->requiresSynthesis()) {
            $jobs[] = new SynthesizeWorkflowJob(
                batchId: $chainId,
                totalJobs: 1,
                parentExecutionId: $parentExecution->id,
                originalQuery: $plan->originalQuery,
                userId: $parentExecution->user_id,
                chatSessionId: $parentExecution->chat_session_id,
                interactionId: $interactionId,
                synthesizerAgentId: $plan->synthesizerAgentId,
                requiresQA: $plan->requiresQA,
                finalActions: $plan->finalActions
            );

            // Use chain to ensure synthesis runs after execution
            Bus::chain($jobs)->dispatch();

            Log::info('WorkflowOrchestrator: Simple workflow dispatched with synthesis', [
                'parent_execution_id' => $parentExecution->id,
                'execution_id' => $execution->id,
                'agent_id' => $node->agentId,
                'interaction_id' => $interactionId,
                'chain_id' => $chainId,
                'requires_synthesis' => true,
            ]);

            return $chainId;
        } else {
            // No synthesis - dispatch job directly and handle completion in job
            ExecuteAgentJob::dispatch($execution, $interactionId);

            Log::info('WorkflowOrchestrator: Simple workflow dispatched without synthesis', [
                'parent_execution_id' => $parentExecution->id,
                'execution_id' => $execution->id,
                'agent_id' => $node->agentId,
                'interaction_id' => $interactionId,
                'chain_id' => $chainId,
                'requires_synthesis' => false,
            ]);

            return $chainId;
        }
    }

    /**
     * Execute sequential workflow where agents run one after another
     *
     * Uses job chaining to ensure strict execution order: Agent A completes before
     * Agent B starts, Agent B completes before Agent C starts, etc.
     *
     * Flow:
     * 1. Create all AgentExecutions upfront (for UI visibility)
     * 2. Chain ExecuteAgentJob instances in order
     * 3. Optionally chain SynthesizeWorkflowJob at the end
     *
     * Each agent receives the output of the previous agent as input, enabling
     * sequential refinement patterns (draft → review → polish).
     *
     * Use cases:
     * - Research → Analysis → Summary pipelines
     * - Draft → Review → Edit workflows
     * - Data collection → Processing → Formatting chains
     * - Any workflow requiring strict execution order
     *
     * @param  WorkflowPlan  $plan  Plan with one or more stages, each with one node
     * @param  AgentExecution  $parentExecution  Parent workflow execution
     * @param  int|null  $interactionId  ChatInteraction ID for result linking
     * @return string Chain ID for tracking job completion
     */
    protected function executeSequential(WorkflowPlan $plan, AgentExecution $parentExecution, ?int $interactionId): string
    {
        $jobs = [];
        $jobIndex = 0;
        $chainId = 'chain_'.uniqid();

        // Create all executions and jobs with sequential metadata
        foreach ($plan->stages as $stageIndex => $stage) {
            foreach ($stage->nodes as $node) {
                $execution = $this->createExecution($node, $parentExecution, $jobIndex);

                // Add sequential workflow metadata
                $execution->update([
                    'metadata' => array_merge($execution->metadata ?? [], [
                        'workflow_type' => 'sequential',
                        'batch_id' => $chainId,
                        'job_index' => $jobIndex,
                        'stage_index' => $stageIndex,
                        'total_stages' => count($plan->stages),
                    ]),
                ]);

                $jobs[] = new ExecuteAgentJob($execution, $interactionId, $jobIndex++);
            }
        }

        // Add synthesis job at the end if required
        if ($plan->requiresSynthesis()) {
            $jobs[] = new SynthesizeWorkflowJob(
                batchId: $chainId,
                totalJobs: $jobIndex,
                parentExecutionId: $parentExecution->id,
                originalQuery: $plan->originalQuery,
                userId: $parentExecution->user_id,
                chatSessionId: $parentExecution->chat_session_id,
                interactionId: $interactionId,
                synthesizerAgentId: $plan->synthesizerAgentId,
                requiresQA: $plan->requiresQA,
                finalActions: $plan->finalActions
            );
        }

        // Build and dispatch the chain
        Bus::chain($jobs)->dispatch();

        Log::info('WorkflowOrchestrator: Sequential workflow dispatched', [
            'parent_execution_id' => $parentExecution->id,
            'chain_id' => $chainId,
            'total_jobs' => count($jobs),
            'total_stages' => count($plan->stages),
            'requires_synthesis' => $plan->requiresSynthesis(),
        ]);

        return $chainId;
    }

    /**
     * Execute parallel workflow where agents run simultaneously
     *
     * Uses Laravel job batching to execute multiple agents concurrently.
     * All agents receive the same input and run independently. Results are
     * collected and synthesized after all agents complete.
     *
     * Flow:
     * 1. Create all AgentExecutions
     * 2. Dispatch all ExecuteAgentJob instances in a batch
     * 3. Batch completion triggers SynthesizeWorkflowJob
     *
     * Parallel execution significantly reduces total workflow time when agents
     * perform independent operations (e.g., searching different databases).
     *
     * Use cases:
     * - Multi-source research (search web, docs, knowledge base simultaneously)
     * - Comparative analysis (multiple agents analyze same data differently)
     * - Redundancy/consensus (multiple agents for cross-validation)
     * - Time-sensitive workflows requiring maximum speed
     *
     * @param  WorkflowPlan  $plan  Plan with one stage containing multiple nodes
     * @param  AgentExecution  $parentExecution  Parent workflow execution
     * @param  int|null  $interactionId  ChatInteraction ID for result linking
     * @return string Batch ID for tracking completion
     */
    protected function executeParallel(WorkflowPlan $plan, AgentExecution $parentExecution, ?int $interactionId): string
    {
        if (empty($plan->stages) || empty($plan->stages[0]->nodes)) {
            throw new \InvalidArgumentException('Parallel workflow must have at least one node');
        }

        $jobs = [];
        $jobIndex = 0;

        // Create all executions and jobs for parallel stage
        foreach ($plan->stages[0]->nodes as $node) {
            $execution = $this->createExecution($node, $parentExecution, $jobIndex);
            $jobs[] = new ExecuteAgentJob($execution, $interactionId, $jobIndex++);
        }

        // Create batch with synthesis callback
        // Truncate batch name to fit database column limit (255 chars)
        $batchName = mb_strlen($plan->originalQuery) > 240
            ? 'Workflow: '.mb_substr($plan->originalQuery, 0, 240).'...'
            : "Workflow: {$plan->originalQuery}";

        $batch = Bus::batch($jobs)
            ->name($batchName)
            ->allowFailures() // Continue even if some agents fail
            ->onQueue('research-coordinator');

        // Add synthesis callback if required
        if ($plan->requiresSynthesis()) {
            $batch->then(function ($batch) use ($plan, $parentExecution, $jobIndex, $interactionId) {
                SynthesizeWorkflowJob::dispatch(
                    batchId: $batch->id,
                    totalJobs: $jobIndex,
                    parentExecutionId: $parentExecution->id,
                    originalQuery: $plan->originalQuery,
                    userId: $parentExecution->user_id,
                    chatSessionId: $parentExecution->chat_session_id,
                    interactionId: $interactionId,
                    synthesizerAgentId: $plan->synthesizerAgentId,
                    requiresQA: $plan->requiresQA,
                    finalActions: $plan->finalActions
                );
            });
        }

        $batch = $batch->dispatch();

        Log::info('WorkflowOrchestrator: Parallel workflow dispatched', [
            'parent_execution_id' => $parentExecution->id,
            'batch_id' => $batch->id,
            'total_jobs' => count($jobs),
            'requires_synthesis' => $plan->requiresSynthesis(),
        ]);

        return $batch->id;
    }

    /**
     * Execute mixed workflow with both parallel and sequential stages
     *
     * Combines parallel and sequential execution patterns: agents within a stage
     * run in parallel (if multiple), but stages execute sequentially (one after another).
     *
     * Example: Stage 1 (A, B parallel) → Stage 2 (C, D parallel) → Stage 3 (E solo)
     *
     * Flow:
     * 1. Create all AgentExecutions across all stages
     * 2. For each stage:
     *    - Single node: Add ExecuteAgentJob directly to chain
     *    - Multiple nodes: Create batch with parallel jobs
     * 3. Chain stages using Bus::chain()
     * 4. Append SynthesizeWorkflowJob at the end if needed
     *
     * This pattern provides maximum flexibility: parallel execution for speed within
     * stages, sequential execution for data dependencies between stages.
     *
     * Use cases:
     * - Research workflow: Parallel search stage → Sequential analysis stage
     * - Content pipeline: Parallel gathering → Sequential quality checks
     * - Validation workflow: Parallel tests → Sequential approval chain
     * - Complex multi-phase operations with both speed and order requirements
     *
     * @param  WorkflowPlan  $plan  Plan with multiple stages, each with one or more nodes
     * @param  AgentExecution  $parentExecution  Parent workflow execution
     * @param  int|null  $interactionId  ChatInteraction ID for result linking
     * @return string Chain ID for tracking completion
     */
    protected function executeMixed(WorkflowPlan $plan, AgentExecution $parentExecution, ?int $interactionId): string
    {
        $chainId = 'mixed_'.uniqid();
        $globalJobIndex = 0;
        $stageChain = [];

        // Build chain of stages (each stage is either a batch or sequence of jobs)
        foreach ($plan->stages as $stageIndex => $stage) {
            if ($stage->isParallel()) {
                // Parallel stage - create a batch that all jobs run simultaneously
                $stageJobs = [];
                foreach ($stage->nodes as $node) {
                    $execution = $this->createExecution($node, $parentExecution, $globalJobIndex);

                    // Add mixed workflow metadata
                    $execution->update([
                        'metadata' => array_merge($execution->metadata ?? [], [
                            'workflow_type' => 'mixed',
                            'batch_id' => $chainId,
                            'stage_index' => $stageIndex,
                            'stage_type' => 'parallel',
                            'job_index' => $globalJobIndex,
                            'total_stages' => count($plan->stages),
                        ]),
                    ]);

                    $stageJobs[] = new ExecuteAgentJob($execution, $interactionId, $globalJobIndex++);
                }

                // Add batch to chain - Laravel waits for entire batch to complete
                $stageBatch = Bus::batch($stageJobs)
                    ->name("Stage {$stageIndex}: Parallel")
                    ->allowFailures()
                    ->onQueue('research-coordinator');

                $stageChain[] = $stageBatch;

                Log::debug('WorkflowOrchestrator: Added parallel stage to chain', [
                    'stage_index' => $stageIndex,
                    'jobs_count' => count($stageJobs),
                ]);

            } else {
                // Sequential stage - add individual jobs to chain
                foreach ($stage->nodes as $node) {
                    $execution = $this->createExecution($node, $parentExecution, $globalJobIndex);

                    // Add mixed workflow metadata
                    $execution->update([
                        'metadata' => array_merge($execution->metadata ?? [], [
                            'workflow_type' => 'mixed',
                            'batch_id' => $chainId,
                            'stage_index' => $stageIndex,
                            'stage_type' => 'sequential',
                            'job_index' => $globalJobIndex,
                            'total_stages' => count($plan->stages),
                        ]),
                    ]);

                    $stageChain[] = new ExecuteAgentJob($execution, $interactionId, $globalJobIndex++);
                }

                Log::debug('WorkflowOrchestrator: Added sequential stage to chain', [
                    'stage_index' => $stageIndex,
                    'jobs_count' => count($stage->nodes),
                ]);
            }
        }

        // Add synthesis job at the end if required
        if ($plan->requiresSynthesis()) {
            $stageChain[] = new SynthesizeWorkflowJob(
                batchId: $chainId,
                totalJobs: $globalJobIndex,
                parentExecutionId: $parentExecution->id,
                originalQuery: $plan->originalQuery,
                userId: $parentExecution->user_id,
                chatSessionId: $parentExecution->chat_session_id,
                interactionId: $interactionId,
                synthesizerAgentId: $plan->synthesizerAgentId,
                requiresQA: $plan->requiresQA,
                finalActions: $plan->finalActions
            );

            Log::debug('WorkflowOrchestrator: Added synthesis job to chain', [
                'total_jobs' => $globalJobIndex,
                'synthesizer_agent_id' => $plan->synthesizerAgentId,
            ]);
        }

        // Execute the chain - stages run sequentially, parallel stages run jobs simultaneously
        Bus::chain($stageChain)->dispatch();

        Log::info('WorkflowOrchestrator: Mixed workflow dispatched', [
            'parent_execution_id' => $parentExecution->id,
            'chain_id' => $chainId,
            'total_stages' => count($plan->stages),
            'total_jobs' => $globalJobIndex,
            'requires_synthesis' => $plan->requiresSynthesis(),
            'chain_structure' => array_map(function ($item) {
                if ($item instanceof \Illuminate\Bus\PendingBatch) {
                    return 'batch';
                } elseif ($item instanceof ExecuteAgentJob) {
                    return 'job';
                } elseif ($item instanceof SynthesizeWorkflowJob) {
                    return 'synthesis';
                }

                return 'unknown';
            }, $stageChain),
        ]);

        return $chainId;
    }

    /**
     * Create agent execution for a workflow node
     */
    protected function createExecution(WorkflowNode $node, AgentExecution $parentExecution, int $jobIndex): AgentExecution
    {
        // Serialize action configs for execution
        $metadata = [
            'workflow_node' => true,
            'agent_name' => $node->agentName,
            'rationale' => $node->rationale,
        ];

        // Add action configs if present
        if (! empty($node->inputActions)) {
            $metadata['input_actions'] = array_map(
                fn ($action) => [
                    'method' => $action->method,
                    'params' => $action->params,
                    'priority' => $action->priority,
                ],
                $node->inputActions
            );
        }

        if (! empty($node->outputActions)) {
            $metadata['output_actions'] = array_map(
                fn ($action) => [
                    'method' => $action->method,
                    'params' => $action->params,
                    'priority' => $action->priority,
                ],
                $node->outputActions
            );
        }

        return AgentExecution::create([
            'agent_id' => $node->agentId,
            'user_id' => $parentExecution->user_id,
            'chat_session_id' => $parentExecution->chat_session_id,
            'input' => $node->input,
            'max_steps' => 25, // Increased from 5 to allow sufficient tool calls + synthesis
            'state' => 'pending',
            'parent_agent_execution_id' => $parentExecution->id,
            'active_execution_key' => 'workflow_'.$parentExecution->id.'_job_'.$jobIndex, // Unique per workflow instance
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get workflow status (for monitoring and UI updates)
     */
    public function getWorkflowStatus(string $batchId): array
    {
        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return [
                'status' => 'not_found',
                'progress' => 0,
            ];
        }

        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'status' => $this->determineBatchStatus($batch),
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'processed_jobs' => $batch->processedJobs,
            'failed_jobs' => $batch->failedJobs,
            'progress' => $batch->totalJobs > 0 ? round(($batch->processedJobs / $batch->totalJobs) * 100) : 0,
            'finished_at' => $batch->finishedAt?->toISOString(),
        ];
    }

    /**
     * Determine batch status
     */
    protected function determineBatchStatus($batch): string
    {
        if ($batch->finished()) {
            return $batch->failedJobs > 0 ? 'completed_with_failures' : 'completed';
        }

        if ($batch->cancelled()) {
            return 'cancelled';
        }

        return 'running';
    }

    /**
     * Validate synthesizer agent type at runtime
     *
     * @throws \RuntimeException if agent doesn't exist or has wrong type
     */
    protected function validateSynthesizerAgent(?int $agentId): void
    {
        if ($agentId === null) {
            return;
        }

        $agent = \App\Models\Agent::find($agentId);

        if (! $agent) {
            throw new \RuntimeException(
                "WorkflowOrchestrator: Synthesizer agent with ID {$agentId} not found. ".
                'Workflow execution aborted. Please select a valid synthesizer agent.'
            );
        }

        if ($agent->agent_type !== 'synthesizer') {
            throw new \RuntimeException(
                "WorkflowOrchestrator: Agent '{$agent->name}' (ID: {$agentId}) has type '{$agent->agent_type}' but synthesis requires agent_type='synthesizer'. ".
                'Only agents specifically designed for synthesis can be used. '.
                'Workflow execution aborted.'
            );
        }

        Log::info('WorkflowOrchestrator: Synthesizer agent validated', [
            'synthesizer_agent_id' => $agentId,
            'agent_name' => $agent->name,
            'agent_type' => $agent->agent_type,
        ]);
    }

    /**
     * Execute initial workflow actions
     *
     * Runs workflow-level actions once at the start, before any agents execute.
     * Use cases: logging, external API calls, resource setup, notifications
     *
     * @param  WorkflowPlan  $plan  The workflow plan with initial actions
     * @param  AgentExecution  $parentExecution  Parent execution for context
     */
    protected function executeInitialActions(WorkflowPlan $plan, AgentExecution $parentExecution): void
    {
        $actions = collect($plan->initialActions)
            ->sortBy('priority')
            ->values()
            ->toArray();

        Log::info('WorkflowOrchestrator: Executing initial workflow actions', [
            'parent_execution_id' => $parentExecution->id,
            'actions_count' => count($actions),
        ]);

        $context = [
            'execution' => $parentExecution,
            'workflow_type' => 'initial',
            'original_query' => $plan->originalQuery,
        ];

        foreach ($actions as $actionConfig) {
            try {
                $data = $plan->originalQuery; // Initial actions receive the original query

                $result = \App\Services\Agents\Actions\ActionRegistry::execute(
                    $actionConfig->method,
                    $data,
                    $context,
                    $actionConfig->params
                );

                Log::debug('WorkflowOrchestrator: Initial action executed', [
                    'action' => $actionConfig->method,
                    'parent_execution_id' => $parentExecution->id,
                ]);
            } catch (\Exception $e) {
                Log::error('WorkflowOrchestrator: Initial action failed', [
                    'action' => $actionConfig->method,
                    'parent_execution_id' => $parentExecution->id,
                    'error' => $e->getMessage(),
                ]);

                // Continue with remaining actions even if one fails
            }
        }
    }
}
