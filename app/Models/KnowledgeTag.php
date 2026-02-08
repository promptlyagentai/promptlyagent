<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Knowledge Tag Model
 *
 * Categorizes knowledge documents for organization and filtering.
 * Supports system tags (protected, admin-managed) and user-created tags.
 * Used for agent knowledge assignment (assign all documents with tag X to agent Y).
 */
class KnowledgeTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'color',
        'description',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    protected $attributes = [
        'color' => '#3b82f6',
        'is_system' => false,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            if ($tag->isDirty('name') && empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeDocument::class, 'knowledge_document_tags');
    }

    public function agentAssignments(): HasMany
    {
        return $this->hasMany(AgentKnowledgeAssignment::class);
    }

    // Scopes
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserCreated($query)
    {
        return $query->where('is_system', false);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    public function scopePopular($query, int $minDocuments = 1)
    {
        return $query->withCount('documents')
            ->having('documents_count', '>=', $minDocuments)
            ->orderByDesc('documents_count');
    }

    // Accessors & Mutators
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getDocumentCountAttribute(): int
    {
        return $this->documents()->count();
    }

    // Helper methods
    public static function findOrCreateByName(string $name, ?int $userId = null): self
    {
        $slug = Str::slug($name);

        return static::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'slug' => $slug,
                'created_by' => $userId,
            ]
        );
    }

    public static function getSystemTags(): array
    {
        return [
            ['name' => 'Important', 'color' => '#dc2626', 'is_system' => true],
            ['name' => 'Documentation', 'color' => '#059669', 'is_system' => true],
            ['name' => 'Reference', 'color' => '#7c3aed', 'is_system' => true],
            ['name' => 'Tutorial', 'color' => '#ea580c', 'is_system' => true],
            ['name' => 'Archive', 'color' => '#6b7280', 'is_system' => true],
        ];
    }

    public function canEdit(User $user): bool
    {
        if ($this->is_system) {
            return $user->is_admin ?? false;
        }

        return $this->created_by === $user->id;
    }

    public function canDelete(User $user): bool
    {
        if ($this->is_system) {
            return false; // System tags cannot be deleted
        }

        if ($this->documents()->exists()) {
            return false; // Tags with documents cannot be deleted
        }

        return $this->created_by === $user->id;
    }
}
