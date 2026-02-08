<?php

namespace App\Services\Security;

/**
 * SSRF (Server-Side Request Forgery) Protection Service
 *
 * Validates URLs before fetching to prevent:
 * - Access to cloud metadata services (AWS, GCP, Azure)
 * - Internal network scanning
 * - Localhost/loopback access
 * - Private IP range access
 * - Port scanning
 * - DNS rebinding attacks
 */
class SsrfProtection
{
    /**
     * Blocked IP ranges (CIDR notation)
     */
    private const BLOCKED_IP_RANGES = [
        // Private networks (RFC 1918)
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',

        // Loopback
        '127.0.0.0/8',

        // Link-local (includes AWS metadata service)
        '169.254.0.0/16',

        // IPv6 loopback
        '::1/128',

        // IPv6 private
        'fc00::/7',
        'fd00::/8',

        // IPv6 link-local
        'fe80::/10',
    ];

    /**
     * Allowed URL schemes
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Blocked ports (common internal services)
     */
    private const BLOCKED_PORTS = [
        22,   // SSH
        23,   // Telnet
        25,   // SMTP
        3306, // MySQL
        5432, // PostgreSQL
        6379, // Redis
        8000, // Common dev servers
        9000, // PHP-FPM
    ];

    /**
     * Blocked hostnames (exact match, case-insensitive)
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal', // GCP metadata
        '169.254.169.254',          // AWS metadata IP
    ];

    /**
     * Validate URL for SSRF vulnerabilities
     *
     * @param  string  $url  URL to validate
     * @return array{valid: bool, error: ?string, ip: ?string}
     */
    public static function validate(string $url): array
    {
        // Step 1: Parse URL
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            return ['valid' => false, 'error' => 'Invalid URL format', 'ip' => null];
        }

        // Step 2: Validate scheme (only http/https allowed)
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES)) {
            return ['valid' => false, 'error' => 'Only HTTP and HTTPS protocols are allowed', 'ip' => null];
        }

        // Step 3: Check hostname against blocked list
        $host = strtolower($parsed['host']);
        if (in_array($host, self::BLOCKED_HOSTNAMES)) {
            return ['valid' => false, 'error' => 'Blocked hostname', 'ip' => null];
        }

        // Step 4: Validate port
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (in_array($port, self::BLOCKED_PORTS)) {
            return ['valid' => false, 'error' => 'Blocked port', 'ip' => null];
        }

        // Step 5: Resolve hostname to IP address
        $ip = @gethostbyname($host);

        // If gethostbyname fails, it returns the hostname itself
        if ($ip === $host) {
            return ['valid' => false, 'error' => 'Unable to resolve hostname', 'ip' => null];
        }

        // Step 6: Check IP against blocked ranges
        foreach (self::BLOCKED_IP_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                return ['valid' => false, 'error' => 'Access to private/internal IP ranges is blocked', 'ip' => $ip];
            }
        }

        // Step 7: DNS rebinding protection - resolve again and compare
        $ip2 = @gethostbyname($host);
        if ($ip !== $ip2) {
            return ['valid' => false, 'error' => 'DNS rebinding detected', 'ip' => $ip];
        }

        return ['valid' => true, 'error' => null, 'ip' => $ip];
    }

    /**
     * Check if an IP address is within a CIDR range
     *
     * Supports both IPv4 and IPv6
     *
     * @param  string  $ip  IP address to check
     * @param  string  $range  CIDR range (e.g., "192.168.0.0/16")
     */
    private static function ipInRange(string $ip, string $range): bool
    {
        // Check if this is IPv6
        if (strpos($ip, ':') !== false || strpos($range, ':') !== false) {
            return self::ipv6InRange($ip, $range);
        }

        // IPv4 handling
        if (! str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $mask] = explode('/', $range);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - (int) $mask);
        $ipLong &= $maskLong;

        return $ipLong === ($subnetLong & $maskLong);
    }

    /**
     * Check if an IPv6 address is within a CIDR range
     *
     * @param  string  $ip  IPv6 address
     * @param  string  $range  IPv6 CIDR range
     */
    private static function ipv6InRange(string $ip, string $range): bool
    {
        if (! str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $mask] = explode('/', $range);

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $mask = (int) $mask;

        // Compare bit by bit
        for ($i = 0; $i < $mask; $i++) {
            $byte = intdiv($i, 8);
            $bit = 7 - ($i % 8);

            $ipBit = (ord($ipBinary[$byte]) >> $bit) & 1;
            $subnetBit = (ord($subnetBinary[$byte]) >> $bit) & 1;

            if ($ipBit !== $subnetBit) {
                return false;
            }
        }

        return true;
    }
}
