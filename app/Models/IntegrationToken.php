<?php

namespace App\Models;

use App\Models\Concerns\HasCapabilities;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * Integration Token Model
 *
 * Stores OAuth/API credentials for external service integrations with automatic
 * encryption, expiration tracking, and capability-based access control.
 *
 * **OAuth Flow:**
 * 1. User initiates OAuth connection
 * 2. Provider returns access_token + optional refresh_token
 * 3. Token stored with automatic encryption
 * 4. Capabilities detected from scopes/permissions
 * 5. Token used by Integration instances for API calls
 *
 * **Token Security:**
 * - access_token: Encrypted at rest
 * - refresh_token: Encrypted at rest
 * - metadata: Encrypted array (may contain sensitive credentials and workspace info)
 * - config: Encrypted array (may contain sensitive configuration)
 * - Masked token display for UI (first 4 + last 4 chars)
 *
 * **Lifecycle Management:**
 * - active: Valid and usable
 * - expired: Past expiration date, needs refresh
 * - revoked: Manually revoked by user
 * - error: Last API call failed
 *
 * **Scope & Capability Mapping:**
 * Scopes from OAuth provider are mapped to internal capabilities
 * (e.g., "knowledge:read", "knowledge:write", "chat:create")
 * Capabilities control what Integration instances can do
 *
 * **MCP Server Tokens:**
 * MCP (Model Context Protocol) server integrations use provider_id='mcp_server'
 * These tokens are exclusive to single integrations and deleted with the integration
 *
 * @property string $id UUID
 * @property int $user_id Owner
 * @property string $provider_id Provider identifier (e.g., 'github', 'slack', 'mcp_server')
 * @property string $provider_name Display name
 * @property string $token_type (oauth, api_key, personal_access_token)
 * @property string $access_token Encrypted access token
 * @property string|null $refresh_token Encrypted refresh token
 * @property \Carbon\Carbon|null $expires_at Token expiration
 * @property array|null $metadata Encrypted metadata (scopes, workspace info, credentials)
 * @property array|null $config Encrypted provider-specific configuration
 * @property string $status (active, expired, revoked, error)
 */
class IntegrationToken extends Model
{
    use HasCapabilities;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'provider_id',
        'provider_name',
        'token_type',
        'access_token',
        'refresh_token',
        'expires_at',
        'metadata',
        'config',
        'status',
        'last_error',
        'last_used_at',
        'last_refresh_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'metadata' => 'encrypted:array',
        'config' => 'encrypted:array',
        'last_used_at' => 'datetime',
        'last_refresh_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $appends = [
        'masked_token',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForProvider($query, string $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    // Helper Methods

    public function isExpired(): bool
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    public function getMaskedTokenAttribute(): string
    {
        $token = $this->access_token;

        if (strlen($token) < 12) {
            return str_repeat('*', 8);
        }

        return substr($token, 0, 4).str_repeat('*', 8).substr($token, -4);
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);

        Log::info('Integration token used', [
            'token_id' => $this->id,
            'provider_id' => $this->provider_id,
            'user_id' => $this->user_id,
        ]);
    }

    public function markAsExpired(?string $error = null): void
    {
        $this->update([
            'status' => 'expired',
            'last_error' => $error,
        ]);

        Log::error('Integration token expired', [
            'token_id' => $this->id,
            'provider_id' => $this->provider_id,
            'user_id' => $this->user_id,
            'error' => $error,
        ]);
    }

    public function markAsRevoked(): void
    {
        $this->update(['status' => 'revoked']);
    }

    public function markAsError(string $error): void
    {
        $this->update([
            'status' => 'error',
            'last_error' => $error,
        ]);
    }

    public function markAsActive(): void
    {
        $this->update([
            'status' => 'active',
            'last_error' => null,
        ]);
    }

    public function getWorkspaceNameAttribute(): ?string
    {
        return $this->metadata['workspace_name'] ?? null;
    }

    public function getAccountNameAttribute(): ?string
    {
        return $this->metadata['account_name']
            ?? $this->metadata['email']
            ?? null;
    }

    // Scope Management

    public function getDetectedScopes(): array
    {
        return $this->metadata['scopes'] ?? [];
    }

    public function setDetectedScopes(array $scopes): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['scopes'] = $scopes;
        $this->metadata = $metadata;
        $this->save();
    }

    // Capability Management

    public function getAvailableCapabilities(): array
    {
        return $this->metadata['available_capabilities'] ?? [];
    }

    public function getBlockedCapabilities(): array
    {
        return $this->metadata['blocked_capabilities'] ?? [];
    }

    public function isCapabilityAvailable(string $capability): bool
    {
        return in_array($capability, $this->getAvailableCapabilities());
    }

    public function getAvailableCategories(): array
    {
        return array_unique(array_map(
            fn ($cap) => explode(':', $cap)[0],
            $this->getAvailableCapabilities()
        ));
    }

    /**
     * Check if this token is exclusively used by MCP server integrations
     * MCP tokens should be deleted when their integration is deleted
     */
    public function isExclusiveMcpToken(): bool
    {
        return $this->provider_id === 'mcp_server' && $this->integrations()->count() === 1;
    }
}
