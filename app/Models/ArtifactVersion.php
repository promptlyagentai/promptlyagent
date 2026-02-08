<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'artifact_id',
        'version',
        'content',
        'asset_id',
        'changes',
        'created_by',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    // Relationships
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Accessors & Mutators
    public function getWordCountAttribute(): int
    {
        if (! $this->content) {
            return 0;
        }

        return str_word_count(strip_tags($this->content));
    }

    // Helper methods
    public function restore(): bool
    {
        return $this->artifact->restoreVersion($this);
    }

    public function getChangesSummaryAttribute(): string
    {
        if (! $this->changes) {
            return 'No changes recorded';
        }

        $changeTypes = array_keys($this->changes);

        return implode(', ', $changeTypes);
    }
}
