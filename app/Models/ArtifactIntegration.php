<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactIntegration extends Model
{
    protected $fillable = [
        'artifact_id',
        'integration_id',
        'external_id',
        'external_url',
        'auto_sync_enabled',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
        'sync_metadata',
    ];

    protected $casts = [
        'auto_sync_enabled' => 'boolean',
        'last_synced_at' => 'datetime',
        'sync_metadata' => 'array',
    ];

    // Relationships

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    // Status Methods

    /**
     * Mark the integration as successfully synced
     */
    public function markSynced(): void
    {
        $this->update([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_error' => null,
        ]);
    }

    /**
     * Mark the integration as failed sync
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'last_sync_status' => 'failed',
            'last_sync_error' => $error,
        ]);
    }

    /**
     * Mark sync as pending
     */
    public function markPending(): void
    {
        $this->update([
            'last_sync_status' => 'pending',
        ]);
    }

    /**
     * Check if this integration needs sync
     * (auto-sync enabled and artifact modified after last sync)
     */
    public function needsSync(): bool
    {
        if (! $this->auto_sync_enabled) {
            return false;
        }

        // If never synced, needs sync
        if (! $this->last_synced_at) {
            return true;
        }

        // If artifact was updated after last sync
        return $this->artifact->updated_at->isAfter($this->last_synced_at);
    }

    /**
     * Check if sync is currently in progress
     */
    public function isSyncing(): bool
    {
        return $this->last_sync_status === 'pending';
    }

    /**
     * Check if last sync was successful
     */
    public function isSyncSuccessful(): bool
    {
        return $this->last_sync_status === 'success';
    }

    /**
     * Check if last sync failed
     */
    public function hasSyncFailed(): bool
    {
        return $this->last_sync_status === 'failed';
    }

    // Accessors

    /**
     * Get CSS class for sync status badge
     */
    public function getSyncStatusBadgeClassAttribute(): string
    {
        return match ($this->last_sync_status) {
            'success' => 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-200',
            'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-200',
            'pending' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-200',
            default => 'bg-zinc-100 text-zinc-800 dark:bg-zinc-900/20 dark:text-zinc-200',
        };
    }

    /**
     * Get user-friendly sync status text
     */
    public function getSyncStatusTextAttribute(): string
    {
        return match ($this->last_sync_status) {
            'success' => 'Synced',
            'failed' => 'Failed',
            'pending' => 'Syncing...',
            default => 'Not synced',
        };
    }
}
