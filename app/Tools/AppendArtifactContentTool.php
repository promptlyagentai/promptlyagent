<?php

namespace App\Tools;

use App\Exceptions\ContentHashMismatchException;
use App\Models\Artifact;
use App\Models\StatusStream;
use App\Models\User;
use App\Services\Artifacts\ArtifactEditor;
use App\Tools\Concerns\SafeJsonResponse;
use App\Tools\Concerns\SanitizesArtifactContent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * AppendArtifactContentTool - Content Appending with Concurrency Control.
 *
 * Prism tool for adding content to the end of existing artifacts. Simplest method
 * for extending artifacts - just provide new content to append. Requires content_hash
 * for optimistic locking to prevent race conditions.
 *
 * Workflow:
 * 1. read_artifact to get content_hash
 * 2. Call append_artifact_content with new content + hash
 * 3. Content appended to end automatically
 * 4. Version history created before modification
 *
 * Concurrency Control:
 * - Requires content_hash from read_artifact
 * - Validates hash before appending (optimistic locking)
 * - Returns retry hint if hash mismatch detected
 *
 * Content Sanitization:
 * - Removes NULL bytes and control characters
 * - Ensures JSON-encodable output
 * - Prevents UTF-8 encoding errors
 *
 * Use Cases:
 * - Adding new sections to documents
 * - Appending log entries
 * - Extending code files
 * - Adding notes or updates
 *
 * @see \App\Services\Artifacts\ArtifactEditor
 * @see \App\Tools\UpdateArtifactContentTool
 * @see \App\Tools\Concerns\SanitizesArtifactContent
 */
class AppendArtifactContentTool
{
    use SafeJsonResponse, SanitizesArtifactContent;

    public static function create()
    {
        return Tool::as('append_artifact_content')
            ->for('APPENDS content to the END of a artifact. This is the PRIMARY tool for adding content. WORKFLOW: (1) Call read_artifact to get content_hash, (2) Call this tool with artifact_id, your new content, and the content_hash. The tool handles everything automatically and creates version history. Safe, reliable, and preferred for adding content.')
            ->withNumberParameter('artifact_id', 'The artifact ID to append to (REQUIRED)', true)
            ->withStringParameter('content', static::getContentParameterDescription('The new content to add at the end'), true)
            ->withStringParameter('content_hash', 'The content_hash from read_artifact response (REQUIRED for safety)', true)
            ->using(function (
                int $artifact_id,
                string $content,
                string $content_hash
            ) {
                // Sanitize content to remove/replace control characters that break JSON encoding
                $sanitizedContent = static::sanitizeContent($content);

                return static::executeAppendArtifactContent([
                    'artifact_id' => $artifact_id,
                    'content' => $sanitizedContent,
                    'content_hash' => $content_hash,
                ]);
            });
    }

    protected static function executeAppendArtifactContent(array $arguments = []): string
    {
        // Get StatusReporter for interaction tracking
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;
        $interactionId = $statusReporter ? $statusReporter->getInteractionId() : null;
        $executionId = $statusReporter ? $statusReporter->getAgentExecutionId() : null;

        try {
            // Get authenticated user or fallback
            $user = User::find(app('current_user_id'));

            $userId = app()->has('current_user_id') ? app('current_user_id') : null;
            if (! $userId || ! $user) {
                Log::error('Tool: No user context', ['tool' => basename('app/Tools/AppendArtifactContentTool.php')]);

                return static::safeJsonEncode(['success' => false, 'error' => 'No user context available'], basename('app/Tools/AppendArtifactContentTool.php'));
            }

            if (! $user) {
                Log::error('AppendArtifactContentTool: No authenticated user', [
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No authenticated user available',
                ], 'AppendArtifactContentTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'content' => 'required|string',
                'content_hash' => 'required|string|size:64', // SHA-256 hash is 64 characters
            ]);

            if ($validator->fails()) {
                Log::warning('AppendArtifactContentTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'append_artifact_content',
                        'Validation failed for artifact append',
                        ['errors' => $validator->errors()->all()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'AppendArtifactContentTool');
            }

            $validated = $validator->validated();

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                Log::warning('AppendArtifactContentTool: Artifact not found', [
                    'artifact_id' => $validated['artifact_id'],
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'append_artifact_content',
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
                ], 'AppendArtifactContentTool');
            }

            // Check write permissions
            if (! $artifact->canEdit($user)) {
                Log::warning('AppendArtifactContentTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'user_id' => $user->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'append_artifact_content',
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
                ], 'AppendArtifactContentTool');
            }

            // Report starting operation
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'append_artifact_content',
                    "Appending content to: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'content_length' => mb_strlen($validated['content']),
                    ],
                    true,
                    false,
                    $executionId
                );
            }

            // Append content using ArtifactEditor service
            $documentEditor = new ArtifactEditor;

            try {
                $updatedDocument = $documentEditor->appendContent(
                    $artifact,
                    $validated['content'],
                    $validated['content_hash']
                );
            } catch (ContentHashMismatchException $e) {
                Log::warning('AppendArtifactContentTool: Hash mismatch', [
                    'artifact_id' => $artifact->id,
                    'error' => $e->getMessage(),
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'append_artifact_content',
                        'Artifact was modified - hash mismatch detected',
                        ['artifact_id' => $artifact->id, 'error' => $e->getMessage()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'retry_suggested' => true,
                    'hint' => 'Use read_artifact again to get the latest content_hash and retry',
                ], 'AppendArtifactContentTool');
            }

            // Prepare response
            $responseData = [
                'id' => $updatedDocument->id,
                'title' => $updatedDocument->title,
                'content_hash' => $updatedDocument->content_hash, // New hash after append
                'content_length' => $updatedDocument->content_length,
                'version' => $updatedDocument->version,
                'updated_at' => $updatedDocument->updated_at->toISOString(),
            ];

            Log::info('AppendArtifactContentTool: Content appended successfully', [
                'artifact_id' => $updatedDocument->id,
                'user_id' => $user->id,
                'content_length_added' => mb_strlen($validated['content']),
                'new_total_length' => $updatedDocument->content_length,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Track artifact modification in chat interaction
            if ($interactionId) {
                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $updatedDocument->id,
                    'modified',
                    'append_artifact_content',
                    "Appended content to: {$updatedDocument->title}",
                    [
                        'content_length_added' => mb_strlen($validated['content']),
                        'title' => $updatedDocument->title,
                        'filetype' => $updatedDocument->filetype,
                    ]
                );
            }

            // Report success
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'append_artifact_content',
                    "✅ Content appended to: {$updatedDocument->title}",
                    [
                        'artifact_id' => $updatedDocument->id,
                        'content_added' => mb_strlen($validated['content']),
                        'new_length' => $updatedDocument->content_length,
                        'version' => $updatedDocument->version,
                    ],
                    true,
                    true, // Mark as significant
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => true,
                'message' => 'Content appended successfully. Version history automatically created.',
                'data' => [
                    'artifact' => $responseData,
                ],
            ], 'AppendArtifactContentTool');

        } catch (\Exception $e) {
            Log::error('AppendArtifactContentTool: Exception caught', [
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
                    'append_artifact_content',
                    '❌ Failed to append content',
                    ['error' => $e->getMessage(), 'error_type' => get_class($e)],
                    true,
                    true,
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to append content: '.$e->getMessage(),
            ], 'AppendArtifactContentTool');
        }
    }
}
