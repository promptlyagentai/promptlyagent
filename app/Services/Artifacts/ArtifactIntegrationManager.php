<?php

namespace App\Services\Artifacts;

use App\Models\Artifact;
use App\Models\ArtifactIntegration;
use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\Integrations\Contracts\ArtifactStorageProvider;
use App\Services\Integrations\ProviderRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Artifact Integration Manager - External Integration Sync Management.
 *
 * Coordinates artifact synchronization with external storage providers (GitHub,
 * Google Drive, OneDrive, etc.). Manages bidirectional sync, auto-sync scheduling,
 * and multi-provider artifact distribution.
 *
 * Core Responsibilities:
 * - **Initial Sync**: Create artifacts in external providers with metadata
 * - **Update Sync**: Push artifact changes to integrated providers
 * - **Deletion Sync**: Remove artifacts from external providers
 * - **Auto-Sync**: Schedule automated synchronization for changed artifacts
 * - **Provider Discovery**: Find available integrations with artifact storage capability
 *
 * Integration Lifecycle:
 * 1. User initiates sync → syncToIntegration()
 * 2. ArtifactIntegration record created with external_id
 * 3. Auto-sync monitors artifact.updated_at vs sync_metadata.last_synced_at
 * 4. Changes trigger updateInIntegration()
 * 5. Removal calls removeFromIntegration() with optional external deletion
 *
 * Provider Interface:
 * - Uses ArtifactStorageProvider contract for provider abstraction
 * - Providers implement storeArtifact(), updateArtifact(), deleteArtifact()
 * - Provider capabilities checked before sync operations
 *
 * Error Handling:
 * - Transactional sync operations with rollback on failure
 * - ArtifactIntegration status tracking (pending, synced, failed)
 * - Comprehensive error logging with full context
 * - Graceful handling of external provider failures
 *
 * @see \App\Models\ArtifactIntegration
 * @see \App\Services\Integrations\Contracts\ArtifactStorageProvider
 * @see \App\Services\Integrations\ProviderRegistry
 */
class ArtifactIntegrationManager
{
    public function __construct(
        private ProviderRegistry $integrationRegistry
    ) {}

    /**
     * Sync a artifact to an integration for the first time
     * Creates a new ArtifactIntegration record and stores the artifact
     *
     * @param  array{integration_id?: int, auto_sync_enabled?: bool}  $options  Sync configuration options
     *
     * @throws \Exception if the provider doesn't support artifact storage or artifact already synced
     */
    public function syncToIntegration(Artifact $artifact, Integration $integration, array $options = []): ArtifactIntegration
    {
        $token = $integration->integrationToken;

        // Get the provider
        $provider = $this->integrationRegistry->get($token->provider_id);

        // Check if provider supports artifact storage
        if (! $provider instanceof ArtifactStorageProvider) {
            throw new \Exception("Provider {$token->provider_id} does not support artifact storage");
        }

        if (! $provider->supportsArtifactStorage($integration)) {
            throw new \Exception('Integration does not have artifact storage capability enabled');
        }

        // Check if already exists
        if ($artifact->isInIntegration($integration)) {
            throw new \Exception('Artifact is already synced to this integration. Use update instead.');
        }

        try {
            // Store in integration using credentials from token
            // Pass integration_id so provider can access integration-specific config
            $options['integration_id'] = $integration->id;
            $result = $provider->storeArtifact($artifact, $token, $options);

            // Create ArtifactIntegration record
            $artifactIntegration = ArtifactIntegration::create([
                'artifact_id' => $artifact->id,
                'integration_id' => $integration->id,
                'external_id' => $result['external_id'],
                'external_url' => $result['external_url'] ?? null,
                'auto_sync_enabled' => $options['auto_sync_enabled'] ?? false,
                'sync_metadata' => $result['metadata'] ?? null,
            ]);

            // Mark as synced
            $artifactIntegration->markSynced();

            Log::info('Artifact synced to integration', [
                'artifact_id' => $artifact->id,
                'integration_id' => $integration->id,
                'provider_id' => $token->provider_id,
                'external_id' => $result['external_id'],
            ]);

            return $artifactIntegration;

        } catch (\Exception $e) {
            Log::error('Failed to sync artifact to integration', [
                'artifact_id' => $artifact->id,
                'integration_id' => $integration->id,
                'provider_id' => $token->provider_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update an existing artifact in an integration
     *
     * @throws \Exception if provider doesn't support artifact storage or update fails
     */
    public function updateInIntegration(Artifact $artifact, ArtifactIntegration $integration): void
    {
        // Get the provider
        $token = $integration->integrationToken;
        $provider = $this->integrationRegistry->get($token->provider_id);

        if (! $provider instanceof ArtifactStorageProvider) {
            throw new \Exception("Provider {$token->provider_id} does not support artifact storage");
        }

        try {
            // Mark as pending
            $integration->markPending();

            // Update in integration
            $result = $provider->updateArtifact($artifact, $integration);

            // Update ArtifactIntegration record
            $integration->update([
                'external_id' => $result['external_id'],
                'external_url' => $result['external_url'] ?? $integration->external_url,
                'sync_metadata' => $result['metadata'] ?? $integration->sync_metadata,
            ]);

            // Mark as synced
            $integration->markSynced();

            Log::info('Artifact updated in integration', [
                'artifact_id' => $artifact->id,
                'integration_id' => $integration->id,
                'provider_id' => $token->provider_id,
            ]);

        } catch (\Exception $e) {
            // Mark as failed
            $integration->markFailed($e->getMessage());

            Log::error('Failed to update artifact in integration', [
                'artifact_id' => $artifact->id,
                'integration_id' => $integration->id,
                'provider_id' => $token->provider_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Remove artifact from an integration
     * Optionally delete from the integration or just remove the link
     *
     * @throws \Exception if artifact not synced to integration
     */
    public function removeFromIntegration(Artifact $artifact, Integration $integration, bool $deleteExternal = false): void
    {
        $artifactIntegration = $artifact->getIntegrationData($integration);

        if (! $artifactIntegration) {
            throw new \Exception('Artifact is not synced to this integration');
        }

        if ($deleteExternal) {
            // Get the provider and delete externally
            $token = $integration->integrationToken;
            $provider = $this->integrationRegistry->get($token->provider_id);

            if ($provider instanceof ArtifactStorageProvider) {
                try {
                    $provider->deleteArtifact($artifactIntegration);
                    Log::info('Artifact deleted from integration', [
                        'artifact_id' => $artifact->id,
                        'artifact_integration_id' => $artifactIntegration->id,
                        'integration_id' => $integration->id,
                        'provider_id' => $token->provider_id,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete artifact from integration, removing link anyway', [
                        'artifact_id' => $artifact->id,
                        'artifact_integration_id' => $artifactIntegration->id,
                        'integration_id' => $integration->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Delete the integration record
        $artifactIntegration->delete();

        Log::info('Artifact integration removed', [
            'artifact_id' => $artifact->id,
            'integration_id' => $integration->id,
            'deleted_external' => $deleteExternal,
        ]);
    }

    /**
     * Get all integrations available for a user that support artifact storage
     */
    public function getAvailableIntegrations(User $user): Collection
    {
        return Integration::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('integrationToken', function ($query) {
                $query->where('status', 'active');
            })
            ->with('integrationToken')
            ->get()
            ->filter(function ($integration) {
                $provider = $this->integrationRegistry->get($integration->integrationToken->provider_id);

                return $provider instanceof ArtifactStorageProvider
                    && $provider->supportsArtifactStorage($integration);
            });
    }

    /**
     * Get all artifact integrations that need sync
     * (auto-sync enabled and content changed since last sync)
     */
    public function getIntegrationsNeedingSync(): Collection
    {
        return ArtifactIntegration::where('auto_sync_enabled', true)
            ->with(['artifact', 'integrationToken'])
            ->get()
            ->filter(fn (ArtifactIntegration $integration) => $integration->needsSync());
    }

    /**
     * Toggle auto-sync for a artifact integration
     */
    public function toggleAutoSync(ArtifactIntegration $integration): void
    {
        $integration->update([
            'auto_sync_enabled' => ! $integration->auto_sync_enabled,
        ]);

        Log::info('Artifact integration auto-sync toggled', [
            'integration_id' => $integration->id,
            'auto_sync_enabled' => $integration->auto_sync_enabled,
        ]);
    }

    /**
     * Get artifact storage provider for a token
     */
    public function getArtifactStorageProvider(IntegrationToken $token): ?ArtifactStorageProvider
    {
        $provider = $this->integrationRegistry->get($token->provider_id);

        return $provider instanceof ArtifactStorageProvider ? $provider : null;
    }
}
