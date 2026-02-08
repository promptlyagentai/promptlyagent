<?php

namespace App\Enums;

/**
 * Standard API Error Codes
 *
 * Provides consistent error codes across all API endpoints with automatic HTTP status mapping.
 * All API controllers should use these codes for error responses.
 *
 * Categories:
 * - Authentication & Authorization
 * - Validation & Input Errors
 * - Resource Errors
 * - Business Logic Errors
 * - Security Errors
 * - Rate Limiting & Quotas
 * - Server & Infrastructure Errors
 *
 * Usage:
 * ```php
 * return $this->error(
 *     ApiErrorCode::INSUFFICIENT_SCOPE,
 *     'Your API token does not have the trigger:invoke ability'
 * );
 * ```
 */
enum ApiErrorCode: string
{
    /*
    |--------------------------------------------------------------------------
    | Authentication & Authorization
    |--------------------------------------------------------------------------
    */

    /** Missing or invalid authentication credentials */
    case UNAUTHENTICATED = 'UNAUTHENTICATED';

    /** Authenticated but lacks permission for this resource */
    case UNAUTHORIZED = 'UNAUTHORIZED';

    /** API token is invalid or expired */
    case INVALID_TOKEN = 'INVALID_TOKEN';

    /** API token lacks required scope/ability */
    case INSUFFICIENT_SCOPE = 'INSUFFICIENT_SCOPE';

    /*
    |--------------------------------------------------------------------------
    | Validation & Input Errors
    |--------------------------------------------------------------------------
    */

    /** General validation failure */
    case VALIDATION_ERROR = 'VALIDATION_ERROR';

    /** Invalid input format or type */
    case INVALID_INPUT = 'INVALID_INPUT';

    /** Required field missing from request */
    case REQUIRED_FIELD_MISSING = 'REQUIRED_FIELD_MISSING';

    /*
    |--------------------------------------------------------------------------
    | Resource Errors
    |--------------------------------------------------------------------------
    */

    /** Requested resource not found */
    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    /** Resource already exists (duplicate) */
    case RESOURCE_CONFLICT = 'RESOURCE_CONFLICT';

    /** Resource has expired (TTL exceeded) */
    case RESOURCE_EXPIRED = 'RESOURCE_EXPIRED';

    /*
    |--------------------------------------------------------------------------
    | Business Logic Errors
    |--------------------------------------------------------------------------
    */

    /** Input trigger is disabled */
    case TRIGGER_DISABLED = 'TRIGGER_DISABLED';

    /** Agent is inactive */
    case AGENT_INACTIVE = 'AGENT_INACTIVE';

    /** Document cannot be refreshed (wrong type or no URL) */
    case DOCUMENT_NOT_REFRESHABLE = 'DOCUMENT_NOT_REFRESHABLE';

    /** Agent execution failed */
    case EXECUTION_FAILED = 'EXECUTION_FAILED';

    /** Provider not supported */
    case PROVIDER_NOT_SUPPORTED = 'PROVIDER_NOT_SUPPORTED';

    /** Not yet implemented */
    case NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';

    /*
    |--------------------------------------------------------------------------
    | Security Errors
    |--------------------------------------------------------------------------
    */

    /** IP address not in whitelist */
    case IP_NOT_WHITELISTED = 'IP_NOT_WHITELISTED';

    /** Webhook signature validation failed */
    case SIGNATURE_INVALID = 'SIGNATURE_INVALID';

    /** Nonce replay attack detected */
    case NONCE_REPLAY = 'NONCE_REPLAY';

    /** File validation failed (malware, size, type) */
    case FILE_VALIDATION_FAILED = 'FILE_VALIDATION_FAILED';

    /** SSRF protection blocked request */
    case SSRF_BLOCKED = 'SSRF_BLOCKED';

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting & Quotas
    |--------------------------------------------------------------------------
    */

    /** Rate limit exceeded */
    case RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    /** Usage quota exceeded */
    case QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';

    /*
    |--------------------------------------------------------------------------
    | Server & Infrastructure Errors
    |--------------------------------------------------------------------------
    */

    /** Internal server error */
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    /** Service temporarily unavailable */
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /** Request timeout */
    case TIMEOUT = 'TIMEOUT';

    /** Stream timeout */
    case STREAM_TIMEOUT = 'STREAM_TIMEOUT';

    /** Stream error during processing */
    case STREAM_ERROR = 'STREAM_ERROR';

    /**
     * Get HTTP status code for this error
     *
     * Maps error codes to appropriate HTTP status codes following REST conventions.
     *
     * @return int HTTP status code (400-599)
     */
    public function httpStatus(): int
    {
        return match ($this) {
            // 401 Unauthorized - Authentication required
            self::UNAUTHENTICATED, self::INVALID_TOKEN => 401,

            // 403 Forbidden - Authenticated but not authorized
            self::UNAUTHORIZED,
            self::INSUFFICIENT_SCOPE,
            self::IP_NOT_WHITELISTED,
            self::SIGNATURE_INVALID,
            self::SSRF_BLOCKED => 403,

            // 404 Not Found
            self::RESOURCE_NOT_FOUND => 404,

            // 409 Conflict - Resource already exists
            self::RESOURCE_CONFLICT => 409,

            // 410 Gone - Resource expired
            self::RESOURCE_EXPIRED => 410,

            // 422 Unprocessable Entity - Validation errors
            self::VALIDATION_ERROR,
            self::INVALID_INPUT,
            self::REQUIRED_FIELD_MISSING,
            self::FILE_VALIDATION_FAILED,
            self::DOCUMENT_NOT_REFRESHABLE,
            self::TRIGGER_DISABLED,
            self::AGENT_INACTIVE,
            self::PROVIDER_NOT_SUPPORTED => 422,

            // 429 Too Many Requests - Rate limiting
            self::RATE_LIMIT_EXCEEDED,
            self::QUOTA_EXCEEDED => 429,

            // 501 Not Implemented
            self::NOT_IMPLEMENTED => 501,

            // 503 Service Unavailable
            self::SERVICE_UNAVAILABLE => 503,

            // 504 Gateway Timeout
            self::TIMEOUT,
            self::STREAM_TIMEOUT => 504,

            // 500 Internal Server Error - Default for unhandled cases
            default => 500,
        };
    }

    /**
     * Get documentation URL for this error code
     *
     * @return string|null Documentation URL
     */
    public function documentationUrl(): ?string
    {
        // Future: Link to error code documentation
        // return "https://docs.promptlyagent.com/errors/" . strtolower($this->value);
        return null;
    }

    /**
     * Get user-friendly error message
     *
     * Provides default error messages when custom message not provided.
     *
     * @return string Default error message
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::UNAUTHENTICATED => 'Authentication required',
            self::UNAUTHORIZED => 'You do not have permission to access this resource',
            self::INVALID_TOKEN => 'Invalid or expired API token',
            self::INSUFFICIENT_SCOPE => 'API token lacks required permissions',

            self::VALIDATION_ERROR => 'Validation failed',
            self::INVALID_INPUT => 'Invalid input provided',
            self::REQUIRED_FIELD_MISSING => 'Required field missing',

            self::RESOURCE_NOT_FOUND => 'Resource not found',
            self::RESOURCE_CONFLICT => 'Resource already exists',
            self::RESOURCE_EXPIRED => 'Resource has expired',

            self::TRIGGER_DISABLED => 'Trigger is currently disabled',
            self::AGENT_INACTIVE => 'Agent is not active',
            self::DOCUMENT_NOT_REFRESHABLE => 'Document cannot be refreshed',
            self::EXECUTION_FAILED => 'Execution failed',
            self::PROVIDER_NOT_SUPPORTED => 'Provider not supported',
            self::NOT_IMPLEMENTED => 'Feature not yet implemented',

            self::IP_NOT_WHITELISTED => 'IP address not authorized',
            self::SIGNATURE_INVALID => 'Invalid signature',
            self::NONCE_REPLAY => 'Replay attack detected',
            self::FILE_VALIDATION_FAILED => 'File validation failed',
            self::SSRF_BLOCKED => 'Access to this URL is not allowed for security reasons',

            self::RATE_LIMIT_EXCEEDED => 'Rate limit exceeded',
            self::QUOTA_EXCEEDED => 'Usage quota exceeded',

            self::INTERNAL_ERROR => 'An unexpected error occurred',
            self::SERVICE_UNAVAILABLE => 'Service temporarily unavailable',
            self::TIMEOUT => 'Request timeout',
            self::STREAM_TIMEOUT => 'Stream exceeded maximum duration',
            self::STREAM_ERROR => 'An error occurred during streaming',
        };
    }
}
