<?php

namespace App\Models;

use App\Events\StatusStreamCreated;
use App\Services\StatusReporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StatusStream - Model for storing and broadcasting status updates
 */
class StatusStream extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'interaction_id',
        'agent_execution_id',
        'source',
        'message',
        'timestamp',
        'metadata',
        'is_significant',
        'create_event',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'timestamp' => 'datetime',
        'metadata' => 'array',
        'is_significant' => 'boolean',
        'create_event' => 'boolean',
    ];

    /**
     * Get the chat interaction that owns the status stream.
     */
    public function chatInteraction(): BelongsTo
    {
        return $this->belongsTo(ChatInteraction::class, 'interaction_id');
    }

    /**
     * Get the agent execution that triggered this status stream.
     */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'agent_execution_id');
    }

    /**
     * Create a new status stream record and broadcast via WebSockets
     *
     * Enhanced with additional metadata, consistent formatting,
     * and guaranteed broadcasting.
     *
     * @param  int  $interactionId  The interaction ID
     * @param  string  $source  The source of the status update
     * @param  string  $message  The status message
     * @param  array  $metadata  Additional metadata
     * @param  bool  $isSignificant  Whether this is a significant update
     * @param  int|null  $agentExecutionId  The agent execution ID if triggered by an agent
     */
    public static function report(int $interactionId, string $source, string $message, array $metadata = [], bool $createEvent = true, bool $isSignificant = false, ?int $agentExecutionId = null): self
    {
        try {
            // Verify interaction exists before trying to create status record
            // This prevents foreign key constraint violations in race conditions
            if (! ChatInteraction::where('id', $interactionId)->exists()) {
                \Log::warning('StatusStream::report() - Interaction does not exist, skipping status record', [
                    'interaction_id' => $interactionId,
                    'source' => $source,
                    'message' => $message,
                ]);

                // Return a fallback object without saving to DB
                $fallback = new self([
                    'interaction_id' => $interactionId,
                    'agent_execution_id' => $agentExecutionId,
                    'source' => $source,
                    'message' => $message,
                    'timestamp' => now(),
                    'metadata' => array_merge($metadata, [
                        'skipped' => true,
                        'reason' => 'interaction_deleted',
                        'step_type' => 'info',
                    ]),
                    'create_event' => false,
                    'is_significant' => $isSignificant,
                ]);
                $fallback->exists = true;

                return $fallback;
            }

            // Sanitize message to ensure valid UTF-8 (web scraping can introduce invalid characters)
            $message = self::sanitizeUtf8($message);
            $source = self::sanitizeUtf8($source);

            // Get step type for UI display using StatusReporter
            $statusReporter = app()->make(StatusReporter::class);
            $stepType = $statusReporter->determineStepType($source, $message);

            // Enhance metadata with additional useful info
            $enhancedMetadata = array_merge($metadata, [
                'step_type' => $stepType,
                'timestamp_iso' => now()->toISOString(),
            ]);

            // Sanitize metadata strings recursively
            $enhancedMetadata = self::sanitizeUtf8Recursive($enhancedMetadata);

            // Create the status stream entry
            $statusStream = self::create([
                'interaction_id' => $interactionId,
                'agent_execution_id' => $agentExecutionId,
                'source' => $source,
                'message' => $message,
                'timestamp' => now(),
                'metadata' => $enhancedMetadata,
                'create_event' => $createEvent,
                'is_significant' => $isSignificant,
            ]);

            // Always broadcast events via WebSockets
            event(new StatusStreamCreated($statusStream));

            // Also queue for SSE streaming (used by CLI and API clients)
            \App\Services\EventStreamNotifier::stepAdded(
                $interactionId,
                $source,
                $message
            );

            return $statusStream;

        } catch (\Exception $e) {
            // Handle any database or other errors
            \Log::error('StatusStream::report() - Failed to create record', [
                'interaction_id' => $interactionId,
                'agent_execution_id' => $agentExecutionId,
                'source' => $source,
                'message' => $message,
                'metadata' => $metadata,
                'is_significant' => $isSignificant,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // Return a fallback object to prevent crashes
            $fallback = new self([
                'interaction_id' => $interactionId,
                'agent_execution_id' => $agentExecutionId,
                'source' => 'system_error',
                'message' => 'Status reporting error occurred',
                'timestamp' => now(),
                'metadata' => [
                    'error' => $e->getMessage(),
                    'original_source' => $source,
                    'original_message' => $message,
                    'step_type' => 'error',
                ],
                'create_event' => true,
                'is_significant' => false,
            ]);

            // Do not save the fallback object to the database
            $fallback->exists = true;

            return $fallback;
        }
    }

    /**
     * Get formatted data for frontend display
     */
    public function toDisplayArray(): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'id' => $this->id,
            'type' => $metadata['step_type'] ?? 'info',
            'source' => $this->source,
            'message' => $this->message,
            'timestamp' => $this->timestamp->format('H:i:s'),
            'timestamp_iso' => $this->timestamp->toISOString(),
            'metadata' => $metadata,
            'is_significant' => $this->is_significant,
            'create_event' => $this->create_event ?? true,
            'agent_execution_id' => $this->agent_execution_id,
        ];
    }

    /**
     * Sanitize a string to ensure valid UTF-8 encoding
     *
     * Web scraping can introduce invalid UTF-8 characters that cause database errors.
     * This method removes/replaces invalid sequences while preserving valid content.
     *
     * @param  string  $value  The string to sanitize
     * @return string The sanitized string with valid UTF-8 encoding
     */
    protected static function sanitizeUtf8(string $value): string
    {
        // Remove invalid UTF-8 sequences
        // mb_convert_encoding with UTF-8//IGNORE drops invalid sequences
        $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Alternative: replace invalid sequences with replacement character
        // This preserves string length but may introduce � characters
        // $sanitized = mb_scrub($value, 'UTF-8');

        return $sanitized;
    }

    /**
     * Recursively sanitize UTF-8 in array values
     *
     * Walks through array/object structures and sanitizes all string values
     * to ensure metadata can be safely JSON-encoded for database storage.
     *
     * @param  mixed  $data  The data to sanitize (array, string, or other)
     * @return mixed The sanitized data with same structure
     */
    protected static function sanitizeUtf8Recursive(mixed $data): mixed
    {
        if (is_string($data)) {
            return self::sanitizeUtf8($data);
        }

        if (is_array($data)) {
            return array_map(fn ($value) => self::sanitizeUtf8Recursive($value), $data);
        }

        // Objects, numbers, booleans, null pass through unchanged
        return $data;
    }
}
