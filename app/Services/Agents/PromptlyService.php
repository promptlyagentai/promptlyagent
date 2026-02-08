<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\Agents\Schemas\AgentSelectionSchema;
use App\Services\AI\ModelSelector;
use Illuminate\Support\Facades\Log;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Promptly Service - AI-Powered Meta-Agent Selection.
 *
 * Orchestrates intelligent agent selection using an AI meta-agent that analyzes
 * user queries and selects the most appropriate agent from available options.
 * Replaces manual agent selection with AI-driven routing.
 *
 * Workflow:
 * 1. **Analyze**: AI analyzes query complexity and requirements
 * 2. **Select**: AI chooses best-fit agent from available pool
 * 3. **Execute**: Selected agent processes the query
 *
 * Selection Criteria:
 * - Query complexity and type (research vs creation vs chat)
 * - Agent capabilities and tool availability
 * - Agent specialization and strengths
 * - Fallback to General Agent if selection fails
 *
 * Structured Output:
 * - Uses AgentSelectionSchema for reliable JSON parsing
 * - Returns agent_name and reasoning for transparency
 *
 * @see \App\Services\Agents\Schemas\AgentSelectionSchema
 * @see \App\Services\Agents\AgentService
 */
class PromptlyService
{
    public function __construct(
        private AgentService $agentService,
        private ModelSelector $modelSelector
    ) {}

    /**
     * Execute Promptly workflow: analyze → select → execute
     */
    public function execute(
        string $query,
        User $user,
        ?int $chatSessionId = null,
        bool $async = true,
        ?int $parentExecutionId = null,
        ?int $interactionId = null
    ): AgentExecution {
        // 1. Get only individual agents for research to avoid workflow loops
        $agents = Agent::active()
            ->where('show_in_chat', true)
            ->where('agent_type', 'individual')
            ->with(['enabledTools'])
            ->get();

        if ($agents->isEmpty()) {
            throw new \Exception('No agents available for selection');
        }

        // 2. Use AI to select best agent
        $selection = $this->selectAgentWithAI($query, $agents);

        Log::info('Promptly selected agent via AI', [
            'query' => substr($query, 0, 100),
            'selected_agent' => $selection['agent_name'],
            'agent_id' => $selection['agent_id'],
            'confidence' => $selection['confidence'],
            'reasoning' => $selection['reasoning'],
        ]);

        // 3. Get selected agent
        $selectedAgent = $agents->firstWhere('id', $selection['agent_id']);

        if (! $selectedAgent) {
            // Fallback to first available agent
            $selectedAgent = $agents->first();
            Log::warning('Selected agent not found, using fallback', [
                'attempted_id' => $selection['agent_id'],
                'fallback_agent' => $selectedAgent->name,
            ]);
        }

        // 4. Execute selected agent with original query and full context
        // Pass parent execution ID so AgentService can link to parent's chatInteraction
        // This ensures the selected agent has access to all files and context from the original request
        // CRITICAL: Skip context building because input is already contextualized from Promptly execution
        // Building context twice would duplicate conversation history and exceed token limits
        return $this->agentService->executeAgent(
            agent: $selectedAgent,
            input: $query,
            user: $user,
            chatSessionId: $chatSessionId,
            async: $async,
            interactionId: $interactionId,
            parentAgentExecutionId: $parentExecutionId,
            skipContextBuilding: true // Input already includes context from parent execution
        );
    }

    /**
     * Use AI to select the best agent for the query
     */
    public function selectAgentWithAI(string $query, $agents): array
    {
        // Prepare agent information for AI
        $agentInfo = $agents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'tools' => $agent->enabledTools->pluck('tool_name')->toArray(),
                'prompt_preview' => $this->extractPromptPreview($agent->system_prompt),
            ];
        })->toArray();

        // Build selection prompt
        $systemPrompt = $this->buildSelectionSystemPrompt();
        $userPrompt = $this->buildSelectionUserPrompt($query, $agentInfo);

        // Use medium model for selection (fast but capable)
        $model = $this->modelSelector->getMediumModel();

        // Get structured selection from AI
        // Use withSystemPrompt() for provider interoperability (per Prism best practices)
        $schema = app(\App\Services\AI\PrismWrapper::class)
            ->structured()
            ->using($model['provider'], $model['model'])
            ->withMaxTokens(2048) // Agent selection is concise, doesn't need large output
            ->withSystemPrompt($systemPrompt) // Provider-agnostic system prompt handling
            ->withMessages([
                new UserMessage($userPrompt),
            ])
            ->withSchema(new AgentSelectionSchema)
            ->withContext([
                'operation' => 'agent_selection',
                'query_length' => strlen($query),
                'available_agents' => count($agents),
                'source' => 'PromptlyService::selectAgent',
            ])
            ->asStructured();

        return AgentSelectionSchema::extractSelectionData($schema->structured);
    }

    /**
     * Extract relevant preview from agent's system prompt
     */
    protected function extractPromptPreview(string $systemPrompt): string
    {
        // Take first 300 characters which usually contains the key description
        $preview = substr($systemPrompt, 0, 300);

        // Try to end at a sentence boundary
        $lastPeriod = strrpos($preview, '.');
        if ($lastPeriod !== false && $lastPeriod > 100) {
            $preview = substr($preview, 0, $lastPeriod + 1);
        }

        return trim($preview);
    }

    /**
     * Build system prompt for agent selection
     *
     * NOTE: This selection logic is now also defined in Promptly Agent's system prompt
     * (see AgentService::createPromptlyAgent() in the AGENT SELECTION CAPABILITY section).
     * This makes Promptly Agent's meta-agent behavior self-documented.
     *
     * This method continues to provide the selection prompt for PromptlyService's
     * AI-powered agent selection workflow. Both definitions should be kept in sync.
     *
     * @see \App\Services\Agents\AgentService::createPromptlyAgent()
     */
    protected function buildSelectionSystemPrompt(): string
    {
        return 'You are an intelligent agent selector. Your job is to analyze a user query and select the single best agent to handle it.

## YOUR TASK

1. Understand the user\'s query requirements
2. Analyze the available agents and their capabilities
3. Select the ONE agent that is best suited for this task
4. Provide clear reasoning for your selection

## ANALYSIS CRITERIA

Consider:
- **Domain Match**: Does the agent specialize in the query\'s domain?
- **Tool Availability**: Does the agent have the right tools?
- **Capability Indicators**: What does the agent\'s description/prompt reveal?
- **Complexity Match**: Is the agent appropriate for the task complexity?

## SELECTION PRINCIPLES

- Choose the MOST SPECIFIC agent when available (e.g., "Contract Evaluation" over "Research Assistant" for contracts)
- Consider tool requirements (e.g., needs markitdown for PDFs, needs artifact tools for document creation)
- Default to general-purpose agents only when no specialist matches
- Be confident - every agent can potentially help, pick the best match

## OUTPUT

Provide:
- Brief analysis of the query
- Selected agent ID and name
- Confidence level (0.0 to 1.0)
- Specific reasoning for the selection

Remember: You are selecting an agent to execute the task, not executing it yourself.';
    }

    /**
     * Build user prompt with query and agents
     */
    protected function buildSelectionUserPrompt(string $query, array $agentInfo): string
    {
        $prompt = "USER QUERY:\n{$query}\n\n";
        $prompt .= "AVAILABLE AGENTS:\n\n";

        foreach ($agentInfo as $agent) {
            $prompt .= "Agent ID: {$agent['id']}\n";
            $prompt .= "Name: {$agent['name']}\n";
            $prompt .= "Description: {$agent['description']}\n";
            $prompt .= 'Tools: '.implode(', ', $agent['tools'])."\n";
            $prompt .= "Capabilities: {$agent['prompt_preview']}\n";
            $prompt .= "---\n\n";
        }

        $prompt .= 'Select the best agent for this query and explain your reasoning.';

        return $prompt;
    }
}
