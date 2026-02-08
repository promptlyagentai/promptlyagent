<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Enums\Provider;

/**
 * Agent Model
 *
 * Represents an AI agent with configurable behavior, tools, knowledge access,
 * and execution patterns. Supports multiple agent types and AI provider backends.
 *
 * **Agent Types:**
 * - direct: Single-step execution with immediate response
 * - promptly: Multi-step research agent with planning and synthesis phases
 * - synthesizer: Aggregates results from multiple agent executions
 * - integration: Connected to external integration provider
 *
 * **Configuration:**
 * - system_prompt: Core instructions defining agent behavior
 * - workflow_config: Multi-agent workflow orchestration rules
 * - ai_config: Provider-specific settings (temperature, max_tokens, etc.)
 * - tools: Registered Prism tools and MCP servers
 * - knowledge: Assigned documents, tags, or all knowledge
 *
 * **AI Provider Support:**
 * - OpenAI (GPT-4, GPT-4 Turbo, GPT-3.5 Turbo)
 * - Anthropic (Claude Opus, Sonnet, Haiku)
 * - AWS Bedrock (Claude via AWS)
 * - Google (Gemini)
 * - Mistral
 *
 * **Visibility & Access:**
 * - is_public: Available to all users
 * - show_in_chat: Appears in chat interface agent selector
 * - available_for_research: Can be used by research workflows
 *
 * @property array{temperature?: float, max_tokens?: int, top_p?: float, frequency_penalty?: float, presence_penalty?: float, stop?: array<string>}|null $ai_config AI provider configuration
 * @property array{steps?: array, parallel?: bool, aggregation?: string}|null $workflow_config Workflow orchestration rules
 */
class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'agent_type',
        'description',
        'system_prompt',
        'workflow_config',
        'ai_provider',
        'ai_model',
        'max_steps',
        'ai_config',
        'status',
        'is_public',
        'show_in_chat',
        'available_for_research',
        'streaming_enabled',
        'thinking_enabled',
        'enforce_response_language',
    ];

    protected $guarded = [
        'id',
        'user_id',
        'created_by',
        'integration_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $casts = [
        'ai_config' => 'array',
        'workflow_config' => 'array',
        'is_public' => 'boolean',
        'show_in_chat' => 'boolean',
        'available_for_research' => 'boolean',
        'streaming_enabled' => 'boolean',
        'thinking_enabled' => 'boolean',
        'enforce_response_language' => 'boolean',
        'max_steps' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($agent) {
            if (empty($agent->slug)) {
                $agent->slug = Str::slug($agent->name);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(AgentTool::class);
    }

    public function enabledTools(): HasMany
    {
        return $this->hasMany(AgentTool::class)->where('enabled', true)->orderBy('execution_order');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AgentExecution::class);
    }

    public function knowledgeAssignments(): HasMany
    {
        return $this->hasMany(AgentKnowledgeAssignment::class);
    }

    public function assignedDocuments(): HasMany
    {
        return $this->hasMany(AgentKnowledgeAssignment::class)
            ->where('assignment_type', 'document')
            ->whereNotNull('knowledge_document_id');
    }

    public function assignedTags(): HasMany
    {
        return $this->hasMany(AgentKnowledgeAssignment::class)
            ->where('assignment_type', 'tag')
            ->whereNotNull('knowledge_tag_id');
    }

    public function outputActions(): BelongsToMany
    {
        return $this->belongsToMany(OutputAction::class, 'agent_output_action')
            ->withTimestamps();
    }

    /**
     * Get provider enum or string for custom providers
     *
     * Converts agent's string provider to Prism Provider enum for execution.
     * Bedrock is handled as a string provider, not in the Provider enum.
     * Falls back to OpenAI for unknown providers with warning log.
     *
     * @return Provider|string Provider enum or 'bedrock' string
     */
    public function getProviderEnum(): Provider|string
    {
        $provider = match ($this->ai_provider) {
            'openai' => Provider::OpenAI,
            'anthropic' => Provider::Anthropic,
            'bedrock' => 'bedrock', // Bedrock uses string provider
            'google' => Provider::Google,
            'mistral' => Provider::Mistral,
            default => null,
        };

        if ($provider === null) {
            Log::warning('Unknown AI provider encountered, falling back to OpenAI', [
                'agent_id' => $this->id,
                'agent_name' => $this->name,
                'ai_provider' => $this->ai_provider,
            ]);

            return Provider::OpenAI;
        }

        return $provider;
    }

    /**
     * Check if streaming is enabled for this agent
     */
    public function isStreamingEnabled(): bool
    {
        return $this->streaming_enabled;
    }

    /**
     * Check if thinking/reasoning process streaming is enabled for this agent
     */
    public function isThinkingEnabled(): bool
    {
        return $this->thinking_enabled;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeForUser($query, $user)
    {
        // Handle both User object and user ID for backward compatibility
        $userId = $user instanceof \App\Models\User ? $user->id : $user;
        $isAdmin = $user instanceof \App\Models\User && $user->is_admin;

        return $query->where(function ($q) use ($userId, $isAdmin) {
            $q->where('is_public', true)
                ->orWhere('created_by', $userId);

            // Admin users can see all agents
            if ($isAdmin) {
                $q->orWhereNotNull('id');
            }
        });
    }

    public function scopeShowInChat($query)
    {
        return $query->where('show_in_chat', true);
    }

    public function scopeAvailableForResearch($query)
    {
        return $query->where('available_for_research', true)
            ->where('status', 'active');
    }

    public function scopeDirectType($query)
    {
        return $query->where('agent_type', 'direct');
    }

    public function scopePromptlyType($query)
    {
        return $query->where('agent_type', 'promptly');
    }

    public function scopeSynthesizerType($query)
    {
        return $query->where('agent_type', 'synthesizer');
    }

    public function scopeAvailableForSynthesis($query)
    {
        return $query->where('status', 'active')
            ->where('agent_type', 'synthesizer');
    }

    public function scopeIntegrationType($query)
    {
        return $query->whereNotNull('integration_id');
    }
}
