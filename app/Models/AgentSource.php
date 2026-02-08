<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent Source Model
 *
 * Tracks external sources referenced or used during agent executions.
 * Stores usage metrics, quality scores, and link analytics for source evaluation.
 */
class AgentSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_id',
        'url',
        'title',
        'snippet',
        'favicon_url',
        'domain',
        'link_usage_count',
        'click_count',
        'quality_score',
        'source_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'quality_score' => 'decimal:2',
        'link_usage_count' => 'integer',
        'click_count' => 'integer',
    ];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    public function getFaviconUrlAttribute($value): string
    {
        return $value ?: "https://www.google.com/s2/favicons?domain={$this->domain}&sz=32";
    }

    public function getPreviewDataAttribute(): array
    {
        return [
            'title' => $this->title,
            'snippet' => $this->snippet,
            'domain' => $this->domain,
            'favicon_url' => $this->favicon_url,
            'usage_count' => $this->link_usage_count,
            'click_count' => $this->click_count,
            'quality_score' => $this->quality_score,
        ];
    }

    public function incrementUsage(): void
    {
        $this->increment('link_usage_count');
    }

    public function incrementClicks(): void
    {
        $this->increment('click_count');
    }

    public function updateQualityScore(float $score): void
    {
        $this->update(['quality_score' => max(0, min(10, $score))]);
    }

    public function scopeHighQuality($query, float $threshold = 7.0)
    {
        return $query->where('quality_score', '>=', $threshold);
    }

    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopeReferenced($query)
    {
        return $query->where('link_usage_count', '>', 0);
    }

    public function scopeUnreferenced($query)
    {
        return $query->where('link_usage_count', 0);
    }

    public function scopeBySourceType($query, string $type)
    {
        return $query->where('source_type', $type);
    }

    public function scopeOrderByUsage($query, string $direction = 'desc')
    {
        return $query->orderBy('link_usage_count', $direction);
    }

    public function scopeOrderByQuality($query, string $direction = 'desc')
    {
        return $query->orderBy('quality_score', $direction);
    }
}
