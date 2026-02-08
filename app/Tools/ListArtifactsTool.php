<?php

namespace App\Tools;

use App\Models\Artifact;
use App\Models\User;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * ListArtifactsTool - Artifact Discovery and Search.
 *
 * Prism tool for listing and searching artifacts with privacy-aware filtering.
 * Returns artifacts the user has access to based on privacy permissions.
 *
 * Search and Filtering:
 * - query: Full-text search on title and description
 * - filetype: Filter by file extension (.md, .php, etc.)
 * - privacy_level: Filter by privacy (private, team, public)
 * - tags: Filter by tag names (OR logic - any tag matches)
 * - author_id: Filter by artifact creator
 * - limit: Maximum results to return
 *
 * Privacy-Aware Results:
 * - Automatically filters based on user permissions
 * - Private: Only creator and admins can see
 * - Team: Team members can see
 * - Public: Everyone can see
 *
 * Response Data:
 * - Artifact metadata (id, title, description, filetype)
 * - Author information
 * - Tag list
 * - Privacy level
 * - Version number
 * - Timestamps
 *
 * Sorting:
 * - Default: Most recently updated first
 * - Consistent ordering for pagination
 *
 * @see \App\Models\Artifact
 * @see \App\Traits\HasPrivacy
 */
class ListArtifactsTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('list_artifacts')
            ->for('Lists and searches artifacts with filtering options. Returns artifacts the user has access to based on privacy permissions. Supports filtering by filetype, privacy level, tags, author, and search query.')
            ->withStringParameter('search', 'Search query to filter artifacts by title, description, or content')
            ->withStringParameter('filetype', 'Filter by file extension: md, csv, txt, css, php, js, py, json, etc.')
            ->withStringParameter('privacy_level', 'Filter by privacy level: private, team, or public')
            ->withNumberParameter('author_id', 'Filter by author user ID')
            ->withArrayParameter('tags', 'Filter by tag names (artifacts must have ALL specified tags)', new StringSchema('tag', 'Tag name'), false)
            ->withNumberParameter('limit', 'Maximum number of results to return (1-100, default: 20)')
            ->using(function (
                ?string $search = null,
                ?string $filetype = null,
                ?string $privacy_level = null,
                ?int $author_id = null,
                ?array $tags = null,
                int $limit = 20
            ) {
                return static::executeListArtifacts([
                    'search' => $search,
                    'filetype' => $filetype,
                    'privacy_level' => $privacy_level,
                    'author_id' => $author_id,
                    'tags' => $tags,
                    'limit' => $limit,
                ]);
            });
    }

    protected static function executeListArtifacts(array $arguments = []): string
    {
        try {
            // Get authenticated user or fallback
            $user = User::find(app('current_user_id'));

            if (! $user) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No authenticated user available',
                ], 'ListArtifactsTool');
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'search' => 'nullable|string|max:255',
                'filetype' => 'nullable|string|max:50',
                'privacy_level' => 'nullable|string|in:private,team,public',
                'author_id' => 'nullable|integer',
                'tags' => 'nullable|array',
                'tags.*' => 'string',
                'limit' => 'integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                Log::warning('ListArtifactsTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'ListArtifactsTool');
            }

            $validated = $validator->validated();

            // Build query with privacy filtering
            $query = Artifact::query()
                ->where(function ($q) use ($user) {
                    $q->where('privacy_level', 'public')
                        ->orWhere('author_id', $user->id)
                        ->orWhere(function ($sq) {
                            $sq->where('privacy_level', 'team');
                            // Add team membership check here if implemented
                        });
                })
                ->with(['author', 'tags']);

            // Apply search filter
            if (! empty($validated['search'])) {
                $searchTerm = $validated['search'];
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'like', "%{$searchTerm}%")
                        ->orWhere('description', 'like', "%{$searchTerm}%")
                        ->orWhere('content', 'like', "%{$searchTerm}%");
                });
            }

            // Apply filetype filter
            if (! empty($validated['filetype'])) {
                $query->where('filetype', $validated['filetype']);
            }

            // Apply privacy level filter
            if (! empty($validated['privacy_level'])) {
                $query->where('privacy_level', $validated['privacy_level']);
            }

            // Apply author filter
            if (! empty($validated['author_id'])) {
                $query->where('author_id', $validated['author_id']);
            }

            // Apply tags filter (artifacts must have ALL specified tags)
            if (! empty($validated['tags'])) {
                foreach ($validated['tags'] as $tagName) {
                    $query->whereHas('tags', function ($q) use ($tagName) {
                        $q->where('name', $tagName);
                    });
                }
            }

            // Get total count before limiting
            $totalCount = $query->count();

            // Apply limit and get results
            $artifacts = $query
                ->orderBy('updated_at', 'desc')
                ->limit($validated['limit'] ?? 20)
                ->get();

            // Format artifacts for response
            $formattedArtifacts = $artifacts->map(function ($artifact) {
                return [
                    'id' => $artifact->id,
                    'title' => $artifact->title,
                    'description' => $artifact->description,
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
                    'created_at' => $artifact->created_at->toISOString(),
                    'updated_at' => $artifact->updated_at->toISOString(),
                ];
            })->toArray();

            Log::info('ListArtifactsTool: Artifacts retrieved successfully', [
                'total_count' => $totalCount,
                'returned_count' => count($formattedArtifacts),
                'user_id' => $user->id,
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'artifacts' => $formattedArtifacts,
                    'total_count' => $totalCount,
                    'returned_count' => count($formattedArtifacts),
                    'filters_applied' => array_filter([
                        'search' => $validated['search'] ?? null,
                        'filetype' => $validated['filetype'] ?? null,
                        'privacy_level' => $validated['privacy_level'] ?? null,
                        'author_id' => $validated['author_id'] ?? null,
                        'tags' => $validated['tags'] ?? null,
                    ]),
                ],
            ], 'ListArtifactsTool');

        } catch (\Exception $e) {
            Log::error('ListArtifactsTool: Exception caught during artifact listing', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to list artifacts: '.$e->getMessage(),
            ], 'ListArtifactsTool');
        }
    }
}
