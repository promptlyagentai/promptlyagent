<?php

use App\Mcp\Servers\AgentServer;
use App\Mcp\Servers\KnowledgeServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP AI Routes
|--------------------------------------------------------------------------
|
| This file defines the MCP (Model Context Protocol) servers for the
| PromptlyAgent application. These servers expose knowledge management
| and agent interaction capabilities via HTTP endpoints.
|
*/

// OAuth 2.1 Authentication Routes
Mcp::oauthRoutes();

// Knowledge API Server - Document management, search, and RAG operations
Mcp::web('knowledge', KnowledgeServer::class)
    ->middleware(['auth:sanctum', 'throttle:100,1']);

// Agent Server - Agent management, execution, and chat interactions
Mcp::web('agent', AgentServer::class)
    ->middleware(['auth:sanctum', 'throttle:100,1']);
