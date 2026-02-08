<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * HasTags Trait - Polymorphic Tagging with Auto-Creation.
 *
 * Provides polymorphic tagging capabilities with automatic tag creation and
 * flexible querying. Tags are shared across the application but associated
 * with specific tag models per entity type.
 *
 * Requirements:
 * - Model must implement abstract methods: getTagModelClass(), getTagPivotTable()
 * - Tag model must have: id, name, slug, created_by columns
 * - Pivot table must exist with: [model]_id, [tag_model]_id, timestamps
 *
 * Tag Lifecycle:
 * - Tags are automatically created if they don't exist (via getOrCreateTagIds)
 * - Tags are NOT deleted when removed from items (shared resource)
 * - Tag names are trimmed and empty names are skipped
 *
 * Implementation Pattern:
 * ```php
 * class Artifact extends Model {
 *     use HasTags;
 *
 *     public function getTagModelClass(): string {
 *         return ArtifactTag::class;
 *     }
 *
 *     public function getTagPivotTable(): string {
 *         return 'artifact_artifact_tag';
 *     }
 * }
 * ```
 *
 * Usage Examples:
 * ```php
 * // Add tags (creates if needed)
 * $artifact->addTag('php')->addTag(['laravel', 'tutorial']);
 *
 * // Replace all tags
 * $artifact->syncTags(['updated', 'tags']);
 *
 * // Query by tags
 * Artifact::withAnyTag(['php', 'laravel'])->get();  // Has PHP OR Laravel
 * Artifact::withAllTags(['php', 'laravel'])->get(); // Has PHP AND Laravel
 *
 * // Popular tags
 * Artifact::getPopularTags(10); // Top 10 most-used tags
 * ```
 *
 * Automatic Tag Creation:
 * - Tags are created on-the-fly with slug generation
 * - Falls back to user ID 1 if no auth (scheduled jobs, etc.)
 * - Duplicate names are prevented via database constraints
 *
 * @see \Illuminate\Database\Eloquent\Relations\BelongsToMany
 */
trait HasTags
{
    /**
     * Get the tag model class for this entity
     *
     * Must return fully-qualified class name of tag model specific to this entity.
     * Example: ArtifactTag::class for Artifact models.
     *
     * @return string Fully-qualified tag model class name
     */
    abstract public function getTagModelClass(): string;

    /**
     * Get the pivot table name for tag relationships
     *
     * Must return name of pivot table connecting this model to its tags.
     * Convention: [singular_model]_[singular_tag_model] (alphabetical).
     * Example: 'artifact_artifact_tag' for Artifact → ArtifactTag.
     *
     * @return string Pivot table name
     */
    abstract public function getTagPivotTable(): string;

    /**
     * Relationship to tags
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->getTagModelClass(),
            $this->getTagPivotTable()
        )->withTimestamps();
    }

    /**
     * Add tags to the model
     */
    public function addTag(string|array $tags): self
    {
        $tagIds = $this->getOrCreateTagIds($tags);
        $this->tags()->syncWithoutDetaching($tagIds);

        return $this;
    }

    /**
     * Add tags and remove all others
     */
    public function syncTags(string|array $tags): self
    {
        $tagIds = $this->getOrCreateTagIds($tags);
        $this->tags()->sync($tagIds);

        return $this;
    }

    /**
     * Remove tags from the model
     */
    public function removeTag(string|array $tags): self
    {
        $tagIds = $this->getTagIds($tags);
        $this->tags()->detach($tagIds);

        return $this;
    }

    /**
     * Remove all tags from the model
     */
    public function removeAllTags(): self
    {
        $this->tags()->detach();

        return $this;
    }

    /**
     * Check if model has any of the given tags
     */
    public function hasTag(string|array $tags): bool
    {
        $tagNames = is_array($tags) ? $tags : [$tags];

        return $this->tags()
            ->whereIn('name', $tagNames)
            ->exists();
    }

    /**
     * Check if model has all of the given tags
     */
    public function hasAllTags(string|array $tags): bool
    {
        $tagNames = is_array($tags) ? $tags : [$tags];

        return $this->tags()
            ->whereIn('name', $tagNames)
            ->count() === count($tagNames);
    }

    /**
     * Get tag names as array
     */
    public function getTagNamesAttribute(): array
    {
        return $this->tags->pluck('name')->toArray();
    }

    /**
     * Scope to filter by tags
     */
    public function scopeWithAnyTag(Builder $query, string|array $tags): Builder
    {
        $tagNames = is_array($tags) ? $tags : [$tags];

        return $query->whereHas('tags', function (Builder $q) use ($tagNames) {
            $q->whereIn('name', $tagNames);
        });
    }

    /**
     * Scope to filter by all tags
     */
    public function scopeWithAllTags(Builder $query, string|array $tags): Builder
    {
        $tagNames = is_array($tags) ? $tags : [$tags];

        foreach ($tagNames as $tag) {
            $query->whereHas('tags', function (Builder $q) use ($tag) {
                $q->where('name', $tag);
            });
        }

        return $query;
    }

    /**
     * Scope to filter by models without any tags
     */
    public function scopeWithoutTags(Builder $query): Builder
    {
        return $query->whereDoesntHave('tags');
    }

    /**
     * Get or create tag IDs for the given tag names
     *
     * Retrieves existing tags by name or creates new ones as needed.
     * Empty/whitespace-only names are skipped. Created tags get auto-generated
     * slugs and are attributed to current user (or user ID 1 as fallback).
     *
     * @param  string|array  $tags  Tag name(s) to find or create
     * @return array Array of tag IDs (duplicates removed)
     */
    protected function getOrCreateTagIds(string|array $tags): array
    {
        $tagNames = is_array($tags) ? $tags : [$tags];
        $tagModel = app($this->getTagModelClass());
        $existingTags = $tagModel->whereIn('name', $tagNames)->get();

        $tagIds = [];

        foreach ($tagNames as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) {
                continue;
            }

            $existingTag = $existingTags->where('name', $tagName)->first();

            if ($existingTag) {
                $tagIds[] = $existingTag->id;
            } else {
                try {
                    $userId = auth()->id();

                    if (! $userId) {
                        Log::warning('Creating tag without authenticated user, falling back to user ID 1', [
                            'model' => static::class,
                            'tag_name' => $tagName,
                            'tag_model' => $this->getTagModelClass(),
                        ]);
                        $userId = 1;
                    }

                    // Create new tag
                    $newTag = $tagModel->create([
                        'name' => $tagName,
                        'slug' => \Str::slug($tagName),
                        'created_by' => $userId,
                    ]);

                    $tagIds[] = $newTag->id;
                } catch (\Exception $e) {
                    Log::error('Failed to create tag', [
                        'model' => static::class,
                        'tag_name' => $tagName,
                        'tag_model' => $this->getTagModelClass(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Continue with other tags rather than failing completely
                    continue;
                }
            }
        }

        return array_unique($tagIds);
    }

    /**
     * Get existing tag IDs for the given tag names
     */
    protected function getTagIds(string|array $tags): array
    {
        $tagNames = is_array($tags) ? $tags : [$tags];
        $tagModel = app($this->getTagModelClass());

        return $tagModel->whereIn('name', $tagNames)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get popular tags for this model type
     *
     * Returns tags ordered by usage count across all instances of this model.
     * Uses dynamic relationship counting based on model table name.
     *
     * @param  int  $limit  Maximum number of tags to return
     * @return Collection<Tag> Tag models with usage counts
     */
    public static function getPopularTags(int $limit = 10): Collection
    {
        $instance = new static;
        $tagModel = app($instance->getTagModelClass());

        return $tagModel->select(['id', 'name'])
            ->withCount($instance->getTable())
            ->orderByDesc($instance->getTable().'_count')
            ->limit($limit)
            ->get();
    }
}
