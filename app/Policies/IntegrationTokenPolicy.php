<?php

namespace App\Policies;

use App\Models\IntegrationToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Authorization policy for IntegrationToken resources
 *
 * Enforces strict ownership-based access control for OAuth/API tokens.
 * Tokens are scoped to individual users and cannot be shared or accessed
 * by other users, even admins, for security reasons.
 *
 * Capability system:
 * - Tokens have granular capabilities (scopes) defined at creation
 * - useCapability() validates both ownership AND token capability
 * - Failed capability checks prevent unauthorized integration operations
 */
class IntegrationTokenPolicy
{
    /**
     * Determine if the user can view the integration token.
     */
    public function view(User $user, IntegrationToken $token): bool
    {
        return $token->user_id === $user->id;
    }

    /**
     * Determine if the user can update the integration token.
     */
    public function update(User $user, IntegrationToken $token): bool
    {
        return $token->user_id === $user->id;
    }

    /**
     * Determine if the user can delete the integration token.
     */
    public function delete(User $user, IntegrationToken $token): bool
    {
        return $token->user_id === $user->id;
    }

    /**
     * Determine if the user can use a specific capability on the integration token.
     *
     * @param  User  $user  The user attempting to use the capability
     * @param  IntegrationToken  $token  The token being checked
     * @param  string  $capability  The capability identifier (e.g., 'read', 'write', 'webhook:manage')
     * @return bool True if user owns token and token has the capability
     */
    public function useCapability(User $user, IntegrationToken $token, string $capability): bool
    {
        if ($token->user_id !== $user->id) {
            Log::warning('Integration token capability check failed - ownership', [
                'user_id' => $user->id,
                'token_id' => $token->id,
                'token_owner_id' => $token->user_id,
                'capability' => $capability,
            ]);

            return false;
        }

        // Check if capability is available and enabled
        $hasCapability = $token->tokenCan($capability);

        if (! $hasCapability) {
            Log::warning('Integration token capability check failed - missing capability', [
                'user_id' => $user->id,
                'token_id' => $token->id,
                'capability' => $capability,
            ]);
        }

        return $hasCapability;
    }
}
