<?php

namespace App\Tools;

use App\Models\Artifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactManager;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * DeleteArtifactTool - Permanent Artifact Deletion with Confirmation Requirement.
 *
 * Prism tool for permanently deleting artifacts and all associated versions.
 * Requires explicit confirmation parameter to prevent accidental deletion.
 * Use with caution - this action is irreversible.
 *
 * Deletion Scope:
 * - Removes artifact record from database
 * - Deletes all version history (ArtifactVersion records)
 * - Removes tag associations (pivot table entries)
 * - Clears chat interaction references
 *
 * Authorization:
 * - Validates delete permissions via canDelete()
 * - Only artifact owner or admin can delete
 * - Respects privacy level restrictions
 *
 * Safety Features:
 * - Requires confirm=true parameter (prevents accidental deletion)
 * - Validates artifact existence before attempting delete
 * - Logs all deletion attempts for audit trail
 * - Returns detailed error messages for failures
 *
 * Integration:
 * - Uses ArtifactManager for deletion logic
 * - Cascading deletes handled by database constraints
 * - Status reporting for long-running operations
 *
 * @see \App\Services\Artifacts\ArtifactManager
 * @see \App\Models\Artifact
 */
class DeleteArtifactTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('delete_artifact')
            ->for('Deletes a artifact. This action removes the artifact and all its versions permanently. Requires explicit confirmation and delete permissions. Use with caution.')
            ->withNumberParameter('artifact_id', 'The ID of the artifact to delete', true)
            ->withBooleanParameter('confirm', 'Must be set to true to confirm deletion', true)
            ->using(function (
                int $artifact_id,
                bool $confirm
            ) {
                return static::executeDeleteArtifact([
                    'artifact_id' => $artifact_id,
                    'confirm' => $confirm,
                ]);
            });
    }

    protected static function executeDeleteArtifact(array $arguments = []): string
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
                Log::error('DeleteArtifactTool: No user ID in execution context', [
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('delete_artifact', 'Failed: No user context available', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No user context available',
                ], 'DeleteArtifactTool');
            }

            $user = User::find($userId);

            if (! $user) {
                Log::error('DeleteArtifactTool: User not found', [
                    'user_id' => $userId,
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('delete_artifact', "Failed: User {$userId} not found", true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "User not found with ID: {$userId}",
                ], 'DeleteArtifactTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'confirm' => 'required|boolean|accepted',
            ]);

            if ($validator->fails()) {
                Log::warning('DeleteArtifactTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'DeleteArtifactTool');
            }

            $validated = $validator->validated();

            // Require explicit confirmation
            if (! $validated['confirm']) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Deletion requires explicit confirmation. Set "confirm" parameter to true.',
                ], 'DeleteArtifactTool');
            }

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "Artifact not found with ID: {$validated['artifact_id']}",
                ], 'DeleteArtifactTool');
            }

            // Check delete permissions
            if (! $artifact->canDelete($user)) {
                Log::warning('DeleteArtifactTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'user_id' => $user->id,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Access denied: You do not have permission to delete this artifact',
                ], 'DeleteArtifactTool');
            }

            // Store title for response message
            $documentTitle = $artifact->title;
            $documentId = $artifact->id;

            // Report deletion start
            if ($statusReporter) {
                $statusReporter->report('delete_artifact', "Deleting artifact: {$documentTitle}", true, false);
            }

            // Track artifact deletion in chat interaction BEFORE deletion
            if ($interactionId) {
                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $documentId,
                    'deleted',
                    'delete_artifact',
                    "Deleted artifact: {$documentTitle}",
                    [
                        'title' => $documentTitle,
                        'filetype' => $artifact->filetype,
                    ]
                );

                Log::info('DeleteArtifactTool: Artifact deletion tracked in chat interaction', [
                    'artifact_id' => $documentId,
                    'interaction_id' => $interactionId,
                ]);
            }

            // Delete artifact using ArtifactManager
            $documentManager = app(ArtifactManager::class);
            $documentManager->delete($artifact);

            Log::info('DeleteArtifactTool: Artifact deleted successfully', [
                'artifact_id' => $documentId,
                'document_title' => $documentTitle,
                'user_id' => $user->id,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Report successful deletion
            if ($statusReporter) {
                $statusReporter->report('delete_artifact', "✅ Deleted artifact: {$documentTitle}", true, true);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'message' => "Artifact '{$documentTitle}' (ID: {$documentId}) deleted successfully",
                    'deleted_artifact_id' => $documentId,
                ],
            ], 'DeleteArtifactTool');

        } catch (\Exception $e) {
            Log::error('DeleteArtifactTool: Exception caught during artifact deletion', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId ?? null,
                'execution_id' => $executionId ?? null,
            ]);

            if ($statusReporter ?? null) {
                $statusReporter->report('delete_artifact', '❌ Failed to delete artifact', true, true);
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to delete artifact: '.$e->getMessage(),
            ], 'DeleteArtifactTool');
        }
    }
}
