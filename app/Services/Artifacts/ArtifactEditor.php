<?php

namespace App\Services\Artifacts;

use App\Exceptions\ContentHashMismatchException;
use App\Models\Artifact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artifact Editor - Optimistic Locking Content Editor.
 *
 * Provides safe, concurrent content editing for artifacts using optimistic locking
 * via content hash validation. Prevents race conditions and data loss when multiple
 * editors work on the same artifact simultaneously.
 *
 * Optimistic Locking Pattern:
 * 1. Client reads artifact with current content_hash
 * 2. User makes edits locally
 * 3. Client submits changes with original content_hash
 * 4. Server validates hash before applying changes
 * 5. If hash mismatch, reject with ContentHashMismatchException
 * 6. Client must fetch latest version and reapply changes
 *
 * Editing Operations:
 * - **Append**: Add content to end of artifact
 * - **Insert**: Insert content at specific character position
 * - **Patch**: Apply multiple range-based content replacements
 *
 * Versioning:
 * - Automatically creates artifact version before content changes
 * - Gracefully handles duplicate version constraint violations
 * - Maintains full version history for rollback capabilities
 *
 * Transaction Safety:
 * - All edits wrapped in database transactions
 * - Atomic hash validation + content modification
 * - Automatic rollback on any failure
 *
 * @see \App\Models\Artifact
 * @see \App\Exceptions\ContentHashMismatchException
 */
class ArtifactEditor
{
    /**
     * Validate that the artifact's content hash matches the expected hash.
     *
     * @throws ContentHashMismatchException if hash mismatch detected
     */
    public function validateContentHash(Artifact $artifact, string $expectedHash): void
    {
        $currentHash = $artifact->content_hash;

        if (! hash_equals($currentHash, $expectedHash)) {
            throw new ContentHashMismatchException(
                "Artifact content was modified by another process. Current hash: {$currentHash}, Expected: {$expectedHash}"
            );
        }
    }

    /**
     * Append content to the end of a artifact.
     *
     * @throws ContentHashMismatchException if content was modified by another process
     */
    public function appendContent(Artifact $artifact, string $content, string $contentHash): Artifact
    {
        return DB::transaction(function () use ($artifact, $content, $contentHash) {
            // Validate hash before making changes
            $this->validateContentHash($artifact, $contentHash);

            // Create version before modifying content
            try {
                $artifact->createVersion();
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle duplicate version constraint violation gracefully
                if ($e->errorInfo[1] === 1062) { // MySQL duplicate entry error
                    Log::warning('ArtifactEditor: Duplicate version detected during append', [
                        'artifact_id' => $artifact->id,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    throw $e;
                }
            }

            // Append content
            $artifact->content = ($artifact->content ?? '').($content ?? '');
            $artifact->save();

            return $artifact->fresh();
        });
    }

    /**
     * Insert content at a specific character position in the artifact.
     *
     * @throws ContentHashMismatchException if content was modified by another process
     */
    public function insertContent(Artifact $artifact, int $position, string $content, string $contentHash): Artifact
    {
        return DB::transaction(function () use ($artifact, $position, $content, $contentHash) {
            // Validate hash before making changes
            $this->validateContentHash($artifact, $contentHash);

            // Create version before modifying content
            try {
                $artifact->createVersion();
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle duplicate version constraint violation gracefully
                if ($e->errorInfo[1] === 1062) { // MySQL duplicate entry error
                    Log::warning('ArtifactEditor: Duplicate version detected during insert', [
                        'artifact_id' => $artifact->id,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    throw $e;
                }
            }

            // Insert content at position
            $currentContent = $artifact->content ?? '';
            $before = mb_substr($currentContent, 0, $position);
            $after = mb_substr($currentContent, $position);
            $artifact->content = $before.($content ?? '').$after;
            $artifact->save();

            return $artifact->fresh();
        });
    }

    /**
     * Apply multiple patches to artifact content.
     *
     * @param  array<array{start: int, end: int, content: string, range_hash?: string}>  $patches  Patch definitions with start/end positions
     *
     * @throws ContentHashMismatchException if overall content or any range was modified
     */
    public function patchContent(Artifact $artifact, array $patches, string $contentHash): Artifact
    {
        return DB::transaction(function () use ($artifact, $patches, $contentHash) {
            // Validate overall content hash before making changes
            $this->validateContentHash($artifact, $contentHash);

            // Validate each patch's range hash
            foreach ($patches as $index => $patch) {
                $start = $patch['start'];
                $end = $patch['end'];
                $expectedRangeHash = $patch['range_hash'] ?? null;

                if ($expectedRangeHash) {
                    $currentRangeHash = $artifact->calculateRangeHash($start, $end);

                    if (! hash_equals($currentRangeHash, $expectedRangeHash)) {
                        throw new ContentHashMismatchException(
                            "Range hash mismatch for patch {$index}. Range {$start}-{$end} was modified. Current: {$currentRangeHash}, Expected: {$expectedRangeHash}"
                        );
                    }
                }
            }

            // Create version before modifying content
            try {
                $artifact->createVersion();
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle duplicate version constraint violation gracefully
                if ($e->errorInfo[1] === 1062) { // MySQL duplicate entry error
                    Log::warning('ArtifactEditor: Duplicate version detected during patch', [
                        'artifact_id' => $artifact->id,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    throw $e;
                }
            }

            // Sort patches by start position in descending order to avoid position shifts
            usort($patches, fn ($a, $b) => $b['start'] <=> $a['start']);

            // Apply all patches
            $currentContent = $artifact->content ?? '';
            foreach ($patches as $patch) {
                $start = $patch['start'];
                $end = $patch['end'];
                $newContent = $patch['content'] ?? '';

                // Replace range with new content
                $before = mb_substr($currentContent, 0, $start);
                $after = mb_substr($currentContent, $end);
                $currentContent = $before.$newContent.$after;
            }

            $artifact->content = $currentContent;
            $artifact->save();

            return $artifact->fresh();
        });
    }
}
