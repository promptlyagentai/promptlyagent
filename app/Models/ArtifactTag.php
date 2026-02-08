<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ArtifactTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'created_by',
    ];

    // Relationships
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function artifacts(): BelongsToMany
    {
        return $this->belongsToMany(Artifact::class, 'artifact_artifact_tag');
    }

    // Boot method to auto-generate slug
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (! $tag->slug) {
                $tag->slug = \Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name')) {
                $tag->slug = \Str::slug($tag->name);
            }
        });
    }

    // Helper methods
    public function getArtifactCountAttribute(): int
    {
        return $this->artifacts()->count();
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public static function popular(int $limit = 10): \Illuminate\Support\Collection
    {
        return static::select(['id', 'name', 'slug'])
            ->withCount('artifacts')
            ->orderByDesc('artifacts_count')
            ->limit($limit)
            ->get();
    }
}
