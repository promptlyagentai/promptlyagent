<?php

namespace App\Services\OutputAction;

use App\Models\InputTrigger;

/**
 * Webhook Signature Service - Industry-Standard HMAC Signature Generation.
 *
 * Provides cryptographic signature generation for outgoing webhooks using
 * industry-standard patterns (GitHub, Stripe, etc.). Enables webhook recipients
 * to verify payload authenticity and prevent tampering.
 *
 * Supported Signature Styles:
 * - **GitHub**: sha256={hash} in X-Hub-Signature-256 header
 * - **Stripe**: timestamp + hash in Stripe-Signature header (v1={hash},t={timestamp})
 * - **Simple**: Raw HMAC-SHA256 hash in X-Signature header
 *
 * Security Features:
 * - HMAC-SHA256 cryptographic hashing
 * - Shared secret per webhook (stored encrypted)
 * - Timestamp inclusion (Stripe style) prevents replay attacks
 * - Multiple algorithm support for compatibility
 *
 * GitHub Style:
 * - Header: X-Hub-Signature-256
 * - Format: sha256={hex_hash}
 * - Algorithm: HMAC-SHA256
 * - Used by: GitHub, GitLab
 *
 * Stripe Style:
 * - Header: Stripe-Signature
 * - Format: v1={hex_hash},t={unix_timestamp}
 * - Algorithm: HMAC-SHA256(timestamp + payload)
 * - Timestamp prevents replay attacks
 *
 * Simple Style:
 * - Header: X-Signature
 * - Format: {hex_hash}
 * - Algorithm: HMAC-SHA256
 * - No timestamp, simplest format
 *
 * Usage Pattern:
 * - OutputAction specifies signature style
 * - Service generates headers before HTTP request
 * - Recipient verifies signature on receiving webhook
 *
 * @see \App\Services\InputTrigger\Providers\WebhookTriggerProvider
 * @see \App\Jobs\ExecuteOutputActionJob
 * @see \App\Models\OutputAction
 */
class WebhookSignatureService
{
    /**
     * Generate HMAC signature headers for an input trigger webhook
     *
     * This creates the necessary headers to authenticate requests to an input trigger's
     * webhook URL, using the trigger's secret if available.
     *
     * @param  InputTrigger  $trigger  The input trigger to generate headers for
     * @param  string  $payload  The request body that will be sent
     * @param  string  $style  Signature style: 'github', 'stripe', 'simple' (default: 'github')
     * @return array Headers to include in the webhook request
     */
    public function generateTriggerWebhookHeaders(InputTrigger $trigger, string $payload, string $style = 'github'): array
    {
        $secret = $trigger->config['webhook_secret'] ?? null;

        if (! $secret) {
            return [];
        }

        return match ($style) {
            'github' => [
                'X-Hub-Signature-256' => $this->generateGitHubSignature($payload, $secret),
            ],
            'stripe' => [
                'Stripe-Signature' => $this->generateStripeSignature($payload, $secret),
            ],
            'simple' => [
                'X-Webhook-Signature' => $this->generateHmac($payload, $secret),
            ],
            default => [
                'X-Hub-Signature-256' => $this->generateGitHubSignature($payload, $secret),
            ],
        };
    }

    /**
     * Generate a GitHub-style HMAC-SHA256 signature
     *
     * GitHub format: sha256={signature}
     *
     * @param  string  $payload  The request body to sign
     * @param  string  $secret  The shared secret key
     * @return string The formatted signature header value
     */
    public function generateGitHubSignature(string $payload, string $secret): string
    {
        $signature = hash_hmac('sha256', $payload, $secret);

        return 'sha256='.$signature;
    }

    /**
     * Generate a Stripe-style timestamped signature
     *
     * Stripe format: t={timestamp},v1={signature}
     * The signature includes the timestamp to prevent replay attacks
     *
     * @param  string  $payload  The request body to sign
     * @param  string  $secret  The shared secret key
     * @param  int|null  $timestamp  Optional timestamp (defaults to current time)
     * @return string The formatted signature header value
     */
    public function generateStripeSignature(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $signedPayload = $timestamp.'.'.$payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Generate a simple HMAC signature
     *
     * @param  string  $payload  The data to sign
     * @param  string  $secret  The shared secret key
     * @param  string  $algorithm  The hash algorithm (default: sha256)
     * @return string The raw HMAC signature
     */
    public function generateHmac(string $payload, string $secret, string $algorithm = 'sha256'): string
    {
        return hash_hmac($algorithm, $payload, $secret);
    }

    /**
     * Format a signature header with multiple algorithms
     *
     * Useful for providers that support multiple signature algorithms
     * Format: sha1={sig1},sha256={sig2}
     *
     * @param  string  $payload  The data to sign
     * @param  string  $secret  The shared secret key
     * @param  array  $algorithms  Array of algorithms to use
     * @return string The formatted signature header value
     */
    public function generateMultiAlgorithmSignature(string $payload, string $secret, array $algorithms = ['sha1', 'sha256']): string
    {
        $signatures = [];

        foreach ($algorithms as $algorithm) {
            $signature = hash_hmac($algorithm, $payload, $secret);
            $signatures[] = "{$algorithm}={$signature}";
        }

        return implode(',', $signatures);
    }

    /**
     * Verify a GitHub-style signature
     *
     * Uses timing-safe comparison to prevent timing attacks
     *
     * @param  string  $payload  The request body
     * @param  string  $secret  The shared secret key
     * @param  string  $providedSignature  The signature from the webhook header
     * @return bool True if signature is valid
     */
    public function verifyGitHubSignature(string $payload, string $secret, string $providedSignature): bool
    {
        // Remove 'sha256=' prefix if present
        $providedSignature = str_starts_with($providedSignature, 'sha256=')
            ? substr($providedSignature, 7)
            : $providedSignature;

        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Verify a Stripe-style timestamped signature
     *
     * @param  string  $payload  The request body
     * @param  string  $secret  The shared secret key
     * @param  string  $signatureHeader  The full signature header (t=...,v1=...)
     * @param  int  $toleranceSeconds  Maximum age of timestamp (default: 300 = 5 minutes)
     * @return bool True if signature is valid and not expired
     */
    public function verifyStripeSignature(string $payload, string $secret, string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        // Parse signature header
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        if (! isset($parts['t']) || ! isset($parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $providedSignature = $parts['v1'];

        // Check timestamp tolerance (prevent replay attacks)
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        // Verify signature
        $signedPayload = $timestamp.'.'.$payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }

    /**
     * Generate a base64-encoded HMAC signature
     *
     * Some providers prefer base64-encoded signatures
     *
     * @param  string  $payload  The data to sign
     * @param  string  $secret  The shared secret key
     * @param  string  $algorithm  The hash algorithm
     * @return string The base64-encoded HMAC signature
     */
    public function generateBase64Hmac(string $payload, string $secret, string $algorithm = 'sha256'): string
    {
        $signature = hash_hmac($algorithm, $payload, $secret, true);

        return base64_encode($signature);
    }
}
