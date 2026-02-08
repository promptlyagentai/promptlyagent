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
 * InsertArtifactContentTool - Position-Based Content Insertion.
 *
 * Prism tool for inserting content at specific character position within artifacts.
 * Useful for adding content in the middle of documents. Requires content_hash for
 * concurrency control.
 *
 * Workflow:
 * 1. read_artifact to get content and content_hash
 * 2. Identify insertion position (character offset)
 * 3. Call insert_artifact_content with position + new content + hash
 * 4. Content inserted at specified position
 *
 * Position Calculation:
 * - Position is 0-indexed character offset
 * - Position 0 = beginning of content
 * - Position content_length = end of content (same as append)
 *
 * Concurrency Control:
 * - Requires content_hash from read_artifact
 * - Validates hash before inserting (optimistic locking)
 * - Returns retry hint if hash mismatch detected
 *
 * Content Sanitization:
 * - Removes NULL bytes and control characters
 * - Ensures JSON-encodable output
 * - Prevents UTF-8 encoding errors
 *
 * Use Cases:
 * - Adding sections to middle of documents
 * - Inserting imports/dependencies
 * - Adding method implementations
 *
 * @see \App\Services\Artifacts\ArtifactEditor
 * @see \App\Tools\AppendArtifactContentTool
 */
class InsertArtifactContentTool
{
    use SafeJsonResponse, SanitizesArtifactContent;

    public static function create()
    {
        return Tool::as('insert_artifact_content')
            ->for('INSERTS content at a SPECIFIC position in a artifact. Use this when you need to add content in the middle or beginning (not at the end - use append for that). WORKFLOW: (1) Call read_artifact to get content and content_hash, (2) Determine insertion position (character index, 0-based), (3) Call this tool. Creates version history automatically.')
            ->withNumberParameter('artifact_id', 'The artifact ID (REQUIRED)', true)
            ->withNumberParameter('position', 'Character position where to insert, 0-based (REQUIRED). Position 0 = start, content_length = end', true)
            ->withStringParameter('content', static::getContentParameterDescription('The content to insert'), true)
            ->withStringParameter('content_hash', 'The content_hash from read_artifact (REQUIRED)', true)
            ->using(function (
                int $artifact_id,
                int $position,
                string $content,
                string $content_hash
            ) {
                // Sanitize content to remove/replace control characters that break JSON encoding
                $sanitizedContent = static::sanitizeContent($content);

                return static::executeInsertArtifactContent([
                    'artifact_id' => $artifact_id,
                    'position' => $position,
                    'content' => $sanitizedContent,
                    'content_hash' => $content_hash,
                ]);
            });
    }

    protected static function executeInsertArtifactContent(array $arguments = []): string
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
                Log::error('Tool: No user context', ['tool' => basename('app/Tools/InsertArtifactContentTool.php')]);

                return static::safeJsonEncode(['success' => false, 'error' => 'No user context available'], basename('app/Tools/InsertArtifactContentTool.php'));
            }

            if (! $user) {
                Log::error('InsertArtifactContentTool: No authenticated user', [
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No authenticated user available',
                ], 'InsertArtifactContentTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'position' => 'required|integer|min:0',
                'content' => 'required|string',
                'content_hash' => 'required|string|size:64', // SHA-256 hash is 64 characters
            ]);

            if ($validator->fails()) {
                Log::warning('InsertArtifactContentTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'insert_artifact_content',
                        'Validation failed for artifact insert',
                        ['errors' => $validator->errors()->all()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'InsertArtifactContentTool');
            }

            $validated = $validator->validated();

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                Log::warning('InsertArtifactContentTool: Artifact not found', [
                    'artifact_id' => $validated['artifact_id'],
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'insert_artifact_content',
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
                ], 'InsertArtifactContentTool');
            }

            // Check write permissions
            if (! $artifact->canEdit($user)) {
                Log::warning('InsertArtifactContentTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'user_id' => $user->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'insert_artifact_content',
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
                ], 'InsertArtifactContentTool');
            }

            // Validate position is within bounds
            $contentLength = $artifact->content_length;
            if ($validated['position'] > $contentLength) {
                Log::warning('InsertArtifactContentTool: Position out of bounds', [
                    'position' => $validated['position'],
                    'content_length' => $contentLength,
                    'artifact_id' => $artifact->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'insert_artifact_content',
                        "Position {$validated['position']} exceeds content length {$contentLength}",
                        ['position' => $validated['position'], 'content_length' => $contentLength],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "Position {$validated['position']} is beyond content length {$contentLength}. Use append_artifact_content instead.",
                ], 'InsertArtifactContentTool');
            }

            // Report starting operation
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'insert_artifact_content',
                    "Inserting content at position {$validated['position']} in: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'position' => $validated['position'],
                        'content_length' => mb_strlen($validated['content']),
                    ],
                    true,
                    false,
                    $executionId
                );
            }

            // Insert content using ArtifactEditor service
            $documentEditor = new ArtifactEditor;

            try {
                $updatedDocument = $documentEditor->insertContent(
                    $artifact,
                    $validated['position'],
                    $validated['content'],
                    $validated['content_hash']
                );
            } catch (ContentHashMismatchException $e) {
                Log::warning('InsertArtifactContentTool: Hash mismatch', [
                    'artifact_id' => $artifact->id,
                    'error' => $e->getMessage(),
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'insert_artifact_content',
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
                ], 'InsertArtifactContentTool');
            }

            // Prepare response
            $responseData = [
                'id' => $updatedDocument->id,
                'title' => $updatedDocument->title,
                'content_hash' => $updatedDocument->content_hash, // New hash after insert
                'content_length' => $updatedDocument->content_length,
                'version' => $updatedDocument->version,
                'updated_at' => $updatedDocument->updated_at->toISOString(),
            ];

            Log::info('InsertArtifactContentTool: Content inserted successfully', [
                'artifact_id' => $updatedDocument->id,
                'user_id' => $user->id,
                'position' => $validated['position'],
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
                    'insert_artifact_content',
                    "Inserted content into: {$updatedDocument->title}",
                    [
                        'position' => $validated['position'],
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
                    'insert_artifact_content',
                    "✅ Content inserted at position {$validated['position']} in: {$updatedDocument->title}",
                    [
                        'artifact_id' => $updatedDocument->id,
                        'position' => $validated['position'],
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
                'message' => 'Content inserted successfully. Version history automatically created.',
                'data' => [
                    'artifact' => $responseData,
                ],
            ], 'InsertArtifactContentTool');

        } catch (\Exception $e) {
            Log::error('InsertArtifactContentTool: Exception caught', [
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
                    'insert_artifact_content',
                    '❌ Failed to insert content',
                    ['error' => $e->getMessage(), 'error_type' => get_class($e)],
                    true,
                    true,
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to insert content: '.$e->getMessage(),
            ], 'InsertArtifactContentTool');
        }
    }
}
