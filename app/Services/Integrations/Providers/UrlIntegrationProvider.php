<?php

namespace App\Services\Integrations\Providers;

use App\Livewire\UrlBrowser;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Contracts\IntegrationProvider;
use App\Services\Integrations\Contracts\KnowledgeSourceProvider;
use App\Services\Knowledge\ExternalKnowledgeManager;
use Illuminate\Support\Facades\Log;

/**
 * URL Integration Provider
 * Provides URL-based knowledge source import (no authentication required)
 */
class UrlIntegrationProvider implements IntegrationProvider, KnowledgeSourceProvider
{
    // Provider Identification

    public function getProviderId(): string
    {
        return 'url';
    }

    public function getProviderName(): string
    {
        return 'External URL';
    }

    public function getDescription(): string
    {
        return 'Import content from any publicly accessible web URL.';
    }

    public function getLogoUrl(): ?string
    {
        return null;
    }

    // Capabilities

    public function getCapabilities(): array
    {
        return [
            'Knowledge' => ['add', 'refresh'],
        ];
    }

    public function getCapabilityRequirements(): array
    {
        // URL provider doesn't require any scopes (public access)
        return [
            'Knowledge:add' => [],
            'Knowledge:refresh' => [],
        ];
    }

    public function getCapabilityDescriptions(): array
    {
        return [
            'Knowledge:add' => 'Import web pages and online documents as knowledge',
            'Knowledge:refresh' => 'Update knowledge documents with latest content from the web',
        ];
    }

    public function detectTokenScopes(\App\Models\IntegrationToken $token): array
    {
        // URL provider doesn't use tokens - always available
        return ['public'];
    }

    public function evaluateTokenCapabilities(\App\Models\IntegrationToken $token): array
    {
        $capabilities = $this->getCapabilities();
        $available = [];
        $categories = array_keys($capabilities);

        // All capabilities are always available for URL provider (no authentication)
        foreach ($capabilities as $category => $actions) {
            foreach ($actions as $action) {
                $available[] = "{$category}:{$action}";
            }
        }

        return [
            'available' => $available,
            'blocked' => [],
            'categories' => $categories,
        ];
    }

    // Authentication (not required for URLs)

    public function getAuthType(): string
    {
        return 'none';
    }

    public function getSupportedAuthTypes(): array
    {
        return ['none'];
    }

    public function getDefaultAuthType(): string
    {
        return 'none';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return 'No authentication required for public URLs';
    }

    public function requiresAuthentication(): bool
    {
        return false;
    }

    public function getAuthorizationUrl(array $config = []): string
    {
        return '';
    }

    public function handleCallback(array $params): array
    {
        return [];
    }

    // Provider Management

    public function testConnection($token): bool
    {
        // No connection test needed for URL provider
        return true;
    }

    public function getConfigurationSchema(): array
    {
        return [];
    }

    public function getCustomConfigComponent(): ?string
    {
        return null; // Use generic form rendering
    }

    public function validateConfiguration(array $config): array
    {
        return [];
    }

    public function getRateLimits(): array
    {
        return [];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getRequiredConfig(): array
    {
        return [];
    }

    public function isConnectable(): bool
    {
        // URL provider is always available (no setup/connection needed)
        return false;
    }

    public function supportsMultipleConnections(): bool
    {
        // URL provider doesn't have connections (always available)
        return false;
    }

    // Knowledge Source Provider Implementation

    public function supportsKnowledgeImport(): bool
    {
        return true;
    }

    public function getKnowledgeBrowserComponent(): ?string
    {
        return UrlBrowser::class;
    }

    /**
     * Import selected URLs as knowledge documents
     *
     * Creates external knowledge documents with auto-refresh capabilities for web URLs.
     * Each URL is fetched, parsed, and indexed for semantic search.
     *
     * @param  User  $user  The user creating these knowledge documents
     * @param  array<array{url: string, title?: string, description?: string, tags?: array, privacy_level?: string, ttl_hours?: int, auto_refresh?: bool, refresh_interval?: int}>  $selectedItems  URL items to import
     * @param  array  $options  Additional import options (currently unused)
     * @return array{success: array, failed: array} Results with successful documents and failures
     */
    public function importAsKnowledge(User $user, array $selectedItems, array $options = []): array
    {
        $externalKnowledgeManager = app(ExternalKnowledgeManager::class);
        $results = ['success' => [], 'failed' => []];

        foreach ($selectedItems as $item) {
            try {
                $url = $item['url'] ?? null;
                $title = $item['title'] ?? null;
                $description = $item['description'] ?? null;
                $tags = $item['tags'] ?? [];
                $privacyLevel = $item['privacy_level'] ?? 'private';
                $ttlHours = $item['ttl_hours'] ?? null;
                $autoRefresh = $item['auto_refresh'] ?? false;
                $refreshInterval = $item['refresh_interval'] ?? 60;

                if (! $url) {
                    $results['failed'][] = [
                        'item' => $item,
                        'error' => 'URL is required',
                    ];

                    continue;
                }

                $document = $externalKnowledgeManager->addExternalSource(
                    sourceIdentifier: $url,
                    sourceType: 'url',
                    title: $title,
                    description: $description,
                    tags: $tags,
                    privacyLevel: $privacyLevel,
                    ttlHours: $ttlHours,
                    autoRefresh: $autoRefresh,
                    refreshIntervalMinutes: $autoRefresh ? $refreshInterval : null,
                    authCredentials: [],
                    userId: $user->id
                );

                $results['success'][] = $document;

            } catch (\Exception $e) {
                Log::error('Failed to import URL as knowledge', [
                    'url' => $item['url'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'user_id' => $user->id,
                ]);

                $results['failed'][] = [
                    'item' => $item,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function getKnowledgeSourceInfo(): array
    {
        return [
            'label' => 'External URL',
            'description' => 'Import content from any web URL',
            'icon' => 'link',
        ];
    }

    public function renderViewOriginalLinks($document): array
    {
        // Return single link with URL
        if (! empty($document->external_source_identifier)) {
            return [
                [
                    'url' => $document->external_source_identifier,
                    'label' => $document->title ?? parse_url($document->external_source_identifier, PHP_URL_HOST) ?? 'View Original',
                ],
            ];
        }

        return [];
    }

    public function getSourceSummary($document): ?string
    {
        // Return domain name as summary
        if (! empty($document->external_source_identifier)) {
            $host = parse_url($document->external_source_identifier, PHP_URL_HOST);

            return $host ? "From {$host}" : 'External URL';
        }

        return null;
    }

    public function getEditModalView($document): ?string
    {
        // Use default rendering for URLs - no special view needed
        return null;
    }

    public function getPageManagerComponent(): ?string
    {
        // URLs don't support page management
        return null;
    }

    public function updateDocumentContent($document, array $config): array
    {
        // URLs don't support content updates through configuration
        // Content is updated via refresh/re-fetch only
        return [
            'success' => false,
            'message' => 'URL sources do not support page management',
        ];
    }

    public function getKnowledgeSourceClass(): ?string
    {
        return \App\Services\Knowledge\ExternalKnowledgeSources\UrlKnowledgeSource::class;
    }

    // Agent Integration Methods (URL provider does not support agents)

    public function getAgentToolMappings(): array
    {
        return [];
    }

    public function getAgentSystemPrompt(Integration $integration): string
    {
        return '';
    }

    public function getAgentDescription(Integration $integration): string
    {
        return '';
    }

    public function getCreateFormSections(): array
    {
        return [];
    }

    public function getEditFormSections(): array
    {
        return [];
    }

    public function processIntegrationUpdate(Integration $integration, array $requestData): void
    {
        // URL provider doesn't have configuration to process
    }

    public function getSetupInstructions(mixed $context = null): string
    {
        return '';
    }

    public function getSetupRoute(): string
    {
        // URL provider is always available, no setup needed
        return '#';
    }
}
