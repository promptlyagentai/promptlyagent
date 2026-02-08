<?php

namespace App\Tools;

use App\Models\ChatInteractionAttachment;
use App\Models\User;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool;

/**
 * GenerateMermaidDiagramTool - Agent-Invokable Mermaid Diagram Generator.
 *
 * Prism tool for generating diagrams using Mermaid.js syntax. Enables agents
 * to create flowcharts, sequence diagrams, class diagrams, ER diagrams, Gantt charts,
 * and other visualizations from text-based descriptions.
 *
 * Supported Diagram Types:
 * - Flowcharts (graph TD/LR)
 * - Sequence diagrams
 * - Class diagrams
 * - State diagrams
 * - ER diagrams (Entity-Relationship)
 * - Gantt charts
 * - Pie charts
 * - Git graphs
 * - User journey diagrams
 * - Mindmaps
 *
 * Features:
 * - Server-side rendering via Mermaid-CLI microservice
 * - PNG and SVG output formats (PNG is default for better PDF compatibility)
 * - Original Mermaid code preserved in metadata
 * - Stored as chat attachments for easy reference
 * - Load-balanced rendering (2 workers)
 *
 * Execution Context:
 * - User ID retrieved from app('current_user_id')
 * - Interaction ID from StatusReporter or fallback to current_interaction_id
 * - Status updates streamed via StatusReporter
 * - Diagrams stored in S3 (chat-attachments/)
 *
 * Integration:
 * - Uses Mermaid-CLI service (http://mermaid-lb:80)
 * - Creates ChatInteractionAttachment records
 * - Supports client-side preview via mermaid.js
 *
 * @see \App\Models\ChatInteractionAttachment
 * @see \App\Tools\Concerns\SafeJsonResponse
 */
class GenerateMermaidDiagramTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('generate_mermaid_diagram')
            ->for('Generate diagrams using Mermaid.js syntax. Supports flowcharts, sequence diagrams, class diagrams, state diagrams, ER diagrams, Gantt charts, pie charts, and more. The diagram will be rendered and saved as an attachment.

CRITICAL SYNTAX RULES (violations cause render failures):
1. NEVER use parentheses () inside square brackets [] for node labels
   ❌ BAD: A[Server (Primary)]
   ✅ GOOD: A[Server - Primary] or A[Primary Server]

2. NEVER use multi-line text inside node labels (no \\n in labels)
   ❌ BAD: A[First Line\\nSecond Line]
   ✅ GOOD: A[First Line and Second Line]

3. Escape special characters in labels: use quotes for complex text
   ❌ BAD: A[Cost: $100 & up]
   ✅ GOOD: A["Cost: $100 and up"]

4. Keep node labels simple and descriptive
   ✅ GOOD: A[User Authentication] --> B[Token Validation]

5. Use HTML entities for special symbols if needed: &amp; for &, &lt; for <, &gt; for >

Examples of valid syntax:
- graph TD\\n    A[Start] --> B[Process] --> C[End]
- graph LR\\n    User[User Account] --> Auth[Authentication Service]
- sequenceDiagram\\n    Alice->>Bob: Hello\\n    Bob-->>Alice: Hi')
            ->withStringParameter('code', 'The Mermaid diagram code. Must follow syntax rules: no parentheses in square brackets, no multi-line labels, escape special characters.', true)
            ->withStringParameter('title', 'Title/name for the diagram (required)', true)
            ->withStringParameter('description', 'Brief description of what the diagram shows (optional)')
            ->withStringParameter('format', 'Output format: svg or png (default: png)', false)
            ->using(function (
                string $code,
                string $title,
                ?string $description = null,
                string $format = 'png'
            ) {
                return static::executeGenerateDiagram([
                    'code' => $code,
                    'title' => $title,
                    'description' => $description,
                    'format' => $format,
                ]);
            });
    }

    /**
     * Validate Mermaid syntax for common errors.
     *
     * @return array{valid: bool, errors: array<string>}
     */
    protected static function validateMermaidSyntax(string $code): array
    {
        $errors = [];

        // Check for parentheses inside square brackets (common error)
        if (preg_match('/\[[^\]]*\([^\)]*\)[^\]]*\]/', $code)) {
            $errors[] = 'Syntax Error: Parentheses found inside square brackets. Use dashes or rewrite labels. Example: Change "A[Server (Primary)]" to "A[Server - Primary]" or "A[Primary Server]".';
        }

        // Check for newlines inside square brackets (multi-line labels)
        if (preg_match('/\[[^\]]*\\\\n[^\]]*\]/', $code) || preg_match('/\[[^\]]*\n[^\]]*\]/', $code)) {
            $errors[] = 'Syntax Error: Multi-line text found in node labels. Combine into single line. Example: Change "A[Line 1\\nLine 2]" to "A[Line 1 and Line 2]".';
        }

        // Check for unescaped ampersands in labels
        if (preg_match('/\[[^\]]*&[^a;\]]*\]/', $code) && ! preg_match('/&(amp|lt|gt|quot|apos);/', $code)) {
            $errors[] = 'Syntax Warning: Unescaped ampersand (&) detected in labels. Consider using "and" instead or escape as &amp;. Example: Change "A[Cost & Time]" to "A[Cost and Time]".';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Provide contextual guidance based on error details from Mermaid renderer.
     */
    protected static function provideSyntaxGuidance(string $errorDetails): string
    {
        $guidance = [];

        // Parse common error patterns
        if (stripos($errorDetails, 'parse') !== false || stripos($errorDetails, 'syntax') !== false) {
            $guidance[] = 'Syntax error detected in Mermaid code.';

            if (stripos($errorDetails, 'expecting') !== false) {
                $guidance[] = 'The parser encountered unexpected syntax. Check for missing or extra characters.';
            }
        }

        if (stripos($errorDetails, 'bracket') !== false || stripos($errorDetails, 'parenthes') !== false) {
            $guidance[] = 'COMMON ISSUE: Do not use parentheses inside square brackets. Use dashes instead: A[Server - Primary]';
        }

        if (empty($guidance)) {
            $guidance[] = 'Review your Mermaid syntax. Common issues: parentheses in square brackets, multi-line labels, special characters.';
        }

        $guidance[] = 'Tip: Test your diagram at https://mermaid.live before using the tool.';

        return implode(' ', $guidance);
    }

    protected static function executeGenerateDiagram(array $arguments = []): string
    {
        try {
            // Get status reporter and interaction ID
            $statusReporter = null;
            $interactionId = null;
            $executionId = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
                $executionId = $statusReporter->getAgentExecutionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            // Get user from execution context
            $userId = app()->has('current_user_id') ? app('current_user_id') : null;

            if (! $userId) {
                Log::error('GenerateMermaidDiagramTool: No user ID in execution context', [
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', 'Failed: No user context available', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No user context available for diagram generation',
                ], 'GenerateMermaidDiagramTool');
            }

            $user = User::find($userId);

            if (! $user) {
                Log::error('GenerateMermaidDiagramTool: User not found', [
                    'user_id' => $userId,
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', "Failed: User {$userId} not found", true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "User not found with ID: {$userId}",
                ], 'GenerateMermaidDiagramTool');
            }

            if (! $interactionId) {
                Log::error('GenerateMermaidDiagramTool: No interaction ID available', [
                    'user_id' => $userId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', 'Failed: No interaction context available', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No interaction context available for diagram attachment',
                ], 'GenerateMermaidDiagramTool');
            }

            // Report start
            if ($statusReporter) {
                $statusReporter->report('generate_mermaid_diagram', "Generating diagram: {$arguments['title']}", true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'code' => 'required|string',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'format' => 'nullable|string|in:svg,png',
            ]);

            if ($validator->fails()) {
                Log::warning('GenerateMermaidDiagramTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', 'Validation failed', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'GenerateMermaidDiagramTool');
            }

            $validated = $validator->validated();
            $format = $validated['format'] ?? 'png';

            // Validate Mermaid syntax
            $syntaxValidation = static::validateMermaidSyntax($validated['code']);

            if (! $syntaxValidation['valid']) {
                $errorMessage = "Mermaid syntax validation failed:\n".implode("\n", $syntaxValidation['errors']);

                Log::warning('GenerateMermaidDiagramTool: Syntax validation failed', [
                    'errors' => $syntaxValidation['errors'],
                    'interaction_id' => $interactionId,
                    'code_preview' => substr($validated['code'], 0, 200),
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', '❌ Syntax validation failed', true, true);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => $errorMessage,
                    'validation_errors' => $syntaxValidation['errors'],
                    'guidance' => 'Please fix the syntax errors and try again. Common fixes: Remove parentheses from square brackets, combine multi-line labels into single lines, replace & with "and".',
                ], 'GenerateMermaidDiagramTool');
            }

            // Check if service is enabled
            if (! config('services.mermaid.enabled', true)) {
                Log::warning('GenerateMermaidDiagramTool: Service disabled', [
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', 'Mermaid service is disabled', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Mermaid diagram generation is currently disabled',
                ], 'GenerateMermaidDiagramTool');
            }

            // Call Mermaid service to render the diagram
            $serviceUrl = config('services.mermaid.url');
            $timeout = config('services.mermaid.timeout', 60);

            Log::info('GenerateMermaidDiagramTool: Calling Mermaid service', [
                'service_url' => $serviceUrl,
                'format' => $format,
                'code_length' => strlen($validated['code']),
                'interaction_id' => $interactionId,
            ]);

            try {
                $response = Http::timeout($timeout)
                    ->retry(config('services.mermaid.retry_times', 2), config('services.mermaid.retry_delay', 1000))
                    ->post("{$serviceUrl}/render", [
                        'code' => $validated['code'],
                        'format' => $format,
                        'backgroundColor' => 'transparent',
                        // No theme specified - uses default rendering
                    ]);

                if (! $response->successful()) {
                    $errorBody = $response->json();
                    $errorMessage = $errorBody['error'] ?? 'Unknown error';
                    $errorDetails = $errorBody['details'] ?? '';

                    // Parse error details for common issues and provide guidance
                    $guidance = static::provideSyntaxGuidance($errorDetails);

                    Log::error('GenerateMermaidDiagramTool: Mermaid service returned error', [
                        'status' => $response->status(),
                        'error' => $errorMessage,
                        'details' => $errorDetails,
                        'guidance' => $guidance,
                        'interaction_id' => $interactionId,
                        'code_preview' => substr($validated['code'], 0, 300),
                    ]);

                    if ($statusReporter) {
                        $statusReporter->report('generate_mermaid_diagram', '❌ Diagram rendering failed', true, true);
                    }

                    return static::safeJsonEncode([
                        'success' => false,
                        'error' => "Diagram rendering failed: {$errorMessage}",
                        'details' => $errorDetails,
                        'guidance' => $guidance,
                        'common_fixes' => [
                            'Remove parentheses from inside square brackets: A[Server (Primary)] → A[Server - Primary]',
                            'Avoid multi-line labels: Combine text into single line',
                            'Escape special characters: Use "and" instead of &',
                            'Check arrow syntax: --> for solid, -->> for dotted arrows',
                            'Verify diagram type declaration: graph TD, sequenceDiagram, etc.',
                        ],
                    ], 'GenerateMermaidDiagramTool');
                }

                $renderedContent = $response->body();

                Log::info('GenerateMermaidDiagramTool: Diagram rendered successfully', [
                    'content_length' => strlen($renderedContent),
                    'format' => $format,
                    'interaction_id' => $interactionId,
                ]);

            } catch (\Exception $e) {
                Log::error('GenerateMermaidDiagramTool: Failed to call Mermaid service', [
                    'error' => $e->getMessage(),
                    'service_url' => $serviceUrl,
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('generate_mermaid_diagram', '❌ Failed to connect to rendering service', true, true);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to connect to diagram rendering service: '.$e->getMessage(),
                ], 'GenerateMermaidDiagramTool');
            }

            // Store the rendered diagram as an attachment
            $filename = Str::slug($validated['title']).'-'.Str::random(8).'.'.$format;
            $storagePath = 'chat-attachments/'.date('Y/m/d').'/'.$filename;

            // Store in S3
            Storage::disk('s3')->put($storagePath, $renderedContent);

            Log::info('GenerateMermaidDiagramTool: Diagram stored in S3', [
                'storage_path' => $storagePath,
                'filename' => $filename,
                'interaction_id' => $interactionId,
            ]);

            // Create attachment record
            $mimeType = $format === 'svg' ? 'image/svg+xml' : 'image/png';
            $attachment = ChatInteractionAttachment::create([
                'chat_interaction_id' => $interactionId,
                'attached_to' => 'answer',
                'filename' => $filename,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size' => strlen($renderedContent),
                'type' => 'image',
                'metadata' => [
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'mermaid_code' => $validated['code'],
                    'format' => $format,
                    'generated_by' => 'GenerateMermaidDiagramTool',
                    'generated_at' => now()->toISOString(),
                ],
                'is_temporary' => false,
            ]);

            Log::info('GenerateMermaidDiagramTool: Attachment created successfully', [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->filename,
                'storage_path' => $attachment->storage_path,
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
            ]);

            // Report success
            if ($statusReporter) {
                $statusReporter->report('generate_mermaid_diagram', "✅ Generated diagram: {$validated['title']}", true, true);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'attachment' => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'title' => $validated['title'],
                        'description' => $validated['description'],
                        'format' => $format,
                        'mime_type' => $mimeType,
                        'file_size' => $attachment->file_size,
                        'type' => $attachment->type,
                        'storage_path' => $attachment->storage_path,
                        'created_at' => $attachment->created_at->toISOString(),
                    ],
                    'message' => "Diagram '{$validated['title']}' generated successfully as {$format} attachment (ID: {$attachment->id}). You can reference it in markdown as: ![{$validated['title']}](attachment://{$attachment->id})",
                ],
            ], 'GenerateMermaidDiagramTool');

        } catch (\Exception $e) {
            Log::error('GenerateMermaidDiagramTool: Exception during diagram generation', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId ?? null,
                'execution_id' => $executionId ?? null,
            ]);

            if ($statusReporter ?? null) {
                $statusReporter->report('generate_mermaid_diagram', '❌ Failed to generate diagram', true, true);
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to generate diagram: '.$e->getMessage(),
            ], 'GenerateMermaidDiagramTool');
        }
    }
}
