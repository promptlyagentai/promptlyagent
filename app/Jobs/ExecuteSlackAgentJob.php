<?php

namespace App\Jobs;

use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\Integration;
use App\Services\Agents\AgentExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use PromptlyAgentAI\SlackIntegration\Services\SlackApiService;
use PromptlyAgentAI\SlackIntegration\Services\SlackMessageFormatter;

/**
 * Execute Slack Agent Job
 *
 * Handles async execution of agents triggered from Slack.
 * This allows webhook endpoints to respond immediately while execution happens in background.
 */
class ExecuteSlackAgentJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     * Set to 30 minutes for long-running research processes.
     */
    public int $timeout = 1800;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    public int $executionId;

    public int $interactionId;

    public string $integrationId;

    public string $channel;

    public string $messageTs;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $executionId,
        int $interactionId,
        string $integrationId,
        string $channel,
        string $messageTs
    ) {
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;
        $this->integrationId = $integrationId;
        $this->channel = $channel;
        $this->messageTs = $messageTs;

        // Queue on 'default' queue
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(AgentExecutor $agentExecutor, SlackApiService $slackApi, SlackMessageFormatter $formatter): void
    {
        try {
            // Load models
            $execution = AgentExecution::findOrFail($this->executionId);
            // Need to explicitly select metadata since it's excluded by default
            $interaction = ChatInteraction::where('id', $this->interactionId)
                ->addSelect('metadata')
                ->firstOrFail();
            $integration = Integration::findOrFail($this->integrationId);
            $token = $integration->integrationToken;

            Log::info('ExecuteSlackAgentJob: Starting execution', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'integration_id' => $this->integrationId,
                'channel' => $this->channel,
            ]);

            // Execute with full pipeline (same as web interface and input triggers)
            // Trigger context will be automatically injected into system prompt by AgentExecutor
            $result = $agentExecutor->execute($execution, $interaction->id);

            // Update interaction with result
            $interaction->update(['answer' => $result]);

            Log::info('ExecuteSlackAgentJob: Agent execution completed, posting final message', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'answer_length' => strlen($result),
            ]);

            // Check if result is empty
            if (empty(trim($result))) {
                Log::warning('ExecuteSlackAgentJob: Agent returned empty result, not posting to Slack', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                ]);

                // Update the processing message to indicate completion without result
                try {
                    $slackApi->updateMessage(
                        $token,
                        $this->channel,
                        $this->messageTs,
                        '✅ Execution completed (no response generated)'
                    );
                } catch (\Exception $updateError) {
                    Log::error('ExecuteSlackAgentJob: Failed to update processing message', [
                        'error' => $updateError->getMessage(),
                    ]);
                }

                return;
            }

            // Convert markdown to Slack mrkdwn format
            $slackFormattedResult = $formatter->markdownToSlack($result);

            // Double-check after conversion
            if (empty(trim($slackFormattedResult))) {
                Log::warning('ExecuteSlackAgentJob: Converted result is empty, not posting to Slack', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'original_length' => strlen($result),
                ]);

                // Update the processing message
                try {
                    $slackApi->updateMessage(
                        $token,
                        $this->channel,
                        $this->messageTs,
                        '✅ Execution completed (formatting resulted in empty message)'
                    );
                } catch (\Exception $updateError) {
                    Log::error('ExecuteSlackAgentJob: Failed to update processing message', [
                        'error' => $updateError->getMessage(),
                    ]);
                }

                return;
            }

            // Get thread_ts from interaction metadata
            $metadata = $interaction->metadata ?? [];
            $threadTs = $metadata['slack_thread_ts'] ?? $this->messageTs;

            // Post final result as message(s) in the thread
            // Split into multiple messages if exceeds Slack's character limit
            // Use try-catch to ensure we log if this specific step fails
            try {
                $finalMessageTs = $this->postResultToSlack(
                    $slackApi,
                    $token,
                    $this->channel,
                    $threadTs,
                    $slackFormattedResult
                );

                Log::info('ExecuteSlackAgentJob: Final message(s) posted to Slack', [
                    'execution_id' => $this->executionId,
                    'channel' => $this->channel,
                    'thread_ts' => $threadTs,
                    'last_message_ts' => $finalMessageTs,
                ]);

                // Track bot's last message for conversation continuation
                if ($finalMessageTs) {
                    $this->trackBotMessage($integration, $this->channel, $threadTs, $finalMessageTs);
                }

                // Check if there are sources and add a "View Sources" button if so
                $this->postSourcesButtonIfNeeded($slackApi, $token, $interaction, $threadTs);

            } catch (\Exception $e) {
                Log::error('ExecuteSlackAgentJob: Failed to post final message to Slack', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'channel' => $this->channel,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Try to update the processing message with an error instead
                try {
                    $slackApi->updateMessage(
                        $token,
                        $this->channel,
                        $this->messageTs,
                        "✅ Execution completed but failed to post result. Please check the web interface.\n\n_Error: {$e->getMessage()}_"
                    );
                } catch (\Exception $updateError) {
                    Log::error('ExecuteSlackAgentJob: Failed to update processing message', [
                        'error' => $updateError->getMessage(),
                    ]);
                }

                // Re-throw to mark job as failed
                throw $e;
            }

            Log::info('ExecuteSlackAgentJob: Execution completed successfully', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'integration_id' => $this->integrationId,
                'status' => $execution->fresh()->state,
            ]);

        } catch (\Exception $e) {
            Log::error('ExecuteSlackAgentJob: Execution failed', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Try to update Slack message with error
            try {
                $integration = Integration::findOrFail($this->integrationId);
                $token = $integration->integrationToken;
                $slackApi->updateMessage(
                    $token,
                    $this->channel,
                    $this->messageTs,
                    "❌ Error: {$e->getMessage()}"
                );
            } catch (\Exception $updateError) {
                Log::error('ExecuteSlackAgentJob: Failed to update Slack message with error', [
                    'error' => $updateError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Post result to Slack, splitting into multiple messages if needed
     *
     * @return string|null The timestamp of the last message posted
     */
    protected function postResultToSlack(
        SlackApiService $slackApi,
        $token,
        string $channel,
        string $threadTs,
        string $message
    ): ?string {
        // Validate message is not empty
        if (empty(trim($message))) {
            Log::error('ExecuteSlackAgentJob: Attempted to post empty message', [
                'execution_id' => $this->executionId,
                'channel' => $channel,
            ]);
            throw new \Exception('Cannot post empty message to Slack');
        }

        $maxLength = 40000; // Slack's character limit
        $lastMessageTs = null;

        // If message fits in one message, post it directly
        if (strlen($message) <= $maxLength) {
            $response = $slackApi->postMessage($token, $channel, $message, [
                'thread_ts' => $threadTs,
            ]);

            return $response['ts'] ?? null;
        }

        // Message is too long, split it into chunks
        Log::info('ExecuteSlackAgentJob: Message exceeds limit, splitting into multiple messages', [
            'execution_id' => $this->executionId,
            'total_length' => strlen($message),
            'max_length' => $maxLength,
        ]);

        // Split by paragraphs (double newlines) to avoid breaking mid-paragraph
        $paragraphs = preg_split('/\n\n+/', $message);
        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            // If adding this paragraph would exceed the limit, start a new chunk
            if (strlen($currentChunk) + strlen($paragraph) + 2 > $maxLength) {
                if (! empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }

                // If a single paragraph is longer than max length, force split it
                if (strlen($paragraph) > $maxLength) {
                    $words = explode(' ', $paragraph);
                    $tempChunk = '';

                    foreach ($words as $word) {
                        if (strlen($tempChunk) + strlen($word) + 1 > $maxLength) {
                            if (! empty($tempChunk)) {
                                $chunks[] = trim($tempChunk);
                                $tempChunk = '';
                            }
                        }
                        $tempChunk .= ($tempChunk ? ' ' : '').$word;
                    }

                    if (! empty($tempChunk)) {
                        $currentChunk = $tempChunk;
                    }
                } else {
                    $currentChunk = $paragraph;
                }
            } else {
                $currentChunk .= ($currentChunk ? "\n\n" : '').$paragraph;
            }
        }

        // Add the last chunk
        if (! empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        Log::info('ExecuteSlackAgentJob: Split message into chunks', [
            'execution_id' => $this->executionId,
            'chunk_count' => count($chunks),
        ]);

        // Post each chunk as a separate message
        foreach ($chunks as $index => $chunk) {
            // Skip empty chunks
            if (empty(trim($chunk))) {
                Log::warning('ExecuteSlackAgentJob: Skipping empty chunk', [
                    'execution_id' => $this->executionId,
                    'chunk_index' => $index,
                ]);

                continue;
            }

            $chunkNumber = $index + 1;
            $totalChunks = count($chunks);

            // Add part indicator if multiple chunks
            $chunkMessage = $chunk;
            if ($totalChunks > 1) {
                $chunkMessage = "_Part {$chunkNumber} of {$totalChunks}_\n\n".$chunk;
            }

            $response = $slackApi->postMessage($token, $channel, $chunkMessage, [
                'thread_ts' => $threadTs,
            ]);

            $lastMessageTs = $response['ts'] ?? null;

            // Small delay between messages to maintain order
            if ($index < count($chunks) - 1) {
                usleep(500000); // 0.5 seconds
            }
        }

        return $lastMessageTs;
    }

    /**
     * Track bot's message for conversation continuation
     *
     * Stores the message timestamp in integration metadata for later reference
     * when determining if the bot should respond without @mention.
     *
     * Uses an LRU cache strategy to keep only the last 100 threads.
     */
    protected function trackBotMessage(
        Integration $integration,
        string $channel,
        string $threadTs,
        string $messageTs
    ): void {
        $metadata = $integration->metadata ?? [];
        $metadata['slack_last_messages'] = $metadata['slack_last_messages'] ?? [];

        // Use channel:thread as key
        $threadKey = "{$channel}:{$threadTs}";
        $metadata['slack_last_messages'][$threadKey] = [
            'ts' => $messageTs,
            'posted_at' => now()->toISOString(),
        ];

        // Keep only last 100 threads (LRU cache)
        if (count($metadata['slack_last_messages']) > 100) {
            $metadata['slack_last_messages'] = array_slice(
                $metadata['slack_last_messages'],
                -100,
                100,
                true
            );
        }

        $integration->update(['metadata' => $metadata]);

        Log::debug('ExecuteSlackAgentJob: Bot message tracked', [
            'integration_id' => $integration->id,
            'thread_key' => $threadKey,
            'message_ts' => $messageTs,
        ]);
    }

    /**
     * Post a "View Sources" button if the interaction has any sources
     */
    protected function postSourcesButtonIfNeeded(
        SlackApiService $slackApi,
        $token,
        ChatInteraction $interaction,
        string $threadTs
    ): void {
        // Get all sources (web + knowledge)
        $sources = $interaction->getAllSources();

        if ($sources->isEmpty()) {
            Log::debug('ExecuteSlackAgentJob: No sources found, skipping sources button', [
                'interaction_id' => $interaction->id,
            ]);

            return;
        }

        $sourcesCount = $sources->count();

        try {
            $slackApi->postMessage(
                $token,
                $this->channel,
                '📚 This response used information from multiple sources.',
                [
                    'thread_ts' => $threadTs,
                    'blocks' => [
                        [
                            'type' => 'section',
                            'text' => [
                                'type' => 'mrkdwn',
                                'text' => "📚 This response used information from *{$sourcesCount}* source(s).",
                            ],
                        ],
                        [
                            'type' => 'actions',
                            'elements' => [
                                [
                                    'type' => 'button',
                                    'text' => [
                                        'type' => 'plain_text',
                                        'text' => '📚 View Sources',
                                        'emoji' => true,
                                    ],
                                    'style' => 'primary',
                                    'value' => json_encode([
                                        'interaction_id' => $interaction->id,
                                    ]),
                                    'action_id' => 'view_sources',
                                ],
                            ],
                        ],
                    ],
                ]
            );

            Log::info('ExecuteSlackAgentJob: Posted sources button', [
                'interaction_id' => $interaction->id,
                'sources_count' => $sourcesCount,
            ]);

        } catch (\Exception $e) {
            Log::warning('ExecuteSlackAgentJob: Failed to post sources button', [
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - this is not critical
        }
    }
}
