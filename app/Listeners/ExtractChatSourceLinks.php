<?php

namespace App\Listeners;

use App\Events\ChatInteractionCompleted;
use App\Services\LinkValidator;
use App\Services\SourceLinkExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Extract Chat Source Links Listener
 *
 * Extracts and persists source links from tool results (web searches, web fetch, etc.)
 * to the ChatInteractionSource table. Validates URLs and creates Source records.
 *
 * **Execution Priority:** HIGH (should run early, before URL tracking)
 * Source links provide structured metadata that URL tracking can reference.
 *
 * **Error Handling:** Non-blocking - failures for individual URLs are logged
 * but don't prevent other URLs from being processed or other listeners from executing.
 *
 * **Dependencies:**
 * - SourceLinkExtractor: Parses tool results to find URLs
 * - LinkValidator: Validates URLs and extracts metadata
 * - Source: Validated URL records with metadata
 * - ChatInteractionSource: Links sources to chat interactions
 *
 * @see \App\Services\SourceLinkExtractor
 * @see \App\Services\LinkValidator
 * @see \App\Events\ChatInteractionCompleted
 */
class ExtractChatSourceLinks
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected SourceLinkExtractor $sourceLinkExtractor,
        protected LinkValidator $linkValidator
    ) {}

    /**
     * Handle the event.
     *
     * @param  ChatInteractionCompleted  $event  The completed interaction event
     */
    public function handle(ChatInteractionCompleted $event): void
    {
        try {
            $toolResults = $event->toolResults;

            if (empty($toolResults)) {
                Log::debug('ExtractChatSourceLinks: No tool results to process', [
                    'interaction_id' => $event->chatInteraction->id,
                    'context' => $event->context,
                ]);

                return;
            }

            Log::info('ExtractChatSourceLinks: Extracting source links from tool results', [
                'interaction_id' => $event->chatInteraction->id,
                'tool_results_count' => count($toolResults),
                'context' => $event->context,
            ]);

            $successCount = 0;
            $failureCount = 0;

            foreach ($toolResults as $index => $toolResult) {
                try {
                    $sourceLinks = $this->sourceLinkExtractor->extractFromToolResult($toolResult);

                    foreach ($sourceLinks as $sourceLink) {
                        try {
                            $url = $sourceLink['url'] ?? '';
                            if (empty($url)) {
                                continue;
                            }

                            // Validate and extract link metadata
                            $linkInfo = $this->linkValidator->validateAndExtractLinkInfo($url);

                            if ($linkInfo && isset($linkInfo['status']) && $linkInfo['status'] >= 200 && $linkInfo['status'] < 400) {
                                // Find the validated source by URL hash
                                $urlHash = md5($url);
                                $source = \App\Models\Source::where('url_hash', $urlHash)->first();

                                if ($source) {
                                    // Create ChatInteractionSource record
                                    \App\Models\ChatInteractionSource::createOrUpdate(
                                        $event->chatInteraction->id,
                                        $source->id,
                                        $event->chatInteraction->question ?? 'chat execution',
                                        [
                                            'url' => $url,
                                            'title' => $source->title ?? ($sourceLink['title'] ?? 'Untitled'),
                                            'description' => $source->description ?? ($sourceLink['content'] ?? ''),
                                            'domain' => $source->domain ?? parse_url($url, PHP_URL_HOST) ?? 'unknown',
                                            'content_category' => $source->content_category ?? 'general',
                                            'http_status' => $source->http_status ?? 200,
                                        ],
                                        'chat_execution',
                                        $sourceLink['tool'] ?? 'unknown'
                                    );

                                    $successCount++;

                                    Log::debug('ExtractChatSourceLinks: Persisted source link', [
                                        'interaction_id' => $event->chatInteraction->id,
                                        'source_id' => $source->id,
                                        'url' => $url,
                                        'tool' => $sourceLink['tool'] ?? 'unknown',
                                    ]);
                                }
                            }

                        } catch (\Exception $e) {
                            $failureCount++;

                            Log::error('ExtractChatSourceLinks: Failed to persist individual source link', [
                                'interaction_id' => $event->chatInteraction->id,
                                'source_url' => $sourceLink['url'] ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    Log::error('ExtractChatSourceLinks: Failed to process tool result', [
                        'interaction_id' => $event->chatInteraction->id,
                        'tool_index' => $index,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('ExtractChatSourceLinks: Completed extraction', [
                'interaction_id' => $event->chatInteraction->id,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'context' => $event->context,
            ]);

        } catch (\Exception $e) {
            Log::error('ExtractChatSourceLinks: Listener failed', [
                'interaction_id' => $event->chatInteraction->id,
                'context' => $event->context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw - allow other listeners to execute
        }
    }
}
