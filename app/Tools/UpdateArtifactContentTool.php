<?php

namespace App\Tools;

use App\Models\Artifact;
use App\Models\StatusStream;
use App\Models\User;
use App\Tools\Concerns\SafeJsonResponse;
use App\Tools\Concerns\SanitizesArtifactContent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * UpdateArtifactContentTool - Full Content Replacement with Concurrency Control.
 *
 * Prism tool for replacing entire artifact content with new version. Preferred
 * for broader changes or small artifacts. Simpler than patch_artifact_content -
 * just provide complete new content.
 *
 * Concurrency Control:
 * - Requires content_hash from read_artifact to prevent conflicts
 * - Validates hash before applying changes (optimistic locking)
 * - Returns retry hint if hash mismatch detected
 * - Prevents race conditions when multiple agents edit same artifact
 *
 * Workflow:
 * 1. read_artifact to get current content and content_hash
 * 2. Modify content as needed
 * 3. Call update_artifact_content with complete new content + hash
 * 4. Creates version history automatically before update
 *
 * Use Cases:
 * - Making changes across multiple sections
 * - Artifact is small (<500 lines)
 * - Rewriting significant portions
 * - When patch operations would be too complex
 *
 * Version History:
 * - Automatically creates ArtifactVersion before modifying
 * - Gracefully handles duplicate version constraints
 * - Version number incremented after successful update
 *
 * Content Sanitization:
 * - Removes NULL bytes and control characters
 * - Ensures JSON-encodable output
 * - Prevents UTF-8 encoding errors
 *
 * @see \App\Services\Artifacts\ArtifactEditor
 * @see \App\Models\Artifact
 * @see \App\Tools\Concerns\SanitizesArtifactContent
 */
class UpdateArtifactContentTool
{
    use SafeJsonResponse, SanitizesArtifactContent;

    public static function create()
    {
        return Tool::as('update_artifact_content')
            ->for('REPLACES entire artifact content with new content. PREFERRED for broader changes or small artifacts. SIMPLER than patch_artifact_content - just provide the complete new content. Use when: (1) Making changes across multiple sections, (2) Artifact is small (<500 lines), (3) Rewriting significant portions. WORKFLOW: (1) read_artifact for content and content_hash, (2) Modify content as needed, (3) Call with complete new content. Creates version history automatically.')
            ->withNumberParameter('artifact_id', 'The artifact ID (REQUIRED)', true)
            ->withStringParameter('content', static::getContentParameterDescription('The complete new content for the artifact'), true)
            ->withStringParameter('content_hash', 'The content_hash from read_artifact to prevent conflicts (REQUIRED)', true)
            ->using(function (
                int $artifact_id,
                string $content,
                string $content_hash
            ) {
                // Sanitize content to remove/replace control characters that break JSON encoding
                $sanitizedContent = static::sanitizeContent($content);

                return static::executeUpdateArtifactContent([
                    'artifact_id' => $artifact_id,
                    'content' => $sanitizedContent,
                    'content_hash' => $content_hash,
                ]);
            });
    }

    protected static function executeUpdateArtifactContent(array $arguments = []): string
    {
        // Get StatusReporter for interaction tracking
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;
        $interactionId = $statusReporter ? $statusReporter->getInteractionId() : null;
        $executionId = $statusReporter ? $statusReporter->getAgentExecutionId() : null;

        try {
            // Get authenticated user or fallback
            $user = User::find(app('current_user_id'));

            if (! $user) {
                Log::error('UpdateArtifactContentTool: No authenticated user', [
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No authenticated user available',
                ], 'UpdateArtifactContentTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'content' => 'required|string',
                'content_hash' => 'required|string|size:64',
            ]);

            if ($validator->fails()) {
                Log::warning('UpdateArtifactContentTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_content',
                        'Validation failed for artifact update',
                        ['errors' => $validator->errors()->all()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'UpdateArtifactContentTool');
            }

            $validated = $validator->validated();

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                Log::warning('UpdateArtifactContentTool: Artifact not found', [
                    'artifact_id' => $validated['artifact_id'],
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_content',
                        "Artifact not found (ID: {$validated['artifact_id']})",
                        ['artifact_id' => $validated['artifact_id']],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "Artifact not found with ID: {$validated['artifact_id']}",
                ], 'UpdateArtifactContentTool');
            }

            // Check write permissions
            if (! $artifact->canEdit($user)) {
                Log::warning('UpdateArtifactContentTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'user_id' => $user->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_content',
                        "Access denied for artifact: {$artifact->title}",
                        ['artifact_id' => $artifact->id],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Access denied: You do not have permission to modify this artifact',
                ], 'UpdateArtifactContentTool');
            }

            // Verify content hash to prevent conflicts
            $currentHash = hash('sha256', $artifact->content ?? '');
            if ($currentHash !== $validated['content_hash']) {
                Log::warning('UpdateArtifactContentTool: Content hash mismatch', [
                    'artifact_id' => $artifact->id,
                    'expected_hash' => $validated['content_hash'],
                    'actual_hash' => $currentHash,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_content',
                        'Artifact was modified - hash mismatch detected',
                        ['artifact_id' => $artifact->id],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Content hash mismatch: Artifact was modified since last read. Use read_artifact again to get latest content_hash.',
                    'retry_suggested' => true,
                    'hint' => 'Use read_artifact again to get the latest content_hash, then retry',
                ], 'UpdateArtifactContentTool');
            }

            // Report starting operation
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'update_artifact_content',
                    "Updating content for: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'old_length' => $artifact->content_length,
                        'new_length' => strlen($validated['content']),
                    ],
                    true,
                    false,
                    $executionId
                );
            }

            // Create version before modifying content
            try {
                $artifact->createVersion();
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle duplicate version constraint violation gracefully
                if ($e->errorInfo[1] === 1062) { // MySQL duplicate entry error
                    Log::warning('UpdateArtifactContentTool: Duplicate version detected', [
                        'artifact_id' => $artifact->id,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    throw $e;
                }
            }

            // Update the artifact content
            $artifact->update(['content' => $validated['content']]);
            $artifact->refresh();

            // Prepare response
            $responseData = [
                'id' => $artifact->id,
                'title' => $artifact->title,
                'content_hash' => $artifact->content_hash,
                'content_length' => $artifact->content_length,
                'version' => $artifact->version,
                'updated_at' => $artifact->updated_at->toISOString(),
            ];

            Log::info('UpdateArtifactContentTool: Content updated successfully', [
                'artifact_id' => $artifact->id,
                'user_id' => $user->id,
                'old_length' => strlen($validated['content_hash']),
                'new_length' => $artifact->content_length,
                'new_version' => $artifact->version,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Track artifact modification in chat interaction
            if ($interactionId) {
                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $artifact->id,
                    'modified',
                    'update_artifact_content',
                    "Updated content for: {$artifact->title}",
                    [
                        'title' => $artifact->title,
                        'filetype' => $artifact->filetype,
                        'new_length' => $artifact->content_length,
                    ]
                );
            }

            // Report success
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'update_artifact_content',
                    "✅ Updated content for: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'new_length' => $artifact->content_length,
                        'version' => $artifact->version,
                    ],
                    true,
                    true, // Mark as significant
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => true,
                'message' => 'Artifact content updated successfully. Version history automatically created.',
                'data' => [
                    'artifact' => $responseData,
                ],
            ], 'UpdateArtifactContentTool');

        } catch (\Exception $e) {
            Log::error('UpdateArtifactContentTool: Exception caught', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'update_artifact_content',
                    '❌ Failed to update artifact content',
                    ['error' => $e->getMessage(), 'error_type' => get_class($e)],
                    true,
                    true,
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to update artifact content: '.$e->getMessage(),
            ], 'UpdateArtifactContentTool');
        }
    }
}
