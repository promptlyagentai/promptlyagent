<?php

namespace App\Livewire\Components\Modals;

use App\Models\StatusStream;
use Livewire\Component;

class ExecutionStepDetailModal extends Component
{
    public bool $show = false;

    public ?StatusStream $statusStream = null;

    public array $formattedMetadata = [];

    public ?string $error = null;

    public bool $showFullExecutionDetails = false;

    protected $listeners = [
        'openStepModal' => 'openModal',
        'closeStepModal' => 'closeModal',
    ];

    public function openModal($stepId)
    {
        try {
            $this->statusStream = StatusStream::with([
                'chatInteraction',
                'agentExecution.agent',
            ])->find($stepId);

            if (! $this->statusStream) {
                $this->error = "Step details not found (ID: {$stepId})";
                $this->show = true;

                return;
            }

            // Format metadata for display
            $this->formattedMetadata = $this->formatMetadata($this->statusStream->metadata ?? []);
            $this->error = null;
            $this->show = true;

        } catch (\Exception $e) {
            $this->error = 'Error loading step details: '.$e->getMessage();
            $this->show = true;
        }
    }

    public function closeModal()
    {
        $this->show = false;
        $this->statusStream = null;
        $this->formattedMetadata = [];
        $this->error = null;
        $this->showFullExecutionDetails = false;
    }

    public function toggleExecutionDetails()
    {
        $this->showFullExecutionDetails = ! $this->showFullExecutionDetails;
    }

    private function formatMetadata(array $metadata): array
    {
        $formatted = [];

        foreach ($metadata as $key => $value) {
            $formatted[] = [
                'key' => $key,
                'value' => $value,
                'type' => $this->getValueType($value),
                'formatted_value' => $this->formatValue($value),
            ];
        }

        return $formatted;
    }

    private function getValueType($value): string
    {
        if (is_array($value) || is_object($value)) {
            return 'json';
        } elseif (is_bool($value)) {
            return 'boolean';
        } elseif (is_numeric($value)) {
            return 'number';
        } elseif ($this->isUrl($value)) {
            return 'url';
        } elseif ($this->isTimestamp($value)) {
            return 'timestamp';
        }

        return 'string';
    }

    private function formatValue($value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif ($this->isTimestamp($value)) {
            try {
                return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s T');
            } catch (\Exception $e) {
                return (string) $value;
            }
        }

        return (string) $value;
    }

    private function isUrl($value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function isTimestamp($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        // Check for ISO 8601 format or other common timestamp patterns
        return preg_match('/^\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}/', $value) === 1;
    }

    public function getStepTypeIcon(): string
    {
        if (! $this->statusStream) {
            return '❓';
        }

        $metadata = $this->statusStream->metadata ?? [];
        $stepType = $metadata['step_type'] ?? 'info';

        return match ($stepType) {
            'search' => '🔍',
            'validation' => '✅',
            'download' => '⬇️',
            'analysis' => '🧠',
            'complete' => '✅',
            'error' => '❌',
            default => 'ℹ️'
        };
    }

    public function getStepTypeColor(): string
    {
        if (! $this->statusStream) {
            return 'text-gray-500';
        }

        $metadata = $this->statusStream->metadata ?? [];
        $stepType = $metadata['step_type'] ?? 'info';

        return match ($stepType) {
            'search' => 'text-tropical-teal-600 dark:text-tropical-teal-400',
            'validation' => 'text-green-600 dark:text-green-400',
            'download' => 'text-indigo-600 dark:text-indigo-400',
            'analysis' => 'text-purple-600 dark:text-purple-400',
            'complete' => 'text-green-600 dark:text-green-400',
            'error' => 'text-red-600 dark:text-red-400',
            default => 'text-gray-600 dark:text-gray-400'
        };
    }

    public function copyToClipboard($text)
    {
        $this->dispatch('copy-to-clipboard', text: $text);
    }

    public function saveOutputToChat()
    {
        if (! $this->statusStream || ! $this->statusStream->agentExecution) {
            $this->dispatch('notification',
                type: 'error',
                message: 'No agent execution found.'
            );

            return;
        }

        $execution = $this->statusStream->agentExecution;

        // Check if execution has output
        if (empty($execution->output)) {
            $this->dispatch('notification',
                type: 'error',
                message: 'No output available to save.'
            );

            return;
        }

        // Check if there's a related chat interaction
        if (! $this->statusStream->chatInteraction) {
            $this->dispatch('notification',
                type: 'error',
                message: 'No chat interaction found to save output to.'
            );

            return;
        }

        try {
            $interaction = $this->statusStream->chatInteraction;

            // Update the interaction's answer with the agent execution output
            // If there's already an answer, append the new output
            if (! empty($interaction->answer)) {
                $interaction->answer .= "\n\n---\n\n**Agent Execution Result:**\n\n".$execution->output;
            } else {
                $interaction->answer = $execution->output;
            }

            $interaction->save();

            \Log::info('Saved agent execution output to chat interaction', [
                'interaction_id' => $interaction->id,
                'execution_id' => $execution->id,
                'output_length' => strlen($execution->output),
            ]);

            $this->dispatch('notification',
                type: 'success',
                message: 'Output saved to chat interaction successfully.'
            );

            // Dispatch event to refresh the chat interface
            $this->dispatch('interaction-updated', interactionId: $interaction->id);

        } catch (\Exception $e) {
            \Log::error('Failed to save output to chat interaction', [
                'execution_id' => $execution->id,
                'interaction_id' => $this->statusStream->chatInteraction->id ?? null,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notification',
                type: 'error',
                message: 'Failed to save output: '.$e->getMessage()
            );
        }
    }

    public function retryExecution()
    {
        if (! $this->statusStream || ! $this->statusStream->agentExecution) {
            return;
        }

        $execution = $this->statusStream->agentExecution;

        // Only allow retry for failed executions
        if ($execution->status !== 'failed') {
            $this->dispatch('notification',
                type: 'error',
                message: 'Only failed executions can be retried.'
            );

            return;
        }

        try {
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
            $newExecution->state = \App\Models\AgentExecution::STATE_PENDING;
            $newExecution->save();

            // Create a StatusStream entry for the retry
            // Use the current statusStream's interaction_id since execution may not have chat_session_id
            if ($this->statusStream && $this->statusStream->interaction_id) {
                \Log::info('Creating StatusStream entry for retry', [
                    'interaction_id' => $this->statusStream->interaction_id,
                    'original_execution_id' => $execution->id,
                    'new_execution_id' => $newExecution->id,
                ]);

                $statusStreamEntry = \App\Models\StatusStream::report(
                    $this->statusStream->interaction_id,
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

            // Close the modal and refresh the parent view
            $this->closeModal();
            $this->dispatch('execution-retried', executionId: $newExecution->id);

        } catch (\Exception $e) {
            \Log::error('Failed to retry execution', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notification',
                type: 'error',
                message: 'Failed to retry execution: '.$e->getMessage()
            );
        }
    }

    public function render()
    {
        return view('livewire.components.modals.execution-step-detail-modal');
    }
}
