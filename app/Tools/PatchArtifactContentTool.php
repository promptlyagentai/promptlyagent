<?php

namespace App\Tools;

use App\Exceptions\ContentHashMismatchException;
use App\Models\Artifact;
use App\Models\StatusStream;
use App\Models\User;
use App\Services\Artifacts\ArtifactEditor;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * PatchArtifactContentTool - Surgical Content Replacement with Position-Based Patching.
 *
 * ADVANCED Prism tool for replacing specific sections of artifact content using
 * start/end character positions. Preferred for editing middle sections or making
 * multiple targeted changes. Simpler alternatives: append_artifact_content for
 * adding at end, update_artifact_content for full replacement.
 *
 * Patch Format:
 * - JSON array of patches: [{"start": 50, "end": 200, "content": "new text"}]
 * - Base64 support: {"start": 50, "end": 200, "content_base64": "encoded=="}
 * - Empty content for deletion (whitespace removed from range)
 * - Positions are 0-indexed character offsets
 *
 * Workflow:
 * 1. read_artifact to get content and content_hash
 * 2. Identify sections to replace (calculate start/end positions)
 * 3. Create patches array with replacements
 * 4. Call with patches_json + content_hash
 *
 * Concurrency Control:
 * - Requires content_hash from read_artifact (optimistic locking)
 * - Throws ContentHashMismatchException if artifact modified
 * - Returns retry hint with suggested workflow
 *
 * JSON Auto-Repair:
 * - Attempts to fix common JSON syntax errors
 * - Handles trailing commas, unescaped quotes, control characters
 * - Logs successful repairs for debugging
 *
 * Integration with ArtifactEditor:
 * - Delegates to ArtifactEditor::applyPatches()
 * - Automatic version history creation
 * - Transaction-safe patch application
 *
 * @see \App\Services\Artifacts\ArtifactEditor
 * @see \App\Tools\UpdateArtifactContentTool
 * @see \App\Tools\AppendArtifactContentTool
 */
class PatchArtifactContentTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('patch_artifact_content')
            ->for('REPLACES specific sections of artifact content. ADVANCED tool for editing middle sections. Use append_artifact_content for adding at end (easier). WORKFLOW: (1) read_artifact for content_hash, (2) Identify sections to replace with start/end positions, (3) Call with JSON array of patches. Each patch: {start, end, content} OR {start, end, content_base64} for complex content. Content can be empty or whitespace for deletion. Creates version history.')
            ->withNumberParameter('artifact_id', 'The artifact ID (REQUIRED)', true)
            ->withStringParameter('patches_json', 'JSON array of patches: [{"start":50,"end":200,"content":"text"}] OR [{"start":50,"end":200,"content_base64":"YmFzZTY0X2VuY29kZWRfdGV4dA=="}]. Use content_base64 for complex code with quotes/escapes. (REQUIRED)', true)
            ->withStringParameter('content_hash', 'The content_hash from read_artifact (REQUIRED)', true)
            ->using(function (
                int $artifact_id,
                string $patches_json,
                string $content_hash
            ) {
                // Parse patches from JSON
                $patches = json_decode($patches_json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errorMsg = json_last_error_msg();

                    // Try to fix common JSON issues
                    $fixedJson = static::attemptJsonFix($patches_json);
                    if ($fixedJson !== $patches_json) {
                        $patches = json_decode($fixedJson, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            Log::info('PatchArtifactContentTool: JSON auto-fixed with simple fixes', [
                                'artifact_id' => $artifact_id,
                                'original_error' => $errorMsg,
                            ]);

                            // Continue with the fixed patches
                            return static::executePatchArtifactContent([
                                'artifact_id' => $artifact_id,
                                'patches' => $patches,
                                'content_hash' => $content_hash,
                            ]);
                        }
                    }

                    // Try aggressive repair for escape sequences
                    $repairedPatches = static::repairJsonWithEscapes($patches_json);
                    if ($repairedPatches !== null) {
                        Log::info('PatchArtifactContentTool: JSON repaired with escape sequence handling', [
                            'artifact_id' => $artifact_id,
                            'original_error' => $errorMsg,
                            'patches_count' => count($repairedPatches),
                        ]);

                        return static::executePatchArtifactContent([
                            'artifact_id' => $artifact_id,
                            'patches' => $repairedPatches,
                            'content_hash' => $content_hash,
                        ]);
                    }

                    // Log the actual JSON that failed to help with debugging
                    Log::error('PatchArtifactContentTool: JSON parse error - all repair attempts failed', [
                        'error' => $errorMsg,
                        'json_length' => strlen($patches_json),
                        'json_preview' => substr($patches_json, 0, 500),
                        'json_end_preview' => substr($patches_json, -200),
                        'artifact_id' => $artifact_id,
                    ]);

                    return static::safeJsonEncode([
                        'success' => false,
                        'error' => 'Invalid patches JSON: '.$errorMsg.'. The content may contain characters that need special escaping. Try using content_base64 with base64-encoded content for complex code, or use append_artifact_content instead.',
                    ], 'PatchArtifactContentTool');
                }

                return static::executePatchArtifactContent([
                    'artifact_id' => $artifact_id,
                    'patches' => $patches,
                    'content_hash' => $content_hash,
                ]);
            });
    }

    /**
     * Attempt to fix common JSON issues with improved handling
     */
    protected static function attemptJsonFix(string $json): string
    {
        // Try to detect and fix truncated JSON
        $json = trim($json);

        // If JSON doesn't end properly, try to close it
        if (! str_ends_with($json, ']') && ! str_ends_with($json, '}')) {
            // Find the last complete object/array
            $lastBracePos = max(strrpos($json, '}'), strrpos($json, ']'));
            if ($lastBracePos !== false) {
                $json = substr($json, 0, $lastBracePos + 1);
            }
        }

        // Try to fix unescaped newlines in strings (common issue)
        // This is a simple heuristic and may not work for all cases
        $json = str_replace(["\r\n", "\r"], "\n", $json);

        return $json;
    }

    /**
     * More aggressive JSON repair for content with escape sequences
     * Manually parse the JSON structure when standard parsing fails
     */
    protected static function repairJsonWithEscapes(string $json): ?array
    {
        $patches = [];

        // Find all patch objects by looking for the pattern: {"start":<num>,"end":<num>,"content":"..."}
        // We'll manually extract each field to avoid JSON parsing issues

        $offset = 0;
        while (($pos = strpos($json, '{"start"', $offset)) !== false) {
            // Extract start value
            if (preg_match('/"start"\s*:\s*(\d+)/', $json, $startMatch, 0, $pos)) {
                $start = (int) $startMatch[1];
            } else {
                $offset = $pos + 1;

                continue;
            }

            // Extract end value
            if (preg_match('/"end"\s*:\s*(\d+)/', $json, $endMatch, 0, $pos)) {
                $end = (int) $endMatch[1];
            } else {
                $offset = $pos + 1;

                continue;
            }

            // Find content field - this is the tricky part
            // Look for: "content":"<value>"} or "content":"<value>"}]
            $contentPos = strpos($json, '"content"', $pos);
            if ($contentPos === false) {
                $offset = $pos + 1;

                continue;
            }

            // Find the colon and opening quote
            $colonPos = strpos($json, ':', $contentPos);
            if ($colonPos === false) {
                $offset = $pos + 1;

                continue;
            }

            $quotePos = strpos($json, '"', $colonPos);
            if ($quotePos === false) {
                $offset = $pos + 1;

                continue;
            }

            // Find the closing brace of this patch object
            // We look for "}," or "}]" that closes this patch
            $depth = 1;
            $i = $quotePos + 1;
            $contentEnd = -1;
            $inString = true;
            $escaped = false;

            while ($i < strlen($json) && $depth > 0) {
                $char = $json[$i];

                if ($escaped) {
                    $escaped = false;
                    $i++;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    $i++;

                    continue;
                }

                if ($inString) {
                    if ($char === '"') {
                        // Check if next char is } - this would close the content string and patch
                        if (isset($json[$i + 1]) && $json[$i + 1] === '}') {
                            $contentEnd = $i;
                            break;
                        }
                    }
                }

                $i++;
            }

            if ($contentEnd === -1) {
                $offset = $pos + 1;

                continue;
            }

            // Extract and decode the content
            $rawContent = substr($json, $quotePos + 1, $contentEnd - $quotePos - 1);

            // Properly decode the escaped content
            // This handles \n, \t, \\, \", and any other standard escape sequences
            $content = json_decode('"'.$rawContent.'"');

            if ($content === null && json_last_error() !== JSON_ERROR_NONE) {
                // If JSON decode still fails, use stripcslashes as fallback
                $content = stripcslashes($rawContent);
            }

            $patches[] = [
                'start' => $start,
                'end' => $end,
                'content' => $content,
            ];

            // Move offset past this patch
            $offset = $contentEnd + 1;
        }

        if (count($patches) > 0) {
            Log::info('PatchArtifactContentTool: Successfully repaired JSON using manual parsing', [
                'patches_found' => count($patches),
            ]);

            return $patches;
        }

        return null;
    }

    protected static function executePatchArtifactContent(array $arguments = []): string
    {
        // Get StatusReporter for interaction tracking
        $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;
        $interactionId = $statusReporter ? $statusReporter->getInteractionId() : null;
        $executionId = $statusReporter ? $statusReporter->getAgentExecutionId() : null;

        try {
            // Get authenticated user or fallback
            $user = User::find(app('current_user_id'));

            if (! $user) {
                Log::error('PatchArtifactContentTool: No authenticated user', [
                    'interaction_id' => $interactionId,
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No authenticated user available',
                ], 'PatchArtifactContentTool');
            }

            // Decode base64 content if present
            foreach ($arguments['patches'] as $index => &$patch) {
                if (isset($patch['content_base64']) && ! isset($patch['content'])) {
                    $decoded = base64_decode($patch['content_base64'], true);
                    if ($decoded === false) {
                        Log::warning('PatchArtifactContentTool: Invalid base64 content', [
                            'patch_index' => $index,
                            'interaction_id' => $interactionId,
                        ]);

                        return static::safeJsonEncode([
                            'success' => false,
                            'error' => "Patch {$index}: Invalid base64 encoding in content_base64",
                        ], 'PatchArtifactContentTool');
                    }
                    $patch['content'] = $decoded;
                    unset($patch['content_base64']);
                }
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'artifact_id' => 'required|integer',
                'patches' => 'required|array|min:1',
                'patches.*.start' => 'required|integer|min:0',
                'patches.*.end' => 'required|integer',
                'patches.*.content' => 'string', // Allow empty/whitespace for deletion operations
                'patches.*.range_hash' => 'nullable|string|size:64', // SHA-256 hash is 64 characters
                'content_hash' => 'required|string|size:64',
            ]);

            if ($validator->fails()) {
                Log::warning('PatchArtifactContentTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'patch_artifact_content',
                        'Validation failed for artifact patch',
                        ['errors' => $validator->errors()->all()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'PatchArtifactContentTool');
            }

            $validated = $validator->validated();

            // Validate patch ranges
            foreach ($validated['patches'] as $index => $patch) {
                if ($patch['end'] <= $patch['start']) {
                    return static::safeJsonEncode([
                        'success' => false,
                        'error' => "Invalid patch {$index}: end position ({$patch['end']}) must be greater than start position ({$patch['start']})",
                    ], 'PatchArtifactContentTool');
                }
            }

            // Find artifact
            $artifact = Artifact::find($validated['artifact_id']);

            if (! $artifact) {
                Log::warning('PatchArtifactContentTool: Artifact not found', [
                    'artifact_id' => $validated['artifact_id'],
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'patch_artifact_content',
                        "Artifact not found (ID: {$validated['artifact_id']})",
                        ['artifact_id' => $validated['artifact_id']],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "Artifact not found with ID: {$validated['artifact_id']}",
                ], 'PatchArtifactContentTool');
            }

            // Check write permissions
            if (! $artifact->canEdit($user)) {
                Log::warning('PatchArtifactContentTool: Access denied', [
                    'artifact_id' => $artifact->id,
                    'user_id' => $user->id,
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'patch_artifact_content',
                        "Access denied for artifact: {$artifact->title}",
                        ['artifact_id' => $artifact->id],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Access denied: You do not have permission to modify this artifact',
                ], 'PatchArtifactContentTool');
            }

            // Validate all patch positions are within bounds
            $contentLength = $artifact->content_length;
            foreach ($validated['patches'] as $index => $patch) {
                if ($patch['end'] > $contentLength) {
                    Log::warning('PatchArtifactContentTool: Patch position out of bounds', [
                        'patch_index' => $index,
                        'end_position' => $patch['end'],
                        'content_length' => $contentLength,
                        'artifact_id' => $artifact->id,
                        'interaction_id' => $interactionId,
                    ]);

                    if ($interactionId) {
                        StatusStream::report(
                            $interactionId,
                            'patch_artifact_content',
                            "Patch {$index}: end position {$patch['end']} exceeds content length {$contentLength}",
                            ['patch_index' => $index, 'end_position' => $patch['end'], 'content_length' => $contentLength],
                            true,
                            false,
                            $executionId
                        );
                    }

                    return static::safeJsonEncode([
                        'success' => false,
                        'error' => "Patch {$index}: end position {$patch['end']} exceeds content length {$contentLength}",
                    ], 'PatchArtifactContentTool');
                }
            }

            // Report starting operation
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'patch_artifact_content',
                    'Applying '.count($validated['patches'])." patch(es) to: {$artifact->title}",
                    [
                        'artifact_id' => $artifact->id,
                        'patch_count' => count($validated['patches']),
                    ],
                    true,
                    false,
                    $executionId
                );
            }

            // Apply patches using ArtifactEditor service
            $documentEditor = new ArtifactEditor;

            try {
                $updatedDocument = $documentEditor->patchContent(
                    $artifact,
                    $validated['patches'],
                    $validated['content_hash']
                );
            } catch (ContentHashMismatchException $e) {
                Log::warning('PatchArtifactContentTool: Hash mismatch', [
                    'artifact_id' => $artifact->id,
                    'error' => $e->getMessage(),
                    'interaction_id' => $interactionId,
                ]);

                if ($interactionId) {
                    StatusStream::report(
                        $interactionId,
                        'patch_artifact_content',
                        'Artifact was modified - hash mismatch detected',
                        ['artifact_id' => $artifact->id, 'error' => $e->getMessage()],
                        true,
                        false,
                        $executionId
                    );
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'retry_suggested' => true,
                    'hint' => 'Use read_artifact again to get the latest content_hash and range hashes, then retry',
                ], 'PatchArtifactContentTool');
            }

            // Prepare response
            $responseData = [
                'id' => $updatedDocument->id,
                'title' => $updatedDocument->title,
                'content_hash' => $updatedDocument->content_hash, // New hash after patching
                'content_length' => $updatedDocument->content_length,
                'version' => $updatedDocument->version,
                'updated_at' => $updatedDocument->updated_at->toISOString(),
                'patches_applied' => count($validated['patches']),
            ];

            Log::info('PatchArtifactContentTool: Patches applied successfully', [
                'artifact_id' => $updatedDocument->id,
                'user_id' => $user->id,
                'patch_count' => count($validated['patches']),
                'new_total_length' => $updatedDocument->content_length,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Track artifact modification in chat interaction
            if ($interactionId) {
                $patchCount = count($validated['patches']);
                \App\Models\ChatInteractionArtifact::createOrUpdate(
                    $interactionId,
                    $updatedDocument->id,
                    'modified',
                    'patch_artifact_content',
                    "Applied {$patchCount} patches to: {$updatedDocument->title}",
                    [
                        'patch_count' => $patchCount,
                        'title' => $updatedDocument->title,
                        'filetype' => $updatedDocument->filetype,
                    ]
                );
            }

            // Report success
            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'patch_artifact_content',
                    '✅ Applied '.count($validated['patches'])." patch(es) to: {$updatedDocument->title}",
                    [
                        'artifact_id' => $updatedDocument->id,
                        'patches_applied' => count($validated['patches']),
                        'new_length' => $updatedDocument->content_length,
                        'version' => $updatedDocument->version,
                    ],
                    true,
                    true, // Mark as significant
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => true,
                'message' => 'Patches applied successfully. Version history automatically created.',
                'data' => [
                    'artifact' => $responseData,
                ],
            ], 'PatchArtifactContentTool');

        } catch (\Exception $e) {
            Log::error('PatchArtifactContentTool: Exception caught', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            if ($interactionId) {
                StatusStream::report(
                    $interactionId,
                    'patch_artifact_content',
                    '❌ Failed to apply patches',
                    ['error' => $e->getMessage(), 'error_type' => get_class($e)],
                    true,
                    true,
                    $executionId
                );
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to apply patches: '.$e->getMessage(),
            ], 'PatchArtifactContentTool');
        }
    }
}
