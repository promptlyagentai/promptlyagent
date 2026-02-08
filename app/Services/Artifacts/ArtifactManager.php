<?php

namespace App\Services\Artifacts;

use App\Models\Artifact;
use App\Models\ArtifactVersion;
use App\Models\User;
use App\Services\Assets\AssetService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * Artifact Manager - Artifact Lifecycle Management Service.
 *
 * Provides facade for complete artifact lifecycle including CRUD operations,
 * versioning, search/filtering, asset management, and statistics generation.
 * Coordinates between Artifact models, AssetService, and versioning system.
 *
 * Core Responsibilities:
 * - **CRUD Operations**: Create, update, delete artifacts with validation
 * - **Version Management**: Create and restore artifact versions
 * - **Search & Filtering**: Advanced search with tags, status, author, privacy filters
 * - **Asset Integration**: Attach and manage file assets with artifacts
 * - **Statistics**: Generate usage and status statistics
 * - **Access Control**: Enforce user-based access restrictions
 *
 * Search Filter Structure:
 * - status: Artifact status (published, draft, archived)
 * - author_id: Filter by author user ID
 * - privacy_level: Filter by privacy (public, private, team)
 * - tags: Array of tag names or single tag
 * - search: Text search across title, description, content
 * - user: User model for access control filtering
 *
 * @see \App\Models\Artifact
 * @see \App\Services\Assets\AssetService
 * @see \App\Models\ArtifactVersion
 */
class ArtifactManager
{
    public function __construct(
        private AssetService $assetService
    ) {}

    /**
     * Create a new artifact
     */
    public function create(array $data): Artifact
    {
        // Handle file upload if provided
        if (isset($data['file']) && $data['file'] instanceof UploadedFile) {
            $asset = $this->assetService->upload($data['file'], 'artifacts');
            $data['asset_id'] = $asset->id;
            unset($data['file']);
        }

        // Set default author if not provided
        if (! isset($data['author_id'])) {
            $data['author_id'] = auth()->id() ?: 1; // Fallback to first user

            if (! auth()->id()) {
                Log::warning('ArtifactManager: Fallback to first user as artifact author', [
                    'artifact_title' => $data['title'] ?? 'Untitled',
                ]);
            }
        }

        return Artifact::create($data);
    }

    /**
     * Update an existing artifact
     */
    public function update(Artifact $artifact, array $data): Artifact
    {
        // Create a version before updating if content changed
        if (isset($data['content']) && $data['content'] !== $artifact->content) {
            try {
                $artifact->createVersion();
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle duplicate version constraint violation gracefully
                if ($e->errorInfo[1] === 1062) { // MySQL duplicate entry error
                    \Log::warning('ArtifactManager: Duplicate version detected, skipping version creation', [
                        'artifact_id' => $artifact->id,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    throw $e;
                }
            }
        }

        // Handle file upload if provided
        if (isset($data['file']) && $data['file'] instanceof UploadedFile) {
            $asset = $this->assetService->upload($data['file'], 'artifacts');
            $data['asset_id'] = $asset->id;
            unset($data['file']);
        }

        $artifact->update($data);

        return $artifact->fresh();
    }

    /**
     * Delete a artifact
     *
     * @throws \Exception if deletion fails
     */
    public function delete(Artifact $artifact): bool
    {
        try {
            // Delete associated asset if exists
            if ($artifact->asset_id && $artifact->asset) {
                $this->assetService->delete($artifact->asset);
            }

            return $artifact->delete();
        } catch (\Exception $e) {
            Log::error('ArtifactManager: Failed to delete artifact', [
                'artifact_id' => $artifact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Create a new version of a artifact
     */
    public function createVersion(Artifact $artifact): ArtifactVersion
    {
        return $artifact->createVersion();
    }

    /**
     * Restore a artifact to a specific version
     */
    public function restoreVersion(Artifact $artifact, ArtifactVersion $version): Artifact
    {
        return $artifact->restoreVersion($version);
    }

    /**
     * Search artifacts with filters
     *
     * @param  array{status?: string, author_id?: int, privacy_level?: string, tags?: array<string>|string, search?: string, user?: User}  $filters  Search filters
     */
    public function search(array $filters = []): Collection
    {
        $query = Artifact::query();

        // Apply filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        if (isset($filters['privacy_level'])) {
            $query->where('privacy_level', $filters['privacy_level']);
        }

        if (isset($filters['tags'])) {
            $tags = is_array($filters['tags']) ? $filters['tags'] : [$filters['tags']];
            $query->withAnyTag($tags);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Apply user access restrictions
        if (isset($filters['user']) && $filters['user'] instanceof User) {
            $query->accessibleBy($filters['user']);
        }

        return $query->with(['author', 'tags', 'asset'])
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    /**
     * Get paginated artifacts
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Artifact::query();

        // Apply same filters as search method
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        if (isset($filters['privacy_level'])) {
            $query->where('privacy_level', $filters['privacy_level']);
        }

        if (isset($filters['tags'])) {
            $tags = is_array($filters['tags']) ? $filters['tags'] : [$filters['tags']];
            $query->withAnyTag($tags);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        // Apply user access restrictions
        if (isset($filters['user']) && $filters['user'] instanceof User) {
            $query->accessibleBy($filters['user']);
        }

        return $query->with(['author', 'tags', 'asset'])
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Attach an asset to a artifact
     *
     * @throws \Exception if asset not found
     */
    public function attachAsset(Artifact $artifact, int $assetId): void
    {
        try {
            $asset = $this->assetService->findAsset($assetId);
            $artifact->update(['asset_id' => $asset->id]);
        } catch (\Exception $e) {
            Log::error('ArtifactManager: Failed to attach asset', [
                'artifact_id' => $artifact->id,
                'asset_id' => $assetId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Duplicate a artifact
     */
    public function duplicate(Artifact $artifact, array $overrides = []): Artifact
    {
        return $artifact->duplicate($overrides);
    }

    /**
     * Get artifacts by user
     */
    public function getByUser(User $user, array $filters = []): Collection
    {
        $filters['user'] = $user;

        return $this->search($filters);
    }

    /**
     * Get public artifacts
     */
    public function getPublicArtifacts(array $filters = []): Collection
    {
        $filters['privacy_level'] = 'public';

        return $this->search($filters);
    }

    /**
     * Get recently updated artifacts
     */
    public function getRecentlyUpdated(int $limit = 10): Collection
    {
        return Artifact::with(['author', 'tags'])
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get artifact statistics
     *
     * @return array{total: int, published: int, drafts: int, archived: int, public: int, private: int} Statistics
     */
    public function getStats(): array
    {
        $totalArtifacts = Artifact::count();
        $publishedArtifacts = Artifact::published()->count();
        $draftArtifacts = Artifact::draft()->count();
        $archivedArtifacts = Artifact::archived()->count();

        return [
            'total' => $totalArtifacts,
            'published' => $publishedArtifacts,
            'drafts' => $draftArtifacts,
            'archived' => $archivedArtifacts,
            'public' => Artifact::public()->count(),
            'private' => Artifact::private()->count(),
        ];
    }

    /**
     * Search artifacts with content similarity based on shared tags.
     */
    public function searchSimilar(Artifact $artifact, int $limit = 5): Collection
    {
        $tagIds = $artifact->tags->pluck('id')->toArray();

        if (empty($tagIds)) {
            return collect();
        }

        return Artifact::whereHas('tags', function ($query) use ($tagIds) {
            $query->whereIn('artifact_tag_id', $tagIds);
        })
            ->where('id', '!=', $artifact->id)
            ->with(['author', 'tags'])
            ->limit($limit)
            ->get();
    }
}
