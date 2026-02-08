<?php

namespace App\Services\Agents;

/**
 * Research Thread Result Data Structure
 */
class ResearchThreadResult
{
    public function __construct(
        public string $subQuery,
        public string $findings,
        public array $sourceLinks,
        public float $confidenceScore,
        public int $executionTimeMs
    ) {}
}
