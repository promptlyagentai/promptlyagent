<?php

namespace App\Http\Controllers\Api\V1\Knowledge\Traits;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;

/**
 * Knowledge API Response Trait (Backwards Compatibility Layer)
 *
 * This trait provides backwards-compatible method signatures while using
 * the standardized ApiResponseTrait internally. Knowledge controllers can
 * continue using their existing method calls.
 *
 * New controllers should use the standardized trait directly:
 * use App\Http\Controllers\Api\Traits\ApiResponseTrait;
 */
trait ApiResponseTrait
{
    use \App\Http\Controllers\Api\Traits\ApiResponseTrait;

    /**
     * Return a successful API response (backwards compatible)
     *
     * @param  mixed  $data  Response data
     * @param  array  $meta  Additional metadata
     * @param  int  $status  HTTP status code
     * @return JsonResponse Success response
     */
    protected function successResponse(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        return $this->success($data, $meta, $status);
    }

    /**
     * Return a paginated API response (backwards compatible)
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $paginator  Laravel paginator
     * @param  array  $meta  Additional metadata
     * @return JsonResponse Paginated response
     */
    protected function paginatedResponse($paginator, array $meta = []): JsonResponse
    {
        return $this->paginated($paginator, $meta);
    }

    /**
     * Return an error API response (backwards compatible)
     *
     * Converts string error codes to ApiErrorCode enum for standardization.
     *
     * @param  string  $code  Error code string
     * @param  string  $message  Error message
     * @param  int  $status  HTTP status code
     * @param  array  $details  Additional error details
     * @return JsonResponse Error response
     */
    protected function errorResponse(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        // Map string codes to enum (best effort)
        $errorCode = $this->mapStringCodeToEnum($code);

        return $this->error($errorCode, $message, $details, $status);
    }

    /**
     * Return an async job queued response (backwards compatible)
     *
     * @param  string  $jobId  Job identifier
     * @param  string  $statusUrl  URL to check job status
     * @return JsonResponse Async response (202 Accepted)
     */
    protected function asyncResponse(string $jobId, string $statusUrl): JsonResponse
    {
        return $this->async($jobId, $statusUrl);
    }

    /**
     * Return a validation error response (backwards compatible)
     *
     * @param  array  $errors  Validation errors
     * @return JsonResponse Validation error response (422)
     */
    protected function validationErrorResponse(array $errors): JsonResponse
    {
        return $this->validationError($errors, 'The given data was invalid.');
    }

    /**
     * Return an unauthorized response (backwards compatible)
     *
     * @param  string  $message  Error message
     * @return JsonResponse Unauthorized response (403)
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error(ApiErrorCode::UNAUTHORIZED, $message);
    }

    /**
     * Return a not found response (backwards compatible)
     *
     * @param  string  $message  Error message
     * @return JsonResponse Not found response (404)
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error(ApiErrorCode::RESOURCE_NOT_FOUND, $message);
    }

    /**
     * Return a server error response (backwards compatible)
     *
     * @param  string  $message  Error message
     * @return JsonResponse Server error response (500)
     */
    protected function serverErrorResponse(string $message = 'An unexpected error occurred'): JsonResponse
    {
        return $this->error(ApiErrorCode::INTERNAL_ERROR, $message);
    }

    /**
     * Map legacy string error codes to ApiErrorCode enum
     *
     * Provides best-effort mapping of string codes used in Knowledge API
     * to standardized enum values.
     *
     * @param  string  $code  String error code
     * @return ApiErrorCode Mapped enum value
     */
    private function mapStringCodeToEnum(string $code): ApiErrorCode
    {
        return match (strtoupper($code)) {
            'VALIDATION_ERROR', 'VALIDATION_FAILED' => ApiErrorCode::VALIDATION_ERROR,
            'UNAUTHORIZED' => ApiErrorCode::UNAUTHORIZED,
            'NOT_FOUND' => ApiErrorCode::RESOURCE_NOT_FOUND,
            'SERVER_ERROR', 'INTERNAL_ERROR' => ApiErrorCode::INTERNAL_ERROR,
            'INVALID_DOCUMENT' => ApiErrorCode::DOCUMENT_NOT_REFRESHABLE,
            'FILE_VALIDATION_FAILED' => ApiErrorCode::FILE_VALIDATION_FAILED,
            'SSRF_BLOCKED' => ApiErrorCode::SSRF_BLOCKED,
            'INVALID_INPUT' => ApiErrorCode::INVALID_INPUT,
            'CREATION_FAILED', 'EXECUTION_FAILED' => ApiErrorCode::EXECUTION_FAILED,
            default => ApiErrorCode::INTERNAL_ERROR,
        };
    }
}
