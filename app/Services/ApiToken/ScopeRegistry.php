<?php

namespace App\Services\ApiToken;

use App\Services\InputTrigger\InputTriggerRegistry;

/**
 * Registry for API token scopes/abilities
 *
 * Collects and organizes scopes from various providers (input triggers, integrations, etc.)
 * for display in the API token configuration UI.
 */
class ScopeRegistry
{
    protected array $scopes = [];

    protected array $categories = [];

    public function __construct(
        protected InputTriggerRegistry $triggerRegistry
    ) {
        $this->registerCoreScopes();
        $this->registerProviderScopes();
    }

    /**
     * Register core application scopes
     */
    protected function registerCoreScopes(): void
    {
        $this->registerCategory('Chat & Sessions', [
            'chat:view' => 'View chat sessions and conversation history',
            'chat:create' => 'Create new chat sessions',
            'chat:manage' => 'Manage chat sessions (archive, unarchive, keep)',
            'chat:delete' => 'Delete chat sessions',
        ]);

        $this->registerCategory('Agents', [
            'agent:view' => 'View agent details and configurations',
            'agent:execute' => 'Execute agents directly',
            'agent:attach' => 'Attach files to agent queries',
        ]);

        $this->registerCategory('Tools', [
            'tools:view' => 'View available tools and tool configurations',
            'tools:execute' => 'Execute tools directly',
        ]);

        $this->registerCategory('Knowledge Management', [
            'knowledge:view' => 'View knowledge documents and details',
            'knowledge:create' => 'Create new knowledge documents',
            'knowledge:update' => 'Update knowledge documents',
            'knowledge:delete' => 'Delete knowledge documents',
            'knowledge:search' => 'Search knowledge documents',
            'knowledge:rag' => 'Perform RAG queries on knowledge',
            'knowledge:embeddings:view' => 'View embedding status',
            'knowledge:embeddings:regenerate' => 'Regenerate document embeddings',
            'knowledge:tags:manage' => 'Manage knowledge tags',
            'knowledge:agents:manage' => 'Assign knowledge to agents',
            'knowledge:bulk:manage' => 'Perform bulk operations',
        ]);
    }

    /**
     * Register scopes from input trigger providers
     */
    protected function registerProviderScopes(): void
    {
        $providers = $this->triggerRegistry->getAllProviders();

        foreach ($providers as $provider) {
            if ($provider->requiresApiToken()) {
                $abilities = $provider->getRequiredTokenAbilities();
                $scopeDefinitions = [];

                foreach ($abilities as $ability) {
                    // Generate description based on ability name if not explicitly defined
                    $scopeDefinitions[$ability] = $this->generateScopeDescription($ability);
                }

                $categoryName = $provider->getTriggerTypeName().' Triggers';
                $this->registerCategory($categoryName, $scopeDefinitions);
            }
        }
    }

    /**
     * Register a category of scopes
     *
     * @param  string  $category  Category name (e.g., "Chat & Sessions", "API Triggers")
     * @param  array  $scopes  Array of scope => description
     */
    public function registerCategory(string $category, array $scopes): void
    {
        if (! isset($this->categories[$category])) {
            $this->categories[$category] = [];
        }

        foreach ($scopes as $scope => $description) {
            $this->categories[$category][$scope] = $description;
            $this->scopes[$scope] = [
                'description' => $description,
                'category' => $category,
            ];
        }
    }

    /**
     * Get all registered scopes organized by category
     *
     * @return array ['category' => ['scope' => 'description', ...], ...]
     */
    public function getByCategory(): array
    {
        return $this->categories;
    }

    /**
     * Get all registered scopes as flat array
     *
     * @return array ['scope' => 'description', ...]
     */
    public function getAll(): array
    {
        return collect($this->scopes)
            ->mapWithKeys(fn ($data, $scope) => [$scope => $data['description']])
            ->toArray();
    }

    /**
     * Get all scope keys for validation
     *
     * @return array ['scope1', 'scope2', ...]
     */
    public function getAllKeys(): array
    {
        return array_keys($this->scopes);
    }

    /**
     * Check if a scope exists
     */
    public function has(string $scope): bool
    {
        return isset($this->scopes[$scope]);
    }

    /**
     * Get scope description
     */
    public function getDescription(string $scope): ?string
    {
        return $this->scopes[$scope]['description'] ?? null;
    }

    /**
     * Get scope category
     */
    public function getCategory(string $scope): ?string
    {
        return $this->scopes[$scope]['category'] ?? null;
    }

    /**
     * Generate a human-readable description from a scope name
     */
    protected function generateScopeDescription(string $scope): string
    {
        // Split by colon and format nicely
        $parts = explode(':', $scope);

        if (count($parts) === 2) {
            [$resource, $action] = $parts;

            return ucfirst($action).' '.str_replace('_', ' ', $resource);
        }

        return ucfirst(str_replace([':', '_'], ' ', $scope));
    }
}
