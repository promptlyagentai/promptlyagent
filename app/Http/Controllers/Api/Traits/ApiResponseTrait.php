<?php

namespace App\Http\Controllers\Api\Traits;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;

/**
 * Standardized API Response Helper Trait
 *
 * Provides consistent response formatting for all API endpoints.
 * All API controllers should use this trait for uniform responses.
 *
 * Usage:
 * ```php
 * class MyController extends Controller
 * {
 *     use ApiResponseTrait;
 *
 *     public function index()
 *     {
 *         return $this->success($data);
 *     }
 *
 *     public function store(Request $request)
 *     {
 *         if (! $request->user()->tokenCan('resource:create')) {
 *             return $this->error(ApiErrorCode::INSUFFICIENT_SCOPE);
 *         }
 *         // ...
 *     }
 * }
 * ```
 */
trait ApiResponseTrait
{
    /**
     * Return a successful API response
     *
     * @param  mixed  $data  Response data
     * @param  array  $meta  Additional metadata
     * @param  int  $status  HTTP status code
     * @return JsonResponse Success response
     */
    protected function success(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
                'version' => 'v1',
            ], $meta),
        ], $status);
    }

    /**
     * Return a paginated API response
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator  Laravel paginator
     * @param  array  $meta  Additional metadata
     * @return JsonResponse Paginated response
     */
    protected function paginated($paginator, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
                'version' => 'v1',
            ], $meta),
        ]);
    }

    /**
     * Return an error API response with standardized error code
     *
     * @param  ApiErrorCode  $code  Standardized error code
     * @param  string|null  $message  Custom error message (uses default if null)
     * @param  array  $details  Additional error details
     * @param  int|null  $status  HTTP status (uses code's default if null)
     * @return JsonResponse Error response
     */
    protected function error(
        ApiErrorCode $code,
        ?string $message = null,
        array $details = [],
        ?int $status = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->defaultMessage(),
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => 'v1',
            ],
        ];

        if (! empty($details)) {
            $response['error']['details'] = $details;
        }

        $documentationUrl = $code->documentationUrl();
        if ($documentationUrl) {
            $response['error']['documentation_url'] = $documentationUrl;
        }

        return response()->json($response, $status ?? $code->httpStatus());
    }

    /**
     * Return an async job queued response
     *
     * @param  string  $jobId  Job identifier
     * @param  string  $statusUrl  URL to check job status
     * @return JsonResponse Async response (202 Accepted)
     */
    protected function async(string $jobId, string $statusUrl): JsonResponse
    {
        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'queued',
            'status_url' => $statusUrl,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'version' => 'v1',
            ],
        ], 202); // 202 Accepted
    }

    /**
     * Return a validation error response
     *
     * @param  array  $errors  Validation errors from validator
     * @param  string|null  $message  Custom validation message
     * @return JsonResponse Validation error response (422)
     */
    protected function validationError(array $errors, ?string $message = null): JsonResponse
    {
        return $this->error(
            ApiErrorCode::VALIDATION_ERROR,
            $message ?? 'The given data was invalid',
            ['validation' => $errors]
        );
    }

    // Legacy compatibility methods - deprecated, use error() instead

    /**
     * @deprecated Use error(ApiErrorCode::UNAUTHORIZED) instead
     */
    protected function unauthorized(?string $message = null): JsonResponse
    {
        return $this->error(ApiErrorCode::UNAUTHORIZED, $message);
    }

    /**
     * @deprecated Use error(ApiErrorCode::RESOURCE_NOT_FOUND) instead
     */
    protected function notFound(?string $message = null): JsonResponse
    {
        return $this->error(ApiErrorCode::RESOURCE_NOT_FOUND, $message);
    }

    /**
     * @deprecated Use error(ApiErrorCode::INTERNAL_ERROR) instead
     */
    protected function serverError(?string $message = null): JsonResponse
    {
        return $this->error(ApiErrorCode::INTERNAL_ERROR, $message);
    }
}
