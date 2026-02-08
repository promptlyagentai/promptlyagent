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
 * CreateChatAttachmentTool - Agent-Invokable External Media Download.
 *
 * Prism tool for downloading media from external URLs and creating chat attachments
 * with proper source attribution. Enables agents to fetch relevant images, documents,
 * audio, and video for use in multi-media artifacts and responses.
 *
 * **CRITICAL: Source Attribution Requirements**
 * When downloading media from external sources, you MUST:
 * - Always provide source_url (required)
 * - Always provide source_title when known
 * - Always provide source_author when known
 * - Preserve attribution for proper crediting
 * - Include source information in artifacts that use the media
 *
 * Supported Media Types:
 * - Images: PNG, JPG, JPEG, GIF, WebP (max 20MB)
 * - Documents: PDF, CSV (max 512MB for PDF, 20MB for CSV)
 * - Audio: MP3, WAV, M4A (max 25MB)
 * - Video: MP4, MOV, AVI (max 25MB)
 *
 * Features:
 * - Downloads media from external URLs
 * - Validates file types and sizes
 * - Stores in S3 with proper organization
 * - Associates with current chat session
 * - Preserves source attribution for crediting
 * - Returns attachment metadata for use in artifacts
 *
 * Execution Context:
 * - User ID retrieved from app('current_user_id')
 * - Interaction ID from StatusReporter or fallback to current_interaction_id
 * - Status updates streamed via StatusReporter
 *
 * Integration:
 * - Works with ChatInteractionAttachment model
 * - Uses S3 for storage (chat-attachments/ prefix)
 * - Tracks source attribution in metadata
 *
 * @see \App\Models\ChatInteractionAttachment
 * @see \App\Tools\Concerns\SafeJsonResponse
 */
class CreateChatAttachmentTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('create_chat_attachment')
            ->for('Downloads media from an external URL and creates a chat attachment with proper source attribution. Use this to fetch relevant images, documents, audio, or video for use in multi-media artifacts. **REQUIRED**: You MUST provide source_url (required) and should provide source_title and source_author when known to preserve proper attribution and credits.')
            ->withStringParameter('url', 'The external URL to download media from (required)', true)
            ->withStringParameter('source_title', 'Title of the source content for attribution (required for proper crediting)', true)
            ->withStringParameter('source_author', 'Author or creator of the source content (optional but recommended)')
            ->withStringParameter('source_description', 'Brief description of the source content (optional)')
            ->withStringParameter('filename', 'Custom filename for the attachment (optional, will be derived from URL if not provided)')
            ->using(function (
                string $url,
                string $source_title,
                ?string $source_author = null,
                ?string $source_description = null,
                ?string $filename = null
            ) {
                return static::executeCreateAttachment([
                    'url' => $url,
                    'source_title' => $source_title,
                    'source_author' => $source_author,
                    'source_description' => $source_description,
                    'filename' => $filename,
                ]);
            });
    }

    protected static function executeCreateAttachment(array $arguments = []): string
    {
        try {
            // Get status reporter and interaction ID with fallback strategy
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
                Log::error('CreateChatAttachmentTool: No user ID in execution context', [
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_chat_attachment', 'Failed: No user context available', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No user context available for creating attachment',
                ], 'CreateChatAttachmentTool');
            }

            $user = User::find($userId);

            if (! $user) {
                Log::error('CreateChatAttachmentTool: User not found', [
                    'user_id' => $userId,
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_chat_attachment', "Failed: User {$userId} not found", true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "User not found with ID: {$userId}",
                ], 'CreateChatAttachmentTool');
            }

            // Must have interaction ID to associate attachment
            if (! $interactionId) {
                Log::error('CreateChatAttachmentTool: No interaction ID available', [
                    'user_id' => $userId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_chat_attachment', 'Failed: No interaction context', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No interaction context available for attachment creation',
                ], 'CreateChatAttachmentTool');
            }

            // Report start
            if ($statusReporter) {
                $statusReporter->report('create_chat_attachment', 'Downloading media from external URL...', true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'url' => 'required|url',
                'source_title' => 'required|string|max:255',
                'source_author' => 'nullable|string|max:255',
                'source_description' => 'nullable|string',
                'filename' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning('CreateChatAttachmentTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_chat_attachment', 'Validation failed', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'CreateChatAttachmentTool');
            }

            $validated = $validator->validated();
            $url = $validated['url'];

            // Download file from URL
            Log::info('CreateChatAttachmentTool: Downloading file', [
                'url' => $url,
                'user_id' => $user->id,
                'interaction_id' => $interactionId,
            ]);

            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::error('CreateChatAttachmentTool: Download failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'user_id' => $user->id,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_chat_attachment', '❌ Failed to download media', true, true);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "Failed to download file from URL: HTTP {$response->status()}",
                ], 'CreateChatAttachmentTool');
            }

            $fileContent = $response->body();
            $fileSize = strlen($fileContent);

            // Determine filename
            $filename = $validated['filename'] ?? basename(parse_url($url, PHP_URL_PATH));
            if (empty($filename) || $filename === '/') {
                $filename = 'download_'.Str::random(8);
            }

            // Get mime type from response or file extension
            $mimeType = $response->header('Content-Type');
            if (! $mimeType || str_contains($mimeType, ';')) {
                $mimeType = explode(';', $mimeType)[0];
            }

            // Fallback to mime type detection from extension if header is missing
            if (! $mimeType || $mimeType === 'application/octet-stream') {
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mimeType = static::getMimeTypeFromExtension($extension) ?? 'application/octet-stream';
            }

            // Determine attachment type
            $type = ChatInteractionAttachment::determineTypeFromMimeType($mimeType);

            // Validate file size limits based on type
            $sizeLimit = match ($type) {
                'document' => $mimeType === 'application/pdf' ? 512 * 1024 * 1024 : 20 * 1024 * 1024, // 512MB for PDF, 20MB for others
                'image' => 20 * 1024 * 1024,     // 20MB
                'audio' => 25 * 1024 * 1024,     // 25MB
                'video' => 25 * 1024 * 1024,     // 25MB
                default => 20 * 1024 * 1024
            };

            if ($fileSize > $sizeLimit) {
                Log::error('CreateChatAttachmentTool: File too large', [
                    'url' => $url,
                    'file_size' => $fileSize,
                    'size_limit' => $sizeLimit,
                    'type' => $type,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('create_chat_attachment', '❌ File too large', true, true);
                }

                $sizeLimitMB = round($sizeLimit / (1024 * 1024), 2);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "File too large for type '{$type}': {$fileSize} bytes exceeds {$sizeLimitMB}MB limit",
                ], 'CreateChatAttachmentTool');
            }

            // Generate storage path in S3
            $storagePath = 'chat-attachments/'.date('Y/m/d').'/'.Str::uuid().'_'.$filename;

            // Store in S3
            Storage::disk('s3')->put($storagePath, $fileContent);

            Log::info('CreateChatAttachmentTool: File stored in S3', [
                'storage_path' => $storagePath,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
            ]);

            // Create attachment record
            $attachment = ChatInteractionAttachment::create([
                'chat_interaction_id' => $interactionId,
                'attached_to' => 'answer',
                'filename' => $filename,
                'storage_path' => $storagePath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'type' => $type,
                'is_temporary' => false,
                'metadata' => [
                    'original_filename' => $filename,
                    'source_url' => $url,
                    'source_title' => $validated['source_title'],
                    'source_author' => $validated['source_author'] ?? null,
                    'source_description' => $validated['source_description'] ?? null,
                    'downloaded_at' => now()->toISOString(),
                ],
            ]);

            Log::info('CreateChatAttachmentTool: Attachment created successfully', [
                'attachment_id' => $attachment->id,
                'filename' => $filename,
                'type' => $type,
                'source_url' => $url,
                'source_title' => $validated['source_title'],
                'user_id' => $user->id,
                'interaction_id' => $interactionId,
            ]);

            // Report success
            if ($statusReporter) {
                $statusReporter->report('create_chat_attachment', "✅ Downloaded and created attachment: {$filename}", true, true);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'attachment' => [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'original_filename' => $attachment->original_filename,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'file_size_human' => round($attachment->file_size / 1024, 2).' KB',
                        'attachment_type' => $attachment->type,
                        'url' => $attachment->getFileUrl(),
                        'source_url' => $attachment->source_url,
                        'source_title' => $attachment->source_title,
                        'source_author' => $attachment->source_author,
                        'source_description' => $attachment->source_description,
                        'chat_interaction_id' => $attachment->chat_interaction_id,
                        'created_at' => $attachment->created_at->toISOString(),
                    ],
                    'message' => "Media downloaded and attachment created: {$attachment->filename}. Reference as: ![{$attachment->filename}](attachment://{$attachment->id})",
                    'attribution' => "Source: {$attachment->source_title}".($attachment->source_author ? " by {$attachment->source_author}" : ''),
                ],
            ], 'CreateChatAttachmentTool');

        } catch (\Exception $e) {
            Log::error('CreateChatAttachmentTool: Exception caught', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId ?? null,
                'execution_id' => $executionId ?? null,
            ]);

            if ($statusReporter ?? null) {
                $statusReporter->report('create_chat_attachment', '❌ Failed to create attachment', true, true);
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to create chat attachment: '.$e->getMessage(),
            ], 'CreateChatAttachmentTool');
        }
    }

    /**
     * Get MIME type from file extension
     */
    protected static function getMimeTypeFromExtension(string $extension): ?string
    {
        return match (strtolower($extension)) {
            // Images
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            // Documents
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            // Audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/x-m4a',
            // Video
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            default => null,
        };
    }
}
