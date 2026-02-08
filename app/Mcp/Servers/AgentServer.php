<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Agent\GetAgentDetailsTool;
use App\Mcp\Tools\Agent\GetChatInteractionTool;
use App\Mcp\Tools\Agent\GetChatSessionTool;
use App\Mcp\Tools\Agent\InvokeAgentTool;
use App\Mcp\Tools\Agent\ListAgentsTool;
use App\Mcp\Tools\Agent\ListChatInteractionsTool;
use App\Mcp\Tools\Agent\ListChatSessionsTool;
use App\Mcp\Tools\Agent\SearchChatSessionsTool;
use App\Mcp\Tools\Agent\StreamAgentTool;
use Laravel\Mcp\Server;

/**
 * Agent MCP Server
 *
 * Provides access to PromptlyAgent's AI agent system including:
 * - Agent management and configuration
 * - Agent execution (invoke and streaming)
 * - Chat session management
 * - Interaction history
 *
 * Authentication: Requires Sanctum token with appropriate agent:* and chat:* scopes
 * Endpoint: /mcp/agent
 */
class AgentServer extends Server
{
    public string $name = 'PromptlyAgent Agent Server';

    public string $version = '1.0.0';

    public string $instructions = <<<'INSTRUCTIONS'
This server provides access to PromptlyAgent's AI agent system.

Available operations:
- List and view agent configurations
- Execute agents with input (synchronous and streaming)
- List and search chat sessions
- View chat interactions and conversation history

Required Authentication:
- Sanctum token with appropriate scopes (agent:view, agent:execute, chat:view)

Agent Capabilities:
- Prism-PHP powered AI execution
- Tool calling and function execution
- Real-time streaming responses via SSE
- Persistent chat sessions
- Multi-turn conversations

Chat Sessions:
- View conversation history
- Search sessions by content
- Access interaction details
- See tool calls and artifacts
INSTRUCTIONS;

    public array $tools = [
        // Agent Management
        ListAgentsTool::class,
        GetAgentDetailsTool::class,

        // Agent Execution
        InvokeAgentTool::class,
        StreamAgentTool::class,

        // Chat Sessions
        ListChatSessionsTool::class,
        SearchChatSessionsTool::class,
        GetChatSessionTool::class,

        // Chat Interactions
        ListChatInteractionsTool::class,
        GetChatInteractionTool::class,
    ];
}
