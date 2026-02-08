<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Api\V1\Knowledge\Traits\ApiResponseTrait;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @group Knowledge Management
 *
 * Manage tags for knowledge documents. Tags enable organization, categorization,
 * and bulk assignment of documents to agents.
 *
 * ## Features
 * - List all available tags
 * - Create new tags
 * - Add tags to documents
 *
 * ## Rate Limiting
 * - Tag operations: 60 requests/minute
 */
class KnowledgeTagController extends Controller
{
    use ApiResponseTrait;

    /**
     * List all knowledge tags
     *
     * Retrieve all knowledge tags ordered alphabetically by name.
     *
     * @authenticated
     *
     * @response 200 scenario="Success" {"success": true, "data": [{"id": 1, "name": "laravel", "description": "Laravel framework documentation", "color": "blue", "created_at": "2024-01-01T00:00:00Z"}]}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to retrieve tags"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data array Array of tags ordered alphabetically
     * @responseField data[].id integer Tag ID
     * @responseField data[].name string Tag name
     * @responseField data[].description string Tag description
     * @responseField data[].color string Tag color (for UI display)
     * @responseField data[].created_at string Creation timestamp (ISO 8601)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:view')) {
                return $this->unauthorizedResponse();
            }

            $tags = KnowledgeTag::orderBy('name')->get();

            return $this->successResponse($tags);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to get tags', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to retrieve tags');
        }
    }

    /**
     * Create a new knowledge tag
     *
     * Create a new tag for organizing knowledge documents. If a tag with the same name
     * already exists, returns the existing tag.
     *
     * @authenticated
     *
     * @bodyParam name string required Tag name. Must be unique. Example: laravel
     * @bodyParam description string Optional tag description. Example: Laravel framework documentation and guides
     * @bodyParam color string Optional color for UI display. Defaults to zinc. Example: blue
     *
     * @response 201 scenario="Success" {"success": true, "data": {"id": 1, "name": "laravel", "description": "Laravel framework documentation and guides", "color": "blue", "created_at": "2024-01-01T00:00:00Z"}, "message": "Tag created successfully"}
     * @response 403 scenario="Missing Ability" {"success": false, "error": "Unauthorized"}
     * @response 422 scenario="Validation Failed" {"success": false, "error": "Validation failed", "errors": {"name": ["The name field is required."]}}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to create tag"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object The created or existing tag
     * @responseField data.id integer Tag ID
     * @responseField data.name string Tag name
     * @responseField data.description string Tag description
     * @responseField data.color string Tag color
     * @responseField data.created_at string Creation timestamp (ISO 8601)
     * @responseField message string Success message
     */
    public function store(Request $request): JsonResponse
    {
        try {
            if (! $request->user()->tokenCan('knowledge:tags:manage')) {
                return $this->unauthorizedResponse();
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255|unique:knowledge_tags,name',
                'description' => 'nullable|string|max:500',
                'color' => 'nullable|string|max:20',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $tag = KnowledgeTag::findOrCreateByName(
                $request->input('name'),
                Auth::id(),
                [
                    'description' => $request->input('description'),
                    'color' => $request->input('color', 'zinc'),
                ]
            );

            return $this->successResponse($tag, ['message' => 'Tag created successfully'], 201);

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to create tag', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to create tag');
        }
    }

    /**
     * Add tags to a document
     *
     * Add one or more tags to a knowledge document. Existing tags are preserved (does not replace).
     *
     * @authenticated
     *
     * @urlParam document integer required The document ID. Example: 1
     *
     * @bodyParam tag_ids integer[] required Array of tag IDs to add. Example: [1, 2, 3]
     *
     * @response 200 scenario="Success" {"success": true, "data": {"id": 1, "title": "Laravel Guide", "tags": [{"id": 1, "name": "laravel"}, {"id": 2, "name": "php"}]}, "message": "Tags added to document successfully"}
     * @response 403 scenario="No Permission" {"success": false, "error": "You do not have permission to modify this document"}
     * @response 422 scenario="Validation Failed" {"success": false, "error": "Validation failed", "errors": {"tag_ids": ["The tag ids field is required."]}}
     * @response 500 scenario="Failed" {"success": false, "error": "Failed to add tags"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField data object The updated document with tags
     * @responseField data.id integer Document ID
     * @responseField data.title string Document title
     * @responseField data.tags array All tags now attached to the document
     * @responseField message string Success message
     */
    public function addToDocument(Request $request, KnowledgeDocument $document): JsonResponse
    {
        try {
            if (! $request->user()->can('update', $document)) {
                return $this->unauthorizedResponse('You do not have permission to modify this document');
            }

            $validator = Validator::make($request->all(), [
                'tag_ids' => 'required|array',
                'tag_ids.*' => 'integer|exists:knowledge_tags,id',
            ]);

            if ($validator->fails()) {
                return $this->validationErrorResponse($validator->errors()->toArray());
            }

            $document->tags()->syncWithoutDetaching($request->input('tag_ids'));

            return $this->successResponse(
                $document->load('tags'),
                ['message' => 'Tags added to document successfully']
            );

        } catch (\Exception $e) {
            Log::error('Knowledge API: Failed to add tags to document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return $this->serverErrorResponse('Failed to add tags');
        }
    }
}
