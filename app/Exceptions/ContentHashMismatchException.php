<?php

namespace App\Exceptions;

use Exception;

/**
 * Content Hash Mismatch Exception - Optimistic Concurrency Control.
 *
 * Thrown when an artifact update operation detects that the content was
 * modified by another process between read and write operations. Implements
 * optimistic locking using SHA-256 content hashes for conflict detection.
 *
 * When Thrown:
 * - Artifact content modification detected during update operations
 * - Hash-based validation fails in ArtifactService
 * - Concurrent edits from multiple users/agents
 * - Race conditions in streaming updates
 *
 * Hash-Based Concurrency Control:
 * 1. Client reads artifact with content_hash
 * 2. Client modifies content locally
 * 3. Client sends update with original content_hash
 * 4. Server validates current hash matches provided hash
 * 5. Mismatch → throw exception (409 Conflict)
 * 6. Match → proceed with update, generate new hash
 *
 * HTTP Response:
 * - Status Code: 409 Conflict
 * - JSON Response: `{"error": "Content hash mismatch", "message": "..."}`
 * - HTML Response: Custom 409 error view
 *
 * Client Handling:
 * - Display user-friendly conflict message
 * - Prompt user to refresh and retry
 * - Optionally implement automatic merge/retry strategies
 * - Preserve user's changes for manual reconciliation
 *
 * Related Tools:
 * - append_artifact_content: Validates hash before appending
 * - insert_artifact_content: Validates hash before inserting
 * - patch_artifact_content: Validates hash before patching
 * - update_artifact_content: Validates hash before full replacement
 *
 * @see \App\Services\ArtifactService
 * @see \App\Tools\AppendArtifactContentTool
 * @see \App\Tools\PatchArtifactContentTool
 * @see \App\Models\Artifact
 */
class ContentHashMismatchException extends Exception
{
    /**
     * Create a new content hash mismatch exception.
     *
     * Default message guides users to refresh and retry. HTTP 409 Conflict
     * status code indicates optimistic locking failure.
     *
     * @param  string  $message  User-friendly error message
     * @param  int  $code  HTTP status code (409 Conflict)
     * @param  \Throwable|null  $previous  Previous exception in chain
     */
    public function __construct(
        string $message = 'Artifact content was modified by another process. Please refresh and try again.',
        int $code = 409,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Report the exception to logging system.
     *
     * Logs as warning (not error) since concurrency conflicts are expected
     * in collaborative environments. Includes message and trace for debugging
     * without alarming monitoring systems.
     */
    public function report(): void
    {
        \Log::warning('Content hash mismatch detected - concurrent modification', [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'trace' => $this->getTraceAsString(),
        ]);
    }

    /**
     * Render the exception as an HTTP response.
     *
     * Returns appropriate response based on request type:
     * - JSON requests: Structured error object with 409 status
     * - HTML requests: Custom 409 error view
     *
     * @param  \Illuminate\Http\Request  $request  Incoming HTTP request
     * @return \Illuminate\Http\Response JSON or HTML response with 409 status
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Content hash mismatch',
                'message' => $this->getMessage(),
            ], 409);
        }

        return response()->view('errors.409', [
            'exception' => $this,
        ], 409);
    }
}
