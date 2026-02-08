<?php

namespace App\Tools;

use App\Models\Agent;
use App\Models\KnowledgeDocument;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * AssignKnowledgeToAgentTool - Assign Knowledge Documents to Agents
 *
 * Prism tool for assigning knowledge documents to agents. Supports:
 * - Assigning specific document IDs
 * - Assigning all documents with specific tags
 * - Assigning all available knowledge (use with caution)
 *
 * Knowledge Assignment Strategies:
 * - **specific**: Assign specific documents by ID
 * - **by_tags**: Assign all documents with specified tags (AND logic)
 * - **all**: Assign all available knowledge documents
 *
 * Use Cases:
 * - Configure agent-specific knowledge access
 * - Assign domain-specific documentation
 * - Provide context for specialized agents
 * - Enable RAG-enhanced agent responses
 *
 * Response Data:
 * - Assignment confirmation
 * - Count of documents assigned
 * - Assignment strategy used
 *
 * @see \App\Models\Agent
 * @see \App\Models\KnowledgeDocument
 */
class AssignKnowledgeToAgentTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('assign_knowledge_to_agent')
            ->for('Assign knowledge documents to an agent. You can assign specific documents by ID, all documents with specific tags, or all available knowledge. Knowledge assignment enables the agent to use RAG (Retrieval Augmented Generation) with assigned documents.')
            ->withNumberParameter('agent_id', 'Agent ID to assign knowledge to (required)')
            ->withStringParameter('strategy', 'Assignment strategy: specific (document IDs), by_tags (documents with tags), all (all documents)')
            ->withArrayParameter('document_ids', 'Array of document IDs to assign (required if strategy=specific)', new NumberSchema('document_id', 'Document ID'), false)
            ->withArrayParameter('tags', 'Array of tag names to filter documents (required if strategy=by_tags)', new StringSchema('tag', 'Tag name'), false)
            ->using(function (
                int $agent_id,
                string $strategy = 'specific',
                array $document_ids = [],
                array $tags = []
            ) {
                return static::executeAssignKnowledge([
                    'agent_id' => $agent_id,
                    'strategy' => $strategy,
                    'document_ids' => $document_ids,
                    'tags' => $tags,
                ]);
            });
    }

    protected static function executeAssignKnowledge(array $arguments): string
    {
        try {
            $interactionId = null;
            $statusReporter = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            if ($statusReporter) {
                $statusReporter->report('assign_knowledge', 'Assigning knowledge to agent', true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'agent_id' => 'required|integer|exists:agents,id',
                'strategy' => 'required|in:specific,by_tags,all',
                'document_ids' => 'array',
                'document_ids.*' => 'integer|exists:knowledge_documents,id',
                'tags' => 'array',
                'tags.*' => 'string',
            ]);

            if ($validator->fails()) {
                Log::warning('AssignKnowledgeToAgentTool: Validation failed', [
                    'interaction_id' => $interactionId,
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'AssignKnowledgeToAgentTool');
            }

            $validated = $validator->validated();

            // Get agent
            $agent = Agent::findOrFail($validated['agent_id']);

            if ($statusReporter) {
                $statusReporter->report('assign_knowledge', "Using '{$validated['strategy']}' strategy for agent: {$agent->name}", true, false);
            }

            // Determine documents to assign based on strategy
            $documentIds = [];

            switch ($validated['strategy']) {
                case 'specific':
                    if (empty($validated['document_ids'])) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'document_ids required when strategy is "specific"',
                        ], 'AssignKnowledgeToAgentTool');
                    }
                    $documentIds = $validated['document_ids'];
                    if ($statusReporter) {
                        $statusReporter->report('assign_knowledge', 'Assigning '.count($documentIds).' specific documents', true, false);
                    }
                    break;

                case 'by_tags':
                    if (empty($validated['tags'])) {
                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => 'tags required when strategy is "by_tags"',
                        ], 'AssignKnowledgeToAgentTool');
                    }

                    if ($statusReporter) {
                        $statusReporter->report('assign_knowledge', 'Searching for documents with tags: '.implode(', ', $validated['tags']), true, false);
                    }

                    // Find documents that have ALL specified tags (AND logic)
                    $query = KnowledgeDocument::query();
                    foreach ($validated['tags'] as $tagName) {
                        $query->whereHas('tags', function ($q) use ($tagName) {
                            $q->where('name', $tagName);
                        });
                    }
                    $documentIds = $query->pluck('id')->toArray();

                    if ($statusReporter) {
                        $statusReporter->report('assign_knowledge', 'Found '.count($documentIds).' documents matching tags', true, false);
                    }
                    break;

                case 'all':
                    if ($statusReporter) {
                        $statusReporter->report('assign_knowledge', 'Fetching all available knowledge documents', true, false);
                    }
                    $documentIds = KnowledgeDocument::pluck('id')->toArray();
                    if ($statusReporter) {
                        $statusReporter->report('assign_knowledge', 'Found '.count($documentIds).' total documents', true, false);
                    }
                    break;
            }

            // Assign knowledge documents
            if ($statusReporter) {
                $statusReporter->report('assign_knowledge', 'Syncing documents with agent', true, false);
            }

            DB::transaction(function () use ($agent, $documentIds) {
                // Sync will add new and remove old assignments
                $agent->knowledgeDocuments()->sync($documentIds);
            });

            $assignedCount = count($documentIds);

            if ($statusReporter) {
                $statusReporter->report('assign_knowledge', "✓ Successfully assigned {$assignedCount} documents to {$agent->name}", true, false);
            }

            Log::info('AssignKnowledgeToAgentTool: Knowledge assigned successfully', [
                'interaction_id' => $interactionId,
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'strategy' => $validated['strategy'],
                'documents_assigned' => $assignedCount,
                'tags_used' => $validated['tags'] ?? null,
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'strategy' => $validated['strategy'],
                    'documents_assigned' => $assignedCount,
                    'tags_used' => $validated['tags'] ?? null,
                ],
                'message' => "Successfully assigned {$assignedCount} knowledge document(s) to {$agent->name}",
            ], 'AssignKnowledgeToAgentTool');

        } catch (\Exception $e) {
            Log::error('AssignKnowledgeToAgentTool: Exception during execution', [
                'interaction_id' => $interactionId ?? null,
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to assign knowledge: '.$e->getMessage(),
            ], 'AssignKnowledgeToAgentTool');
        }
    }
}
