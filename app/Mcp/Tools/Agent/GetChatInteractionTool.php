<?php

namespace App\Mcp\Tools\Agent;

use App\Models\ChatInteraction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Get Chat Interaction
 *
 * Retrieve detailed information about a specific chat interaction
 * including the question, answer, agent used, sources, and artifacts.
 *
 * Required Scope: chat:view
 */
class GetChatInteractionTool extends Tool
{
    public function description(): string
    {
        return 'Get detailed information about a specific chat interaction by ID';
    }

    public function name(): string
    {
        return 'get_chat_interaction';
    }

    /**
     * Retrieve detailed chat interaction information.
     *
     * @param  array{interaction_id: int}  $arguments
     * @return ToolResult JSON result with interaction data or error
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When interaction not found
     */
    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('chat:view')) {
            return ToolResult::error('Insufficient permissions. Required scope: chat:view');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'interaction_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        try {
            // Query the interaction directly with relationships
            $interaction = ChatInteraction::with(['agent:id,name', 'sources', 'session:id,name,user_id'])
                ->findOrFail($validated['interaction_id']);

            // Verify ownership through session
            if ($interaction->session->user_id !== $user->id) {
                Log::warning('MCP unauthorized interaction access attempt', [
                    'tool' => 'get_chat_interaction',
                    'user_id' => $user->id,
                    'interaction_id' => $validated['interaction_id'],
                    'session_user_id' => $interaction->session->user_id,
                ]);

                return ToolResult::error('You do not have permission to access this interaction');
            }

            // Format the response
            $data = [
                'success' => true,
                'interaction' => [
                    'id' => $interaction->id,
                    'session_id' => $interaction->chat_session_id,
                    'session_name' => $interaction->session->name,
                    'question' => $interaction->question,
                    'answer' => $interaction->answer,
                    'agent' => [
                        'id' => $interaction->agent?->id,
                        'name' => $interaction->agent?->name,
                    ],
                    'sources' => $interaction->sources->map(function ($source) {
                        return [
                            'title' => $source->title,
                            'url' => $source->url,
                            'domain' => $source->domain,
                        ];
                    }),
                    'artifacts' => $interaction->getAllArtifacts(),
                    'metadata' => $interaction->metadata,
                    'created_at' => $interaction->created_at,
                    'updated_at' => $interaction->updated_at,
                ],
            ];

            return ToolResult::json($data);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('MCP chat interaction not found', [
                'tool' => 'get_chat_interaction',
                'user_id' => $user->id,
                'interaction_id' => $validated['interaction_id'],
            ]);

            return ToolResult::error('Chat interaction not found');
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'get_chat_interaction',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Failed to get chat interaction: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('interaction_id')
            ->description('The ID of the chat interaction to retrieve (required)')
            ->required();
    }
}
