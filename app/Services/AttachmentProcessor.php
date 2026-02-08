<?php

namespace App\Services;

use App\Models\ChatInteractionAttachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Attachment Processor Service
 *
 * Consolidates attachment processing logic for text injection and binary handling.
 * Used by both AgentExecutor and StreamingController to process file attachments
 * for AI model consumption.
 *
 * Processing Strategy:
 * - Text-based files (txt, md, csv, etc.): Inject content directly into prompt
 * - Binary files (images, PDFs, etc.): Convert to Prism value objects for AI models
 * - Unsupported files: Log and skip to prevent AI model errors
 *
 * @see \App\Models\ChatInteractionAttachment::shouldInjectAsText()
 * @see \App\Models\ChatInteractionAttachment::toPrismValueObject()
 */
class AttachmentProcessor
{
    /**
     * Process a collection of attachments and return text content and Prism objects.
     *
     * @param  Collection<ChatInteractionAttachment>  $attachments  Attachments to process
     * @param  string|null  $contextId  Optional context ID for logging (execution_id, interaction_id)
     * @return array{text_content: string, prism_objects: array, skipped: array, image_urls: array}
     */
    public function process(Collection $attachments, ?string $contextId = null): array
    {
        $textContent = '';
        $prismObjects = [];
        $skippedAttachments = [];
        $imageUrls = [];

        foreach ($attachments as $attachment) {
            try {
                // Strategy 1: Text injection for text-based files
                if ($attachment->shouldInjectAsText()) {
                    $content = $attachment->getTextContent();
                    if ($content) {
                        $textContent .= "\n\n--- Attached File: {$attachment->filename} ---\n{$content}\n--- End of {$attachment->filename} ---\n";

                        Log::info('AttachmentProcessor: Injected text attachment', [
                            'context_id' => $contextId,
                            'attachment_id' => $attachment->id,
                            'filename' => $attachment->filename,
                            'content_length' => strlen($content),
                        ]);
                    } else {
                        Log::warning('AttachmentProcessor: Failed to read text content', [
                            'context_id' => $contextId,
                            'attachment_id' => $attachment->id,
                            'filename' => $attachment->filename,
                        ]);
                    }
                } elseif ($attachment->isSupportedForBinaryAttachment()) {
                    // Strategy 2: Binary attachment for supported file types (images, PDFs, etc.)
                    $prismObject = $attachment->toPrismValueObject();
                    if ($prismObject) {
                        $prismObjects[] = $prismObject;

                        Log::info('AttachmentProcessor: Created binary attachment', [
                            'context_id' => $contextId,
                            'attachment_id' => $attachment->id,
                            'filename' => $attachment->filename,
                            'mime_type' => $attachment->mime_type,
                        ]);
                    } else {
                        Log::warning('AttachmentProcessor: Failed to convert supported attachment', [
                            'context_id' => $contextId,
                            'attachment_id' => $attachment->id,
                            'filename' => $attachment->filename,
                        ]);
                    }
                } else {
                    // Strategy 3: Skip unsupported file types
                    $skippedAttachments[] = [
                        'filename' => $attachment->filename,
                        'mime_type' => $attachment->mime_type,
                        'type' => $attachment->type,
                        'reason' => 'unsupported_file_type_for_ai_model',
                    ];

                    Log::info('AttachmentProcessor: Skipped unsupported file type', [
                        'context_id' => $contextId,
                        'attachment_id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'mime_type' => $attachment->mime_type,
                        'type' => $attachment->type,
                    ]);
                }

                // Collect image URLs for reference in tools (like create_github_issue)
                if (str_starts_with($attachment->mime_type, 'image/')) {
                    $url = $attachment->getFileUrl();
                    if ($url) {
                        $imageUrls[] = [
                            'filename' => $attachment->filename,
                            'url' => $url,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::error('AttachmentProcessor: Error processing attachment', [
                    'context_id' => $contextId,
                    'attachment_id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mime_type,
                    'type' => $attachment->type,
                    'storage_path' => $attachment->storage_path,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Log processing summary if any attachments were skipped
        if (! empty($skippedAttachments)) {
            Log::info('AttachmentProcessor: Processing summary', [
                'context_id' => $contextId,
                'total_attachments' => $attachments->count(),
                'binary_attachments' => count($prismObjects),
                'text_injected_count' => substr_count($textContent, '--- Attached File:'),
                'skipped_count' => count($skippedAttachments),
                'skipped_files' => array_column($skippedAttachments, 'filename'),
                'image_urls_count' => count($imageUrls),
            ]);
        }

        return [
            'text_content' => $textContent,
            'prism_objects' => $prismObjects,
            'skipped' => $skippedAttachments,
            'image_urls' => $imageUrls,
        ];
    }

    /**
     * Build formatted image URLs section for user input.
     *
     * @param  array  $imageUrls  Array of image URLs from process() method
     * @return string Formatted markdown section with image URLs
     */
    public function buildImageUrlsSection(array $imageUrls): string
    {
        if (empty($imageUrls)) {
            return '';
        }

        $section = "\n\n--- Attached Images ---\n";
        foreach ($imageUrls as $image) {
            $section .= "- {$image['filename']}: {$image['url']}\n";
        }
        $section .= "--- End of Attached Images ---\n";

        return $section;
    }
}
