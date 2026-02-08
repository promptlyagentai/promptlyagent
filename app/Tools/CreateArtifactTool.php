<?php

namespace App\Tools;

use App\Models\User;
use App\Services\Artifacts\ArtifactManager;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * CreateArtifactTool - Agent-Invokable Artifact Creation.
 *
 * Prism tool for creating new artifacts in the document system. Enables agents
 * to save AI-generated content, code, reports, and analysis results with metadata,
 * tags, and privacy controls.
 *
 * Artifact Types:
 * - Markdown documents (.md)
 * - CSV data files (.csv)
 * - Code files (.php, .js, .py, .css, etc.)
 * - Configuration files (.json, .xml, .yaml)
 * - Plain text (.txt)
 *
 * Features:
 * - Automatic version history initialization
 * - Tag attachment with auto-creation
 * - Privacy level control (private/team/public)
 * - Chat interaction tracking
 * - User context resolution from execution environment
 *
 * Execution Context:
 * - User ID retrieved from app('current_user_id')
 * - Interaction ID from StatusReporter or fallback to current_interaction_id
 * - Status updates streamed via StatusReporter
 *
 * Integration:
 * - Uses ArtifactManager for creation logic
 * - Tracks artifacts in ChatInteractionArtifact pivot table
 * - Supports tag syncing via HasTags trait
 *
 * @see \App\Services\Artifacts\ArtifactManager
 * @see \App\Models\Artifact
 * @see \App\Tools\Concerns\SafeJsonResponse
 */
class CreateArtifactTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('create_artifact')
            ->for('Creates a new artifact in the Documents system. Use this to save AI-generated content, code artifacts, reports, analysis results, or any text-based artifacts. Supports various file types (.md, .csv, .txt, .css, .php, .js, etc.).')
            ->withStringParameter('title', 'The title of the artifact (required)', true)
            ->withStringParameter('description', 'A brief description of the artifact content (optional)')
            ->withStringParameter('content', 'The main content of the artifact (optional, can be added later)')
            ->withStringParameter('filetype', 'File extension/type: md, csv, txt, css, php, js, py, json, xml, etc. (optional)')
            ->withStringParameter('privacy_level', 'Privacy level for the artifact: private, team, or public (default: private)')
            ->withArrayParameter('tags', 'Array of tag names to attach to the artifact (optional)', new StringSchema('tag', 'Tag name'), false)
            ->using(function (
                string $title,
                ?string $description = null,
                ?string $content = null,
                ?string $filetype = null,
                string $privacy_level = 'private',
                array $tags = []
            ) {
                return static::executeCreateArtifact([
                    'title' => $title,
                    'description' => $description,
                    'content' => $content,
                    'filetype' => $filetype,
                    'privacy_level' => $privacy_level,
                    'tags' => $tags,
                ]);
            });
    }

    protected static function executeCreateArtifact(array $arguments = []): string
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

            // Fallback to agent execution context for interaction ID
            if ($statusReporter && ! $interactionId && app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
                Log::info('CreateArtifactTool: Retrieved interaction ID from current_interaction_id fallback', [
                    'interaction_id' => $interactionId,
                ]);
            }

            // Get user from execution context (not from auth)
            $userId = app()->has('current_user_id') ? app('current_user_id') : null;

            if (! $userId) {
                Log::error('CreateArtifactTool: No user ID in execution context', [
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_artifact', 'Failed: No user context available', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No user context available for artifact creation',
                ], 'CreateArtifactTool');
            }

            $user = User::find($userId);

            if (! $user) {
                Log::error('CreateArtifactTool: User not found', [
                    'user_id' => $userId,
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_artifact', "Failed: User {$userId} not found", true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "User not found with ID: {$userId}",
                ], 'CreateArtifactTool');
            }

            // Report meaningful start - mark as significant to render as timeline dot
            if ($statusReporter) {
                $statusReporter->report('create_artifact', "Creating artifact: {$arguments['title']}", true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'content' => 'nullable|string',
                'filetype' => 'nullable|string|max:50',
                'privacy_level' => 'nullable|string|in:private,team,public',
                'tags' => 'nullable|array',
                'tags.*' => 'string',
            ]);

            if ($validator->fails()) {
                Log::warning('CreateArtifactTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_artifact', 'Validation failed for artifact creation', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'CreateArtifactTool');
            }

            $validated = $validator->validated();
            $documentManager = app(ArtifactManager::class);

            // Prepare data for artifact creation
            $data = [
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'content' => $validated['content'] ?? null,
                'filetype' => $validated['filetype'] ?? null,
                'privacy_level' => $validated['privacy_level'] ?? 'private',
                'metadata' => [],
                'author_id' => $user->id,
            ];

            $artifact = $documentManager->create($data);

            // Attach tags if provided
            if (! empty($validated['tags'])) {
                $artifact->syncTags($validated['tags']);
            }

            // Track artifact in chat interaction if we have an interaction ID
            if ($interactionId) {
                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $artifact->id,
                    'created',
                    'create_artifact',
                    "Created artifact: {$artifact->title}",
                    [
                        'title' => $artifact->title,
                        'filetype' => $artifact->filetype,
                        'tags' => $validated['tags'] ?? [],
                    ]
                );

                Log::info('CreateArtifactTool: Artifact tracked in chat interaction', [
                    'artifact_id' => $artifact->id,
                    'interaction_id' => $interactionId,
                ]);
            }

            Log::info('CreateArtifactTool: Artifact created successfully', [
                'artifact_id' => $artifact->id,
                'title' => $artifact->title,
                'author_id' => $user->id,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Report successful creation
            if ($statusReporter) {
                $statusReporter->report('create_artifact', "✅ Created artifact: {$artifact->title}", true, true);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'artifact' => [
                        'id' => $artifact->id,
                        'title' => $artifact->title,
                        'description' => $artifact->description,
                        'filetype' => $artifact->filetype,
                        'privacy_level' => $artifact->privacy_level,
                        'version' => $artifact->version,
                        'author_id' => $artifact->author_id,
                        'word_count' => $artifact->word_count,
                        'reading_time' => $artifact->reading_time,
                        'tags' => $artifact->tags->pluck('name')->toArray(),
                        'created_at' => $artifact->created_at->toISOString(),
                        'updated_at' => $artifact->updated_at->toISOString(),
                    ],
                    'message' => "Artifact '{$artifact->title}' created successfully with ID {$artifact->id}",
                ],
            ], 'CreateArtifactTool');

        } catch (\Exception $e) {
            Log::error('CreateArtifactTool: Exception caught during artifact creation', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId ?? null,
                'execution_id' => $executionId ?? null,
            ]);

            if ($statusReporter ?? null) {
                $statusReporter->report('create_artifact', '❌ Failed to create artifact', true, true);
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to create artifact: '.$e->getMessage(),
            ], 'CreateArtifactTool');
        }
    }
}
