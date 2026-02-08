<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;

/**
 * Holistic Research Service - AI-Powered Research Planning and Execution.
 *
 * Orchestrates multi-phase research workflows with AI-powered query analysis,
 * research plan generation, and structured output parsing. Enables agents to
 * handle complex research queries by breaking them into sub-queries and
 * executing them strategically.
 *
 * Research Workflow:
 * 1. **Analyze**: Assess query complexity (simple/standard/complex)
 * 2. **Plan**: Generate research plan using Research Planner agent
 * 3. **Execute**: Coordinate parallel or sequential research threads
 * 4. **Synthesize**: Combine findings into comprehensive response
 *
 * Complexity Analysis:
 * - **Simple**: Direct factual questions (≤10 words, 1 question mark, no complex terms)
 * - **Standard**: General queries requiring moderate research
 * - **Complex**: Multi-faceted queries (3+ complex terms, >25 words, or >2 questions)
 *
 * Structured Output Strategy:
 * - Uses Prism's structured output feature for reliable JSON parsing
 * - Supports ResearchPlan (parallel threads) and WorkflowPlan (multi-strategy)
 * - Schema-driven validation ensures plan integrity
 *
 * @see \App\Services\Agents\ParallelResearchCoordinator
 * @see \App\Services\Agents\WorkflowOrchestrator
 */
class HolisticResearchService
{
    public function __construct(
        private AgentService $agentService
    ) {}

    /**
     * Analyze query complexity and route to appropriate strategy
     */
    public function analyzeQueryComplexity(string $query): string
    {
        $wordCount = str_word_count($query);
        $questionMarks = substr_count($query, '?');
        $complexityIndicators = [
            'analyze', 'compare', 'evaluate', 'impact', 'implications',
            'comprehensive', 'detailed', 'thorough', 'across', 'between',
            'economic', 'social', 'political', 'environmental', 'global',
        ];

        $complexTerms = 0;
        foreach ($complexityIndicators as $term) {
            if (stripos($query, $term) !== false) {
                $complexTerms++;
            }
        }

        // Simple: Direct factual questions
        if ($wordCount <= 10 && $questionMarks <= 1 && $complexTerms === 0) {
            return 'simple';
        }

        // Complex: Multiple complexity indicators or very long queries
        if ($complexTerms >= 3 || $wordCount > 25 || $questionMarks > 2) {
            return 'complex';
        }

        // Standard: Everything else
        return 'standard';
    }

    /**
     * Execute Research Planner with structured output for reliable JSON parsing
     * This is the new recommended approach - replaces parseResearchPlan()
     *
     * Returns either ResearchPlan (old parallel format) or WorkflowPlan (new multi-strategy format)
     * based on the agent's workflow_config['schema_class']
     */
    public function executeResearchPlannerWithStructuredOutput(Agent $agent, string $query, int $parentExecutionId, ?AgentExecution $existingExecution = null): ResearchPlan|WorkflowPlan
    {
        // Use existing execution if provided, otherwise create new one
        if ($existingExecution) {
            $execution = $existingExecution;
            // Update the input to use the properly formatted query
            $execution->update(['input' => $query]);
        } else {
            // Create a temporary execution for this agent
            // Get parent execution's user to maintain proper attribution chain
            $parentExecution = AgentExecution::find($parentExecutionId);
            if (! $parentExecution) {
                throw new \Exception("Parent execution {$parentExecutionId} not found - cannot determine user context");
            }
            $userId = $parentExecution->user_id;

            $execution = new AgentExecution([
                'agent_id' => $agent->id,
                'user_id' => $userId,
                'input' => $query,
                'max_steps' => $agent->max_steps,
                'status' => 'running',
                'parent_agent_execution_id' => $parentExecutionId,
            ]);
            $execution->save();
        }

        // Link to original ChatInteraction for attachment access
        $parentExecution = AgentExecution::find($parentExecutionId);
        $chatInteraction = $parentExecution ? $parentExecution->chatInteraction : null;

        if ($chatInteraction) {
            // Set the relationship to enable attachment access
            $execution->setRelation('chatInteraction', $chatInteraction);

            \Illuminate\Support\Facades\Log::info('HolisticResearchService: Linked Research Planner execution to ChatInteraction', [
                'planner_execution_id' => $execution->id,
                'parent_execution_id' => $parentExecutionId,
                'interaction_id' => $chatInteraction->id,
                'attachments_count' => $chatInteraction->attachments ? $chatInteraction->attachments->count() : 0,
            ]);
        }

        // Prepare messages with attachment context
        // Do NOT add SystemMessage here - will use withSystemPrompt() for provider interoperability
        $messages = [];

        // Build user input with attachments if available
        $userInput = $query;
        $attachmentObjects = [];

        if ($chatInteraction && $chatInteraction->attachments && $chatInteraction->attachments->count() > 0) {
            $textAttachments = '';

            foreach ($chatInteraction->attachments as $attachment) {
                try {
                    // Check if this should be injected as text
                    if ($attachment->shouldInjectAsText()) {
                        $textContent = $attachment->getTextContent();
                        if ($textContent) {
                            $textAttachments .= "\n\n--- Attached File: {$attachment->filename} ---\n{$textContent}\n--- End of {$attachment->filename} ---\n";
                            \Illuminate\Support\Facades\Log::info('HolisticResearchService: Injected text attachment for Research Planner', [
                                'attachment_id' => $attachment->id,
                                'filename' => $attachment->filename,
                                'content_length' => strlen($textContent),
                            ]);
                        }
                    } else {
                        // Handle as document attachment (PDFs, images, etc.)
                        $prismObject = $attachment->toPrismValueObject();
                        if ($prismObject) {
                            $attachmentObjects[] = $prismObject;
                            \Illuminate\Support\Facades\Log::info('HolisticResearchService: Created document attachment for Research Planner', [
                                'attachment_id' => $attachment->id,
                                'filename' => $attachment->filename,
                                'mime_type' => $attachment->mime_type,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('HolisticResearchService: Error processing attachment for Research Planner', [
                        'attachment_id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Add text attachments to user input
            if (! empty($textAttachments)) {
                $userInput .= $textAttachments;
            }
        }

        // Create UserMessage with or without binary attachments
        if (! empty($attachmentObjects)) {
            foreach ($attachmentObjects as $attachmentObject) {
                $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($userInput, [$attachmentObject]);
            }
        } else {
            $messages[] = new \Prism\Prism\ValueObjects\Messages\UserMessage($userInput);
        }

        // Store AI prompt metadata before execution (consistent with AgentExecutor pattern)
        $currentMetadata = $execution->metadata ?? [];
        $currentMetadata['ai_prompt'] = json_encode($messages);
        $execution->update(['metadata' => $currentMetadata]);

        \Illuminate\Support\Facades\Log::info('HolisticResearchService: Stored AI prompt metadata for Research Planner', [
            'execution_id' => $execution->id,
            'messages_count' => count($messages),
            'has_attachments' => ! empty($attachmentObjects),
            'agent_name' => $agent->name,
        ]);

        // Determine which schema to use based on agent configuration
        $schemaClass = $agent->workflow_config['schema_class'] ?? \App\Services\Agents\Schemas\ResearchPlanSchema::class;
        $schemaInstance = new $schemaClass;

        \Illuminate\Support\Facades\Log::info('HolisticResearchService: Using schema for Research Planner', [
            'schema_class' => $schemaClass,
            'agent_id' => $agent->id,
        ]);

        // Execute with structured output using Prism-PHP via PrismWrapper
        // Use withSystemPrompt() for provider interoperability (per Prism best practices)
        $schema = app(\App\Services\AI\PrismWrapper::class)
            ->structured()
            ->using($agent->getProviderEnum(), $agent->ai_model)
            ->withMaxTokens(4096) // Research planning needs moderate output space for sub-queries
            ->withSystemPrompt($agent->system_prompt) // Provider-agnostic system prompt handling
            ->withMessages($messages)
            ->withSchema($schemaInstance)
            ->withContext([
                'operation' => 'research_planning',
                'agent_id' => $agent->id,
                'execution_id' => $execution->id,
                'schema_class' => class_basename($schemaClass),
                'source' => 'HolisticResearchService::execute',
            ])
            ->asStructured();

        // Mark execution as completed - merge with existing metadata to preserve ai_prompt
        $existingMetadata = $execution->metadata ?? [];
        $completionMetadata = array_merge($existingMetadata, [
            'structured_output' => true,
            'schema_used' => class_basename($schemaClass),
            'attachments_processed' => $chatInteraction && $chatInteraction->attachments ? $chatInteraction->attachments->count() : 0,
        ]);

        $execution->markAsCompleted('Research plan generated with structured output', $completionMetadata);

        // Convert structured output to appropriate plan object based on schema type
        if ($schemaClass === \App\Services\Agents\Schemas\WorkflowPlanSchema::class) {
            return \App\Services\Agents\Schemas\WorkflowPlanSchema::toWorkflowPlan($schema->structured);
        } else {
            return \App\Services\Agents\Schemas\ResearchPlanSchema::toResearchPlan($schema->structured);
        }
    }

    /**
     * Prepare synthesis input from research plan and results
     */
    public function prepareSynthesisInput(ResearchPlan $plan, array $researchResults): string
    {
        $synthesisInput = "ORIGINAL QUERY: {$plan->originalQuery}\n\n";
        $synthesisInput .= "SYNTHESIS INSTRUCTIONS: {$plan->synthesisInstructions}\n\n";
        $synthesisInput .= "RESEARCH FINDINGS:\n\n";

        foreach ($researchResults as $index => $result) {
            $subQuery = $result['sub_query'] ?? 'Research Analysis '.($index + 1);
            $findings = $result['findings'] ?? 'No findings';
            $sourceCount = $result['source_count'] ?? 0;

            // Remove "Research Thread" terminology - use more natural language
            $synthesisInput .= '=== RESEARCH ANALYSIS '.($index + 1).": {$subQuery} ===\n";
            $synthesisInput .= "Sources Found: {$sourceCount}\n";
            $synthesisInput .= "Findings:\n{$findings}\n\n";
        }

        $synthesisInput .= 'Please synthesize these findings into a comprehensive response that addresses the original query.';

        return $synthesisInput;
    }

    /**
     * Prepare Research Planner input with available agents for selection and conversation context
     */
    public function prepareResearchPlannerInput(\App\Models\ChatInteraction $interaction): string
    {
        // Get only individual agents for research to avoid workflow loops
        $availableAgents = \App\Models\Agent::availableForResearch()
            ->where('agent_type', 'individual')
            ->with(['enabledTools' => function ($q) {
                $q->select('agent_id', 'tool_name', 'tool_config');
            }])
            ->get();

        // Get synthesizer agents separately for result synthesis
        $synthesizerAgents = \App\Models\Agent::availableForSynthesis()
            ->with(['enabledTools' => function ($q) {
                $q->select('agent_id', 'tool_name', 'tool_config');
            }])
            ->get();

        // Ensure at least one synthesizer is available
        if ($synthesizerAgents->isEmpty()) {
            throw new \RuntimeException(
                'No active synthesizer agents available. At least one synthesizer agent is required for workflow synthesis.'
            );
        }

        // Format research agents for AI analysis - include full prompts for capability extraction
        $agentsJson = $availableAgents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'system_prompt' => $agent->system_prompt, // Full prompt for AI analysis
                'tools' => $agent->enabledTools->pluck('tool_name')->toArray(),
                'model' => $agent->ai_model, // For capability assessment
            ];
        });

        // Format synthesizer agents for selection
        $synthesizersJson = $synthesizerAgents->map(function ($agent) {
            return [
                'id' => $agent->id,
                'name' => $agent->name,
                'description' => $agent->description,
                'tools' => $agent->enabledTools->pluck('tool_name')->toArray(),
            ];
        });

        // Build conversation context from previous interactions
        $conversationContext = $this->buildConversationContext($interaction);

        $prompt = '';

        // Add conversation context if available
        if (! empty($conversationContext)) {
            $prompt .= "CONVERSATION CONTEXT:\n";
            $prompt .= $conversationContext."\n\n";
        }

        $prompt .= "CURRENT RESEARCH QUERY: {$interaction->question}\n\n";
        $prompt .= "AVAILABLE RESEARCH AGENTS:\n";
        $prompt .= json_encode($agentsJson, JSON_PRETTY_PRINT);
        $prompt .= "\n\n".str_repeat('=', 70)."\n";
        $prompt .= "⚠️  CRITICAL: AVAILABLE SYNTHESIZER AGENTS (SELECT FROM THIS LIST ONLY)\n";
        $prompt .= str_repeat('=', 70)."\n";
        $prompt .= json_encode($synthesizersJson, JSON_PRETTY_PRINT);
        $prompt .= "\n".str_repeat('=', 70)."\n\n";
        $prompt .= "Analyze each agent's capabilities from their description and system prompt. ";
        $prompt .= 'Consider the conversation context (if any) and select the most appropriate agents for this research query. ';
        $prompt .= 'ENSURE ALL SUB-QUERIES CAN EXECUTE IN PARALLEL - no dependencies between agents. ';
        $prompt .= 'When selecting a synthesizer agent, ONLY choose from the AVAILABLE SYNTHESIZER AGENTS list above. ';
        $prompt .= 'NEVER use agent IDs that are not in the AVAILABLE SYNTHESIZER AGENTS list. ';
        $prompt .= 'If this is a follow-up question, ensure the research plan builds upon previous context.';

        return $prompt;
    }

    /**
     * Build lightweight conversation context from previous interactions using summaries
     */
    private function buildConversationContext(\App\Models\ChatInteraction $currentInteraction): string
    {
        if (! $currentInteraction->chat_session_id) {
            return '';
        }

        // Get previous interactions from the same session (excluding current one)
        $previousInteractions = \App\Models\ChatInteraction::where('chat_session_id', $currentInteraction->chat_session_id)
            ->where('id', '<', $currentInteraction->id)
            ->orderBy('created_at', 'desc')
            ->limit(5) // Last 5 interactions for context
            ->get()
            ->reverse(); // Reverse to show chronological order

        if ($previousInteractions->isEmpty()) {
            return '';
        }

        $context = "Previous conversation in this session (use GetChatInteraction tool to retrieve full details):\n\n";

        foreach ($previousInteractions as $interaction) {
            $context .= "ID: {$interaction->id} | ";
            $context .= 'Q: '.$interaction->question."\n";

            // Use the comprehensive summary if available
            if (! empty($interaction->summary)) {
                $context .= 'Summary: '.$interaction->summary."\n";
            } elseif (! empty($interaction->answer)) {
                // Fallback to truncated answer if no summary exists yet
                $cleanAnswer = strip_tags($interaction->answer);
                $cleanAnswer = preg_replace('/\s+/', ' ', trim($cleanAnswer));
                $context .= 'Answer: '.$this->truncateText($cleanAnswer, 200)."\n";
            }

            $context .= "\n";
        }

        $context .= "Use the GetChatInteraction tool with any of these IDs to get full interaction details when needed for context.\n";

        return $context;
    }

    /**
     * Extract a brief topic summary from a research answer (legacy method - now using summaries)
     */
    private function extractAnswerTopic(string $answer): string
    {
        // This method is kept for backward compatibility but summaries are now preferred
        $cleanAnswer = strip_tags($answer);
        $cleanAnswer = preg_replace('/\s+/', ' ', $cleanAnswer);
        $cleanAnswer = trim($cleanAnswer);

        // Extract first meaningful sentence or key topic
        $sentences = explode('.', $cleanAnswer);
        $firstSentence = trim($sentences[0] ?? '');

        if (strlen($firstSentence) > 80) {
            $firstSentence = substr($firstSentence, 0, 80).'...';
        }

        return $firstSentence ?: 'Research completed';
    }

    /**
     * Truncate text to specified length with ellipsis
     */
    private function truncateText(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength).'...';
    }

    /**
     * Extract context information from interaction metadata (legacy support)
     */
    private function extractContextFromMetadata(array $metadata): ?string
    {
        // Extract key information from metadata for context
        $contextParts = [];

        if (isset($metadata['execution_strategy'])) {
            $contextParts[] = 'Strategy: '.$metadata['execution_strategy'];
        }

        if (isset($metadata['total_sources']) && $metadata['total_sources'] > 0) {
            $contextParts[] = 'Sources: '.$metadata['total_sources'];
        }

        if (isset($metadata['research_threads'])) {
            $contextParts[] = 'Threads: '.$metadata['research_threads'];
        }

        return ! empty($contextParts) ? implode(', ', $contextParts) : null;
    }
}
