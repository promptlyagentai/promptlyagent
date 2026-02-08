<?php

namespace App\Models;

use App\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

/**
 * Integration Model
 *
 * Represents a configured integration instance that connects user resources
 * (agents, triggers, output actions) to external services via integration tokens.
 * Supports capability-based access control and lifecycle management.
 *
 * **Integration Lifecycle:**
 * - active: Fully operational and available for use
 * - paused: Temporarily disabled, preserves configuration
 * - archived: Permanently disabled, hidden from active lists
 *
 * **Capability System:**
 * Capabilities define what actions an integration can perform, formatted as "category:action"
 * Examples: "knowledge:create", "knowledge:search", "chat:create", "agent:execute"
 *
 * Capabilities must be:
 * 1. Available (granted by integration token scopes)
 * 2. Enabled (toggled on for this specific integration)
 *
 * **Visibility Levels:**
 * - private: Only visible to the owner
 * - shared: Visible to all users (admin-only feature)
 *
 * @property string $id UUID-based integration ID
 * @property int $user_id Owner of the integration
 * @property int $integration_token_id Associated token with provider credentials
 * @property string $name Display name
 * @property string|null $description Purpose/notes
 * @property string|null $icon Icon identifier
 * @property string|null $color Theme color
 * @property array|null $config Configuration including enabled_capabilities array
 * @property array|null $metadata Additional metadata
 * @property string $status (active, paused, archived)
 * @property string $visibility (private, shared)
 */
class Integration extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'integration_token_id',
        'name',
        'description',
        'icon',
        'color',
        'config',
        'metadata',
        'status',
        'visibility',
        'sort_order',
        'is_favorite',
        'last_used_at',
        'usage_count',
    ];

    protected $casts = [
        'status' => IntegrationStatus::class,
        'config' => 'array',
        'metadata' => 'array',
        'is_favorite' => 'boolean',
        'sort_order' => 'integer',
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integrationToken(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class);
    }

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function inputTriggers(): HasMany
    {
        return $this->hasMany(InputTrigger::class);
    }

    public function outputActions(): HasMany
    {
        return $this->hasMany(OutputAction::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', IntegrationStatus::ACTIVE);
    }

    public function scopePaused($query)
    {
        return $query->where('status', IntegrationStatus::PAUSED);
    }

    public function scopeArchived($query)
    {
        return $query->where('status', IntegrationStatus::ARCHIVED);
    }

    public function scopeWithStatus($query, IntegrationStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    public function scopeShared($query)
    {
        return $query->where('visibility', 'shared');
    }

    public function scopePrivate($query)
    {
        return $query->where('visibility', 'private');
    }

    // Capability Management

    public function getEnabledCapabilities(): array
    {
        return $this->config['enabled_capabilities'] ?? [];
    }

    public function isCapabilityEnabled(string $capability): bool
    {
        return in_array($capability, $this->getEnabledCapabilities());
    }

    public function toggleCapability(string $capability, bool $enabled): void
    {
        // CONCURRENCY: Use transaction with row-level lock to prevent race conditions
        // Prevents capability state corruption when multiple requests toggle concurrently
        \Illuminate\Support\Facades\DB::transaction(function () use ($capability, $enabled) {
            // FOR UPDATE lock: blocks other transactions from reading/writing this row
            $this->lockForUpdate();

            $config = $this->config ?? [];
            $enabledCapabilities = $config['enabled_capabilities'] ?? [];

            if ($enabled && ! in_array($capability, $enabledCapabilities)) {
                $enabledCapabilities[] = $capability;
            } elseif (! $enabled) {
                $enabledCapabilities = array_filter($enabledCapabilities, fn ($c) => $c !== $capability);
            }

            $config['enabled_capabilities'] = array_values($enabledCapabilities);
            $this->config = $config;
            $this->save();

            Log::info('Integration capability toggled', [
                'integration_id' => $this->id,
                'integration_name' => $this->name,
                'capability' => $capability,
                'enabled' => $enabled,
                'user_id' => $this->user_id,
            ]);
        });
    }

    public function enableAllCapabilities(): void
    {
        // CONCURRENCY: Use transaction with row-level lock
        \Illuminate\Support\Facades\DB::transaction(function () {
            $this->lockForUpdate();

            $config = $this->config ?? [];
            $config['enabled_capabilities'] = $this->getAvailableCapabilities();
            $this->config = $config;
            $this->save();
        });
    }

    public function disableAllCapabilities(): void
    {
        // CONCURRENCY: Use transaction with row-level lock
        \Illuminate\Support\Facades\DB::transaction(function () {
            $this->lockForUpdate();

            $config = $this->config ?? [];
            $config['enabled_capabilities'] = [];
            $this->config = $config;
            $this->save();
        });
    }

    /**
     * Validate that a capability is both enabled for this integration and available via token scopes.
     *
     * SECURITY: This method enforces authorization before checking capabilities.
     * Always call this method instead of isCapabilityEnabled() to ensure proper access control.
     *
     * @param  string  $capability  Capability in "category:action" format (e.g., "knowledge:create")
     * @param  User|null  $user  User to authorize (defaults to auth()->user())
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If user doesn't have access
     * @throws \Exception If the capability is not enabled or not available
     */
    public function validateCapability(string $capability, ?User $user = null): void
    {
        // SECURITY: Always check authorization first
        // Prevents unauthorized users from using other users' integrations
        $user = $user ?? auth()->user();

        if (! $user) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'User must be authenticated to use integrations'
            );
        }

        // Enforce authorization using centralized access control
        $this->authorizeAccess($user);

        // Now that authorization is confirmed, check if the capability is enabled
        if (! $this->isCapabilityEnabled($capability)) {
            Log::warning('Integration capability validation failed - not enabled', [
                'integration_id' => $this->id,
                'integration_name' => $this->name,
                'capability' => $capability,
                'enabled_capabilities' => $this->getEnabledCapabilities(),
                'user_id' => $user->id,
            ]);

            throw new \Exception(
                "Capability '{$capability}' is not enabled for this integration. ".
                'Please enable it in the integration settings.'
            );
        }

        // Then check if the capability is available based on token scopes
        if (! $this->isCapabilityAvailable($capability)) {
            Log::warning('Integration capability validation failed - not available', [
                'integration_id' => $this->id,
                'integration_name' => $this->name,
                'capability' => $capability,
                'integration_token_id' => $this->integration_token_id,
            ]);

            throw new \Exception(
                "Capability '{$capability}' is not available due to insufficient token permissions. ".
                'The connected credentials may need to be reconnected with additional scopes.'
            );
        }
    }

    // Delegate to token for availability

    public function getAvailableCapabilities(): array
    {
        return $this->integrationToken->getAvailableCapabilities();
    }

    public function isCapabilityAvailable(string $capability): bool
    {
        return $this->integrationToken->isCapabilityAvailable($capability);
    }

    public function getEnabledCategories(): array
    {
        $categories = array_unique(array_map(
            fn ($cap) => explode(':', $cap)[0],
            $this->getEnabledCapabilities()
        ));

        // Add "Agent" category if an agent exists for this integration
        if ($this->agents()->exists() && ! in_array('Agent', $categories)) {
            $categories[] = 'Agent';
        }

        return $categories;
    }

    // Status Management

    /**
     * Check if integration has a specific status.
     */
    public function hasStatus(IntegrationStatus $status): bool
    {
        return $this->status === $status;
    }

    /**
     * Check if integration is active (includes token validation).
     *
     * Note: This checks both the integration status AND the underlying token's active state.
     * Use hasStatus(IntegrationStatus::ACTIVE) if you only need to check the integration status.
     */
    public function isActive(): bool
    {
        return $this->status === IntegrationStatus::ACTIVE && $this->integrationToken->isActive();
    }

    /**
     * Set integration status with logging.
     *
     * LOGGING: All status changes are logged for audit trail.
     * Use this method instead of direct updates to ensure consistent logging.
     *
     * @param  IntegrationStatus  $status  New status value
     */
    protected function setStatus(IntegrationStatus $status): void
    {
        $oldStatus = $this->status;

        $this->update(['status' => $status]);

        Log::info('Integration status changed', [
            'integration_id' => $this->id,
            'integration_name' => $this->name,
            'old_status' => $oldStatus?->value,
            'new_status' => $status->value,
            'user_id' => $this->user_id,
        ]);
    }

    /**
     * Mark integration as active.
     */
    public function markAsActive(): void
    {
        $this->setStatus(IntegrationStatus::ACTIVE);
    }

    /**
     * Mark integration as paused.
     */
    public function markAsPaused(): void
    {
        $this->setStatus(IntegrationStatus::PAUSED);
    }

    /**
     * Mark integration as archived.
     */
    public function markAsArchived(): void
    {
        $this->setStatus(IntegrationStatus::ARCHIVED);
    }

    // Usage Tracking

    public function touchLastUsed(): void
    {
        $this->update([
            'last_used_at' => now(),
            'usage_count' => $this->usage_count + 1,
        ]);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    // Helper Methods

    public function isFavorite(): bool
    {
        return $this->is_favorite;
    }

    public function toggleFavorite(): void
    {
        $this->update(['is_favorite' => ! $this->is_favorite]);
    }

    // Visibility Management

    public function isShared(): bool
    {
        return $this->visibility === 'shared';
    }

    public function canBeSharedBy(User $user): bool
    {
        // Only integration owner who is an admin can share
        return $this->user_id === $user->id && $user->is_admin;
    }

    public function markAsShared(): void
    {
        $this->update(['visibility' => 'shared']);
    }

    public function markAsPrivate(): void
    {
        $this->update(['visibility' => 'private']);
    }

    /**
     * Check if integration can be accessed by a user
     *
     * SECURITY: Centralized access control to prevent authorization bypass
     * Use this method consistently instead of checking isShared() alone
     *
     * @param  \App\Models\User  $user  The user to check access for
     * @return bool True if user can access this integration
     */
    public function canBeAccessedBy(User $user): bool
    {
        // Owner always has access to their own integrations
        if ($this->user_id === $user->id) {
            return true;
        }

        // Admins have access to all integrations for management purposes
        if ($user->isAdmin()) {
            return true;
        }

        // Shared integrations are accessible to all authenticated users
        // This allows teams to share API credentials, MCP servers, etc.
        return $this->visibility === 'shared';
    }

    /**
     * Authorize access or throw exception
     *
     * SECURITY: Use this method in controllers to enforce access control
     * Throws exception if user doesn't have permission
     *
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorizeAccess(User $user): void
    {
        if (! $this->canBeAccessedBy($user)) {
            Log::warning('Unauthorized integration access attempt blocked', [
                'integration_id' => $this->id,
                'integration_name' => $this->name,
                'integration_user_id' => $this->user_id,
                'requesting_user_id' => $user->id,
                'visibility' => $this->visibility,
            ]);

            throw new \Illuminate\Auth\Access\AuthorizationException(
                'You do not have permission to access this integration.'
            );
        }
    }
}
