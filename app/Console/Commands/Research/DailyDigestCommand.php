<?php

namespace App\Console\Commands\Research;

use App\Console\Commands\Concerns\RegistersAsInputTrigger;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Agents\ActionConfig;
use App\Services\Agents\WorkflowNode;
use App\Services\Agents\WorkflowOrchestrator;
use App\Services\Agents\WorkflowPlan;
use App\Services\Agents\WorkflowStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily Digest Command
 *
 * Demonstrates programmatic workflow creation with input/output actions.
 * Creates a multi-agent workflow to research news topics, consolidate findings,
 * validate quality, and format for Slack delivery.
 *
 * Workflow Structure:
 * 1. Stage 1 (Parallel): Research agents gather news on each topic
 *    - Output: formatAsJson action structures results
 * 2. Stage 2 (Sequential): Synthesizer consolidates findings
 *    - Input: consolidateResearch action deduplicates data
 * 3. Stage 3 (Sequential): QA validator ensures quality
 * 4. Final Action: slackMarkdown formats complete output for Slack compatibility
 *
 * Delivery: Use output actions on input triggers for webhook/Slack delivery.
 */
class DailyDigestCommand extends Command
{
    use RegistersAsInputTrigger;

    /**
     * Workflow configuration constants
     */
    private const MAX_WORKFLOW_STEPS = 50;

    private const DEFAULT_ACTION_PRIORITY = 10;

    private const ESTIMATED_WORKFLOW_DURATION = 180;

    protected $signature = 'research:daily-digest
                            {topics* : Topics to research (1-4 topics)}
                            {--session-strategy=new : Session strategy (new, continue, reuse_command_line)}
                            {--user-id=1 : User ID for execution context}
                            {--language=English : Response language (English, German, Spanish, etc.)}';

    protected $description = 'Create a daily news digest workflow with multi-agent research and Slack-compatible formatting';

    /**
     * Execute the console command
     */
    public function handle(WorkflowOrchestrator $orchestrator): int
    {
        $topics = $this->argument('topics');
        $sessionStrategy = $this->option('session-strategy');
        $userId = $this->option('user-id');
        $language = $this->option('language');

        // Validate inputs
        if (empty($topics)) {
            $this->error('At least one topic is required');

            return self::FAILURE;
        }

        if (count($topics) > 4) {
            $this->error('Maximum of 4 topics allowed (got '.count($topics).')');

            return self::FAILURE;
        }

        // Validate session strategy
        $validStrategies = ['new', 'continue', 'reuse_command_line'];
        if (! in_array($sessionStrategy, $validStrategies)) {
            $this->error("Invalid session strategy '{$sessionStrategy}'. Valid options: ".implode(', ', $validStrategies));

            return self::FAILURE;
        }

        // Get user
        $user = User::find($userId);
        if (! $user) {
            $this->error("User with ID {$userId} not found");

            return self::FAILURE;
        }

        // Get required agents
        try {
            $agents = $this->getRequiredAgents();
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Display workflow configuration
        $this->info('Creating daily digest workflow for '.count($topics).' topic(s):');
        foreach ($topics as $topic) {
            $this->line("  - {$topic}");
        }

        $this->newLine();
        $this->line('Workflow configuration:');
        $this->line('  ├─ Stage 1: Parallel Research ('.count($topics).' agents)');
        $this->line('  ├─ Stage 2: Synthesis with consolidation');
        $this->line('  ├─ Stage 3: QA validation');
        $this->line('  └─ Final Action: Slack markdown formatting');
        $this->newLine();

        // Create or get chat session based on strategy
        $session = $this->getSessionByStrategy($user, $sessionStrategy);
        $originalQuery = 'Create a daily digest for the following topics: '.implode(', ', $topics).". Please provide your response in {$language}.";

        $this->line("  Session Strategy: {$sessionStrategy}");
        $this->line("  Session ID: {$session->id}");
        if ($session->title) {
            $this->line("  Session Title: {$session->title}");
        }

        // Create chat interaction for full observability and status tracking
        $interaction = ChatInteraction::create([
            'chat_session_id' => $session->id,
            'user_id' => $user->id,
            'question' => $originalQuery,
            'answer' => '', // Will be populated by workflow
            'metadata' => [
                'source' => 'command_line',
                'command' => 'research:daily-digest',
                'topics' => $topics,
                'topic_count' => count($topics),
            ],
        ]);

        $this->line("  Interaction ID: {$interaction->id}");
        $this->newLine();

        // Create workflow plan
        try {
            $workflowPlan = $this->createWorkflowPlan($topics, $agents, $language);
        } catch (\Exception $e) {
            $this->error('Failed to create workflow plan: '.$e->getMessage());
            Log::error('DailyDigestCommand: Failed to create workflow plan', [
                'error' => $e->getMessage(),
                'topics' => $topics,
                'interaction_id' => $interaction->id,
            ]);

            $interaction->update(['answer' => '❌ Failed to create workflow plan: '.$e->getMessage()]);

            return self::FAILURE;
        }

        // Create parent execution record
        $parentExecution = AgentExecution::create([
            'agent_id' => $agents['synthesizer']->id, // Use synthesizer as representative agent
            'user_id' => $user->id,
            'chat_session_id' => $session->id,
            'input' => $originalQuery,
            'max_steps' => self::MAX_WORKFLOW_STEPS,
            'status' => 'pending',
            'metadata' => [
                'workflow_type' => 'daily_digest',
                'topics' => $topics,
                'topic_count' => count($topics),
                'interaction_id' => $interaction->id,
            ],
        ]);

        // Link interaction to parent execution
        $interaction->update(['agent_execution_id' => $parentExecution->id]);

        // Execute workflow with interaction ID for status reporting
        try {
            $batchId = $orchestrator->execute($workflowPlan, $parentExecution, $interaction->id);

            $this->info('Workflow dispatched!');
            $this->line("  Batch ID: {$batchId}");
            $this->line("  Parent Execution ID: {$parentExecution->id}");
            $this->line('  Estimated duration: 3-5 minutes');
            $this->newLine();
            $this->line("Monitor in chat: /sessions/{$session->id}");
            $this->line("View execution: /agent-executions/{$parentExecution->id}");
            $this->line('Horizon dashboard: '.url('/horizon'));
            $this->newLine();
            $this->line('Output formatted for Slack compatibility.');
            $this->line('Use output actions on input triggers for delivery.');

            Log::info('DailyDigestCommand: Workflow dispatched successfully', [
                'batch_id' => $batchId,
                'parent_execution_id' => $parentExecution->id,
                'interaction_id' => $interaction->id,
                'session_id' => $session->id,
                'topics' => $topics,
            ]);

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to dispatch workflow: '.$e->getMessage());
            Log::error('DailyDigestCommand: Failed to dispatch workflow', [
                'error' => $e->getMessage(),
                'parent_execution_id' => $parentExecution->id,
                'interaction_id' => $interaction->id,
                'topics' => $topics,
            ]);

            $interaction->update(['answer' => '❌ Failed to dispatch workflow: '.$e->getMessage()]);

            return self::FAILURE;
        }
    }

    /**
     * Get or create a chat session based on the specified strategy
     *
     * @param  User  $user  The user to create/get session for
     * @param  string  $strategy  Session strategy (new, continue, reuse_command_line)
     */
    protected function getSessionByStrategy(User $user, string $strategy): ChatSession
    {
        // Find existing session based on strategy
        $session = match ($strategy) {
            'new' => null, // Always create new
            'continue' => ChatSession::where('user_id', $user->id)
                ->orderBy('updated_at', 'desc')
                ->first(),
            'reuse_command_line' => ChatSession::where('user_id', $user->id)
                ->where('title', 'Command Line Workflows')
                ->first(),
            default => throw new \InvalidArgumentException("Invalid session strategy: {$strategy}"),
        };

        // Create new session if none exists
        if (! $session) {
            $title = match ($strategy) {
                'new', 'continue' => 'Daily News Digest - '.now()->format('M j, Y g:i A'),
                'reuse_command_line' => 'Command Line Workflows',
            };

            $session = ChatSession::create([
                'user_id' => $user->id,
                'title' => $title,
                'metadata' => [
                    'source' => 'command_line',
                    'command' => 'research:daily-digest',
                    'session_strategy' => $strategy,
                ],
            ]);

            Log::info('DailyDigestCommand: Created session', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'strategy' => $strategy,
                'title' => $title,
            ]);
        } else {
            Log::info('DailyDigestCommand: Using existing session', [
                'session_id' => $session->id,
                'user_id' => $user->id,
                'strategy' => $strategy,
                'title' => $session->title,
                'last_updated' => $session->updated_at->toISOString(),
            ]);
        }

        return $session;
    }

    /**
     * Get required agents for the workflow
     *
     * @throws \Exception if any required agent is not found
     */
    protected function getRequiredAgents(): array
    {
        $requiredNames = [
            'research' => 'Research Assistant',
            'synthesizer' => 'Research Synthesizer',
            'qa' => 'Research QA Validator',
        ];

        // Single query to fetch all required agents
        $agents = Agent::whereIn('name', array_values($requiredNames))
            ->get()
            ->keyBy('name');

        // Validate all required agents exist
        $result = [];
        foreach ($requiredNames as $key => $name) {
            if (! $agents->has($name)) {
                throw new \Exception("{$name} agent not found. Please run database seeders.");
            }
            $result[$key] = $agents[$name];
        }

        return $result;
    }

    /**
     * Create workflow plan programmatically
     */
    protected function createWorkflowPlan(array $topics, array $agents, string $language): WorkflowPlan
    {
        $stages = [];
        $today = now()->format('F j, Y'); // e.g., "January 3, 2026"

        // Stage 1: Parallel Research
        $researchNodes = [];
        foreach ($topics as $topic) {
            $researchNodes[] = new WorkflowNode(
                agentId: $agents['research']->id,
                agentName: $agents['research']->name,
                input: "**CRITICAL: Respond in {$language}. Focus on CURRENT and RECENT news only (within the last 7-14 days)**\n\n".
                    "Today's date is {$today}. Research the LATEST breaking news, developments, and updates on \"{$topic}\".\n\n".
                    "**Requirements:**\n".
                    "- ONLY report news from the past 2 weeks (prioritize last 7 days)\n".
                    "- Focus on breaking developments, announcements, and recent events\n".
                    "- Include publication dates for all news items when available\n".
                    "- Exclude outdated or historical information\n".
                    "- Search for today's and this week's updates\n\n".
                    "**Provide:**\n".
                    "- Key recent topics and developments (bullet points with dates)\n".
                    "- Summaries of the latest news (with publication dates)\n".
                    "- Why this is currently relevant and timely\n".
                    "- Source URLs with recent publication dates\n\n".
                    'Be comprehensive about RECENT developments and cite all sources with dates.',
                rationale: "Research specialist for current news on topic: {$topic}",
                outputActions: [
                    new ActionConfig(
                        method: 'formatAsJson',
                        params: [],
                        priority: self::DEFAULT_ACTION_PRIORITY
                    ),
                ]
            );
        }

        $stages[] = new WorkflowStage(
            type: 'parallel',
            nodes: $researchNodes
        );

        // Stage 2: Synthesis with Consolidation
        $synthesisNode = new WorkflowNode(
            agentId: $agents['synthesizer']->id,
            agentName: $agents['synthesizer']->name,
            input: "**CRITICAL: Respond in {$language}.**\n\n".
                "Create a comprehensive daily news digest for {$today} from the consolidated research findings. ".
                'Focus on the TIMELINESS and CURRENCY of the news. '.
                'Organize by topic, provide clear summaries emphasizing what is NEW and RECENT, and ensure all sources are properly cited with dates. '.
                'Highlight breaking developments and recent trends. '.
                'The digest should feel current and timely, not like historical reporting.',
            rationale: 'Consolidates and synthesizes recent research into timely digest',
            inputActions: [
                new ActionConfig(
                    method: 'consolidateResearch',
                    params: [
                        'operation' => 'deduplicate_and_merge',
                        'similarity_threshold' => 80,
                    ],
                    priority: self::DEFAULT_ACTION_PRIORITY
                ),
            ]
        );

        $stages[] = new WorkflowStage(
            type: 'sequential',
            nodes: [$synthesisNode]
        );

        // Stage 3: QA Validation
        $qaNode = new WorkflowNode(
            agentId: $agents['qa']->id,
            agentName: $agents['qa']->name,
            input: "**CRITICAL: Respond in {$language}.**\n\n".
                'Validate the daily news digest for:\n'.
                '- **RECENCY**: All news should be from the last 7-14 days (critical requirement)\n'.
                '- **ACCURACY**: Information is factually correct\n'.
                '- **RELEVANCE**: Content is on-topic and currently significant\n'.
                '- **SOURCES**: Proper attribution with dates/timestamps when available\n'.
                '- **CURRENCY**: Digest reflects latest developments, not outdated information\n'.
                '- **NO HALLUCINATIONS**: All claims are supported by cited sources\n'.
                '- **LINK VALIDITY**: Source links are accessible\n\n'.
                'If the digest passes validation, approve it. '.
                'If issues are found (especially outdated content), document them clearly.',
            rationale: 'Quality assurance validation with emphasis on recency'
        );

        $stages[] = new WorkflowStage(
            type: 'sequential',
            nodes: [$qaNode]
        );

        // Build workflow plan
        // Note: Language will be passed as command parameter at runtime
        $originalQuery = 'Create a daily digest for the following topics: '.implode(', ', $topics).'. Please provide your response in English.';

        // Build final actions - run once after entire workflow completes
        $finalActions = [
            new ActionConfig(
                method: 'slackMarkdown',
                params: [
                    'stripHtml' => true,
                    'convertLists' => true,
                    'removeTables' => true,
                ],
                priority: self::DEFAULT_ACTION_PRIORITY
            ),
        ];

        // CRITICAL: Even though we have synthesizer/QA agents inside the workflow,
        // we still need a final synthesis job to handle completion broadcasting,
        // interaction updates, and parent execution state management.
        // The internal synthesizer consolidates research; the final synthesis job
        // aggregates all outputs and handles workflow completion properly.
        // Final actions run on the complete workflow output.
        return new WorkflowPlan(
            originalQuery: $originalQuery,
            strategyType: 'mixed',
            stages: $stages,
            synthesizerAgentId: $agents['synthesizer']->id,
            requiresQA: false, // QA already in workflow, no additional QA needed
            estimatedDurationSeconds: self::ESTIMATED_WORKFLOW_DURATION,
            finalActions: $finalActions // Slack formatting runs on final workflow output
        );
    }

    /**
     * Get trigger definition for webhook/scheduled execution
     *
     * Defines parameters for external triggering via webhooks or scheduled tasks.
     * Used by TriggerableCommandRegistry for form generation and validation.
     *
     * Note: Output delivery (Slack, webhooks, etc.) should be configured using
     * output actions on the input trigger, not as command parameters.
     *
     * Note: user-id is automatically set to the trigger owner's ID and should not
     * be provided in webhook payloads.
     */
    public function getTriggerDefinition(): array
    {
        return [
            'name' => 'Daily News Digest',
            'description' => 'Create a daily news digest workflow with parallel research, synthesis, QA validation, and Slack-compatible formatting. Use output actions on input triggers for delivery.',
            'parameters' => [
                'topics' => [
                    'type' => 'array',
                    'required' => true,
                    'description' => 'Topics to research (1-4 topics)',
                    'min' => 1,
                    'max' => 4,
                    'placeholder' => 'AI regulation, Climate tech, Space exploration',
                ],
                'session-strategy' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Session strategy',
                    'default' => 'new',
                    'options' => ['new', 'continue', 'reuse_command_line'],
                ],
                'language' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Response language (English, German, Spanish, etc.)',
                    'default' => 'English',
                    'placeholder' => 'English',
                ],
            ],
        ];
    }
}
