<?php

namespace App\Services\Artifacts\Executors;

use App\Models\Artifact;

/**
 * Interface for artifact execution handlers
 * Executors handle rendering/executing artifact content based on filetype
 */
interface ArtifactExecutorInterface
{
    /**
     * Execute or render the artifact content
     * Returns HTML that can be displayed in the artifact drawer
     *
     * @param  Artifact  $artifact  The artifact to execute
     * @return string HTML output for display
     */
    public function execute(Artifact $artifact): string;

    /**
     * Check if this executor can handle the given artifact
     *
     * @param  Artifact  $artifact  The artifact to check
     * @return bool True if this executor can handle the artifact
     */
    public function canExecute(Artifact $artifact): bool;

    /**
     * Get security warnings for executing this artifact type
     *
     * @param  Artifact  $artifact  The artifact to check
     * @return array Array of warning messages
     */
    public function getSecurityWarnings(Artifact $artifact): array;
}
