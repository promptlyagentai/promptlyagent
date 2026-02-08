<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * Chat Session - Conversation container for multi-turn agent interactions
 *
 * A ChatSession represents a conversation thread between a user and one or more agents.
 * Sessions group related ChatInteraction instances (question-answer pairs) and maintain
 * conversation context across multiple turns.
 *
 * ## Key Features
 * - **Context Persistence**: Conversation history flows across interactions
 * - **Title Generation**: Automatic title generation from first interaction
 * - **Session Management**: Archive, keep, filter, and search capabilities
 * - **Public Sharing**: Share sessions with expiration dates
 * - **Input Trigger Integration**: Sessions can be initiated from external integrations
 *
 * ## Relationships
 * - `interactions()` - HasMany ChatInteraction (question-answer pairs)
 * - `user` - BelongsTo User (session owner)
 * - `agentExecutions()` - HasMany AgentExecution (all AI executions in this session)
 *
 * ## Metadata Structure
 * ```php
 * [
 *     'initiated_by' => 'web|api|slack|webhook',
 *     'input_trigger_id' => int,           // If triggered externally
 *     'context_source' => 'slack_thread',  // External context info
 * ]
 * ```
 *
 * ## Usage
 * ```php
 * $session = ChatSession::create(['user_id' => $user->id]);
 * $interaction = $session->interactions()->create([...]);
 * $session->archive(); // Soft archive without deletion
 * ```
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property array|null $metadata
 * @property bool $is_kept
 * @property bool $is_public
 * @property \Carbon\Carbon|null $archived_at
 * @property \Carbon\Carbon|null $public_shared_at
 * @property \Carbon\Carbon|null $public_expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @see \App\Models\ChatInteraction
 * @see \App\Services\TitleGenerator
 */
class ChatSession extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_kept' => 'boolean',
            'is_public' => 'boolean',
            'archived_at' => 'datetime',
            'public_shared_at' => 'datetime',
            'public_expires_at' => 'datetime',
        ];
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(ChatInteraction::class, 'chat_session_id');
    }

    /**
     * Get how this session was initiated (web, api, webhook, slack, etc.).
     */
    public function getInitiatedBy(): string
    {
        return $this->metadata['initiated_by'] ?? 'web';
    }

    /**
     * Check if this session was initiated via an input trigger.
     * Returns true if the session has an associated input trigger ID.
     */
    public function isTriggerInitiated(): bool
    {
        return ! empty($this->metadata['input_trigger_id']);
    }

    /**
     * Get the input trigger ID if this session was triggered.
     */
    public function getInputTriggerId(): ?int
    {
        return $this->metadata['input_trigger_id'] ?? null;
    }

    /**
     * Scope: Get only active (non-archived) sessions
     */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope: Get only archived sessions
     */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Scope: Filter sessions by source type
     */
    public function scopeBySourceType($query, string $type)
    {
        if ($type === 'all') {
            return $query;
        }

        return $query->where('source_type', $type);
    }

    /**
     * Scope: Get only kept sessions
     */
    public function scopeKept($query)
    {
        return $query->where('is_kept', true);
    }

    /**
     * Archive this session
     */
    public function archive(): bool
    {
        if ($this->is_kept) {
            return false; // Cannot archive kept sessions
        }

        return $this->update(['archived_at' => now()]);
    }

    /**
     * Unarchive this session
     */
    public function unarchive(): bool
    {
        return $this->update(['archived_at' => null]);
    }

    /**
     * Toggle the keep flag
     */
    public function toggleKeep(): bool
    {
        return $this->update(['is_kept' => ! $this->is_kept]);
    }

    /**
     * Check if session is archived
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Make this session publicly accessible
     */
    public function makePublic(?int $expiresInDays = null): bool
    {
        $attributes = [
            'is_public' => true,
            'public_shared_at' => now(),
        ];

        if ($expiresInDays !== null) {
            $attributes['public_expires_at'] = now()->addDays($expiresInDays);
        }

        return $this->update($attributes);
    }

    /**
     * Make this session private
     */
    public function makePrivate(): bool
    {
        return $this->update([
            'is_public' => false,
            'public_shared_at' => null,
            'public_expires_at' => null,
        ]);
    }

    /**
     * Toggle the public sharing status
     */
    public function togglePublic(): bool
    {
        if ($this->is_public) {
            return $this->makePrivate();
        }

        return $this->makePublic();
    }

    /**
     * Check if session is publicly accessible
     */
    public function isPublic(): bool
    {
        if (! $this->is_public) {
            return false;
        }

        // Check if session has expired
        if ($this->public_expires_at !== null && $this->public_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get the public sharing URL
     */
    public function getPublicUrl(): string
    {
        return url("/public/sessions/{$this->uuid}");
    }

    /**
     * Scope: Get only public sessions
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true)
            ->where(function ($q) {
                $q->whereNull('public_expires_at')
                    ->orWhere('public_expires_at', '>', now());
            });
    }

    /**
     * Get count of attachments across all interactions (cached)
     */
    public function getAttachmentsCountAttribute(): int
    {
        return Cache::tags(['session', "session.{$this->id}"])
            ->remember(
                "session.{$this->id}.attachments_count",
                now()->addMinutes(config('chat.counts_cache_minutes', 5)),
                function () {
                    return $this->interactions()
                        ->withCount('attachments')
                        ->get()
                        ->sum('attachments_count');
                }
            );
    }

    /**
     * Get count of artifacts across all interactions (cached)
     */
    public function getArtifactsCountAttribute(): int
    {
        return Cache::tags(['session', "session.{$this->id}"])
            ->remember(
                "session.{$this->id}.artifacts_count",
                now()->addMinutes(config('chat.counts_cache_minutes', 5)),
                function () {
                    return $this->interactions()
                        ->withCount('artifacts')
                        ->get()
                        ->sum('artifacts_count');
                }
            );
    }

    /**
     * Get count of sources across all interactions (cached)
     */
    public function getSourcesCountAttribute(): int
    {
        return Cache::tags(['session', "session.{$this->id}"])
            ->remember(
                "session.{$this->id}.sources_count",
                now()->addMinutes(config('chat.counts_cache_minutes', 5)),
                function () {
                    $knowledgeSourcesCount = $this->interactions()
                        ->withCount('knowledgeSources')
                        ->get()
                        ->sum('knowledge_sources_count');

                    $webSourcesCount = $this->interactions()
                        ->withCount('sources')
                        ->get()
                        ->sum('sources_count');

                    return $knowledgeSourcesCount + $webSourcesCount;
                }
            );
    }

    /**
     * Flush cached counts for this session
     */
    public function flushCountsCache(): void
    {
        Cache::tags(['session', "session.{$this->id}"])->flush();
    }

    /**
     * Boot model event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate UUID for new sessions
        static::creating(function ($session) {
            if (empty($session->uuid)) {
                $session->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });

        // Flush cache when interactions change
        static::saved(function ($session) {
            $session->flushCountsCache();
        });

        static::deleted(function ($session) {
            $session->flushCountsCache();
        });
    }
}
