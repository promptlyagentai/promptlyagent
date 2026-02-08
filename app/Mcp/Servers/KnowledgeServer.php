<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Knowledge\CreateKnowledgeDocumentTool;
use App\Mcp\Tools\Knowledge\DeleteKnowledgeDocumentTool;
use App\Mcp\Tools\Knowledge\GetKnowledgeDocumentTool;
use App\Mcp\Tools\Knowledge\ListKnowledgeDocumentsTool;
use App\Mcp\Tools\Knowledge\ListKnowledgeTagsTool;
use App\Mcp\Tools\Knowledge\ManageDocumentTagsTool;
use App\Mcp\Tools\Knowledge\QueryKnowledgeRagTool;
use App\Mcp\Tools\Knowledge\SearchKnowledgeTool;
use App\Mcp\Tools\Knowledge\UpdateKnowledgeDocumentTool;
use Laravel\Mcp\Server;

/**
 * Knowledge API MCP Server
 *
 * Provides access to PromptlyAgent's knowledge management system including:
 * - Document search (full-text, semantic, hybrid)
 * - RAG (Retrieval Augmented Generation) queries
 * - Document CRUD operations
 * - Tag management
 *
 * Authentication: Requires Sanctum token with appropriate knowledge:* scopes
 * Endpoint: /mcp/knowledge
 */
class KnowledgeServer extends Server
{
    public string $name = 'PromptlyAgent Knowledge API Server';

    public string $version = '1.0.0';

    public string $instructions = <<<'INSTRUCTIONS'
This server provides access to PromptlyAgent's knowledge management system.

Available operations:
- Search documents using full-text, semantic, or hybrid search
- Perform RAG (Retrieval Augmented Generation) queries to get relevant context
- List, view, create, update, and delete knowledge documents
- Manage tags for organizing knowledge

Required Authentication:
- Sanctum token with appropriate scopes (knowledge:view, knowledge:search, etc.)

Document Types:
- Text documents (markdown, plain text)
- File uploads (PDF, Word, etc.)
- External sources (URLs, web content)

All documents support:
- Vector embeddings for semantic search
- Tag-based organization
- Privacy levels (public, private)
- TTL/expiration
INSTRUCTIONS;

    public array $tools = [
        // Search & Retrieval
        SearchKnowledgeTool::class,
        QueryKnowledgeRagTool::class,
        ListKnowledgeDocumentsTool::class,
        GetKnowledgeDocumentTool::class,

        // Document Management
        CreateKnowledgeDocumentTool::class,
        UpdateKnowledgeDocumentTool::class,
        DeleteKnowledgeDocumentTool::class,

        // Tag Management
        ListKnowledgeTagsTool::class,
        ManageDocumentTagsTool::class,
    ];
}
