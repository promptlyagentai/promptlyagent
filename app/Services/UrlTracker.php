<?php

namespace App\Services;

use App\Models\ChatInteraction;
use App\Models\ChatInteractionSource;
use App\Models\Source;
use Illuminate\Support\Facades\Log;

class UrlTracker
{
    /**
     * Track all URLs found in text and associate them with a chat interaction
     */
    public static function trackUrlsInText(string $text, ChatInteraction $interaction, string $discoveryMethod = 'text_extraction', string $discoveryTool = 'url_tracker'): int
    {
        try {
            // Extract all URLs from the text - both markdown links and plain URLs
            $urls = [];

            // Extract markdown links [text](url)
            preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $text, $markdownMatches, PREG_SET_ORDER);
            foreach ($markdownMatches as $match) {
                $urls[] = [
                    'url' => trim($match[2]),
                    'text' => trim($match[1]),
                    'type' => 'markdown_link',
                ];
            }

            // Extract plain URLs (http/https)
            preg_match_all('/https?:\/\/[^\s\)\]\}]+/', $text, $plainMatches);
            foreach ($plainMatches[0] as $plainUrl) {
                // Avoid duplicates from markdown links
                $alreadyTracked = false;
                foreach ($urls as $existingUrl) {
                    if ($existingUrl['url'] === trim($plainUrl)) {
                        $alreadyTracked = true;
                        break;
                    }
                }

                if (! $alreadyTracked) {
                    $urls[] = [
                        'url' => trim($plainUrl),
                        'text' => null,
                        'type' => 'plain_url',
                    ];
                }
            }

            if (empty($urls)) {
                return 0;
            }

            Log::info('UrlTracker: Found URLs in text', [
                'interaction_id' => $interaction->id,
                'url_count' => count($urls),
                'discovery_method' => $discoveryMethod,
                'urls' => array_column($urls, 'url'),
            ]);

            $linkValidator = app(LinkValidator::class);
            $trackedCount = 0;

            // Process each URL through LinkValidator to ensure it's tracked
            foreach ($urls as $urlData) {
                $url = $urlData['url'];

                // Skip obviously fake URLs
                if (preg_match('/example\.com|placeholder|dummy|fake-url|example-source-link/', $url)) {
                    Log::debug('UrlTracker: Skipping fake URL', ['url' => $url]);

                    continue;
                }

                try {
                    // Validate and track the URL
                    $linkInfo = $linkValidator->validateAndExtractLinkInfo($url);

                    if ($linkInfo && isset($linkInfo['status']) && $linkInfo['status'] >= 200 && $linkInfo['status'] < 400) {
                        // Check if this URL is already tracked for this interaction
                        $existingSource = ChatInteractionSource::where('chat_interaction_id', $interaction->id)
                            ->whereHas('source', function ($query) use ($url) {
                                $query->where('url', $url);
                            })
                            ->first();

                        if (! $existingSource) {
                            // Create or get the source
                            $source = Source::where('url', $url)->first();

                            if ($source) {
                                // Create interaction-source relationship
                                ChatInteractionSource::create([
                                    'chat_interaction_id' => $interaction->id,
                                    'source_id' => $source->id,
                                    'relevance_score' => 6.0, // Default relevance for tracked URLs
                                    'discovery_method' => $discoveryMethod,
                                    'discovery_tool' => $discoveryTool,
                                    'discovered_at' => now(),
                                    'was_scraped' => false,
                                ]);

                                $trackedCount++;
                                Log::info('UrlTracker: Tracked URL', [
                                    'url' => $url,
                                    'interaction_id' => $interaction->id,
                                    'source_id' => $source->id,
                                    'discovery_method' => $discoveryMethod,
                                ]);
                            } else {
                                Log::warning('UrlTracker: Source not found after validation', ['url' => $url]);
                            }
                        } else {
                            Log::debug('UrlTracker: URL already tracked', ['url' => $url, 'interaction_id' => $interaction->id]);
                        }
                    } else {
                        Log::debug('UrlTracker: URL validation failed', [
                            'url' => $url,
                            'status' => $linkInfo['status'] ?? 'unknown',
                            'interaction_id' => $interaction->id,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('UrlTracker: Failed to track URL', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                        'interaction_id' => $interaction->id,
                    ]);
                }
            }

            if ($trackedCount > 0) {
                Log::info('UrlTracker: Completed URL tracking', [
                    'interaction_id' => $interaction->id,
                    'urls_found' => count($urls),
                    'urls_tracked' => $trackedCount,
                    'discovery_method' => $discoveryMethod,
                ]);
            }

            return $trackedCount;

        } catch (\Exception $e) {
            Log::error('UrlTracker: Failed to track URLs in text', [
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
                'discovery_method' => $discoveryMethod,
            ]);

            return 0;
        }
    }
}
