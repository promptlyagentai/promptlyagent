<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Services\Agents\Schemas\QAValidationSchema;
use Illuminate\Support\Facades\Log;

/**
 * Quality Assurance Service for Holistic Research Workflows
 *
 * Provides automated validation of synthesized research results to ensure
 * comprehensive coverage of user requirements. Enables iterative improvement
 * through gap identification and follow-up research coordination.
 *
 * Features:
 * - Structured QA validation using dedicated QA agent
 * - Gap identification with specific follow-up queries
 * - Iteration tracking with configurable max limit
 * - Keyword-based QA triggering
 * - Detailed logging for QA audit trail
 *
 * Usage Flow:
 * 1. shouldTriggerQA() - Check if QA is required for this query
 * 2. validateSynthesis() - Execute QA agent to validate results
 * 3. If failed: generateFollowUpQueries() - Extract gap-filling queries
 * 4. Dispatch new research → Re-synthesize → Repeat QA
 * 5. hasReachedMaxIterations() - Enforce iteration limit
 *
 * @see \App\Jobs\SynthesizeWorkflowJob
 * @see \App\Services\Agents\Schemas\QAValidationSchema
 */
class QualityAssuranceService
{
    /**
     * Default maximum QA iterations to prevent infinite loops
     */
    public const DEFAULT_MAX_ITERATIONS = 2;

    /**
     * Keywords that trigger automatic QA validation
     */
    public const QA_TRIGGER_KEYWORDS = [
        'validate',
        'double-check',
        'quality control',
        'ensure completeness',
        'audit',
        'verify',
        'cross-check',
        'thorough',
        'comprehensive',
        'complete analysis',
        'full coverage',
    ];

    public function __construct(
        private AgentService $agentService
    ) {}

    /**
     * Check if QA validation should be triggered for this query
     *
     * @param  string  $query  The original user query
     * @param  bool  $explicitFlag  Whether requiresQA flag was set in workflow plan
     * @return bool True if QA should be executed
     */
    public function shouldTriggerQA(string $query, bool $explicitFlag = false): bool
    {
        // Explicit flag always wins
        if ($explicitFlag) {
            return true;
        }

        // Check for QA keywords in query
        $lowerQuery = strtolower($query);
        foreach (self::QA_TRIGGER_KEYWORDS as $keyword) {
            if (str_contains($lowerQuery, $keyword)) {
                Log::info('QualityAssuranceService: QA triggered by keyword', [
                    'keyword' => $keyword,
                    'query' => $query,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Execute QA validation on synthesized research result
     *
     * @param  string  $originalQuery  The original user query
     * @param  string  $synthesizedAnswer  The synthesized research result to validate
     * @param  array  $researchResults  The individual agent results that were synthesized
     * @param  int  $parentExecutionId  Parent execution for audit trail
     * @param  int  $userId  User ID for execution context
     * @param  int|null  $chatSessionId  Optional chat session ID
     * @return QAValidationResult The validation result with gaps and recommendations
     *
     * @throws \Exception If QA agent not found or validation fails
     */
    public function validateSynthesis(
        string $originalQuery,
        string $synthesizedAnswer,
        array $researchResults,
        int $parentExecutionId,
        int $userId,
        ?int $chatSessionId = null
    ): QAValidationResult {
        // Get QA Validator agent
        $qaAgent = $this->getQAAgent();

        Log::info('QualityAssuranceService: Starting QA validation', [
            'qa_agent_id' => $qaAgent->id,
            'parent_execution_id' => $parentExecutionId,
            'user_id' => $userId,
            'query_length' => strlen($originalQuery),
            'answer_length' => strlen($synthesizedAnswer),
        ]);

        // Build QA validation input
        $qaInput = $this->buildQAInput($originalQuery, $synthesizedAnswer, $researchResults);

        // Create QA execution
        $qaExecution = AgentExecution::create([
            'agent_id' => $qaAgent->id,
            'user_id' => $userId,
            'chat_session_id' => $chatSessionId,
            'input' => $qaInput,
            'max_steps' => $qaAgent->max_steps,
            'status' => 'running',
            'parent_agent_execution_id' => $parentExecutionId,
            'active_execution_key' => 'qa_validation_'.uniqid(),
            'metadata' => [
                'validation_type' => 'qa',
                'original_query' => $originalQuery,
            ],
        ]);

        // Execute QA with structured output
        $schemaInstance = new QAValidationSchema;

        try {
            $schema = app(\App\Services\AI\PrismWrapper::class)
                ->structured()
                ->using($qaAgent->getProviderEnum(), $qaAgent->ai_model)
                ->withMaxTokens(4096) // QA needs space for detailed analysis
                ->withSystemPrompt($qaAgent->system_prompt)
                ->withMessages([
                    new \Prism\Prism\ValueObjects\Messages\UserMessage($qaInput),
                ])
                ->withSchema($schemaInstance)
                ->withContext([
                    'operation' => 'qa_validation',
                    'agent_id' => $qaAgent->id,
                    'execution_id' => $qaExecution->id,
                    'parent_execution_id' => $parentExecutionId,
                    'source' => 'QualityAssuranceService::validateSynthesis',
                ])
                ->asStructured();

            // Convert to QAValidationResult
            $result = QAValidationSchema::toQAValidationResult($schema->structured);

            // Mark execution as completed
            $qaExecution->markAsCompleted(
                output: json_encode($result->toArray(), JSON_PRETTY_PRINT),
                metadata: [
                    'validation_type' => 'qa',
                    'qa_status' => $result->qaStatus,
                    'overall_score' => $result->overallScore,
                    'critical_gaps' => count($result->getCriticalGaps()),
                    'total_gaps' => count($result->gaps),
                ]
            );

            Log::info('QualityAssuranceService: QA validation completed', [
                'qa_execution_id' => $qaExecution->id,
                'qa_status' => $result->qaStatus,
                'overall_score' => $result->overallScore,
                'gaps_count' => count($result->gaps),
                'critical_gaps_count' => count($result->getCriticalGaps()),
            ]);

            return $result;

        } catch (\Exception $e) {
            // Mark QA execution as failed
            $qaExecution->markAsFailed("QA validation failed: {$e->getMessage()}");

            Log::error('QualityAssuranceService: QA validation failed', [
                'qa_execution_id' => $qaExecution->id,
                'parent_execution_id' => $parentExecutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Build QA validation input from original query, synthesis, and research results
     */
    protected function buildQAInput(string $originalQuery, string $synthesizedAnswer, array $researchResults): string
    {
        $input = "ORIGINAL USER QUERY:\n{$originalQuery}\n\n";
        $input .= str_repeat('=', 80)."\n\n";
        $input .= "SYNTHESIZED ANSWER (TO BE VALIDATED):\n{$synthesizedAnswer}\n\n";
        $input .= str_repeat('=', 80)."\n\n";
        $input .= "SUPPORTING RESEARCH RESULTS:\n\n";

        foreach ($researchResults as $index => $result) {
            if (isset($result['error'])) {
                $input .= 'Agent '.($index + 1).": FAILED - {$result['message']}\n\n";
            } else {
                $agentName = $result['agent_name'] ?? 'Unknown Agent';
                $findings = $result['result'] ?? 'No findings';
                $sourceCount = count($result['sources'] ?? []);

                $input .= 'Agent '.($index + 1).": {$agentName}\n";
                $input .= "Sources: {$sourceCount}\n";
                $input .= "Findings:\n{$findings}\n\n";
                $input .= "---\n\n";
            }
        }

        $input .= str_repeat('=', 80)."\n\n";
        $input .= 'TASK: Validate if the synthesized answer comprehensively addresses the original query. ';
        $input .= 'Identify any gaps, missing information, or unaddressed requirements. ';
        $input .= 'Provide specific follow-up queries if additional research is needed.';

        return $input;
    }

    /**
     * Generate follow-up research queries from QA validation gaps
     *
     * @param  QAValidationResult  $qaResult  The QA validation result with gaps
     * @return array<string> Array of follow-up research queries
     */
    public function generateFollowUpQueries(QAValidationResult $qaResult): array
    {
        $queries = [];

        // Prioritize critical gaps, then important ones
        $sortedGaps = collect($qaResult->gaps)
            ->sortBy(function ($gap) {
                return match ($gap['importance']) {
                    'critical' => 1,
                    'important' => 2,
                    'nice-to-have' => 3,
                    default => 4,
                };
            });

        foreach ($sortedGaps as $gap) {
            if (! empty($gap['suggestedQuery'])) {
                $queries[] = $gap['suggestedQuery'];
            }
        }

        Log::info('QualityAssuranceService: Generated follow-up queries', [
            'total_gaps' => count($qaResult->gaps),
            'queries_generated' => count($queries),
        ]);

        return $queries;
    }

    /**
     * Check if maximum QA iterations have been reached
     *
     * @param  array  $metadata  Parent execution metadata
     * @param  int  $maxIterations  Maximum allowed iterations (default: 2)
     * @return bool True if max iterations reached
     */
    public function hasReachedMaxIterations(array $metadata, int $maxIterations = self::DEFAULT_MAX_ITERATIONS): bool
    {
        $currentIteration = $metadata['qa_iteration'] ?? 0;

        return $currentIteration >= $maxIterations;
    }

    /**
     * Get the next iteration number
     */
    public function getNextIteration(array $metadata): int
    {
        return ($metadata['qa_iteration'] ?? 0) + 1;
    }

    /**
     * Get QA Validator agent with caching
     *
     *
     * @throws \RuntimeException If QA agent not found
     */
    protected function getQAAgent(): Agent
    {
        // Cache for 1 hour to reduce DB queries
        return \Illuminate\Support\Facades\Cache::remember('agent:qa_validator:id', 3600, function () {
            $agent = Agent::where('agent_type', 'qa')
                ->where('status', 'active')
                ->first();

            if (! $agent) {
                throw new \RuntimeException(
                    'Research QA Validator agent not found. QA validation requires an active agent with agent_type="qa".'
                );
            }

            return $agent;
        });
    }
}
