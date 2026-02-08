<?php

namespace App\Tools;

use App\Models\ChatInteractionAttachment;
use App\Models\User;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Facades\Tool;

/**
 * ListChatAttachmentsTool - Agent-Invokable Chat Attachment Query.
 *
 * Prism tool for querying and listing chat attachments. Enables agents to discover
 * relevant media (images, documents, audio, video) from conversations for use in
 * artifacts and multi-media document creation.
 *
 * Supported Attachment Types:
 * - Images: PNG, JPG, JPEG, GIF, WebP
 * - Documents: PDF, CSV
 * - Audio: MP3, WAV, M4A
 * - Video: MP4, MOV, AVI
 *
 * Features:
 * - Filter by chat session ID
 * - Filter by attachment type (image/document/audio/video)
 * - Filter by mime type
 * - Sort by date (newest/oldest)
 * - Privacy-aware (user-scoped)
 * - Includes source attribution information
 * - Returns temporary signed URLs for S3 attachments
 *
 * Execution Context:
 * - User ID retrieved from app('current_user_id')
 * - Interaction ID from StatusReporter or fallback to current_interaction_id
 * - Status updates streamed via StatusReporter
 *
 * Integration:
 * - Works with ChatInteractionAttachment model
 * - Supports both S3 and local storage
 * - Returns attachment metadata for use in artifacts
 *
 * @see \App\Models\ChatInteractionAttachment
 * @see \App\Tools\Concerns\SafeJsonResponse
 */
class ListChatAttachmentsTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('list_chat_attachments')
            ->for('Lists chat attachments with filtering options. Use this to discover relevant media (images, documents, audio, video) from conversations for use in artifacts and multi-media responses. Returns attachment metadata including URLs and source attribution.')
            ->withNumberParameter('chat_session_id', 'Filter by specific chat session ID (optional)')
            ->withStringParameter('attachment_type', 'Filter by type: image, document, audio, or video (optional)')
            ->withStringParameter('mime_type', 'Filter by specific mime type, e.g., "image/png" (optional)')
            ->withStringParameter('sort', 'Sort order: newest (default) or oldest')
            ->withNumberParameter('limit', 'Maximum number of attachments to return (default: 20, max: 100)')
            ->using(function (
                ?int $chat_session_id = null,
                ?string $attachment_type = null,
                ?string $mime_type = null,
                string $sort = 'newest',
                int $limit = 20
            ) {
                return static::executeListAttachments([
                    'chat_session_id' => $chat_session_id,
                    'attachment_type' => $attachment_type,
                    'mime_type' => $mime_type,
                    'sort' => $sort,
                    'limit' => $limit,
                ]);
            });
    }

    protected static function executeListAttachments(array $arguments = []): string
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
                Log::error('ListChatAttachmentsTool: No user ID in execution context', [
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('list_chat_attachments', 'Failed: No user context available', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No user context available for listing attachments',
                ], 'ListChatAttachmentsTool');
            }

            $user = User::find($userId);

            if (! $user) {
                Log::error('ListChatAttachmentsTool: User not found', [
                    'user_id' => $userId,
                    'interaction_id' => $interactionId,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('list_chat_attachments', "Failed: User {$userId} not found", true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => "User not found with ID: {$userId}",
                ], 'ListChatAttachmentsTool');
            }

            // Report start
            if ($statusReporter) {
                $statusReporter->report('list_chat_attachments', 'Searching chat attachments...', true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'chat_session_id' => 'nullable|integer|exists:chat_sessions,id',
                'attachment_type' => 'nullable|string|in:image,document,audio,video',
                'mime_type' => 'nullable|string',
                'sort' => 'nullable|string|in:newest,oldest',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                Log::warning('ListChatAttachmentsTool: Validation failed', [
                    'errors' => $validator->errors()->all(),
                    'interaction_id' => $interactionId,
                    'user_id' => $user->id,
                ]);

                if ($statusReporter) {
                    $statusReporter->report('list_chat_attachments', 'Validation failed', true, false);
                }

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Validation failed: '.implode(', ', $validator->errors()->all()),
                ], 'ListChatAttachmentsTool');
            }

            $validated = $validator->validated();

            // Build query
            $query = ChatInteractionAttachment::query()
                ->whereHas('chatInteraction.chatSession', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->with(['chatInteraction.chatSession']);

            // Filter by chat session
            if (! empty($validated['chat_session_id'])) {
                $query->whereHas('chatInteraction', function ($q) use ($validated) {
                    $q->where('chat_session_id', $validated['chat_session_id']);
                });
            }

            // Filter by attachment type
            if (! empty($validated['attachment_type'])) {
                $type = $validated['attachment_type'];
                $query->where(function ($q) use ($type) {
                    switch ($type) {
                        case 'image':
                            $q->whereIn('mime_type', ['image/png', 'image/jpg', 'image/jpeg', 'image/gif', 'image/webp']);
                            break;
                        case 'document':
                            $q->whereIn('mime_type', ['application/pdf', 'text/csv']);
                            break;
                        case 'audio':
                            $q->whereIn('mime_type', ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-m4a']);
                            break;
                        case 'video':
                            $q->whereIn('mime_type', ['video/mp4', 'video/quicktime', 'video/x-msvideo']);
                            break;
                    }
                });
            }

            // Filter by specific mime type
            if (! empty($validated['mime_type'])) {
                $query->where('mime_type', $validated['mime_type']);
            }

            // Sort
            $sort = $validated['sort'] ?? 'newest';
            $query->orderBy('created_at', $sort === 'newest' ? 'desc' : 'asc');

            // Limit
            $limit = $validated['limit'] ?? 20;
            $attachments = $query->limit($limit)->get();

            Log::info('ListChatAttachmentsTool: Attachments retrieved', [
                'count' => $attachments->count(),
                'user_id' => $user->id,
                'interaction_id' => $interactionId,
                'filters' => $validated,
            ]);

            // Format results
            $results = $attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'original_filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,
                    'file_size_human' => round($attachment->file_size / 1024, 2).' KB',
                    'attachment_type' => static::getAttachmentType($attachment->mime_type),
                    'storage_path' => $attachment->storage_path,
                    'url' => $attachment->getFileUrl(),
                    'source_url' => $attachment->source_url,
                    'source_title' => $attachment->source_title,
                    'source_author' => $attachment->source_author,
                    'chat_session_id' => $attachment->chatInteraction->chat_session_id,
                    'chat_interaction_id' => $attachment->chat_interaction_id,
                    'created_at' => $attachment->created_at->toISOString(),
                ];
            });

            // Report success
            if ($statusReporter) {
                $statusReporter->report('list_chat_attachments', "✅ Found {$attachments->count()} attachment(s)", true, true);
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'attachments' => $results,
                    'count' => $attachments->count(),
                    'filters_applied' => array_filter($validated),
                ],
            ], 'ListChatAttachmentsTool');

        } catch (\Exception $e) {
            Log::error('ListChatAttachmentsTool: Exception caught', [
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'interaction_id' => $interactionId ?? null,
                'execution_id' => $executionId ?? null,
            ]);

            if ($statusReporter ?? null) {
                $statusReporter->report('list_chat_attachments', '❌ Failed to list attachments', true, true);
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to list chat attachments: '.$e->getMessage(),
            ], 'ListChatAttachmentsTool');
        }
    }

    /**
     * Determine attachment type from mime type.
     */
    protected static function getAttachmentType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (in_array($mimeType, ['application/pdf', 'text/csv'])) {
            return 'document';
        }

        return 'unknown';
    }
}
