<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Input Trigger Model
 *
 * Enables external systems to trigger agent executions or Artisan commands via webhook URLs.
 * Supports rate limiting, IP whitelisting, and flexible session management
 * strategies for secure programmatic invocations.
 *
 * **Trigger Types:**
 * - API webhooks from external services
 * - Scheduled tasks via cron jobs
 * - IoT device integrations
 * - Custom application integrations
 *
 * **Trigger Targets:**
 * - Agent: Execute an agent with the webhook payload as input
 * - Command: Execute an Artisan command with mapped parameters
 *
 * **Security Features:**
 * - UUID-based trigger IDs for security
 * - Rate limiting (per-minute, per-hour, per-day)
 * - IP whitelist with CIDR range support (IPv4 & IPv6)
 * - Secret rotation with audit trail
 * - Encrypted config storage for webhook secrets
 *
 * **Session Strategies:**
 * - create_new: Each invocation creates a new chat session
 * - reuse_default: All invocations use the same default session
 * - context_based: Session determined by request context
 *
 * @property string $id UUID-based trigger ID
 * @property int $user_id Owner of the trigger
 * @property string $name Human-readable trigger name
 * @property string|null $description Trigger purpose/documentation
 * @property int|null $agent_id Agent to execute when triggered (required if trigger_target_type='agent')
 * @property string $trigger_target_type Target type: 'agent' or 'command'
 * @property string|null $command_class Command class name (required if trigger_target_type='command')
 * @property array|null $command_parameters Command parameter mappings (JSONPath expressions)
 * @property string|null $provider_id Integration provider (if applicable)
 * @property int|null $integration_token_id Associated integration token
 * @property string $status (active, paused, disabled)
 * @property array|null $config Encrypted trigger-specific configuration (may contain webhook secrets)
 * @property array|null $rate_limits Rate limit configuration
 * @property array|null $ip_whitelist Allowed IP addresses/CIDR ranges
 * @property string $session_strategy How to handle chat sessions
 * @property int|null $default_session_id Default session for reuse strategy
 */
class InputTrigger extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'description',
        'agent_id',
        'trigger_target_type',
        'command_class',
        'command_parameters',
        'provider_id',
        'integration_id',
        'status',
        'config',
        'rate_limits',
        'ip_whitelist',
        'session_strategy',
        'default_session_id',
        'secret_created_at',
        'secret_rotated_at',
        'secret_rotation_count',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'command_parameters' => 'array',
            'rate_limits' => 'array',
            'ip_whitelist' => 'array',
            'last_invoked_at' => 'datetime',
            'secret_created_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
        ];
    }

    // Accessors

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if this trigger targets an agent
     */
    public function isAgentTrigger(): bool
    {
        return $this->trigger_target_type === 'agent';
    }

    /**
     * Check if this trigger targets a command
     */
    public function isCommandTrigger(): bool
    {
        return $this->trigger_target_type === 'command';
    }

    /**
     * Get the agent input template from config
     *
     * @return string|null Template string with {{payload}} placeholders, or null for default behavior
     */
    public function getAgentInputTemplate(): ?string
    {
        return $this->config['agent_input_template'] ?? null;
    }

    /**
     * Check if this trigger has a custom agent input template
     */
    public function hasAgentInputTemplate(): bool
    {
        return ! empty($this->config['agent_input_template']);
    }

    /**
     * Get the default agent input template
     *
     * @return string Default template that injects entire payload as JSON
     */
    public static function getDefaultAgentInputTemplate(): string
    {
        return '{{payload}}';
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function defaultSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'default_session_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(ChatInteraction::class);
    }

    public function outputActions(): BelongsToMany
    {
        return $this->belongsToMany(OutputAction::class, 'input_trigger_output_action')
            ->withTimestamps();
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

    // Methods

    public function generateApiUrl(): string
    {
        return url("/api/v1/triggers/{$this->id}/invoke");
    }

    public function incrementUsage(): void
    {
        $this->increment('total_invocations');
        $this->update(['last_invoked_at' => now()]);
    }

    public function recordSuccess(): void
    {
        $this->increment('successful_invocations');
        $this->increment('total_invocations');
        $this->update(['last_invoked_at' => now()]);

        Log::info('Input trigger invoked successfully', [
            'trigger_id' => $this->id,
            'trigger_name' => $this->name,
            'user_id' => $this->user_id,
            'agent_id' => $this->agent_id,
        ]);
    }

    public function recordFailure(): void
    {
        $this->increment('failed_invocations');
        $this->increment('total_invocations');
        $this->update(['last_invoked_at' => now()]);

        Log::error('Input trigger invocation failed', [
            'trigger_id' => $this->id,
            'trigger_name' => $this->name,
            'user_id' => $this->user_id,
            'agent_id' => $this->agent_id,
        ]);
    }

    public function checkRateLimit(): bool
    {
        $limits = $this->rate_limits ?? [
            'per_minute' => 10,
            'per_hour' => 100,
            'per_day' => 1000,
        ];

        // Check using Laravel rate limiter
        $allowed = RateLimiter::attempt(
            "trigger:{$this->id}:minute",
            $limits['per_minute'] ?? 10,
            fn () => true,
            60
        );

        if (! $allowed) {
            Log::warning('Input trigger rate limit exceeded', [
                'trigger_id' => $this->id,
                'trigger_name' => $this->name,
                'user_id' => $this->user_id,
                'rate_limit_per_minute' => $limits['per_minute'] ?? 10,
            ]);
        }

        return $allowed;
    }

    /**
     * Check if the given IP address is allowed by the whitelist
     *
     * @param  string  $ip  The IP address to check
     * @return bool True if allowed (whitelist empty or IP matches), false otherwise
     */
    public function isIpAllowed(string $ip): bool
    {
        // If no whitelist is configured, allow all IPs
        if (empty($this->ip_whitelist)) {
            return true;
        }

        // Check if IP matches any CIDR range in the whitelist
        foreach ($this->ip_whitelist as $cidr) {
            if ($this->ipMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        Log::warning('Input trigger IP address denied by whitelist', [
            'trigger_id' => $this->id,
            'trigger_name' => $this->name,
            'user_id' => $this->user_id,
            'ip_address' => $ip,
            'whitelist' => $this->ip_whitelist,
        ]);

        return false;
    }

    /**
     * Check if an IP address matches a CIDR range
     *
     * Supports both IPv4 and IPv6 addresses with CIDR notation.
     * Handles single IP addresses (no slash) and validates IP version compatibility.
     *
     * @param  string  $ip  The IP address to check
     * @param  string  $cidr  The CIDR range (e.g., "10.0.0.0/24" or "2001:db8::/32")
     * @return bool True if the IP matches the CIDR range
     */
    protected function ipMatchesCidr(string $ip, string $cidr): bool
    {
        // Handle single IP addresses (no CIDR notation)
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr);

        // Validate IP and subnet
        if (! filter_var($ip, FILTER_VALIDATE_IP) || ! filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Check if both are same IP version
        $ipVersion = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;
        $subnetVersion = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;

        if ($ipVersion !== $subnetVersion) {
            return false;
        }

        if ($ipVersion === 4) {
            return $this->ipv4MatchesCidr($ip, $subnet, (int) $mask);
        } else {
            return $this->ipv6MatchesCidr($ip, $subnet, (int) $mask);
        }
    }

    /**
     * Check if an IPv4 address matches a CIDR range
     *
     * Uses bitwise operations to compare IP against subnet mask.
     *
     * @param  string  $ip  IPv4 address to check
     * @param  string  $subnet  IPv4 subnet base address
     * @param  int  $mask  CIDR mask length (0-32)
     * @return bool True if IP is within the subnet
     */
    protected function ipv4MatchesCidr(string $ip, string $subnet, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        $subnetLong &= $maskLong;

        return ($ipLong & $maskLong) === $subnetLong;
    }

    /**
     * Check if an IPv6 address matches a CIDR range
     *
     * Converts IPv6 addresses to binary and compares prefix bits.
     *
     * @param  string  $ip  IPv6 address to check
     * @param  string  $subnet  IPv6 subnet base address
     * @param  int  $mask  CIDR mask length (0-128)
     * @return bool True if IP is within the subnet
     */
    protected function ipv6MatchesCidr(string $ip, string $subnet, int $mask): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // Convert to binary strings for comparison
        $ipBits = $this->inetToBits($ipBinary);
        $subnetBits = $this->inetToBits($subnetBinary);

        // Compare the first $mask bits
        return substr($ipBits, 0, $mask) === substr($subnetBits, 0, $mask);
    }

    /**
     * Convert packed IP address to binary string
     */
    protected function inetToBits(string $inet): string
    {
        $unpacked = unpack('C*', $inet);
        $bits = '';
        foreach ($unpacked as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }

    // Boot

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($trigger) {
            if (empty($trigger->id)) {
                $trigger->id = (string) Str::uuid();
            }
        });
    }
}
