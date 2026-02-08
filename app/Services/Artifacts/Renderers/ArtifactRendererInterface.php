<?php

namespace App\Services\Artifacts\Renderers;

use App\Models\Artifact;

interface ArtifactRendererInterface
{
    /**
     * Render artifact content for preview display (HTML output)
     */
    public function render(Artifact $artifact): string;

    /**
     * Render artifact content for preview in card (truncated)
     */
    public function renderPreview(Artifact $artifact, int $maxLength = 500): string;

    /**
     * Get raw content suitable for download
     */
    public function forDownload(Artifact $artifact): string;

    /**
     * Get raw content as-is
     */
    public function raw(Artifact $artifact): string;

    /**
     * Get MIME type for download
     */
    public function getMimeType(Artifact $artifact): string;

    /**
     * Get file extension for download
     */
    public function getFileExtension(Artifact $artifact): string;
}
