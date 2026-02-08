<?php

namespace App\Models;

use App\Scout\Traits\HasVectorSearch;
use App\Services\ChatInteractionSummaryService;
use App\Services\EventStreamNotifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * Chat Interaction - Individual question-answer pair within a conversation
 *
 * Represents a single turn in a multi-turn conversation between a user and an agent.
 * Each interaction contains a question (user input), answer (agent output), and links
 * to the agent execution that generated the response.
 *
 * ## Key Features
 * - **Source Tracking**: Links to knowledge documents used to generate the answer
 * - **Attachments**: Supports file uploads with the question (images, PDFs, etc.)
 * - **Vector Search**: Embeddings for semantic search across conversation history
 * - **Automatic Summarization**: AI-generated summaries for long conversations
 * - **Streaming Support**: Real-time answer generation with progress tracking
 * - **External Triggers**: Can be initiated from Slack, webhooks, or other integrations
 *
 * ## Relationships
 * - `session` - BelongsTo ChatSession (conversation container)
 * - `user` - BelongsTo User (who asked the question)
 * - `agent` - BelongsTo Agent (which agent was used)
 * - `agentExecution` - BelongsTo AgentExecution (the execution that generated the answer)
 * - `attachments` - BelongsToMany Asset (files uploaded with question)
 * - `knowledgeDocuments` - BelongsToMany KnowledgeDocument (sources used)
 * - `agentExecutions` - HasMany AgentExecution (all executions for this interaction)
 *
 * ## Metadata Structure
 * ```php
 * [
 *     'source_links' => [['url' => '...', 'title' => '...']],
 *     'execution_strategy' => 'simple|parallel|sequential',
 *     'research_threads' => int,
 *     'total_sources' => int,
 *     'thinking_time_ms' => int,
 *     'holistic_research' => bool,
 * ]
 * ```
 *
 * ## Performance Notes
 * - Default query builder excludes `metadata` column to prevent MySQL sort buffer issues
 * - Use `->addSelect('metadata')` when metadata is needed
 * - Vector embeddings stored separately for efficient semantic search
 *
 * @property int $id
 * @property int $chat_session_id
 * @property int $user_id
 * @property int|null $agent_id
 * @property int|null $agent_execution_id
 * @property int|null $input_trigger_id
 * @property string $question
 * @property string|null $answer
 * @property string|null $summary
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @see \App\Models\ChatSession
 * @see \App\Models\AgentExecution
 * @see \App\Services\ChatInteractionSummaryService
 */
class ChatInteraction extends Model
{
    use HasFactory, HasVectorSearch;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chat_session_id',
        'user_id',
        'agent_execution_id',
        'input_trigger_id',
        'agent_id',
        'question',
        'answer',
        'summary',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Create a new Eloquent query builder for the model.
     *
     * Automatically excludes the large 'metadata' column to prevent MySQL sort buffer
     * memory errors. Use ->addSelect('metadata') if you need to include it.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newQuery()
    {
        return parent::newQuery()->select([
            'id',
            'chat_session_id',
            'user_id',
            'agent_execution_id',
            'input_trigger_id',
            'agent_id',
            'question',
            'answer',
            'summary',
            'created_at',
            'updated_at',
            // 'metadata' excluded by default - use ->addSelect('metadata') when needed
        ]);
    }

    protected static function boot()
    {
        parent::boot();

        // Broadcast when a new interaction is created (for real-time UI updates)
        static::created(function ($chatInteraction) {
            // Always broadcast on session channel when interaction is created
            // This ensures API-triggered interactions appear in the UI immediately
            event(new \App\Events\ChatInteractionCreated($chatInteraction));

            Log::info('ChatInteraction: New interaction created, broadcast on session channel', [
                'interaction_id' => $chatInteraction->id,
                'session_id' => $chatInteraction->chat_session_id,
                'has_answer' => ! empty($chatInteraction->answer),
                'input_trigger_id' => $chatInteraction->input_trigger_id,
            ]);
        });

        // Handle both created and updated events for answer processing
        $handleAnswerChange = function ($chatInteraction) {
            // Check if answer exists and either just changed (updated) or was just created
            $hasAnswer = ! empty($chatInteraction->answer);
            $answerChanged = $chatInteraction->isDirty('answer') || $chatInteraction->wasRecentlyCreated;

            if ($hasAnswer && $answerChanged) {
                // Send real-time interaction updated event when answer changes
                EventStreamNotifier::interactionUpdated($chatInteraction->id, $chatInteraction->answer);

                // Generate title for session if this is the first interaction with an answer
                \App\Services\SessionTitleService::generateTitleIfNeeded($chatInteraction);

                // Generate summary if answer is provided and no summary exists
                if (empty($chatInteraction->summary)) {
                    try {
                        $summaryService = app(ChatInteractionSummaryService::class);
                        $summary = $summaryService->generateSummary($chatInteraction);
                        if ($summary) {
                            // Update without triggering events again to avoid recursion
                            $chatInteraction->updateQuietly(['summary' => $summary]);
                            \Illuminate\Support\Facades\Log::info('ChatInteraction: Generated summary', [
                                'interaction_id' => $chatInteraction->id,
                                'summary_length' => strlen($summary),
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning('ChatInteraction: Failed to generate summary', [
                            'interaction_id' => $chatInteraction->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Don't fail the interaction if summary generation fails
                    }
                }

                // Queue embedding generation for completed interactions
                static::queueEmbeddingGeneration($chatInteraction);
            }
        };

        static::created($handleAnswerChange);
        static::updated($handleAnswerChange);
    }

    /**
     * Get the session that owns the interaction.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    /**
     * Get the user that owns the interaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the agent execution that owns the interaction.
     */
    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    /**
     * Get the agent that owns the interaction.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Get the input trigger that initiated this interaction.
     */
    public function inputTrigger(): BelongsTo
    {
        return $this->belongsTo(InputTrigger::class);
    }

    /**
     * Check if this interaction was initiated by an input trigger.
     */
    public function isTriggerInitiated(): bool
    {
        return $this->input_trigger_id !== null;
    }

    /**
     * Get the trigger source type (api, webhook, slack, etc.).
     */
    public function getTriggerSource(): ?string
    {
        return $this->metadata['trigger_source'] ?? null;
    }

    /**
     * Get the web sources for the interaction.
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ChatInteractionSource::class);
    }

    /**
     * Get the knowledge sources for the interaction.
     */
    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(ChatInteractionKnowledgeSource::class);
    }

    /**
     * Get the attachments for the interaction.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatInteractionAttachment::class);
    }

    /**
     * Get the artifacts for the interaction.
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ChatInteractionArtifact::class);
    }

    /**
     * Get all sources (web + knowledge) for the interaction.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAllSources()
    {
        $webSources = $this->sources()->with('source')->get()->map(function ($source) {
            return (object) [
                'type' => 'web',
                'title' => $source->source->title ?? 'Unknown',
                'url' => $source->source->url ?? '#',
                'domain' => $source->source->domain ?? 'unknown',
                'relevance_score' => $source->relevance_score,
                'discovery_method' => $source->discovery_method,
                'discovery_tool' => $source->discovery_tool,
            ];
        });

        $knowledgeSources = $this->knowledgeSources()
            ->with(['knowledgeDocument.tags'])
            ->get()
            ->map(function ($source) {
                return (object) [
                    'type' => 'knowledge',
                    'title' => $source->knowledgeDocument->title ?? 'Untitled Document',
                    'url' => route('knowledge.preview', ['document' => $source->knowledge_document_id]),
                    'domain' => 'knowledge',
                    'relevance_score' => $source->relevance_score,
                    'discovery_method' => $source->discovery_method,
                    'discovery_tool' => $source->discovery_tool,
                    'content_excerpt' => $source->content_excerpt,
                    'tags' => $source->knowledgeDocument->tags->pluck('name')->toArray(),
                    'is_expired' => $source->knowledgeDocument->ttl_expires_at ?
                        $source->knowledgeDocument->ttl_expires_at->isPast() : false,
                ];
            });

        return collect($webSources)->merge($knowledgeSources)->sortByDesc('relevance_score');
    }

    /**
     * Get all artifacts for the interaction.
     */
    public function getAllArtifacts()
    {
        return $this->artifacts()->with('artifact')->get()->map(function ($item) {
            $artifact = $item->artifact;

            return (object) [
                'id' => $artifact->id,
                'title' => $artifact->title,
                'description' => $artifact->description,
                'filetype' => $artifact->filetype,
                'interaction_type' => $item->interaction_type,
                'tool_used' => $item->tool_used,
                'context_summary' => $item->context_summary,
                'timestamp' => $item->interacted_at,
                'word_count' => $artifact->word_count,
                'reading_time' => $artifact->reading_time,
                'filetype_badge_class' => $artifact->filetype_badge_class,
                'url' => '#',
            ];
        })->sortBy('timestamp');
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'chat_interactions';
    }

    public function toSearchableArray(): array
    {
        $array = [
            'id' => $this->getScoutKey(),
            'document_id' => $this->id, // For Scout mapping
            'chat_session_id' => $this->chat_session_id,
            'user_id' => $this->user_id,
            'agent_id' => $this->agent_id,
            'question' => $this->question,
            'answer' => $this->answer,
            'summary' => $this->summary,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
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

        if ($this->question) {
            $content[] = 'Question: '.$this->question;
        }

        if ($this->answer) {
            // Use full answer for embedding (not truncated like Source model)
            $content[] = 'Answer: '.$this->answer;
        }

        return implode("\n\n", $content);
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
        return ! empty($this->question) && ! empty($this->answer);
    }

    /**
     * Queue embedding generation for the interaction
     */
    protected static function queueEmbeddingGeneration(ChatInteraction $interaction): void
    {
        // Only queue if the interaction is searchable
        if (! $interaction->shouldBeSearchable()) {
            Log::debug('ChatInteraction: Skipping embedding generation, interaction not searchable', [
                'interaction_id' => $interaction->id,
            ]);

            return;
        }

        // Check if embeddings are enabled
        $embeddingService = app(\App\Services\Knowledge\Embeddings\EmbeddingService::class);
        if (! $embeddingService->isEnabled()) {
            Log::debug('ChatInteraction: Skipping embedding generation, service not enabled', [
                'interaction_id' => $interaction->id,
            ]);

            return;
        }

        // SECURITY: Authorization check - verify user owns the interaction
        // Skip embedding if called outside user context (background jobs, system tasks)
        if (! auth()->check()) {
            Log::debug('ChatInteraction: Skipping embedding generation, no auth context', [
                'interaction_id' => $interaction->id,
                'reason' => 'background_job_or_system_task',
            ]);

            return;
        }

        // Verify user owns this interaction (prevents embedding poisoning via other users' data)
        if ($interaction->user_id !== auth()->id()) {
            Log::warning('ChatInteraction: Unauthorized embedding generation attempt blocked', [
                'interaction_id' => $interaction->id,
                'interaction_user_id' => $interaction->user_id,
                'auth_user_id' => auth()->id(),
                'ip_address' => request()->ip(),
            ]);

            return;
        }

        // SECURITY: Rate limiting - prevent API quota exhaustion
        // Max 10 embedding generations per user per minute
        $rateLimitKey = 'embedding_generation:user_'.$interaction->user_id;
        $currentCount = \Illuminate\Support\Facades\Cache::get($rateLimitKey, 0);

        if ($currentCount >= 10) {
            Log::warning('ChatInteraction: Embedding generation rate limit exceeded', [
                'user_id' => $interaction->user_id,
                'interaction_id' => $interaction->id,
                'current_count' => $currentCount,
                'limit' => 10,
            ]);

            return;
        }

        // Increment rate limit counter
        \Illuminate\Support\Facades\Cache::put($rateLimitKey, $currentCount + 1, now()->addMinute());

        // Queue the embedding generation job with a small delay to allow for other processing
        \App\Jobs\GenerateChatInteractionEmbeddings::dispatch($interaction)
            ->delay(now()->addSeconds(5))
            ->onQueue('embeddings');

        Log::info('ChatInteraction: Queued embedding generation', [
            'interaction_id' => $interaction->id,
            'chat_session_id' => $interaction->chat_session_id,
            'user_id' => $interaction->user_id,
            'rate_limit_count' => $currentCount + 1,
        ]);
    }
}
