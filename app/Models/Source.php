<?php

namespace App\Models;

use App\Events\SourceCreated;
use App\Scout\Traits\HasVectorSearch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;

class Source extends Model
{
    use HasVectorSearch;

    protected $fillable = [
        'url',
        'url_hash',
        'domain',
        'title',
        'description',
        'favicon',
        'open_graph',
        'twitter_card',
        'content_markdown',
        'content_preview',
        'http_status',
        'content_type',
        'content_retrieved_at',
        'expires_at',
        'ttl_hours',
        'content_category',
        'is_scrapeable',
        'requires_refresh',
        'validation_metadata',
        'scraping_metadata',
        'last_user_agent',
        'access_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'open_graph' => 'array',
        'twitter_card' => 'array',
        'validation_metadata' => 'array',
        'scraping_metadata' => 'array',
        'content_retrieved_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_scrapeable' => 'boolean',
        'requires_refresh' => 'boolean',
    ];

    // Temporarily disabled to reduce event noise
    // protected $dispatchesEvents = [
    //     'created' => SourceCreated::class,
    // ];

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'research_sources';
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        $array = [
            'id' => $this->getScoutKey(),
            'document_id' => $this->id, // For Scout mapping
            'url' => $this->url,
            'domain' => $this->domain,
            'title' => $this->title,
            'description' => $this->description,
            'content_preview' => $this->content_preview,
            'content_category' => $this->content_category,
            'http_status' => $this->http_status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'content_retrieved_at' => $this->content_retrieved_at?->timestamp,
        ];

        // Include embedding if available from trait
        if ($embedding = $this->getEmbedding()) {
            $array['_vectors'] = ['default' => $embedding];
        }

        return $array;
    }

    /**
     * Get content for embedding generation.
     */
    public function getEmbeddingContent(): string
    {
        $content = [];

        if ($this->title) {
            $content[] = $this->title;
        }

        if ($this->description) {
            $content[] = $this->description;
        }

        if ($this->content_preview) {
            $content[] = $this->content_preview;
        } elseif ($this->content_markdown) {
            // Use first 2000 characters of markdown for embedding
            $content[] = substr($this->content_markdown, 0, 2000);
        }

        return implode("\n\n", $content);
    }

    /**
     * Override metadata handling to include validation and scraping metadata
     */
    public function getMetadataAttribute($value): array
    {
        $base = $value ? json_decode($value, true) : [];

        // Merge with validation and scraping metadata
        return array_merge($base, [
            'validation_metadata' => $this->validation_metadata,
            'scraping_metadata' => $this->scraping_metadata,
        ]);
    }

    public function setEmbedding(array $embedding): void
    {
        // Use transient embedding storage (from HasVectorSearch trait)
        $this->tempEmbedding = $embedding;
    }

    public function getEmbedding(): ?array
    {
        // Use transient embedding storage (from HasVectorSearch trait)
        return $this->tempEmbedding;
    }

    /**
     * Determine if we should be searchable based on content availability
     */
    public function shouldBeSearchable(): bool
    {
        return $this->http_status < 400 &&
               ($this->title || $this->description || $this->content_preview || $this->content_markdown);
    }

    /**
     * Relationship to chat interaction sources
     */
    public function chatInteractionSources(): HasMany
    {
        return $this->hasMany(ChatInteractionSource::class);
    }

    /**
     * Check if the source has expired and needs refresh
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the source is valid and not expired
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && $this->http_status < 400;
    }

    /**
     * Determine TTL based on content category and domain
     */
    public static function calculateTtlHours(string $url, ?string $contentCategory = null): int
    {
        $domain = parse_url($url, PHP_URL_HOST);

        // News sites - short TTL (2-6 hours)
        $newsSites = ['bbc.com', 'cnn.com', 'reuters.com', 'ap.org', 'nytimes.com', 'guardian.com', 'washingtonpost.com'];
        if ($domain && collect($newsSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 4; // 4 hours for news
        }

        // Academic/Research sites - long TTL (30-90 days)
        $academicSites = ['arxiv.org', 'ncbi.nlm.nih.gov', 'scholar.google.com', 'researchgate.net', 'ieee.org', 'acm.org'];
        if ($domain && collect($academicSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 720; // 30 days for academic content
        }

        // Documentation sites - medium-long TTL (7 days)
        $docSites = ['docs.microsoft.com', 'developer.mozilla.org', 'stackoverflow.com', 'github.com'];
        if ($domain && collect($docSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 168; // 7 days for documentation
        }

        // Social media - very short TTL (1-2 hours)
        $socialSites = ['twitter.com', 'x.com', 'linkedin.com', 'facebook.com', 'reddit.com'];
        if ($domain && collect($socialSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 2; // 2 hours for social media
        }

        // Blog platforms - medium TTL (24-48 hours)
        $blogSites = ['medium.com', 'substack.com', 'wordpress.com', 'blogspot.com'];
        if ($domain && collect($blogSites)->contains(fn ($site) => str_contains($domain, $site))) {
            return 48; // 2 days for blogs
        }

        // Category-based TTL
        switch ($contentCategory) {
            case 'news':
                return 6;
            case 'research':
            case 'academic':
                return 720; // 30 days
            case 'documentation':
                return 168; // 7 days
            case 'blog':
                return 48; // 2 days
            case 'social':
                return 2;
            default:
                return 24; // 1 day default
        }
    }

    /**
     * Create or update a source with intelligent TTL
     */
    public static function createOrUpdate(array $data): self
    {
        $url = $data['url'];
        $urlHash = md5($url);
        $domain = parse_url($url, PHP_URL_HOST) ?: 'unknown';

        // Check for existing source
        $source = self::where('url_hash', $urlHash)->first();

        if ($source && ! $source->isExpired()) {
            // Update access tracking
            $source->increment('access_count');
            $source->update(['last_accessed_at' => now()]);

            Log::info('Source cache hit', [
                'url' => $url,
                'source_id' => $source->id,
                'expires_at' => $source->expires_at,
                'access_count' => $source->access_count,
            ]);

            return $source;
        }

        // Calculate TTL
        $ttlHours = self::calculateTtlHours($url, $data['content_category'] ?? null);
        $expiresAt = Carbon::now()->addHours($ttlHours);

        $sourceData = array_merge($data, [
            'url_hash' => $urlHash,
            'domain' => $domain,
            'expires_at' => $expiresAt,
            'ttl_hours' => $ttlHours,
            'content_retrieved_at' => now(),
            'last_accessed_at' => now(),
        ]);

        if ($source) {
            // Update existing expired source
            $source->update($sourceData);
            $source->increment('access_count');

            Log::info('Source cache refresh', [
                'url' => $url,
                'source_id' => $source->id,
                'new_expires_at' => $expiresAt,
                'ttl_hours' => $ttlHours,
            ]);
        } else {
            // Create new source
            $source = self::create($sourceData);

            Log::info('Source cache created', [
                'url' => $url,
                'source_id' => $source->id,
                'expires_at' => $expiresAt,
                'ttl_hours' => $ttlHours,
                'content_category' => $data['content_category'] ?? 'general',
            ]);
        }

        return $source;
    }

    /**
     * Clean up expired sources
     */
    public static function cleanupExpired(): int
    {
        $deletedCount = self::where('expires_at', '<', now())->delete();

        if ($deletedCount > 0) {
            Log::info('Cleaned up expired sources', ['deleted_count' => $deletedCount]);
        }

        return $deletedCount;
    }
}
