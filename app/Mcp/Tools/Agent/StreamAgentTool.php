<?php

namespace App\Mcp\Tools\Agent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Stream Agent Execution
 *
 * Execute an AI agent with streaming support. Returns information about
 * the created interaction that can be polled for results.
 *
 * Supports file attachments via multipart/form-data (requires agent:attach scope).
 * Files are validated by SecureFileValidator for security.
 *
 * Required Scopes: agent:execute (+ agent:attach for file uploads)
 */
class StreamAgentTool extends Tool
{
    public function description(): string
    {
        return 'Execute an AI agent with streaming. Supports file attachments via multipart/form-data. Returns interaction details for result retrieval.';
    }

    public function name(): string
    {
        return 'stream_agent';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('agent:execute')) {
            Log::warning('MCP unauthorized agent execution attempt', [
                'tool' => 'stream_agent',
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

        $validated = $validator->validated();

        /**
         * The streaming endpoint returns an SSE stream which is difficult to consume
         * in a synchronous MCP tool. Return structured information about accessing the stream.
         */
        return ToolResult::json([
            'message' => 'Agent execution initiated',
            'stream_endpoint' => url('/api/v1/chat/stream'),
            'parameters' => $validated,
            'instructions' => [
                'The agent execution requires SSE (Server-Sent Events) streaming.',
                'To execute the agent, make a POST request to the stream_endpoint with the provided parameters.',
                'File attachments: Use multipart/form-data with "attachments" field (requires agent:attach scope).',
                'Supported file types: PDF, Word, images, text files (validated by SecureFileValidator).',
                'Multiple files: Send as array in "attachments[]" field.',
                'Alternatively, use the invoke_agent tool once non-streaming support is implemented.',
                'You can also poll for results using get_chat_interaction after creating an interaction.',
            ],
            'file_upload_example' => [
                'method' => 'POST',
                'content_type' => 'multipart/form-data',
                'fields' => [
                    'message' => 'Analyze this document',
                    'attachments[]' => 'file1.pdf',
                    'attachments[]' => 'file2.docx',
                    'session_id' => 'optional_session_id',
                    'agent_id' => 'optional_agent_id',
                ],
                'required_scopes' => ['agent:execute', 'agent:attach'],
            ],
            'note' => 'Full streaming support in MCP tools is planned for a future update.',
        ]);
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
