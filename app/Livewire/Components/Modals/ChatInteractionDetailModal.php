<?php

namespace App\Livewire\Components\Modals;

use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\StatusStream;
use Livewire\Component;

class ChatInteractionDetailModal extends Component
{
    public bool $show = false;

    public ?ChatInteraction $interaction = null;

    public ?AgentExecution $agentExecution = null;

    public array $sources = [];

    public array $formattedInput = [];

    public array $formattedOutput = [];

    public array $formattedMetadata = [];

    public array $executionSteps = [];

    public ?string $error = null;

    protected $listeners = [
        'openInteractionModal' => 'openModal',
        'closeInteractionModal' => 'closeModal',
    ];

    public function openModal($interactionId)
    {
        try {
            $this->interaction = ChatInteraction::with([
                'session',
                'user',
                'agentExecution',
                'agent',
                'sources.source',
                'knowledgeSources.knowledgeDocument.tags',
                'attachments',
            ])->find($interactionId);

            if (! $this->interaction) {
                $this->error = "Interaction not found (ID: {$interactionId})";
                $this->show = true;

                return;
            }

            // Load agent execution if available
            $this->agentExecution = $this->interaction->agentExecution;

            // Format sources
            $this->sources = $this->interaction->getAllSources()->toArray();

            // Format complex data fields
            if ($this->agentExecution) {
                $this->formattedInput = $this->formatJsonField($this->agentExecution->input);
                $this->formattedOutput = $this->formatJsonField($this->agentExecution->output);
                $this->formattedMetadata = $this->formatJsonField($this->agentExecution->metadata ?? []);
            }

            // Load execution steps (StatusStream entries)
            $this->executionSteps = $this->loadExecutionSteps();

            $this->error = null;
            $this->show = true;

        } catch (\Exception $e) {
            $this->error = 'Error loading interaction details: '.$e->getMessage();
            $this->show = true;
        }
    }

    public function closeModal()
    {
        $this->show = false;
        $this->interaction = null;
        $this->agentExecution = null;
        $this->sources = [];
        $this->formattedInput = [];
        $this->formattedOutput = [];
        $this->formattedMetadata = [];
        $this->executionSteps = [];
        $this->error = null;
    }

    public function copyToClipboard($content, $successMessage = 'Content copied to clipboard')
    {
        if (empty($content)) {
            $this->dispatch('notify', [
                'message' => 'No content available to copy',
                'type' => 'error',
            ]);

            return;
        }

        $this->dispatch('copy-content-to-clipboard', [
            'content' => json_encode($content),
            'successMessage' => $successMessage,
        ]);
    }

    // Convenience methods that use the centralized copy function
    public function copyInteractionId()
    {
        $this->copyToClipboard($this->interaction?->id, 'Interaction ID copied to clipboard');
    }

    public function copyInteractionQuestion()
    {
        $this->copyToClipboard($this->interaction?->question, 'Question copied to clipboard');
    }

    public function copyInteractionAnswer()
    {
        $this->copyToClipboard($this->interaction?->answer, 'Answer copied to clipboard');
    }

    public function copyInteractionSummary()
    {
        $this->copyToClipboard($this->interaction?->summary, 'Summary copied to clipboard');
    }

    public function retryExecution($executionId)
    {
        try {
            $execution = AgentExecution::findOrFail($executionId);

            // Only allow retry for failed executions
            if ($execution->status !== 'failed') {
                $this->dispatch('notification',
                    type: 'error',
                    message: 'Only failed executions can be retried.'
                );

                return;
            }

            // Create a new execution with the same input and configuration
            $newExecution = $execution->replicate([
                'output',
                'error_message',
                'started_at',
                'completed_at',
                'status',
                'state',
            ]);

            // Explicitly clear fields that should be reset for retry
            $newExecution->output = null;
            $newExecution->error_message = null;
            $newExecution->started_at = null;
            $newExecution->completed_at = null;

            $newExecution->status = 'pending';
            $newExecution->state = AgentExecution::STATE_PENDING;
            $newExecution->save();

            // Create a StatusStream entry for the retry
            if ($this->interaction) {
                \Log::info('Creating StatusStream entry for retry', [
                    'interaction_id' => $this->interaction->id,
                    'original_execution_id' => $execution->id,
                    'new_execution_id' => $newExecution->id,
                ]);

                $statusStreamEntry = StatusStream::report(
                    $this->interaction->id,
                    'system',
                    "Retrying failed execution (Original ID: {$execution->id})",
                    [
                        'step_type' => 'retry',
                        'original_execution_id' => $execution->id,
                        'retry_reason' => $execution->error_message,
                    ],
                    true, // create_event
                    true, // is_significant
                    $newExecution->id // agent_execution_id
                );

                \Log::info('StatusStream entry created for retry', [
                    'status_stream_id' => $statusStreamEntry->id,
                    'interaction_id' => $statusStreamEntry->interaction_id,
                    'agent_execution_id' => $statusStreamEntry->agent_execution_id,
                ]);
            }

            // Dispatch the execution job
            \App\Jobs\ExecuteAgentJob::dispatch($newExecution);

            $this->dispatch('notification',
                type: 'success',
                message: 'Execution retry initiated successfully.'
            );

            // Refresh the execution steps to show the new retry
            $this->executionSteps = $this->loadExecutionSteps();

        } catch (\Exception $e) {
            \Log::error('Failed to retry execution', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notification',
                type: 'error',
                message: 'Failed to retry execution: '.$e->getMessage()
            );
        }
    }

    private function formatJsonField($data): array
    {
        if (empty($data)) {
            return [
                'raw' => null,
                'formatted' => '',
                'isEmpty' => true,
            ];
        }

        // If it's a string, try to decode it as JSON
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        if (is_array($data) || is_object($data)) {
            return [
                'raw' => $data,
                'formatted' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'isEmpty' => empty($data),
            ];
        }

        return [
            'raw' => $data,
            'formatted' => (string) $data,
            'isEmpty' => empty($data),
        ];
    }

    public function getStatusBadgeClass(): string
    {
        if (! $this->agentExecution) {
            return 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300';
        }

        return match ($this->agentExecution->status) {
            'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'running' => 'bg-tropical-teal-100 text-tropical-teal-800 dark:bg-tropical-teal-900 dark:text-tropical-teal-300',
            'failed' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            'cancelled' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
            default => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300'
        };
    }

    public function getExecutionDuration(): ?string
    {
        if (! $this->agentExecution || ! $this->agentExecution->started_at || ! $this->agentExecution->completed_at) {
            return null;
        }

        $duration = $this->agentExecution->getDuration();
        if ($duration === null) {
            return null;
        }

        if ($duration < 60) {
            return number_format($duration, 1).'s';
        } elseif ($duration < 3600) {
            return number_format($duration / 60, 1).'m';
        } else {
            return number_format($duration / 3600, 1).'h';
        }
    }

    public function downloadAttachment($attachmentId)
    {
        return redirect()->route('chat.attachment.download', $attachmentId);
    }

    private function loadExecutionSteps(): array
    {
        if (! $this->interaction) {
            return [];
        }

        // PERFORMANCE: Load executions with their grouped StatusStream timeline
        // Get unique executions for this interaction
        $executionIds = StatusStream::where('interaction_id', $this->interaction->id)
            ->whereNotNull('agent_execution_id')
            ->distinct()
            ->pluck('agent_execution_id');

        if ($executionIds->isEmpty()) {
            return [];
        }

        // Load executions with basic info
        $executions = AgentExecution::with(['agent:id,name'])
            ->select(['id', 'agent_id', 'status', 'state', 'error_message', 'started_at', 'completed_at', 'created_at'])
            ->whereIn('id', $executionIds)
            ->orderBy('created_at', 'asc')
            ->get();

        // Load all StatusStream entries for these executions
        $allStreams = StatusStream::where('interaction_id', $this->interaction->id)
            ->whereIn('agent_execution_id', $executionIds)
            ->select(['id', 'agent_execution_id', 'source', 'message', 'timestamp', 'metadata', 'is_significant'])
            ->orderBy('timestamp', 'asc')
            ->get()
            ->groupBy('agent_execution_id');

        $steps = [];
        foreach ($executions as $execution) {
            $streams = $allStreams->get($execution->id, collect());

            // Get primary stream (first one) for detail modal
            $primaryStream = $streams->first();

            $timelineSteps = [];
            foreach ($streams as $stream) {
                $metadata = $stream->metadata ?? [];
                $timelineSteps[] = [
                    'id' => $stream->id,
                    'message' => $stream->message,
                    'timestamp' => $stream->timestamp,
                    'source' => $stream->source,
                    'step_type' => $metadata['step_type'] ?? null,
                    'is_significant' => $stream->is_significant,
                ];
            }

            $steps[] = [
                'id' => $execution->id,
                'status_stream_id' => $primaryStream?->id, // For opening detail modal
                'agent_name' => $execution->agent->name ?? 'Unknown Agent',
                'status' => $execution->status,
                'state' => $execution->state,
                'error_message' => $execution->error_message,
                'started_at' => $execution->started_at,
                'completed_at' => $execution->completed_at,
                'created_at' => $execution->created_at,
                'duration' => $this->calculateDuration($execution->started_at, $execution->completed_at),
                'steps' => $timelineSteps,
            ];
        }

        return $steps;
    }

    private function calculateDuration($start, $end): ?string
    {
        if (! $start || ! $end) {
            return null;
        }

        $seconds = $start->diffInSeconds($end);

        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        } elseif ($seconds < 3600) {
            return number_format($seconds / 60, 1).'m';
        } else {
            return number_format($seconds / 3600, 1).'h';
        }
    }

    public function getExecutionStatusIcon($status): string
    {
        return match ($status) {
            'completed' => '✅',
            'running' => '⏳',
            'failed' => '❌',
            'cancelled' => '🚫',
            'pending' => '⌛',
            default => 'ℹ️'
        };
    }

    public function getExecutionStatusColor($status): string
    {
        return match ($status) {
            'completed' => 'text-green-600 dark:text-green-400',
            'running' => 'text-tropical-teal-600 dark:text-tropical-teal-400',
            'failed' => 'text-red-600 dark:text-red-400',
            'cancelled' => 'text-yellow-600 dark:text-yellow-400',
            'pending' => 'text-gray-600 dark:text-gray-400',
            default => 'text-gray-600 dark:text-gray-400'
        };
    }

    public function getExecutionStatusBadgeClass($status): string
    {
        return match ($status) {
            'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
            'running' => 'bg-tropical-teal-100 text-tropical-teal-800 dark:bg-tropical-teal-900 dark:text-tropical-teal-300',
            'failed' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
            'cancelled' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
            'pending' => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300',
            default => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300'
        };
    }

    public function getStepTypeIcon($stepType): string
    {
        return match ($stepType) {
            'search' => '🔍',
            'validation' => '✅',
            'download' => '⬇️',
            'analysis' => '🧠',
            'complete' => '✅',
            'error' => '❌',
            'retry' => '🔄',
            'info' => 'ℹ️',
            default => '•'
        };
    }

    public function render()
    {
        return view('livewire.components.modals.chat-interaction-detail-modal');
    }
}
