<?php

namespace App\Policies;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Authorization policy for Integration resources
 *
 * Implements ownership-based access control where users can only manage their own
 * integrations. Admins have additional privileges to share integrations globally,
 * making them available to all users in the system.
 *
 * Sharing model:
 * - Only admins who own an integration can share/unshare it
 * - Shared integrations become available to all users for read/use operations
 * - Original owner retains full CRUD permissions
 */
class IntegrationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Integration $integration): bool
    {
        return $integration->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Integration $integration): bool
    {
        return $integration->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Integration $integration): bool
    {
        return $integration->user_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Integration $integration): bool
    {
        return $integration->user_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Integration $integration): bool
    {
        return $integration->user_id === $user->id;
    }

    /**
     * Determine whether the user can share the integration with all users.
     * Only admins who own the integration can share it.
     */
    public function share(User $user, Integration $integration): bool
    {
        $canShare = $user->is_admin && $integration->user_id === $user->id;

        if (! $canShare) {
            Log::warning('Unauthorized integration share attempt', [
                'user_id' => $user->id,
                'integration_id' => $integration->id,
                'is_admin' => $user->is_admin,
                'is_owner' => $integration->user_id === $user->id,
            ]);
        }

        return $canShare;
    }

    /**
     * Determine whether the user can unshare the integration.
     * Only admins who own the integration can unshare it.
     */
    public function unshare(User $user, Integration $integration): bool
    {
        $canUnshare = $user->is_admin && $integration->user_id === $user->id;

        if (! $canUnshare) {
            Log::warning('Unauthorized integration unshare attempt', [
                'user_id' => $user->id,
                'integration_id' => $integration->id,
                'is_admin' => $user->is_admin,
                'is_owner' => $integration->user_id === $user->id,
            ]);
        }

        return $canUnshare;
    }
}
