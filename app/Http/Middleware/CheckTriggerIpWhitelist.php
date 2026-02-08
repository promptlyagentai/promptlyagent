<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP Whitelist Middleware for Input Triggers.
 *
 * Validates incoming requests against trigger's IP whitelist configuration.
 * Supports both IPv4 and IPv6 addresses with CIDR notation.
 *
 * Features:
 * - Secure IP detection using REMOTE_ADDR (not spoofable headers)
 * - CIDR range matching for both IPv4 and IPv6
 * - Automatic pass-through when whitelist is empty
 *
 * Security:
 * - DOES NOT trust X-Forwarded-For/X-Real-IP headers by default (prevents spoofing)
 * - Uses $request->ip() which respects Laravel's TrustProxies configuration
 * - Configure TrustProxies middleware in bootstrap/app.php if behind proxy
 * - Logs all whitelist violations with full request context for auditing
 *
 * Proxy Setup:
 * If your application is behind a load balancer/proxy (AWS ALB, CloudFlare, etc.),
 * configure Laravel's TrustProxies middleware to enable X-Forwarded-For support:
 *
 * 1. Create app/Http/Middleware/TrustProxies.php
 * 2. Set $proxies = ['10.0.0.0/8'] (your proxy IP range)
 * 3. Register in bootstrap/app.php: ->withMiddleware(fn($m) => $m->trustProxies(at: '*'))
 * 4. Set TRUST_PROXIES=true in .env
 *
 * @see \App\Http\Controllers\Api\WebhookController
 * @see \App\Http\Controllers\Api\InputTriggerController
 */
class CheckTriggerIpWhitelist
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the trigger from the route parameter
        $trigger = $request->route('trigger');

        if (! $trigger) {
            Log::warning('CheckTriggerIpWhitelist: No trigger found in route parameters');

            return $next($request);
        }

        // Get IP whitelist from trigger configuration
        $ipWhitelist = $trigger->ip_whitelist ?? [];

        // If whitelist is empty, allow all IPs
        if (empty($ipWhitelist)) {
            return $next($request);
        }

        // Get client IP (handle proxy headers)
        $clientIp = $this->getClientIp($request);

        // Check if client IP is in any of the whitelisted ranges
        $isAllowed = false;
        foreach ($ipWhitelist as $cidr) {
            if ($this->ipInRange($clientIp, $cidr)) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            Log::warning('CheckTriggerIpWhitelist: IP not whitelisted', [
                'trigger_id' => $trigger->id,
                'trigger_name' => $trigger->name,
                'client_ip' => $clientIp,
                'whitelist' => $ipWhitelist,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_agent' => $request->userAgent(),
                'attempted_at' => now()->toISOString(),
                // SECURITY: Log proxy headers to detect spoofing attempts
                'x_forwarded_for' => $request->header('X-Forwarded-For'),
                'x_real_ip' => $request->header('X-Real-IP'),
                'remote_addr' => $request->server('REMOTE_ADDR'),
            ]);

            return response()->json([
                'message' => 'Access denied. Your IP address is not authorized to access this trigger.',
            ], Response::HTTP_FORBIDDEN);
        }

        Log::debug('CheckTriggerIpWhitelist: IP allowed', [
            'trigger_id' => $trigger->id,
            'client_ip' => $clientIp,
        ]);

        return $next($request);
    }

    /**
     * Get the client's IP address securely.
     *
     * SECURITY: Uses Laravel's $request->ip() which respects TrustProxies configuration.
     *
     * By default, this returns REMOTE_ADDR (the actual connecting IP), which cannot
     * be spoofed via headers. If TrustProxies middleware is configured, Laravel will
     * automatically use X-Forwarded-For only when the request comes from a trusted proxy.
     *
     * This prevents the following attack:
     * curl -H "X-Forwarded-For: 192.168.1.100" https://api.example.com/trigger/123
     *
     * Without trusted proxy validation, the attacker could spoof any whitelisted IP.
     * With this fix, only the actual connecting IP (REMOTE_ADDR) is used unless
     * Laravel's TrustProxies middleware explicitly trusts the proxy.
     *
     * @see \Illuminate\Http\Request::ip()
     * @see \Illuminate\Http\Middleware\TrustProxies
     */
    protected function getClientIp(Request $request): string
    {
        // Use Laravel's built-in IP detection which respects TrustProxies config
        // Returns REMOTE_ADDR by default (secure, non-spoofable)
        // Returns X-Forwarded-For first IP only if request from trusted proxy
        return $request->ip() ?? '0.0.0.0';
    }

    /**
     * Check if an IP address is within a CIDR range
     *
     * Supports both IPv4 and IPv6
     */
    protected function ipInRange(string $ip, string $cidr): bool
    {
        // Handle single IP addresses (no CIDR notation)
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr);

        // Validate IP and subnet
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            Log::warning('CheckTriggerIpWhitelist: Invalid IP address', ['ip' => $ip]);

            return false;
        }

        if (! filter_var($subnet, FILTER_VALIDATE_IP)) {
            Log::warning('CheckTriggerIpWhitelist: Invalid subnet in CIDR', ['cidr' => $cidr]);

            return false;
        }

        // Check if both are same IP version
        $ipVersion = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;
        $subnetVersion = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;

        if ($ipVersion !== $subnetVersion) {
            return false;
        }

        if ($ipVersion === 4) {
            return $this->ipv4InRange($ip, $subnet, (int) $mask);
        } else {
            return $this->ipv6InRange($ip, $subnet, (int) $mask);
        }
    }

    /**
     * Check if an IPv4 address is within a CIDR range
     */
    protected function ipv4InRange(string $ip, string $subnet, int $mask): bool
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
     * Check if an IPv6 address is within a CIDR range
     */
    protected function ipv6InRange(string $ip, string $subnet, int $mask): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // Convert to binary strings for comparison
        $ipBits = $this->inet_to_bits($ipBinary);
        $subnetBits = $this->inet_to_bits($subnetBinary);

        // Compare the first $mask bits
        return substr($ipBits, 0, $mask) === substr($subnetBits, 0, $mask);
    }

    /**
     * Convert packed IP address to binary string
     */
    protected function inet_to_bits(string $inet): string
    {
        $unpacked = unpack('C*', $inet);
        $bits = '';
        foreach ($unpacked as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
