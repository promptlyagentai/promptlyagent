<?php

namespace App\Models;

use App\Traits\HasFileStorage;
use App\Traits\HasPrivacy;
use App\Traits\HasTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * Artifact Model
 *
 * Represents user-generated or AI-generated content artifacts with versioning,
 * rendering, and execution capabilities. Supports multiple file types, privacy
 * levels, and integration with external systems.
 *
 * **Artifact System:**
 * - Versioning: Full version history with restore capabilities
 * - Execution: Server-side execution of HTML/PHP artifacts (sandboxed)
 * - Rendering: Multiple rendering strategies (markdown, code, data, HTML preview)
 * - Integration: Sync with GitHub Gists, Pastebin, etc.
 *
 * **Concurrency Control:**
 * - Content hashing for optimistic locking
 * - Range-based hashing for patch operations
 * - Prevents conflicting simultaneous edits
 *
 * **Traits:**
 * - HasFileStorage: Asset-based file storage
 * - HasPrivacy: User-level privacy controls (private/public/shared)
 * - HasTags: Tagging and categorization
 *
 * @property int $id
 * @property int|null $asset_id Associated file asset
 * @property string $title Artifact title
 * @property string|null $description Artifact description
 * @property string $content Primary content (code, text, etc.)
 * @property string $filetype File extension (md, html, php, js, etc.)
 * @property string $version Semantic version number
 * @property string $privacy_level (private, public, shared)
 * @property array|null $metadata Additional metadata
 * @property int $author_id Creator user ID
 * @property int|null $parent_artifact_id For artifact forks/derivatives
 */
class Artifact extends Model
{
    use HasFactory, HasFileStorage, HasPrivacy, HasTags;

    protected $fillable = [
        'asset_id',
        'title',
        'description',
        'content',
        'filetype',
        'version',
        'privacy_level',
        'metadata',
        'author_id',
        'parent_artifact_id',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected $attributes = [
        'version' => '1.0.0',
        'privacy_level' => 'private',
    ];

    // Relationships
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function parentArtifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class, 'parent_artifact_id');
    }

    public function childArtifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'parent_artifact_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArtifactVersion::class);
    }

    public function chatInteractions(): HasMany
    {
        return $this->hasMany(ChatInteractionArtifact::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(ArtifactIntegration::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(ArtifactConversion::class);
    }

    // HasTags trait implementation
    public function getTagModelClass(): string
    {
        return ArtifactTag::class;
    }

    public function getTagPivotTable(): string
    {
        return 'artifact_artifact_tag';
    }

    // Scopes
    public function scopeByAuthor(Builder $query, int $authorId): Builder
    {
        return $query->where('author_id', $authorId);
    }

    public function scopeByFiletype(Builder $query, string $filetype): Builder
    {
        return $query->where('filetype', $filetype);
    }

    // Accessors & Mutators

    /**
     * Map created_by to author_id for HasPrivacy trait compatibility
     */
    public function getCreatedByAttribute(): ?int
    {
        // Use getAttributeValue to avoid infinite recursion
        // This accesses the author_id attribute properly through Eloquent
        return $this->getAttributeValue('author_id');
    }

    public function getFileExtensionAttribute(): ?string
    {
        return $this->filetype;
    }

    public function getIsCodeFileAttribute(): bool
    {
        $codeExtensions = ['php', 'js', 'css', 'html', 'vue', 'jsx', 'tsx', 'py', 'java', 'cpp', 'c', 'go', 'rs', 'rb', 'swift'];

        return $this->filetype && in_array(strtolower($this->filetype), $codeExtensions);
    }

    public function getIsTextFileAttribute(): bool
    {
        $textExtensions = ['txt', 'md', 'markdown', 'text'];

        return $this->filetype && in_array(strtolower($this->filetype), $textExtensions);
    }

    public function getIsDataFileAttribute(): bool
    {
        $dataExtensions = ['csv', 'json', 'xml', 'yaml', 'yml'];

        return $this->filetype && in_array(strtolower($this->filetype), $dataExtensions);
    }

    public function getWordCountAttribute(): int
    {
        if (! $this->content) {
            return 0;
        }

        return str_word_count(strip_tags($this->content));
    }

    public function getReadingTimeAttribute(): int
    {
        $wordCount = $this->word_count;
        $wordsPerMinute = 200; // Average reading speed

        return max(1, ceil($wordCount / $wordsPerMinute));
    }

    /**
     * Get SHA-256 hash of the entire artifact content.
     *
     * Used for concurrency control to detect if artifact was modified
     * between read and write operations (optimistic locking). Clients
     * should verify the hash hasn't changed before applying updates.
     *
     * @return string SHA-256 hash of content
     */
    public function getContentHashAttribute(): string
    {
        return hash('sha256', $this->content ?? '');
    }

    /**
     * Calculate hash for a specific range of content.
     *
     * Enables fine-grained concurrency control for patch operations.
     * Allows multiple users to edit different parts of the same artifact
     * simultaneously without conflicts. The hash verifies that the specific
     * range being modified hasn't changed since the patch was computed.
     *
     * @param  int  $start  Starting character position (0-based, inclusive)
     * @param  int  $end  Ending character position (exclusive)
     * @return string SHA-256 hash of the content range
     */
    public function calculateRangeHash(int $start, int $end): string
    {
        $content = $this->content ?? '';
        $substring = mb_substr($content, $start, $end - $start);

        return hash('sha256', $substring);
    }

    /**
     * Get the length of the artifact content in characters.
     */
    public function getContentLengthAttribute(): int
    {
        return mb_strlen($this->content ?? '');
    }

    // Helper methods
    public function createVersion(): ArtifactVersion
    {
        try {
            $version = $this->versions()->create([
                'version' => $this->getNextVersionNumber(),
                'content' => $this->content,
                'asset_id' => $this->asset_id,
                'created_by' => auth()->id() ?: $this->author_id,
            ]);

            Log::info('Artifact version created', [
                'artifact_id' => $this->id,
                'version' => $version->version,
                'created_by' => $version->created_by,
            ]);

            return $version;
        } catch (\Exception $e) {
            Log::error('Failed to create artifact version', [
                'artifact_id' => $this->id,
                'title' => $this->title,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function restoreVersion(ArtifactVersion $version): self
    {
        try {
            $this->update([
                'content' => $version->content,
                'asset_id' => $version->asset_id,
                'version' => $version->version,
            ]);

            Log::info('Artifact version restored', [
                'artifact_id' => $this->id,
                'version' => $version->version,
                'restored_by' => auth()->id(),
            ]);

            return $this;
        } catch (\Exception $e) {
            Log::error('Failed to restore artifact version', [
                'artifact_id' => $this->id,
                'version' => $version->version,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function getNextVersionNumber(): string
    {
        // Get the latest version from database to handle race conditions
        $latestVersion = $this->versions()
            ->orderByRaw('CAST(SUBSTRING_INDEX(version, ".", -1) AS UNSIGNED) DESC')
            ->value('version');

        // If no versions exist, use artifact's current version as base
        $baseVersion = $latestVersion ?? $this->version;

        // Simple version increment (1.0.0 -> 1.0.1)
        $versionParts = explode('.', $baseVersion);
        $versionParts[2] = (int) $versionParts[2] + 1;

        return implode('.', $versionParts);
    }

    public function duplicate(array $overrides = []): self
    {
        $data = array_merge([
            'title' => $this->title.' (Copy)',
            'description' => $this->description,
            'content' => $this->content,
            'filetype' => $this->filetype,
            'version' => '1.0.0',
            'privacy_level' => $this->privacy_level,
            'metadata' => $this->metadata,
            'author_id' => auth()->id() ?: $this->author_id,
        ], $overrides);

        return static::create($data);
    }

    public function getFiletypeBadgeClassAttribute(): string
    {
        if (! $this->filetype) {
            return 'bg-gray-100 text-gray-800';
        }

        return match (strtolower($this->filetype)) {
            'md', 'markdown' => 'bg-tropical-teal-100 text-tropical-teal-800',
            'txt', 'text' => 'bg-gray-100 text-gray-800',
            'csv' => 'bg-green-100 text-green-800',
            'json', 'xml', 'yaml', 'yml' => 'bg-purple-100 text-purple-800',
            'php', 'js', 'css', 'html' => 'bg-indigo-100 text-indigo-800',
            'py', 'java', 'cpp', 'c', 'go', 'rs' => 'bg-orange-100 text-orange-800',
            default => 'bg-zinc-100 text-zinc-800',
        };
    }

    // Integration helper methods

    /**
     * Check if this artifact is stored in a specific integration
     */
    public function isInIntegration(Integration $integration): bool
    {
        return $this->integrations()
            ->where('integration_id', $integration->id)
            ->exists();
    }

    /**
     * Get integration data for a specific integration
     */
    public function getIntegrationData(Integration $integration): ?ArtifactIntegration
    {
        return $this->integrations()
            ->where('integration_id', $integration->id)
            ->first();
    }

    /**
     * Get URL for artifact in a specific integration
     */
    public function getIntegrationUrl(string $providerId): ?string
    {
        $integration = $this->integrations()
            ->whereHas('integrationToken', function ($query) use ($providerId) {
                $query->where('provider_id', $providerId);
            })
            ->first();

        return $integration?->external_url;
    }

    /**
     * Get all integrations with auto-sync enabled
     */
    public function getAutoSyncIntegrations(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->integrations()
            ->where('auto_sync_enabled', true)
            ->with('integrationToken')
            ->get();
    }

    // Rendering methods using renderer factory

    /**
     * Render artifact content for preview display (HTML output)
     *
     * Uses appropriate renderer based on filetype (markdown, code, HTML, etc.)
     *
     * @return string HTML-safe rendered content
     */
    public function render(): string
    {
        return \App\Services\Artifacts\ArtifactRendererFactory::getRenderer($this)->render($this);
    }

    /**
     * Render artifact content for preview in card (truncated)
     *
     * @param  int  $maxLength  Maximum content length before truncation
     * @return string Truncated HTML-safe rendered content
     */
    public function renderPreview(int $maxLength = 500): string
    {
        return \App\Services\Artifacts\ArtifactRendererFactory::getRenderer($this)->renderPreview($this, $maxLength);
    }

    /**
     * Get raw content suitable for download
     *
     * @return string File content with appropriate formatting
     */
    public function forDownload(): string
    {
        return \App\Services\Artifacts\ArtifactRendererFactory::getRenderer($this)->forDownload($this);
    }

    /**
     * Get raw content as-is
     *
     * @return string Unmodified artifact content
     */
    public function raw(): string
    {
        return \App\Services\Artifacts\ArtifactRendererFactory::getRenderer($this)->raw($this);
    }

    /**
     * Get MIME type for download
     *
     * @return string MIME type based on filetype (e.g., "text/markdown", "text/html")
     */
    public function getMimeTypeForDownload(): string
    {
        return \App\Services\Artifacts\ArtifactRendererFactory::getRenderer($this)->getMimeType($this);
    }

    /**
     * Get file extension for download
     *
     * @return string File extension without dot (e.g., "md", "html")
     */
    public function getFileExtensionForDownload(): string
    {
        return \App\Services\Artifacts\ArtifactRendererFactory::getRenderer($this)->getFileExtension($this);
    }

    // Execution methods using executor factory

    /**
     * Execute artifact content (HTML/PHP/etc) and return output
     * Returns null if artifact cannot be executed
     */
    public function execute(): ?string
    {
        $executor = \App\Services\Artifacts\ArtifactExecutorFactory::getExecutor($this);

        if (! $executor) {
            return null;
        }

        return $executor->execute($this);
    }

    /**
     * Check if this artifact can be executed
     */
    public function canExecute(): bool
    {
        return \App\Services\Artifacts\ArtifactExecutorFactory::canExecute($this);
    }

    /**
     * Get security warnings for executing this artifact
     */
    public function getSecurityWarnings(): array
    {
        $executor = \App\Services\Artifacts\ArtifactExecutorFactory::getExecutor($this);

        if (! $executor) {
            return [];
        }

        return $executor->getSecurityWarnings($this);
    }
}
