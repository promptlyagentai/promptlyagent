<?php

namespace App\Services;

use App\Models\AgentExecution;
use App\Models\AgentSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InlineLinkProcessor
{
    public function processAgentResponse(string $content, AgentExecution $execution): string
    {
        // Parse markdown links and enhance them with source tracking
        $pattern = '/\[([^\]]+)\]\(([^)]+)\)/';

        return preg_replace_callback($pattern, function ($matches) use ($execution) {
            $linkText = $matches[1];
            $url = $matches[2];

            // Skip if URL is already processed or is a placeholder
            if (str_contains($url, 'data-source-id') || str_contains($url, 'example.com')) {
                return $matches[0]; // Return original link unchanged
            }

            // Track or update source
            $source = $this->trackSource($execution, $url, $linkText);

            // Return enhanced markdown link with data attributes
            return "[{$linkText}]({$url}){: data-source-id=\"{$source->id}\" class=\"source-link\"}";
        }, $content);
    }

    public function trackSource(AgentExecution $execution, string $url, string $linkText): AgentSource
    {
        // Normalize URL
        $normalizedUrl = $this->normalizeUrl($url);
        $domain = parse_url($normalizedUrl, PHP_URL_HOST);

        $source = AgentSource::firstOrCreate(
            [
                'execution_id' => $execution->id,
                'url' => $normalizedUrl,
            ],
            [
                'title' => $this->extractTitleFromUrl($normalizedUrl) ?: $linkText,
                'domain' => $domain,
                'source_type' => $this->determineSourceType($normalizedUrl),
                'quality_score' => $this->calculateInitialQualityScore($domain, $normalizedUrl),
            ]
        );

        // Increment usage count and update metadata if this is a repeated reference
        if (! $source->wasRecentlyCreated) {
            $source->incrementUsage();

            // Update title if we have a better one
            if (empty($source->title) && ! empty($linkText)) {
                $source->update(['title' => $linkText]);
            }
        } else {
            // For new sources, try to enrich with metadata
            $this->enrichSourceMetadata($source);
        }

        return $source;
    }

    protected function normalizeUrl(string $url): string
    {
        // Remove tracking parameters and normalize URL
        $url = trim($url);

        // Remove common tracking parameters
        $trackingParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ref', 'source'];
        $parsed = parse_url($url);

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            foreach ($trackingParams as $param) {
                unset($queryParams[$param]);
            }
            $parsed['query'] = http_build_query($queryParams);
        }

        return $this->buildUrlFromParts($parsed);
    }

    protected function buildUrlFromParts(array $parsed): string
    {
        $url = '';

        if (isset($parsed['scheme'])) {
            $url .= $parsed['scheme'].'://';
        }

        if (isset($parsed['host'])) {
            if (isset($parsed['user'])) {
                $url .= $parsed['user'];
                if (isset($parsed['pass'])) {
                    $url .= ':'.$parsed['pass'];
                }
                $url .= '@';
            }
            $url .= $parsed['host'];
            if (isset($parsed['port'])) {
                $url .= ':'.$parsed['port'];
            }
        }

        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }

        if (isset($parsed['query']) && ! empty($parsed['query'])) {
            $url .= '?'.$parsed['query'];
        }

        if (isset($parsed['artifact'])) {
            $url .= '#'.$parsed['artifact'];
        }

        return $url;
    }

    protected function extractTitleFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        $domain = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';

        // Clean up the path for a readable title
        if ($path && $path !== '/') {
            $pathParts = array_filter(explode('/', trim($path, '/')));
            $lastPart = end($pathParts);

            // Convert slugs to readable text
            $title = str_replace(['-', '_'], ' ', $lastPart);
            $title = ucwords(strtolower($title));

            return $domain.' - '.$title;
        }

        return $domain;
    }

    protected function determineSourceType(string $url): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        if (in_array(strtolower($extension), ['pdf', 'doc', 'docx', 'txt', 'md'])) {
            return 'document';
        }

        if (in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
            return 'file';
        }

        if (str_contains($url, '/api/') || str_contains($url, '.json') || str_contains($url, '.xml')) {
            return 'api';
        }

        return 'web';
    }

    protected function calculateInitialQualityScore(string $domain, string $url): float
    {
        $score = 5.0; // Base score

        // Boost score for trusted domains
        $trustedDomains = [
            'wikipedia.org' => 9.0,
            'github.com' => 8.5,
            'stackoverflow.com' => 8.0,
            'medium.com' => 7.5,
            'arxiv.org' => 9.5,
            'nature.com' => 9.0,
            'science.org' => 9.0,
            'ieee.org' => 8.5,
            'acm.org' => 8.5,
            'scholar.google.com' => 8.0,
        ];

        foreach ($trustedDomains as $trustedDomain => $domainScore) {
            if (str_contains($domain, $trustedDomain)) {
                return $domainScore;
            }
        }

        // Boost for academic/government domains
        if (str_ends_with($domain, '.edu') || str_ends_with($domain, '.gov')) {
            $score += 2.0;
        }

        // Boost for HTTPS
        if (str_starts_with($url, 'https://')) {
            $score += 0.5;
        }

        // Penalize for suspicious patterns
        if (str_contains($url, 'click') || str_contains($url, 'redirect') || str_contains($url, 'affiliate')) {
            $score -= 2.0;
        }

        return max(1.0, min(10.0, $score));
    }

    protected function enrichSourceMetadata(AgentSource $source): void
    {
        // Try to fetch additional metadata asynchronously
        try {
            $cacheKey = 'source_metadata_'.md5($source->url);

            $metadata = Cache::remember($cacheKey, now()->addHours(24), function () use ($source) {
                return $this->fetchSourceMetadata($source->url);
            });

            if ($metadata) {
                $source->update([
                    'title' => $metadata['title'] ?? $source->title,
                    'snippet' => $metadata['description'] ?? null,
                    'favicon_url' => $metadata['favicon'] ?? null,
                    'metadata' => array_merge($source->metadata ?? [], $metadata),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to enrich source metadata', [
                'source_id' => $source->id,
                'url' => $source->url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function fetchSourceMetadata(string $url): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent('Mozilla/5.0 (compatible; PromptlyAgentBot/1.0)')
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            $metadata = [];

            // Extract title
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                $metadata['title'] = html_entity_decode(strip_tags(trim($matches[1])));
            }

            // Extract meta description
            if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
                $metadata['description'] = html_entity_decode(strip_tags(trim($matches[1])));
            }

            // Extract Open Graph data
            if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
                $metadata['og_title'] = html_entity_decode(strip_tags(trim($matches[1])));
            }

            if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
                $metadata['og_description'] = html_entity_decode(strip_tags(trim($matches[1])));
            }

            // Use OG data if better than regular meta data
            if (! empty($metadata['og_title']) && (empty($metadata['title']) || strlen($metadata['og_title']) > strlen($metadata['title']))) {
                $metadata['title'] = $metadata['og_title'];
            }

            if (! empty($metadata['og_description']) && empty($metadata['description'])) {
                $metadata['description'] = $metadata['og_description'];
            }

            return $metadata;

        } catch (\Exception $e) {
            Log::debug('Failed to fetch source metadata', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function enrichMarkdownForDisplay(string $markdown): string
    {
        // Convert custom markdown attributes to HTML data attributes for display
        $pattern = '/\[([^\]]+)\]\(([^)]+)\)\{:\s*([^}]+)\}/';

        return preg_replace_callback($pattern, function ($matches) {
            $linkText = $matches[1];
            $url = $matches[2];
            $attributes = $matches[3];

            // Parse attributes
            $dataAttributes = '';
            if (preg_match('/data-source-id="([^"]+)"/', $attributes, $sourceMatches)) {
                $sourceId = $sourceMatches[1];
                $dataAttributes .= " data-source-id=\"{$sourceId}\"";
            }

            if (preg_match('/class="([^"]+)"/', $attributes, $classMatches)) {
                $classes = $classMatches[1];
                $dataAttributes .= " class=\"{$classes}\"";
            }

            return "<a href=\"{$url}\"{$dataAttributes} target=\"_blank\" rel=\"noopener\">{$linkText}</a>";
        }, $markdown);
    }

    public function getSourceStats(AgentExecution $execution): array
    {
        $sources = $execution->sources;

        return [
            'total_sources' => $sources->count(),
            'referenced_sources' => $sources->where('link_usage_count', '>', 0)->count(),
            'unreferenced_sources' => $sources->where('link_usage_count', 0)->count(),
            'high_quality_sources' => $sources->where('quality_score', '>=', 7.0)->count(),
            'domains' => $sources->pluck('domain')->unique()->count(),
            'source_types' => $sources->groupBy('source_type')->map->count(),
            'average_quality' => $sources->avg('quality_score'),
            'total_clicks' => $sources->sum('click_count'),
            'most_referenced' => $sources->orderBy('link_usage_count', 'desc')->first(),
        ];
    }
}
