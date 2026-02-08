<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutputActionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'output_action_id',
        'user_id',
        'triggerable_type',
        'triggerable_id',
        'url',
        'method',
        'headers',
        'body',
        'status',
        'response_code',
        'response_body',
        'error_message',
        'duration_ms',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    // Accessors

    public function getIsSuccessAttribute(): bool
    {
        return $this->status === 'success';
    }

    public function getIsFailureAttribute(): bool
    {
        return in_array($this->status, ['failed', 'timeout']);
    }

    // Relationships

    public function outputAction(): BelongsTo
    {
        return $this->belongsTo(OutputAction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggerable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'timeout']);
    }

    public function scopeForAction($query, OutputAction $action)
    {
        return $query->where('output_action_id', $action->id);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('executed_at', '>=', now()->subDays($days));
    }
}
