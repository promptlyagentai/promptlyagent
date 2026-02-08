<?php

namespace App\Tools;

use App\Models\Artifact;
use App\Models\User;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * ReadArtifactTool - Artifact Retrieval with Content Hash for Concurrency Control.
 *
 * Prism tool for reading artifact content with automatic content_hash generation.
 * MUST be called before any content modification to obtain the hash required for
 * optimistic locking in update/patch/append operations.
 *
 * Response Structure:
 * - content_hash at root level for easy extraction
 * - Full artifact details including metadata, tags, versions
 * - Word count and reading time estimates
 * - Privacy level and ownership information
 *
 * Content Hash:
 * - SHA256 hash of current content
 * - Required parameter for all content modification tools
 * - Prevents race conditions when multiple agents edit same artifact
 * - Hash mismatch triggers retry hint in modification tools
 *
 * Optional Features:
 * - Version history inclusion (include_versions parameter)
 * - Authorization checking (validates read permissions)
 * - Status reporting integration
 *
 * Use Cases:
 * - Pre-modification content retrieval
 * - Artifact inspection and analysis
 * - Version history review
 * - Content hash acquisition for concurrent edits
 *
 * @see \App\Models\Artifact
 * @see \App\Tools\UpdateArtifactContentTool
 * @see \App\Tools\PatchArtifactContentTool
 */
class ReadArtifactTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('read_artifact')
            ->for('Reads a artifact and returns content WITH content_hash at root level for easy access. YOU MUST call this BEFORE any content modification to get the content_hash. Returns JSON with success=true, content_hash="...", content_length=N, artifact={full details}. Extract content_hash directly from root level for append/insert/patch operations.')
            ->withNumberParameter('artifact_id', 'The ID of the artifact to retrieve', true)
            ->withBooleanParameter('include_versions', 'Include version history (default: false)')
            ->using(function (
                int $artifact_id,
                bool $include_versions = false
            ) {
                return static::executeReadArtifact([
                    'artifact_id' => $artifact_id,
                    'include_versions' => $include_versions,
                ]);
            });
    }

    protected static function executeReadArtifact(array $arguments = []): string
    {
        try {
            // Get status reporter and interaction ID with fallback strategy
            $statusReporter = null;
            $interactionId = null;
            $executionId = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
                $executionId = $statusReporter->getAgentExecutionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            // Get user from execution context
            $userId = app()->has('current_user_id') ? app('current_user_id') : null;

            if (! $userId) {
                Log::error('ReadArtifactTool: No user ID in execution context', [
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No user context available',
                ], 'ReadArtifactTool');
            }

            $user = User::find($userId);

            if (! $user) {
                Log::error('ReadArtifactTool: User not found', [
                    'user_id' => $userId,
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "User not found with ID: {$userId}",
                ], 'ReadArtifactTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'include_versions' => 'boolean',
            ]);

            if ($validator->fails()) {
                Log::warning('ReadArtifactTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'ReadArtifactTool');
            }

            $validated = $validator->validated();

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "Artifact not found with ID: {$validated['artifact_id']}",
                ], 'ReadArtifactTool');
            }

            // Check access permissions
            if (! $artifact->canAccess($user)) {
                Log::warning('ReadArtifactTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'document_author_id' => $artifact->author_id,
                    'document_created_by' => $artifact->created_by ?? 'NULL',
                    'document_privacy_level' => $artifact->privacy_level,
                    'user_id' => $user->id,
                    'user_is_admin' => $user->isAdmin(),
                    'comparison_author_id_equals_user_id' => $artifact->author_id === $user->id,
                    'comparison_created_by_equals_user_id' => ($artifact->created_by ?? null) === $user->id,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Access denied: You do not have permission to read this artifact',
                ], 'ReadArtifactTool');
            }

            // Prepare response data
            $responseData = [
                'id' => $artifact->id,
                'title' => $artifact->title,
                'description' => $artifact->description,
                'content' => $artifact->content,
                'content_hash' => $artifact->content_hash, // For concurrency control
                'content_length' => $artifact->content_length, // Character count
                'filetype' => $artifact->filetype,
                'privacy_level' => $artifact->privacy_level,
                'version' => $artifact->version,
                'author' => [
                    'id' => $artifact->author->id,
                    'name' => $artifact->author->name,
                ],
                'word_count' => $artifact->word_count,
                'reading_time' => $artifact->reading_time,
                'tags' => $artifact->tags->pluck('name')->toArray(),
                'metadata' => $artifact->metadata ?? [],
                'created_at' => $artifact->created_at->toISOString(),
                'updated_at' => $artifact->updated_at->toISOString(),
            ];

            // Include versions if requested
            if ($validated['include_versions']) {
                $responseData['versions'] = $artifact->versions->map(function ($version) {
                    return [
                        'version' => $version->version,
                        'created_at' => $version->created_at->toISOString(),
                        'created_by' => $version->creator?->name ?? 'Unknown',
                        'changes_summary' => $version->changes_summary,
                    ];
                })->toArray();
            }

            // Track artifact read in chat interaction if we have an interaction ID
            if ($interactionId) {
                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $artifact->id,
                    'referenced',
                    'read_artifact',
                    "Referenced artifact: {$artifact->title}",
                    [
                        'title' => $artifact->title,
                        'filetype' => $artifact->filetype,
                        'include_versions' => $validated['include_versions'],
                    ]
                );

                Log::info('ReadArtifactTool: Artifact reference tracked in chat interaction', [
                    'artifact_id' => $artifact->id,
                    'interaction_id' => $interactionId,
                ]);
            }

            Log::info('ReadArtifactTool: Artifact retrieved successfully', [
                'artifact_id' => $artifact->id,
                'user_id' => $user->id,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'content_hash' => $artifact->content_hash, // ← Critical field at root for easy access
                'content_length' => $artifact->content_length, // ← Also critical
                'artifact' => $responseData, // ← Full details still available
            ], 'ReadArtifactTool');

        } catch (\Exception $e) {
            Log::error('ReadArtifactTool: Exception caught during artifact retrieval', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId ?? null,
                'execution_id' => $executionId ?? null,
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to read artifact: '.$e->getMessage(),
            ], 'ReadArtifactTool');
        }
    }
}
