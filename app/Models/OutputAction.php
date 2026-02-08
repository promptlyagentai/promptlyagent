<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Output Action Model
 *
 * Defines automated actions triggered when agent executions or input triggers
 * complete. Supports webhooks, integrations, and notifications.
 *
 * **Action Types (provider_id):**
 * - webhook: POST execution results to external URL
 * - slack: Send messages to Slack channels
 * - email: Email notifications
 * - discord: Post to Discord webhooks
 * - github: Create issues/comments on GitHub
 * - custom: Integration-specific actions
 *
 * **Trigger Conditions:**
 * - success: Execute only on successful agent completion
 * - failure: Execute only on agent failures
 * - always: Execute regardless of outcome
 *
 * **Associations:**
 * - Agents: Fire when specific agent completes
 * - Input Triggers: Fire when trigger invocation completes
 *
 * **Execution Tracking:**
 * - total_executions: Count of all attempts
 * - successful_executions: Successful deliveries
 * - failed_executions: Failed deliveries
 * - success_rate: Calculated percentage
 *
 * @property string $id UUID-based action ID
 * @property int $user_id Owner
 * @property string $name Display name
 * @property string|null $description Purpose/notes
 * @property string $provider_id Action type/provider
 * @property string $status (active, paused, disabled)
 * @property array|null $config Provider-specific configuration (URL, headers, etc.)
 * @property string|null $webhook_secret Encrypted webhook secret for verification
 * @property string $trigger_on (success, failure, always)
 */
class OutputAction extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'description',
        'provider_id',
        'status',
        'config',
        'webhook_secret',
        'trigger_on',
        'total_executions',
        'successful_executions',
        'failed_executions',
        'last_executed_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'webhook_secret' => 'encrypted',
            'last_executed_at' => 'datetime',
        ];
    }

    // Accessors

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->total_executions === 0) {
            return 0.0;
        }

        return round(($this->successful_executions / $this->total_executions) * 100, 2);
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_output_action')
            ->withTimestamps();
    }

    public function inputTriggers(): BelongsToMany
    {
        return $this->belongsToMany(InputTrigger::class, 'input_trigger_output_action')
            ->withTimestamps();
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OutputActionLog::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeByProvider($query, string $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeTriggerOnSuccess($query)
    {
        return $query->whereIn('trigger_on', ['success', 'always']);
    }

    public function scopeTriggerOnFailure($query)
    {
        return $query->whereIn('trigger_on', ['failure', 'always']);
    }

    // Methods

    public function incrementUsage(bool $success = true): void
    {
        $this->increment('total_executions');

        if ($success) {
            $this->increment('successful_executions');
        } else {
            $this->increment('failed_executions');
        }

        $this->update(['last_executed_at' => now()]);

        Log::info('Output action executed', [
            'action_id' => $this->id,
            'action_name' => $this->name,
            'provider_id' => $this->provider_id,
            'user_id' => $this->user_id,
            'success' => $success,
            'total_executions' => $this->total_executions + 1,
        ]);
    }

    public function shouldExecuteForStatus(string $status): bool
    {
        return match ($this->trigger_on) {
            'success' => $status === 'success',
            'failure' => $status === 'failed',
            'always' => true,
            default => false,
        };
    }

    // Boot

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($action) {
            if (empty($action->id)) {
                $action->id = (string) Str::uuid();
            }
        });
    }
}
