<?php

namespace App\Services\Agents;

/**
 * QA Validation Result Value Object
 *
 * Represents the structured result from the Research QA Validator agent.
 * Includes validation status, quality scores, requirement analysis, and
 * identified gaps for iterative research improvement.
 */
class QAValidationResult
{
    /**
     * @param  string  $qaStatus  Overall validation status: 'pass' or 'fail'
     * @param  int  $overallScore  Overall quality score (0-100)
     * @param  array{completeness: int, depth: int, accuracy: int, coherence: int}  $assessment  Detailed quality assessment scores
     * @param  array<array{requirement: string, addressed: bool, evidence: string}>  $requirements  Individual requirement assessments
     * @param  array<array{missing: string, importance: string, impact: string, suggestedQuery: string, suggestedAgent?: string}>  $gaps  Identified gaps needing research
     * @param  string  $recommendations  Overall feedback and next steps
     */
    public function __construct(
        public string $qaStatus,
        public int $overallScore,
        public array $assessment,
        public array $requirements,
        public array $gaps,
        public string $recommendations
    ) {}

    /**
     * Check if validation passed
     */
    public function passed(): bool
    {
        return $this->qaStatus === 'pass';
    }

    /**
     * Check if validation failed
     */
    public function failed(): bool
    {
        return $this->qaStatus === 'fail';
    }

    /**
     * Get critical gaps that must be addressed
     */
    public function getCriticalGaps(): array
    {
        return array_filter($this->gaps, fn ($gap) => $gap['importance'] === 'critical');
    }

    /**
     * Get all unaddressed requirements
     */
    public function getUnaddressedRequirements(): array
    {
        return array_filter($this->requirements, fn ($req) => ! $req['addressed']);
    }

    /**
     * Check if there are any critical gaps
     */
    public function hasCriticalGaps(): bool
    {
        return count($this->getCriticalGaps()) > 0;
    }

    /**
     * Get summary text for logging and display
     */
    public function getSummary(): string
    {
        $status = $this->passed() ? 'PASSED' : 'FAILED';
        $criticalCount = count($this->getCriticalGaps());
        $totalGaps = count($this->gaps);

        return "QA {$status} (Score: {$this->overallScore}/100) - {$totalGaps} gaps ({$criticalCount} critical)";
    }

    /**
     * Convert to array for storage in metadata
     */
    public function toArray(): array
    {
        return [
            'qaStatus' => $this->qaStatus,
            'overallScore' => $this->overallScore,
            'assessment' => $this->assessment,
            'requirements' => $this->requirements,
            'gaps' => $this->gaps,
            'recommendations' => $this->recommendations,
        ];
    }
}
