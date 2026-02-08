<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Knowledge\BulkOperationRequest;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Knowledge Management
 *
 * Bulk operations for knowledge documents. Perform actions on multiple documents at once
 * for efficient document management.
 *
 * ## Features
 * - Bulk delete multiple documents
 * - Bulk assign tags to multiple documents
 *
 * ## Rate Limiting
 * - Bulk operations: 30 requests/minute
 *
 * ## Authorization
 * Each document in a bulk operation is individually authorized. Documents the user doesn't
 * have permission for will be skipped with an error message in the response.
 */
class KnowledgeBulkController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected KnowledgeManager $knowledgeManager
    ) {}

    /**
     * Bulk delete documents
     *
     * Delete multiple knowledge documents in a single operation. Each document is individually
     * authorized - documents you don't have permission to delete will be skipped.
     *
     * Returns a detailed summary including successful deletions and errors for failed operations.
     *
     * @authenticated
     *
     * @bodyParam document_ids integer[] required Array of document IDs to delete. Example: [1, 2, 3, 4, 5]
     *
     * @response 200 scenario="Success" {"success": true, "data": {"deleted_count": 3, "total_requested": 5, "errors": ["Document 2 not found", "Access denied for document 4"]}, "message": "Successfully deleted 3 documents"}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Operation Failed" {"success": false, "error": "Bulk delete failed"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Bulk operation results
     * @responseField data.deleted_count integer Number of documents successfully deleted
     * @responseField data.total_requested integer Total number of documents requested for deletion
     * @responseField data.errors array Array of error messages for documents that couldn't be deleted
     * @responseField message string Summary message
     */
    public function delete(BulkOperationRequest $request): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:delete')) {
                return $this->unauthorizedResponse();
            }

            $documentIds = $request->input('document_ids');
            $deletedCount = 0;
            $errors = [];

            foreach ($documentIds as $documentId) {
                $document = KnowledgeDocument::find($documentId);

                if (! $document) {
                    $errors[] = "Document {$documentId} not found";

                    continue;
                }

                if (! $request->user()->can('delete', $document)) {
                    $errors[] = "Access denied for document {$documentId}";

                    continue;
                }

                if ($this->knowledgeManager->deleteDocument($document)) {
                    $deletedCount++;
                } else {
                    $errors[] = "Failed to delete document {$documentId}";
                }
            }

            return $this->successResponse([
                'deleted_count' => $deletedCount,
                'total_requested' => count($documentIds),
                'errors' => $errors,
            ], ['message' => "Successfully deleted {$deletedCount} documents"]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Bulk delete failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Bulk delete failed');
        }
    }

    /**
     * Bulk assign tag to documents
     *
     * Assign a tag to multiple knowledge documents in a single operation. If the tag doesn't exist,
     * it will be created automatically. Each document is individually authorized - documents you don't
     * have permission to update will be skipped.
     *
     * @authenticated
     *
     * @bodyParam document_ids integer[] required Array of document IDs to tag. Example: [1, 2, 3, 4, 5]
     * @bodyParam tag_name string required Name of the tag to assign. Will be created if it doesn't exist. Example: important
     *
     * @response 200 scenario="Success" {"success": true, "data": {"assigned_count": 4, "total_requested": 5, "tag": {"id": 1, "name": "important", "color": "zinc"}, "errors": ["Access denied for document 3"]}, "message": "Successfully assigned tag 'important' to 4 documents"}
     * @response 500 scenario="Operation Failed" {"success": false, "error": "Bulk assign tag failed"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object Bulk operation results
     * @responseField data.assigned_count integer Number of documents successfully tagged
     * @responseField data.total_requested integer Total number of documents requested for tagging
     * @responseField data.tag object The tag that was assigned (created if new)
     * @responseField data.tag.id integer Tag ID
     * @responseField data.tag.name string Tag name
     * @responseField data.tag.color string Tag color
     * @responseField data.errors array Array of error messages for documents that couldn't be tagged
     * @responseField message string Summary message
     */
    public function assignTag(BulkOperationRequest $request): JsonResponse
    {
        try {
            $documentIds = $request->input('document_ids');
            $tagName = $request->input('tag_name');

            $tag = KnowledgeTag::findOrCreateByName($tagName, Auth::id());

            $assignedCount = 0;
            $errors = [];

            foreach ($documentIds as $documentId) {
                $document = KnowledgeDocument::find($documentId);

                if (! $document) {
                    $errors[] = "Document {$documentId} not found";

                    continue;
                }

                if (! $request->user()->can('update', $document)) {
                    $errors[] = "Access denied for document {$documentId}";

                    continue;
                }

                if (! $document->tags()->where('knowledge_tag_id', $tag->id)->exists()) {
                    $document->tags()->attach($tag->id);
                    $assignedCount++;
                }
            }

            return $this->successResponse([
                'assigned_count' => $assignedCount,
                'total_requested' => count($documentIds),
                'tag' => $tag,
                'errors' => $errors,
            ], ['message' => "Successfully assigned tag '{$tagName}' to {$assignedCount} documents"]);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Bulk assign tag failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Bulk assign tag failed');
        }
    }
}
