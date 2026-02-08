<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * API Responses Trait
 *
 * Provides standardized JSON response methods for API controllers.
 * Reduces code duplication and ensures consistent response format across all endpoints.
 */
trait ApiResponses
{
    /**
     * Return success response
     *
     * @param  mixed  $data  Response data (will be spread into response object)
     * @param  int  $status  HTTP status code
     */
    protected function success(mixed $data = [], int $status = 200): JsonResponse
    {
        $response = ['success' => true];

        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }

        return response()->json($response, $status);
    }

    /**
     * Return error response
     *
     * @param  string  $error  Error type/category
     * @param  string  $message  Human-readable error message
     * @param  int  $status  HTTP status code
     * @param  array|null  $details  Optional error details
     */
    protected function error(
        string $error,
        string $message,
        int $status = 400,
        ?array $details = null
    ): JsonResponse {
        $response = [
            'success' => false,
            'error' => $error,
            'message' => $message,
        ];

        if ($details !== null) {
            $response['details'] = $details;
        }

        return response()->json($response, $status);
    }

    /**
     * Return unauthorized (403) response
     *
     * @param  string  $message  Error message
     */
    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->error('Unauthorized', $message, 403);
    }

    /**
     * Return forbidden (403) response
     *
     * @param  string  $message  Error message
     */
    protected function forbidden(string $message = 'You do not have permission to perform this action'): JsonResponse
    {
        return $this->error('Forbidden', $message, 403);
    }

    /**
     * Return not found (404) response
     *
     * @param  string  $message  Error message
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error('Not Found', $message, 404);
    }

    /**
     * Return validation error (422) response
     *
     * @param  string  $message  Error message
     * @param  array  $errors  Validation errors
     */
    protected function validationError(string $message, array $errors = []): JsonResponse
    {
        return $this->error('Validation Error', $message, 422, $errors);
    }

    /**
     * Return server error (500) response
     *
     * @param  string  $message  Error message
     * @param  \Throwable|null  $exception  Optional exception for logging
     */
    protected function serverError(
        string $message = 'An error occurred while processing your request',
        ?\Throwable $exception = null
    ): JsonResponse {
        if ($exception) {
            \Illuminate\Support\Facades\Log::error('API Server Error', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
        }

        return $this->error('Server Error', $message, 500);
    }
}
