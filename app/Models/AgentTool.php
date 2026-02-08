<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent Tool Model
 *
 * Represents tools (Prism tools, MCP servers) assigned to an agent with priority-based
 * execution strategies. Supports preferred/standard/fallback priorities and conditional
 * execution based on previous tool results.
 */
class AgentTool extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'tool_name',
        'tool_config',
        'enabled',
        'execution_order',
        'priority_level', // 'preferred', 'standard', 'fallback'
        'execution_strategy', // 'always', 'if_preferred_fails', 'if_no_preferred_results', 'never_if_preferred_succeeds'
        'min_results_threshold', // Minimum results needed before considering this tool successful
        'max_execution_time', // Maximum time to wait for this tool
    ];

    protected $casts = [
        'tool_config' => 'array',
        'enabled' => 'boolean',
        'execution_order' => 'integer',
        'priority_level' => 'string',
        'execution_strategy' => 'string',
        'min_results_threshold' => 'integer',
        'max_execution_time' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('execution_order');
    }

    public function scopePreferred($query)
    {
        return $query->where('priority_level', 'preferred');
    }

    public function scopeStandard($query)
    {
        return $query->where('priority_level', 'standard');
    }

    public function scopeFallback($query)
    {
        return $query->where('priority_level', 'fallback');
    }

    public function scopeByPriority($query)
    {
        return $query->orderByRaw("FIELD(priority_level, 'preferred', 'standard', 'fallback')")
            ->orderBy('execution_order');
    }

    public function isPreferred(): bool
    {
        return $this->priority_level === 'preferred';
    }

    public function shouldExecuteAfterPreferred(array $preferredResults): bool
    {
        if ($this->execution_strategy === 'never_if_preferred_succeeds') {
            return empty($preferredResults);
        }

        if ($this->execution_strategy === 'if_no_preferred_results') {
            return empty($preferredResults);
        }

        if ($this->execution_strategy === 'if_preferred_fails') {
            // Check if preferred tools failed or returned insufficient results
            $hasSufficientResults = collect($preferredResults)->some(function ($result) {
                return $this->hasSufficientResults($result);
            });

            return ! $hasSufficientResults;
        }

        return true; // 'always' strategy
    }

    protected function hasSufficientResults($result): bool
    {
        if (! $this->min_results_threshold) {
            return true;
        }

        // Check if result has sufficient data based on tool type
        if (isset($result['data']['results']) && is_array($result['data']['results'])) {
            return count($result['data']['results']) >= $this->min_results_threshold;
        }

        if (isset($result['data']['content']) && strlen($result['data']['content']) > 100) {
            return true;
        }

        return false;
    }
}
