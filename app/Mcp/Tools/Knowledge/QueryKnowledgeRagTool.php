<?php

namespace App\Mcp\Tools\Knowledge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Query Knowledge RAG
 *
 * Performs Retrieval-Augmented Generation (RAG) query to retrieve
 * relevant knowledge context for AI generation tasks.
 *
 * Required Scope: knowledge:rag
 */
class QueryKnowledgeRagTool extends Tool
{
    public function description(): string
    {
        return 'Perform RAG (Retrieval Augmented Generation) query to get relevant knowledge context for AI tasks';
    }

    public function name(): string
    {
        return 'query_knowledge_rag';
    }

    public function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        // Check authorization
        if (! $user || ! $user->tokenCan('knowledge:rag')) {
            return ToolResult::error('Insufficient permissions. Required scope: knowledge:rag');
        }

        // Validate input
        $validator = Validator::make($arguments, [
            'query' => 'required|string|max:1000',
            'agent_id' => 'nullable|integer',
            'limit' => 'integer|min:1|max:20',
            'context_length' => 'integer|min:100|max:8000',
            'include_sources' => 'boolean',
            'include_expired' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ToolResult::error('Validation failed: '.implode(', ', $validator->errors()->all()));
        }

        $validated = $validator->validated();

        // Create internal sub-request
        $subRequest = \Illuminate\Http\Request::create('/api/v1/knowledge/rag/query', 'POST', $validated);
        $subRequest->setUserResolver(fn () => $user);

        try {
            // Dispatch through routing system
            $response = Route::dispatch($subRequest);

            $data = json_decode($response->getContent(), true);

            Log::info('MCP RAG query performed', [
                'tool' => 'query_knowledge_rag',
                'user_id' => $user->id,
                'query_length' => strlen($validated['query']),
                'agent_id' => $validated['agent_id'] ?? null,
                'sources_count' => isset($data['sources']) ? count($data['sources']) : 0,
            ]);

            return ToolResult::json($data);
        } catch (\Exception $e) {
            Log::error('MCP tool execution failed', [
                'tool' => 'query_knowledge_rag',
                'user_id' => $user->id,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('RAG query failed: '.$e->getMessage());
        }
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')
            ->description('Query to retrieve relevant knowledge context (required, max 1000 characters)')
            ->required()

            ->integer('agent_id')
            ->description('Filter knowledge by agent assignment (optional)')
            ->optional()

            ->integer('limit')
            ->description('Maximum number of knowledge sources to retrieve (1-20, default: 5)')
            ->optional()

            ->integer('context_length')
            ->description('Maximum length of formatted context in characters (100-8000, default: 4000)')
            ->optional()

            ->boolean('include_sources')
            ->description('Include source document metadata in response (default: true)')
            ->optional()

            ->boolean('include_expired')
            ->description('Include documents past their TTL expiration (default: false)')
            ->optional();
    }
}
