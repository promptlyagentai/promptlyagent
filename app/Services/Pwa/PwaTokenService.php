<?php

namespace App\Services\Pwa;

use App\Models\User;
use App\Services\ApiToken\ScopeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PwaTokenService
{
    public function __construct(
        protected ScopeRegistry $scopeRegistry
    ) {}

    /**
     * Generate PWA token with full access (all available scopes)
     *
     * @return array ['token' => string, 'token_id' => int]
     */
    public function generatePwaToken(User $user, string $deviceName = 'PWA Device'): array
    {
        // Grant all available scopes for full access
        $allScopes = $this->scopeRegistry->getAllKeys();

        $token = $user->createToken(
            name: "PWA - {$deviceName}",
            abilities: $allScopes
        );

        return [
            'token' => $token->plainTextToken,
            'token_id' => $token->accessToken->id,
        ];
    }

    /**
     * Get QR code data (JSON format)
     */
    public function getQrData(string $token): array
    {
        return [
            'server' => url('/'),
            'token' => $token,
        ];
    }

    /**
     * Get all active PWA tokens for user
     */
    public function getUserPwaTokens(User $user): Collection
    {
        return $user->tokens()
            ->where('name', 'like', 'PWA - %')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Revoke PWA token
     */
    public function revokePwaToken(User $user, int $tokenId): bool
    {
        $token = $user->tokens()->where('id', $tokenId)->first();

        if (! $token) {
            return false;
        }

        $token->delete();

        return true;
    }

    /**
     * Generate one-time setup code for PWA onboarding
     *
     * @return string 8-character setup code
     */
    public function generateSetupCode(User $user, string $token): string
    {
        $code = Str::random(8);

        Cache::put(
            key: "pwa:setup:{$code}",
            value: [
                'user_id' => $user->id,
                'server' => url('/'),
                'token' => $token,
            ],
            ttl: now()->addMinutes(15)
        );

        return $code;
    }

    /**
     * Get token data from setup code
     *
     * @return array|null ['user_id' => int, 'server' => string, 'token' => string]
     */
    public function getTokenFromSetupCode(string $code): ?array
    {
        return Cache::get("pwa:setup:{$code}");
    }

    /**
     * Get setup URL for QR code
     */
    public function getSetupUrl(string $code): string
    {
        return url("/pwa/setup/{$code}");
    }

    /**
     * Get QR data for setup code (redirects to setup page)
     */
    public function getSetupQrData(string $code): array
    {
        return [
            'url' => $this->getSetupUrl($code),
        ];
    }
}
