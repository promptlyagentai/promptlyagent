<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentKnowledgeAssignment;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Agent Knowledge Service - Agent-Knowledge Integration Management.
 *
 * Manages knowledge assignment and access control for AI agents, providing
 * RAG (Retrieval-Augmented Generation) capabilities through flexible assignment
 * strategies. Supports document-level, tag-based, and global knowledge access.
 *
 * Assignment Strategies:
 * - **Document Assignment**: Direct assignment of specific knowledge documents
 * - **Tag Assignment**: Access to all documents matching specific tags
 * - **All Knowledge Assignment**: Global access to entire knowledge base
 * - **Priority Ordering**: Control retrieval order via priority rankings
 *
 * Knowledge Access Patterns:
 * 1. Agent makes query → knowledge_search tool invoked
 * 2. KnowledgeRAG checks agent assignments (via AgentKnowledgeAssignment model)
 * 3. Search scoped to assigned documents/tags (or all if global access)
 * 4. Results returned with citation requirements
 * 5. Agent uses retrieve_full_document for detailed content
 *
 * Citation Requirements:
 * - formatKnowledgeSources() enforces citation formatting
 * - Agents must reference source titles when using knowledge
 * - Format: "According to [Source Title], [information]..."
 * - Prevents hallucination and maintains transparency
 *
 * Assignment Management:
 * - assignDocumentsToAgent(): Link specific documents
 * - assignTagsToAgent(): Link tag-based access
 * - assignAllKnowledgeToAgent(): Grant global access
 * - removeAgentKnowledgeAssignments(): Revoke access
 * - getAgentKnowledgeSummary(): Audit accessible knowledge
 *
 * Use Cases:
 * - Customer support agents: Access to product documentation
 * - Research agents: Domain-specific knowledge bases
 * - Specialized assistants: Curated content collections
 * - Multi-agent workflows: Shared knowledge pools
 *
 * @see \App\Models\AgentKnowledgeAssignment
 * @see \App\Services\Knowledge\RAG\KnowledgeRAG
 * @see \App\Tools\KnowledgeRAGTool
 * @see \App\Services\Agents\AgentService
 */
class AgentKnowledgeService
{
    /**
     * Format knowledge sources for display in prompts.
     *
     * Generates markdown-formatted source list with citation requirements
     * for agent system prompts. Enforces proper attribution and provides
     * clear instructions for retrieve_full_document tool usage.
     *
     * @param  array<array{id: int, title: string, tags: array<string>}>  $sources  Array of knowledge source metadata
     * @return string Markdown-formatted source list with citation instructions
     */
    public static function formatKnowledgeSources(array $sources): string
    {
        if (empty($sources)) {
            return '';
        }

        $formattedSources = "\n\n## AVAILABLE KNOWLEDGE SOURCES\n\n";
        $formattedSources .= "The above knowledge comes from these sources:\n\n";

        foreach ($sources as $index => $source) {
            $num = $index + 1;
            $title = $source['title'] ?? 'Untitled Document';
            $documentId = $source['id'] ?? 'unknown';
            $tags = isset($source['tags']) && ! empty($source['tags']) ? ' | '.implode(', ', $source['tags']) : '';

            $formattedSources .= "{$num}. **{$title}** (ID: {$documentId}{$tags})\n";
        }

        $formattedSources .= "\n**To retrieve full document content**: Use `retrieve_full_document` tool with the Document ID\n";
        $formattedSources .= "\n**CRITICAL CITATION REQUIREMENTS**:\n";
        $formattedSources .= "1. When referencing information from the knowledge context, you MUST cite the source\n";
        $formattedSources .= "2. Use this format: \"According to [Source Title], [specific information]...\"\n";
        $formattedSources .= "3. Example: \"According to Emilia Clark's Profile, she has experience in project coordination...\"\n";
        $formattedSources .= "4. Always reference the specific source document when making claims from the knowledge context\n";
        $formattedSources .= "5. Do NOT create fake or generic citations - only use the specific source titles listed above\n";

        return $formattedSources;
    }

    /**
     * Assign knowledge documents to an agent
     */
    public function assignDocumentsToAgent(Agent $agent, array $documentIds, array $options = []): Collection
    {
        $assignments = collect();

        foreach ($documentIds as $documentId) {
            $document = KnowledgeDocument::find($documentId);
            if (! $document) {
                Log::warning('Attempted to assign non-existent document to agent', [
                    'agent_id' => $agent->id,
                    'document_id' => $documentId,
                ]);

                continue;
            }

            $assignment = AgentKnowledgeAssignment::assignDocumentToAgent(
                $agent->id,
                $documentId,
                $options
            );

            $assignments->push($assignment);
        }

        Log::info('Documents assigned to agent', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'documents_assigned' => $assignments->count(),
            'document_ids' => $documentIds,
        ]);

        return $assignments;
    }

    /**
     * Assign knowledge tags to an agent
     */
    public function assignTagsToAgent(Agent $agent, array $tagIds, array $options = []): Collection
    {
        $assignments = collect();

        foreach ($tagIds as $tagId) {
            $tag = KnowledgeTag::find($tagId);
            if (! $tag) {
                Log::warning('Attempted to assign non-existent tag to agent', [
                    'agent_id' => $agent->id,
                    'tag_id' => $tagId,
                ]);

                continue;
            }

            $assignment = AgentKnowledgeAssignment::assignTagToAgent(
                $agent->id,
                $tagId,
                $options
            );

            $assignments->push($assignment);
        }

        Log::info('Tags assigned to agent', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'tags_assigned' => $assignments->count(),
            'tag_ids' => $tagIds,
        ]);

        return $assignments;
    }

    /**
     * Assign all knowledge to an agent (gives access to entire knowledge base)
     */
    public function assignAllKnowledgeToAgent(Agent $agent, array $options = []): AgentKnowledgeAssignment
    {
        $assignment = AgentKnowledgeAssignment::assignAllKnowledgeToAgent($agent->id, $options);

        Log::info('All knowledge assigned to agent', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'assignment_id' => $assignment->id,
        ]);

        return $assignment;
    }

    /**
     * Get knowledge assignments for an agent
     */
    public function getAgentKnowledgeAssignments(Agent $agent): Collection
    {
        return AgentKnowledgeAssignment::forAgent($agent->id)
            ->orderedByPriority()
            ->with(['document', 'tag'])
            ->get();
    }

    /**
     * Remove knowledge assignments from an agent
     */
    public function removeAgentKnowledgeAssignments(Agent $agent, array $assignmentIds = []): int
    {
        $query = AgentKnowledgeAssignment::forAgent($agent->id);

        if (! empty($assignmentIds)) {
            $query->whereIn('id', $assignmentIds);
        }

        $deletedCount = $query->delete();

        Log::info('Knowledge assignments removed from agent', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'assignments_removed' => $deletedCount,
            'specific_assignments' => ! empty($assignmentIds) ? $assignmentIds : 'all',
        ]);

        return $deletedCount;
    }

    /**
     * Get knowledge summary for an agent.
     *
     * Calculates comprehensive knowledge access statistics including
     * assignment counts, accessible document totals, and assignment details.
     *
     * @param  Agent  $agent  Agent to summarize
     * @return array{total_assignments: int, document_assignments: int, tag_assignments: int, has_all_knowledge: bool, accessible_documents: int, assignments: \Illuminate\Support\Collection} Knowledge access summary
     */
    public function getAgentKnowledgeSummary(Agent $agent): array
    {
        $assignments = $this->getAgentKnowledgeAssignments($agent);

        $documentCount = $assignments->where('assignment_type', 'document')->count();
        $tagCount = $assignments->where('assignment_type', 'tag')->count();
        $hasAllKnowledge = $assignments->where('assignment_type', 'all')->isNotEmpty();

        // Calculate total accessible documents
        $accessibleDocuments = 0;
        if ($hasAllKnowledge) {
            $accessibleDocuments = KnowledgeDocument::completed()->count();
        } else {
            // Count documents from direct assignments
            $accessibleDocuments += $documentCount;

            // Count documents from tag assignments
            $tagIds = $assignments->where('assignment_type', 'tag')
                ->pluck('knowledge_tag_id')
                ->filter();

            if ($tagIds->isNotEmpty()) {
                $tagDocuments = KnowledgeDocument::whereHas('tags', function ($query) use ($tagIds) {
                    $query->whereIn('knowledge_tag_id', $tagIds);
                })->count();

                $accessibleDocuments += $tagDocuments;
            }
        }

        return [
            'total_assignments' => $assignments->count(),
            'document_assignments' => $documentCount,
            'tag_assignments' => $tagCount,
            'has_all_knowledge' => $hasAllKnowledge,
            'accessible_documents' => $accessibleDocuments,
            'assignments' => $assignments->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'type' => $assignment->assignment_type,
                    'resource_name' => $assignment->assigned_resource_name,
                    'priority' => $assignment->priority,
                    'include_expired' => $assignment->include_expired,
                    'created_at' => $assignment->created_at,
                ];
            }),
        ];
    }

    /**
     * Test knowledge access for an agent with a sample query.
     *
     * Executes test search to verify knowledge access configuration and
     * return sample results. Useful for validating agent setup.
     *
     * @param  Agent  $agent  Agent to test
     * @param  string  $testQuery  Test search query
     * @return array{success: bool, accessible_documents?: int, sample_results?: \Illuminate\Support\Collection, search_metadata?: array, error?: string} Test results with success status
     */
    public function testAgentKnowledgeAccess(Agent $agent, string $testQuery = 'general information'): array
    {
        try {
            $searchResult = $this->knowledgeService->search([
                'query' => $testQuery,
                'search_type' => 'hybrid',
                'limit' => 3,
                'agent_id' => $agent->id,
                'include_content' => false,
                'relevance_threshold' => 0.1,
            ]);

            return [
                'success' => true,
                'accessible_documents' => $searchResult['total_results'],
                'sample_results' => collect($searchResult['documents'])->map(function ($doc) {
                    return [
                        'id' => $doc['id'],
                        'title' => $doc['title'],
                        'type' => $doc['type'],
                        'score' => $doc['score'],
                    ];
                }),
                'search_metadata' => $searchResult['search_metadata'],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'accessible_documents' => 0,
            ];
        }
    }

    /**
     * Create an agent with pre-configured knowledge access
     */
    public function createKnowledgeEnabledAgent(array $agentData, array $knowledgeConfig = []): Agent
    {
        $agentService = app(AgentService::class);

        // Create the agent first
        $agent = $agentService->createAgent($agentData);

        if (isset($knowledgeConfig['all_knowledge']) && $knowledgeConfig['all_knowledge']) {
            $this->assignAllKnowledgeToAgent($agent, $knowledgeConfig['options'] ?? []);
        }

        if (! empty($knowledgeConfig['document_ids'])) {
            $this->assignDocumentsToAgent($agent, $knowledgeConfig['document_ids'], $knowledgeConfig['options'] ?? []);
        }

        if (! empty($knowledgeConfig['tag_ids'])) {
            $this->assignTagsToAgent($agent, $knowledgeConfig['tag_ids'], $knowledgeConfig['options'] ?? []);
        }

        Log::info('Knowledge-enabled agent created', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'knowledge_config' => $knowledgeConfig,
        ]);

        return $agent;
    }
}
