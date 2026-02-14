<?php

namespace App\Jobs\Concerns;

use App\Events\HolisticWorkflowCompleted;
use App\Events\HolisticWorkflowFailed;
use App\Events\ResearchComplete;
use App\Events\ResearchFailed;
use App\Models\AgentExecution;
use App\Services\UrlExtractorService;
use Illuminate\Support\Facades\Log;
use Throwable;

trait BroadcastsWorkflowEvents
{
    /**
     * Broadcast completion for holistic workflow
     */
    protected function broadcastHolisticCompletion(
        int $interactionId,
        int $executionId,
        string $result,
        array $metadata,
        array $sources = []
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting holistic workflow completion', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);

        try {
            // Get execution steps
            $steps = $this->getExecutionSteps($executionId);

            // Ensure sources are extracted if not provided
            if (empty($sources)) {
                $sources = $this->extractSourceLinksFromText($result);
            }

            // Truncate payload if needed
            $truncated = self::truncatePayloadIfNeeded($result, $metadata, $sources, $steps, $interactionId);

            $event = new HolisticWorkflowCompleted(
                $interactionId,
                $executionId,
                $truncated['result'],
                $truncated['metadata'],
                $sources,
                $steps
            );

            Log::info('BroadcastsWorkflowEvents: Created HolisticWorkflowCompleted event object', [
                'event_class' => get_class($event),
                'interaction_id' => $interactionId,
            ]);

            // Dispatch event - Laravel will handle broadcasting via ShouldBroadcastNow
            event($event);

            // Also explicitly broadcast to ensure delivery from job context
            \Illuminate\Support\Facades\Broadcast::connection('reverb')
                ->channel('chat-interaction.'.$interactionId)
                ->broadcast('HolisticWorkflowCompleted', $event->broadcastWith());

            Log::info('BroadcastsWorkflowEvents: Dispatched and explicitly broadcast HolisticWorkflowCompleted event');
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast holistic completion', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast completion for single-agent workflow
     */
    protected function broadcastSingleAgentCompletion(
        int $interactionId,
        int $executionId,
        string $result,
        array $metadata
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting single-agent completion', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);

        try {
            // Get execution steps and sources
            $steps = $this->getExecutionSteps($executionId);
            $sources = $this->extractSourceLinksFromText($result);

            // Merge sources and steps into metadata
            $enrichedMetadata = array_merge($metadata, [
                'sources' => $sources,
                'steps' => $steps,
            ]);

            // Truncate payload if needed
            $truncated = self::truncatePayloadIfNeeded($result, $enrichedMetadata, $sources, $steps, $interactionId);

            $event = new ResearchComplete(
                $interactionId,
                $executionId,
                $truncated['result'],
                $truncated['metadata']
            );

            Log::info('BroadcastsWorkflowEvents: Created ResearchComplete event object', [
                'event_class' => get_class($event),
                'interaction_id' => $interactionId,
            ]);

            // Dispatch event - Laravel will handle broadcasting via ShouldBroadcastNow
            event($event);

            // Also explicitly broadcast to ensure delivery from job context
            \Illuminate\Support\Facades\Broadcast::connection('reverb')
                ->channel('chat-interaction.'.$interactionId)
                ->broadcast('ResearchComplete', $event->broadcastWith());

            Log::info('BroadcastsWorkflowEvents: Dispatched and explicitly broadcast ResearchComplete event');
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast single-agent completion', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Static version for use in closures - broadcasts single-agent completion
     */
    public static function broadcastSingleAgentCompletionStatic(
        int $interactionId,
        int $executionId,
        string $result,
        array $metadata
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting single-agent completion (static)', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);

        try {
            // Get execution steps
            $steps = self::getExecutionStepsStatic($executionId);

            // Extract sources from result
            $sources = UrlExtractorService::extract($result);

            // Merge sources and steps into metadata
            $enrichedMetadata = array_merge($metadata, [
                'sources' => $sources,
                'steps' => $steps,
            ]);

            // Truncate payload if needed
            $truncated = self::truncatePayloadIfNeeded($result, $enrichedMetadata, $sources, $steps, $interactionId);

            $event = new ResearchComplete(
                $interactionId,
                $executionId,
                $truncated['result'],
                $truncated['metadata']
            );

            // Dispatch event
            event($event);

            // Also explicitly broadcast to ensure delivery from job context
            \Illuminate\Support\Facades\Broadcast::connection('reverb')
                ->channel('chat-interaction.'.$interactionId)
                ->broadcast('ResearchComplete', $event->broadcastWith());

            Log::info('BroadcastsWorkflowEvents: Dispatched and explicitly broadcast ResearchComplete event (static)');
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast single-agent completion (static)', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast failure for holistic workflow
     */
    protected function broadcastHolisticFailure(
        int $interactionId,
        int $executionId,
        string $error,
        ?string $phase = null
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting holistic workflow failure', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'error' => $error,
            'phase' => $phase,
        ]);

        try {
            event(new HolisticWorkflowFailed(
                $interactionId,
                $executionId,
                $error,
                [],
                $phase
            ));

            Log::info('BroadcastsWorkflowEvents: Fired HolisticWorkflowFailed event', [
                'phase' => $phase,
            ]);
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast holistic failure', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast failure for single-agent workflow
     */
    protected function broadcastSingleAgentFailure(
        int $interactionId,
        int $executionId,
        string $error
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting single-agent failure', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'error' => $error,
        ]);

        try {
            event(new ResearchFailed(
                $interactionId,
                $executionId,
                $error
            ));

            Log::info('BroadcastsWorkflowEvents: Fired ResearchFailed event');
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast single-agent failure', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Static version for use in closures - broadcasts holistic completion
     */
    public static function broadcastHolisticCompletionStatic(
        int $interactionId,
        int $executionId,
        string $result,
        array $metadata,
        array $sources = []
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting holistic completion (static)', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);

        try {
            // Get execution steps
            $steps = self::getExecutionStepsStatic($executionId);

            // Ensure sources are extracted if not provided
            if (empty($sources)) {
                $sources = UrlExtractorService::extract($result);
            }

            // Truncate payload if needed
            $truncated = self::truncatePayloadIfNeeded($result, $metadata, $sources, $steps, $interactionId);

            $event = new HolisticWorkflowCompleted(
                $interactionId,
                $executionId,
                $truncated['result'],
                $truncated['metadata'],
                $sources,
                $steps
            );

            // Dispatch event
            event($event);

            // Also explicitly broadcast to ensure delivery from job context
            \Illuminate\Support\Facades\Broadcast::connection('reverb')
                ->channel('chat-interaction.'.$interactionId)
                ->broadcast('HolisticWorkflowCompleted', $event->broadcastWith());

            Log::info('BroadcastsWorkflowEvents: Dispatched and explicitly broadcast HolisticWorkflowCompleted event (static)');
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast holistic completion (static)', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Static version for use in closures - broadcasts holistic failure
     */
    public static function broadcastHolisticFailureStatic(
        int $interactionId,
        int $executionId,
        string $error
    ): void {
        Log::info('BroadcastsWorkflowEvents: Broadcasting holistic failure (static)', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'error' => $error,
        ]);

        try {
            event(new HolisticWorkflowFailed(
                $interactionId,
                $executionId,
                $error
            ));

            Log::info('BroadcastsWorkflowEvents: Fired HolisticWorkflowFailed event (static)');
        } catch (Throwable $e) {
            Log::error('BroadcastsWorkflowEvents: Failed to broadcast holistic failure (static)', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get execution steps from metadata
     */
    protected function getExecutionSteps(int $executionId): array
    {
        $execution = AgentExecution::find($executionId);

        return $execution && isset($execution->metadata['execution_steps'])
            ? $execution->metadata['execution_steps']
            : [];
    }

    /**
     * Static version of getExecutionSteps
     */
    protected static function getExecutionStepsStatic(int $executionId): array
    {
        $execution = AgentExecution::find($executionId);

        return $execution && isset($execution->metadata['execution_steps'])
            ? $execution->metadata['execution_steps']
            : [];
    }

    /**
     * Extract source links from result text
     */
    protected function extractSourceLinksFromText(string $text): array
    {
        return UrlExtractorService::extract($text);
    }

    /**
     * Truncate broadcast payload if it exceeds Pusher limits
     *
     * @param  string  $result  The full result text
     * @param  array  $metadata  The metadata array
     * @param  array  $sources  The sources array
     * @param  array  $steps  The execution steps array
     * @param  int  $interactionId  For logging purposes
     * @return array ['result' => string, 'metadata' => array] - potentially truncated
     */
    protected static function truncatePayloadIfNeeded(
        string $result,
        array $metadata,
        array $sources,
        array $steps,
        int $interactionId
    ): array {
        // Estimate payload size (Pusher limit is ~10KB)
        // Account for JSON encoding overhead, metadata, sources, steps
        $estimatedSize = strlen($result) + strlen(json_encode($metadata)) + strlen(json_encode($sources)) + strlen(json_encode($steps));
        $maxPayloadSize = 8000; // Conservative limit (Pusher is ~10KB, leave buffer)

        $broadcastResult = $result;
        $broadcastMetadata = $metadata;

        if ($estimatedSize > $maxPayloadSize) {
            // Calculate space available for result
            $metadataSize = strlen(json_encode($metadata));
            $sourcesSize = strlen(json_encode($sources));
            $stepsSize = strlen(json_encode($steps));
            $truncationMessageSize = 250; // Reserve space for truncation message
            $jsonOverhead = 500; // Reserve space for JSON structure overhead

            $maxResultLength = max(
                2000, // Minimum 2000 chars to ensure useful content
                $maxPayloadSize - $metadataSize - $sourcesSize - $stepsSize - $truncationMessageSize - $jsonOverhead
            );

            $broadcastResult = substr($result, 0, $maxResultLength);
            $broadcastResult .= "\n\n... [Content truncated for broadcast - Full results have been saved and are now visible in your chat]\n\n";
            $broadcastResult .= '📊 **Results Summary**: '.strlen($result).' characters, '.count($sources)." sources\n";

            // Add flag to metadata indicating this is truncated broadcast
            $broadcastMetadata['broadcast_truncated'] = true;
            $broadcastMetadata['full_result_length'] = strlen($result);

            Log::warning('BroadcastsWorkflowEvents: Truncated result for broadcast due to size', [
                'interaction_id' => $interactionId,
                'original_size' => strlen($result),
                'truncated_size' => strlen($broadcastResult),
                'estimated_payload_size' => $estimatedSize,
                'max_result_length' => $maxResultLength,
            ]);
        }

        return [
            'result' => $broadcastResult,
            'metadata' => $broadcastMetadata,
        ];
    }
}
