<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Invoke Agent (Non-Streaming)
 *
 * Execute an AI agent with a message and receive the complete response.
 * Currently not implemented - use StreamAgentTool for real-time responses.
 *
 * When implemented, will support file attachments via multipart/form-data
 * (requires agent:attach scope). Files are validated by SecureFileValidator.
 *
 * Required Scopes: agent:execute (+ agent:attach for file uploads)
 */
class InvokeAgentTool extends Tool
{
    public function description(): string
    {
        return 'Execute an AI agent synchronously and receive the complete response (currently not implemented - use stream_agent instead)';
    }

    public function name(): string
    {
        return 'invoke_agent';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('agent:execute')) {
            Log::warning('MCP unauthorized agent execution attempt', [
                'tool' => 'invoke_agent',
                'user_id' => $user?->id,
                'required_scope' => 'agent:execute',
            ]);

            return ToolResult::error('Insufficient permissions. Required scope: agent:execute');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'message' => 'required|string|max:10000',
            'session_id' => 'nullable|integer',
            'agent_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        // Non-streaming agent execution is not yet implemented
        // Return error with guidance to use stream_agent instead
        return ToolResult::error(
            'Non-streaming agent invocation is not yet implemented. '.
            'Please use the stream_agent tool for real-time agent execution via Server-Sent Events (SSE). '.
            'The streaming endpoint provides real-time responses and supports all agent capabilities.'
        );
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('message')
            ->description('The message or question to send to the agent (required, max 10000 characters)')
            ->required()

            ->integer('session_id')
            ->description('Optional chat session ID to continue an existing conversation')
            ->optional()

            ->integer('agent_id')
            ->description('Optional agent ID to use (defaults to Direct Chat Agent)')
            ->optional();
    }
}
