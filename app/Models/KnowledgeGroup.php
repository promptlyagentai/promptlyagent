<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_document_id',
        'group_identifier',
        'group_type',
    ];

    protected $attributes = [
        'group_type' => 'user_group',
    ];

    // Relationships
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    // Scopes
    public function scopeForGroup($query, string $identifier, ?string $type = null)
    {
        $query->where('group_identifier', $identifier);

        if ($type) {
            $query->where('group_type', $type);
        }

        return $query;
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('group_type', $type);
    }
}
