<?php

namespace App\Services\Integrations\Providers;

use App\Models\Integration;
use App\Models\IntegrationToken;
use App\Services\Integrations\Contracts\IntegrationProvider;

/**
 * Knowledge API Integration Provider
 *
 * Provides programmatic API access to the Knowledge Management system with
 * OAuth scope-based permissions for external applications.
 */
class KnowledgeApiProvider implements IntegrationProvider
{
    public function getProviderId(): string
    {
        return 'knowledge_api';
    }

    public function getProviderName(): string
    {
        return 'Knowledge API';
    }

    public function getDescription(): string
    {
        return 'RESTful API for programmatic access to knowledge documents with granular OAuth scope-based permissions.';
    }

    public function getLogoUrl(): ?string
    {
        return null;
    }

    public function getSupportedAuthTypes(): array
    {
        return ['sanctum'];
    }

    public function getDefaultAuthType(): string
    {
        return 'sanctum';
    }

    public function getAuthTypeDescription(string $authType): string
    {
        return match ($authType) {
            'sanctum' => 'Use Laravel Sanctum API tokens with granular scope permissions',
            default => 'Unknown authentication type',
        };
    }

    public function getCapabilities(): array
    {
        return [
            'Knowledge' => ['manage', 'search', 'rag', 'embeddings'],
        ];
    }

    public function getCapabilityRequirements(): array
    {
        return [
            'Knowledge:manage' => ['knowledge:view', 'knowledge:create', 'knowledge:update', 'knowledge:delete'],
            'Knowledge:search' => ['knowledge:search'],
            'Knowledge:rag' => ['knowledge:rag'],
            'Knowledge:embeddings' => ['knowledge:embeddings:view', 'knowledge:embeddings:regenerate'],
        ];
    }

    public function getCapabilityDescriptions(): array
    {
        return [
            'Knowledge:manage' => 'Full CRUD access to knowledge documents via REST API endpoints',
            'Knowledge:search' => 'Perform full-text, semantic, and hybrid search operations on knowledge documents',
            'Knowledge:rag' => 'Execute RAG queries to retrieve relevant context for AI applications with streaming support',
            'Knowledge:embeddings' => 'View embedding status and regenerate document embeddings for vector search',
        ];
    }

    /**
     * Detect token scopes from Sanctum personal access token
     *
     * Retrieves the abilities/scopes granted to this token from Laravel Sanctum's
     * personal_access_tokens table. These abilities control API access permissions.
     *
     * @return array<string> Array of scope strings (e.g., ['knowledge:view', 'knowledge:search'])
     */
    public function detectTokenScopes(IntegrationToken $token): array
    {
        // For Laravel Sanctum tokens, abilities are stored in the personal access token
        $personalAccessToken = $token->personalAccessToken;

        if (! $personalAccessToken) {
            return [];
        }

        return $personalAccessToken->abilities ?? [];
    }

    public function evaluateTokenCapabilities(IntegrationToken $token): array
    {
        $tokenScopes = $this->detectTokenScopes($token);
        $available = [];
        $blocked = [];
        $categories = array_keys($this->getCapabilities());

        foreach ($this->getCapabilityRequirements() as $capability => $requiredScopes) {
            $missingScopes = array_diff($requiredScopes, $tokenScopes);

            if (empty($missingScopes)) {
                $available[] = $capability;
            } else {
                $blocked[] = [
                    'capability' => $capability,
                    'missing_scopes' => $missingScopes,
                ];
            }
        }

        return [
            'available' => $available,
            'blocked' => $blocked,
            'categories' => $categories,
        ];
    }

    public function getConfigurationSchema(): array
    {
        return [];
    }

    public function getCustomConfigComponent(): ?string
    {
        return null;
    }

    public function validateConfiguration(array $config): array
    {
        return [];
    }

    public function testConnection(IntegrationToken $token): bool
    {
        // Knowledge API is always available if the user has valid tokens
        return true;
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => 100,
            'search_per_minute' => 60,
            'rag_per_minute' => 30,
            'bulk_per_minute' => 10,
        ];
    }

    public function isEnabled(): bool
    {
        // Always enabled since it's a core feature
        return true;
    }

    public function isConnectable(): bool
    {
        // No connection setup needed - users create tokens through API token management
        return false;
    }

    public function supportsMultipleConnections(): bool
    {
        // Only one system integration instance allowed
        return false;
    }

    public function getRequiredConfig(): array
    {
        return []; // No environment variables required
    }

    public function getSetupRoute(): string
    {
        return 'settings.api-tokens';
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
        // No custom processing needed for Knowledge API
    }

    public function getSetupInstructions(mixed $context = null): string
    {
        return <<<'MARKDOWN'
## Knowledge API Setup

The Knowledge API provides programmatic access to your knowledge documents with OAuth scope-based permissions.

### Creating an API Token

1. Choose a descriptive name for your token (e.g., "Production API", "Mobile App")
2. Select the capabilities your application needs:
   - **Manage**: Full CRUD operations on knowledge documents
   - **Search**: Full-text, semantic, and hybrid search capabilities
   - **RAG**: Retrieval-Augmented Generation queries with streaming
   - **Embeddings**: View and regenerate document embeddings

3. Generate your token - **save it immediately** as it won't be shown again

### API Base URL

```
{APP_URL}/api/v1/knowledge
```

### Authentication

Include your token in the `Authorization` header:

```bash
Authorization: Bearer YOUR_TOKEN_HERE
```

### Key Endpoints

**Document Management:**
- `GET /` - List documents
- `POST /` - Create document (text/file/external)
- `GET /{document}` - Get document details
- `PUT /{document}` - Update document
- `DELETE /{document}` - Delete document

**Search Operations:**
- `POST /search` - Full-text search
- `POST /semantic-search` - Vector search
- `POST /hybrid-search` - Combined search (recommended)

**RAG Operations:**
- `POST /rag/query` - Get relevant context for a query
- `POST /rag/stream` - Streaming RAG with Server-Sent Events

**Embeddings:**
- `GET /embeddings/status` - View embedding statistics
- `POST /embeddings/regenerate` - Regenerate missing embeddings

### Example Request

```bash
curl -X GET "{APP_URL}/api/v1/knowledge" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Response Format

All responses follow a consistent JSON format:

```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "timestamp": "2025-01-15T10:30:00Z",
    "version": "v1"
  }
}
```

### Security Best Practices

- Use separate tokens for different applications/environments
- Grant only the minimum scopes required for each use case
- Rotate tokens periodically (recommended: every 90 days)
- Never commit tokens to version control or public repositories
- Monitor token usage in the integration dashboard
- Revoke unused tokens immediately

### Rate Limits

- Standard endpoints: 100 requests/minute
- Search operations: 60 requests/minute
- RAG operations: 30 requests/minute
- Bulk operations: 10 requests/minute

Rate limit headers are included in all responses.
MARKDOWN;
    }

    public function getAgentToolMappings(): array
    {
        return []; // Knowledge API doesn't provide agent tools
    }

    public function getAgentSystemPrompt(Integration $integration): string
    {
        return '';
    }

    public function getAgentDescription(Integration $integration): string
    {
        return '';
    }
}
