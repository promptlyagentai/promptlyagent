<?php

namespace App\Http\Controllers;

use App\Models\ChatInteractionAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * Handles secure download of chat interaction attachments.
 *
 * Supports two identifier formats:
 * - Numeric ID (preferred): /chat/attachment/456/download
 * - Filename (fallback): /chat/attachment/diagram.png/download
 *
 * Security:
 * - Ownership validation (user must own the interaction)
 * - Expiration checking (attachments have TTL)
 * - Storage abstraction (S3 or local disk)
 *
 * @see \App\Models\ChatInteractionAttachment
 */
class ChatAttachmentController extends Controller
{
    public function download(string $attachment)
    {
        // Try to resolve by ID first (preferred)
        if (is_numeric($attachment)) {
            $attachmentModel = ChatInteractionAttachment::find((int) $attachment);
        } else {
            // Fallback: resolve by filename
            $attachmentModel = ChatInteractionAttachment::where('filename', $attachment)->first();
        }

        if (! $attachmentModel) {
            abort(404, 'Attachment not found');
        }

        if ($attachmentModel->chatInteraction->user_id !== auth()->id()) {
            abort(403, 'Unauthorized access to attachment');
        }

        if (! Storage::exists($attachmentModel->storage_path)) {
            abort(404, 'File not found');
        }

        if ($attachmentModel->isExpired()) {
            abort(410, 'File has expired');
        }

        $disk = $attachmentModel->getStorageDisk();

        return Storage::disk($disk)->download(
            $attachmentModel->storage_path,
            $attachmentModel->filename,
            [
                'Content-Type' => $attachmentModel->mime_type,
                'Cache-Control' => 'no-cache, must-revalidate',
            ]
        );
    }
}
