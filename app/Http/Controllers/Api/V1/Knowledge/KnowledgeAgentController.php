<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @group Knowledge Management
 *
 * Manage agent assignments for knowledge documents. Assign documents to agents to make them
 * available as RAG (Retrieval-Augmented Generation) context during agent executions.
 *
 * ## Features
 * - Assign individual documents to agents
 * - Unassign documents from agents
 * - Retrieve all documents available to an agent (includes tag-based assignments)
 *
 * ## Rate Limiting
 * - Assignment operations: 60 requests/minute
 */
class KnowledgeAgentController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected KnowledgeManager $knowledgeManager
    ) {}

    /**
     * Assign document to agent
     *
     * Make a knowledge document available to an agent for RAG context. The agent will be able
     * to query this document's content during executions.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @bodyParam agent_id integer required The agent ID to assign the document to. Example: 5
     * @bodyParam config object Optional agent-specific configuration for this document. Example: {"priority": "high"}
     *
     * @response 200 scenario="Success" {"success": true, "data": null, "message": "Document assigned to agent successfully"}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 403 scenario="No Permission" {"success": false, "error": "You do not have permission to modify this document"}
     * @response 422 scenario="Validation Failed" {"success": false, "error": "Validation failed", "errors": {"agent_id": ["The agent id field is required."]}}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to assign document to agent"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data null Always null for assignment operations
     * @responseField message string Success message
     */
    public function assign(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:agents:manage')) {
                return $this->unauthorizedResponse();
            }

            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to modify this document');
            }

            $validator = Validator::make($request->all(), [
                'agent_id' => 'required|integer',
                'config' => 'array',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $success = $this->knowledgeManager->assignToAgent(
                $request->input('agent_id'),
                $document->id,
                $request->input('config', [])
            );

            if ($success) {
                return $this->successResponse(null, ['message' => 'Document assigned to agent successfully']);
            }

            return $this->serverErrorResponse('Failed to assign document to agent');

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to assign document to agent', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to assign document to agent');
        }
    }

    /**
     * Unassign document from agent
     *
     * Remove a knowledge document from an agent's RAG context. The agent will no longer
     * be able to query this document's content.
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     * @urlParam agent integer required The agent ID. Example: 5
     *
     * @response 200 scenario="Success" {"success": true, "data": null, "message": "Document unassigned from agent successfully"}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 403 scenario="No Permission" {"success": false, "error": "You do not have permission to modify this document"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to unassign document from agent"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data null Always null for unassignment operations
     * @responseField message string Success message
     */
    public function unassign(Request $request, KnowledgeDocument $document, int $agent): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:agents:manage')) {
                return $this->unauthorizedResponse();
            }

            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to modify this document');
            }

            $success = $this->knowledgeManager->removeFromAgent($agent, $document->id);

            if ($success) {
                return $this->successResponse(null, ['message' => 'Document unassigned from agent successfully']);
            }

            return $this->serverErrorResponse('Failed to unassign document from agent');

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to unassign document from agent', [
                'document_id' => $document->id,
                'agent_id' => $agent,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to unassign document from agent');
        }
    }

    /**
     * Get agent's available documents
     *
     * Retrieve all knowledge documents available to an agent. Includes both directly assigned
     * documents and documents tagged with tags assigned to the agent.
     *
     * @authenticated
     *
     * @urlParam agent integer required The agent ID. Example: 5
     *
     * @response 200 scenario="Success" {"success": true, "data": {"agent_id": 5, "documents": [{"id": 1, "title": "Laravel Guide", "tags": [{"id": 1, "name": "laravel"}]}], "assigned_tags": [{"id": 1, "name": "laravel"}], "total_documents": 1}}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to retrieve agent knowledge"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Agent knowledge data
     * @responseField data.agent_id integer The agent ID
     * @responseField data.documents array All documents available to the agent (direct assignments + tag-based)
     * @responseField data.assigned_tags array Tags assigned to the agent (documents with these tags are included)
     * @responseField data.total_documents integer Total number of unique documents available
     */
    public function getAgentDocuments(Request $request, int $agent): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:view')) {
                return $this->unauthorizedResponse();
            }

            $documents = KnowledgeDocument::whereHas('agentAssignments', function ($query) use ($agent) {
                $query->where('agent_id', $agent);
            })->with(['creator', 'tags'])->get();

            $assignedTags = KnowledgeTag::whereHas('agentAssignments', function ($query) use ($agent) {
                $query->where('agent_id', $agent);
            })->get();

            $taggedDocuments = collect();
            if ($assignedTags->isNotEmpty()) {
                $taggedDocuments = KnowledgeDocument::whereHas('tags', function ($query) use ($assignedTags) {
                    $query->whereIn('knowledge_tags.id', $assignedTags->pluck('id'));
                })->with(['creator', 'tags'])->get();
            }

            $allDocuments = $documents->merge($taggedDocuments)->unique('id');

            return $this->successResponse([
                'agent_id' => $agent,
                'documents' => $allDocuments->values(),
                'assigned_tags' => $assignedTags,
                'total_documents' => $allDocuments->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to get agent knowledge', [
                'agent_id' => $agent,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve agent knowledge');
        }
    }
}
