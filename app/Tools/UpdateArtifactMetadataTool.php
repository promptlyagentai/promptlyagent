<?php

namespace App\Tools;

use App\Models\Artifact;
use App\Models\StatusStream;
use App\Models\User;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * UpdateArtifactMetadataTool - Metadata-Only Updates Without Versioning.
 *
 * Prism tool for updating artifact metadata (title, description, tags, filetype,
 * privacy_level) WITHOUT modifying content. Does NOT create version history or
 * require content_hash. Simple, safe for metadata changes only.
 *
 * Updatable Fields:
 * - title: Artifact display name
 * - description: Brief description/summary
 * - tags: Array of tag names (replaces existing tags)
 * - filetype: File extension (.md, .php, .js, etc.)
 * - privacy_level: private, team, or public
 *
 * No Content Changes:
 * - Does NOT modify artifact content
 * - Does NOT create version history
 * - Does NOT require content_hash
 * - Does NOT trigger content-related events
 *
 * Tag Management:
 * - Syncs tags (replaces all existing tags)
 * - Auto-creates missing tags
 * - Removes tags not in new list
 *
 * Authorization:
 * - Validates edit permissions via canEdit()
 * - Only artifact owner or admin can update
 *
 * Use Cases:
 * - Renaming artifacts
 * - Updating descriptions
 * - Changing privacy settings
 * - Managing tags
 * - Correcting filetype
 *
 * @see \App\Models\Artifact
 * @see \App\Tools\UpdateArtifactContentTool
 */
class UpdateArtifactMetadataTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('update_artifact_metadata')
            ->for('UPDATES artifact metadata ONLY (title, description, tags, filetype, privacy_level). Does NOT modify content. Does NOT create version history. Does NOT require content_hash. Simple, safe for metadata changes. For content changes, use append_artifact_content instead.')
            ->withNumberParameter('artifact_id', 'The artifact ID (REQUIRED)', true)
            ->withStringParameter('title', 'New artifact title (OPTIONAL)')
            ->withStringParameter('description', 'New artifact description (OPTIONAL)')
            ->withStringParameter('tags_json', 'JSON array of tag names: ["tag1","tag2"] (OPTIONAL)')
            ->withStringParameter('filetype', 'New file type/extension like .md, .txt (OPTIONAL)')
            ->withStringParameter('privacy_level', 'New privacy: private, team, or public (OPTIONAL)')
            ->using(function (
                int $artifact_id,
                ?string $title = null,
                ?string $description = null,
                ?string $tags_json = null,
                ?string $filetype = null,
                ?string $privacy_level = null
            ) {
                // Parse tags from JSON if provided
                $tags = null;
                if ($tags_json !== null) {
                    $tags = json_decode($tags_json, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'Invalid tags JSON: '.json_last_error_msg(),
                        ], 'UpdateArtifactMetadataTool');
                    }
                }

                return static::executeUpdateArtifactMetadata([
                    'artifact_id' => $artifact_id,
                    'title' => $title,
                    'description' => $description,
                    'tags' => $tags,
                    'filetype' => $filetype,
                    'privacy_level' => $privacy_level,
                ]);
            });
    }

    protected static function executeUpdateArtifactMetadata(array $arguments = []): string
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
                Log::error('Tool: No user context', ['tool' => basename('app/Tools/UpdateArtifactMetadataTool.php')]);

                return static::safeJsonEncode(['success' => false, 'error' => 'No user context available'], basename('app/Tools/UpdateArtifactMetadataTool.php'));
            }

            if (! $user) {
                Log::error('UpdateArtifactMetadataTool: No authenticated user', [
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No authenticated user available',
                ], 'UpdateArtifactMetadataTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:100',
                'filetype' => 'nullable|string|max:50',
                'privacy_level' => 'nullable|in:private,team,public',
            ]);

            if ($validator->fails()) {
                Log::warning('UpdateArtifactMetadataTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_metadata',
                        'Validation failed for artifact metadata update',
                        ['errors' => $validator->errors()->all()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'UpdateArtifactMetadataTool');
            }

            $validated = $validator->validated();

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                Log::warning('UpdateArtifactMetadataTool: Artifact not found', [
                    'artifact_id' => $validated['artifact_id'],
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_metadata',
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
                ], 'UpdateArtifactMetadataTool');
            }

            // Check write permissions
            if (! $artifact->canEdit($user)) {
                Log::warning('UpdateArtifactMetadataTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'user_id' => $user->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_metadata',
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
                ], 'UpdateArtifactMetadataTool');
            }

            // Prepare update data (only include provided fields)
            // Filter out null AND empty string values to prevent database errors
            $updateData = array_filter([
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
                'filetype' => $validated['filetype'] ?? null,
                'privacy_level' => $validated['privacy_level'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            // Check if there's anything to update
            if (empty($updateData) && ! isset($validated['tags'])) {
                Log::warning('UpdateArtifactMetadataTool: No fields provided', [
                    'artifact_id' => $artifact->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'update_artifact_metadata',
                        'No metadata fields provided for update',
                        ['artifact_id' => $artifact->id],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No metadata fields provided for update',
                ], 'UpdateArtifactMetadataTool');
            }

            // Report starting operation
            if ($interactionId) {
                $fieldsToUpdate = array_merge(
                    array_keys($updateData),
                    isset($validated['tags']) ? ['tags'] : []
                );

                StatusStream::report(
                    $interactionId,
                    'update_artifact_metadata',
                    "Updating metadata for: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'fields_updating' => $fieldsToUpdate,
                    ],
                    true,
                    false,
                    $executionId
                );
            }

            // Update artifact metadata (no version creation - metadata only)
            if (! empty($updateData)) {
                $artifact->update($updateData);
            }

            // Update tags if provided
            if (isset($validated['tags'])) {
                $artifact->syncTagsByName($validated['tags']);
            }

            // Refresh to get updated data
            $artifact = $artifact->fresh(['tags', 'author']);

            // Prepare response
            $responseData = [
                'id' => $artifact->id,
                'title' => $artifact->title,
                'description' => $artifact->description,
                'filetype' => $artifact->filetype,
                'privacy_level' => $artifact->privacy_level,
                'tags' => $artifact->tags->pluck('name')->toArray(),
                'version' => $artifact->version,
                'updated_at' => $artifact->updated_at->toISOString(),
            ];

            Log::info('UpdateArtifactMetadataTool: Metadata updated successfully', [
                'artifact_id' => $artifact->id,
                'user_id' => $user->id,
                'updated_fields' => array_keys($updateData),
                'tags_updated' => isset($validated['tags']),
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Track artifact modification in chat interaction if we have an interaction ID
            if ($interactionId) {
                $fieldsUpdated = array_merge(
                    array_keys($updateData),
                    isset($validated['tags']) ? ['tags'] : []
                );

                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $artifact->id,
                    'modified',
                    'update_artifact_metadata',
                    "Updated metadata for: {$artifact->title}",
                    [
                        'fields_updated' => $fieldsUpdated,
                        'title' => $artifact->title,
                        'filetype' => $artifact->filetype,
                    ]
                );
            }

            // Report success
            if ($interactionId) {
                $fieldsUpdated = array_merge(
                    array_keys($updateData),
                    isset($validated['tags']) ? ['tags'] : []
                );

                StatusStream::report(
                    $interactionId,
                    'update_artifact_metadata',
                    "✅ Updated metadata for: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'fields_updated' => $fieldsUpdated,
                    ],
                    true,
                    true, // Mark as significant
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => true,
                'message' => 'Artifact metadata updated successfully. No version history created (metadata only).',
                'data' => [
                    'artifact' => $responseData,
                ],
            ], 'UpdateArtifactMetadataTool');

        } catch (\Exception $e) {
            Log::error('UpdateArtifactMetadataTool: Exception caught', [
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
                    'update_artifact_metadata',
                    '❌ Failed to update artifact metadata',
                    ['error' => $e->getMessage(), 'error_type' => get_class($e)],
                    true,
                    true,
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to update artifact metadata: '.$e->getMessage(),
            ], 'UpdateArtifactMetadataTool');
        }
    }
}
