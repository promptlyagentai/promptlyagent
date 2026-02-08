<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent Knowledge Assignment Model
 *
 * Junction model for assigning knowledge documents or tags to agents.
 * Supports assignment types: document (specific doc), tag (all docs with tag), all (all knowledge).
 */
class AgentKnowledgeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'knowledge_document_id',
        'knowledge_tag_id',
        'assignment_type',
        'assignment_config',
        'include_expired',
        'priority',
    ];

    protected $casts = [
        'assignment_config' => 'array',
        'include_expired' => 'boolean',
        'priority' => 'integer',
    ];

    protected $attributes = [
        'assignment_type' => 'document',
        'include_expired' => false,
        'priority' => 1,
    ];

    // Relationships
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(KnowledgeTag::class, 'knowledge_tag_id');
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('assignment_type', $type);
    }

    public function scopeDocumentAssignments($query)
    {
        return $query->where('assignment_type', 'document')
            ->whereNotNull('knowledge_document_id');
    }

    public function scopeTagAssignments($query)
    {
        return $query->where('assignment_type', 'tag')
            ->whereNotNull('knowledge_tag_id');
    }

    public function scopeAllKnowledgeAssignments($query)
    {
        return $query->where('assignment_type', 'all');
    }

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeOrderedByPriority($query)
    {
        return $query->orderByDesc('priority')->orderBy('created_at');
    }

    public function scopeIncludingExpired($query)
    {
        return $query->where('include_expired', true);
    }

    public function scopeExcludingExpired($query)
    {
        return $query->where('include_expired', false);
    }

    // Accessors & Mutators
    public function getIsDocumentAssignmentAttribute(): bool
    {
        return $this->assignment_type === 'document' && $this->knowledge_document_id !== null;
    }

    public function getIsTagAssignmentAttribute(): bool
    {
        return $this->assignment_type === 'tag' && $this->knowledge_tag_id !== null;
    }

    public function getIsAllKnowledgeAssignmentAttribute(): bool
    {
        return $this->assignment_type === 'all';
    }

    public function getAssignedResourceAttribute(): ?Model
    {
        if ($this->is_document_assignment) {
            return $this->document;
        }

        if ($this->is_tag_assignment) {
            return $this->tag;
        }

        return null;
    }

    public function getAssignedResourceNameAttribute(): ?string
    {
        if ($this->is_document_assignment) {
            return $this->document?->title;
        }

        if ($this->is_tag_assignment) {
            return $this->tag?->name;
        }

        if ($this->is_all_knowledge_assignment) {
            return 'All Knowledge';
        }

        return null;
    }

    // Helper methods
    public static function assignDocumentToAgent(int $agentId, int $documentId, array $config = []): self
    {
        return static::updateOrCreate(
            [
                'agent_id' => $agentId,
                'knowledge_document_id' => $documentId,
                'assignment_type' => 'document',
            ],
            [
                'assignment_config' => $config['assignment_config'] ?? [],
                'include_expired' => $config['include_expired'] ?? false,
                'priority' => $config['priority'] ?? 1,
            ]
        );
    }

    public static function assignTagToAgent(int $agentId, int $tagId, array $config = []): self
    {
        return static::updateOrCreate(
            [
                'agent_id' => $agentId,
                'knowledge_tag_id' => $tagId,
                'assignment_type' => 'tag',
            ],
            [
                'assignment_config' => $config['assignment_config'] ?? [],
                'include_expired' => $config['include_expired'] ?? false,
                'priority' => $config['priority'] ?? 1,
            ]
        );
    }

    public static function assignAllKnowledgeToAgent(int $agentId, array $config = []): self
    {
        return static::updateOrCreate(
            [
                'agent_id' => $agentId,
                'assignment_type' => 'all',
            ],
            [
                'knowledge_document_id' => null,
                'knowledge_tag_id' => null,
                'assignment_config' => $config['assignment_config'] ?? [],
                'include_expired' => $config['include_expired'] ?? false,
                'priority' => $config['priority'] ?? 1,
            ]
        );
    }

    public function getRelevantDocuments()
    {
        $query = KnowledgeDocument::query()->completed();

        if ($this->is_document_assignment && $this->document) {
            return collect([$this->document]);
        }

        if ($this->is_tag_assignment && $this->tag) {
            $query->whereHas('tags', function ($tagQuery) {
                $tagQuery->where('knowledge_tag_id', $this->knowledge_tag_id);
            });
        }

        if (! $this->include_expired) {
            $query->notExpired();
        }

        return $query->get();
    }

    public function updateConfig(array $config): bool
    {
        return $this->update([
            'assignment_config' => array_merge($this->assignment_config ?? [], $config),
        ]);
    }
}
