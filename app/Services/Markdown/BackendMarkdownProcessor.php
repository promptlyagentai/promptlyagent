<?php

namespace App\Services\Markdown;

use App\Models\Asset;
use App\Models\ChatInteractionAttachment;
use Illuminate\Support\Facades\Log;

/**
 * Backend Markdown Processor
 *
 * Resolves internal URLs to presigned S3 URLs for backend processing (Pandoc, jobs, etc.).
 * This service is specifically for backend contexts where presigned URLs are needed,
 * NOT for browser display (which uses client-side rendering with authenticated routes).
 *
 * Features:
 * - Converts asset://ID to presigned S3 URLs
 * - Converts attachment://ID to presigned S3 URLs
 * - Refreshes expired S3 URLs with new presigned URLs
 * - Extracts asset/attachment references for dependency tracking
 */
class BackendMarkdownProcessor
{
    /**
     * Resolve all internal URLs to presigned S3 URLs
     *
     * @param  string  $markdown  Markdown content with internal URLs
     * @param  int  $urlValidityMinutes  URL validity duration (default: 120 minutes for Pandoc)
     * @return string Markdown with presigned S3 URLs
     */
    public function resolveUrls(string $markdown, int $urlValidityMinutes = 120): string
    {
        $markdown = $this->resolveAssetUrls($markdown, $urlValidityMinutes);
        $markdown = $this->resolveAttachmentUrls($markdown, $urlValidityMinutes);
        $markdown = $this->refreshS3Urls($markdown, $urlValidityMinutes);

        return $markdown;
    }

    /**
     * Extract asset and attachment references from markdown
     *
     * Returns array with:
     * - assets: Array of asset IDs
     * - attachments: Array of attachment IDs
     * - external_urls: Array of external image URLs (non-S3)
     *
     * @param  string  $markdown  Markdown content
     * @return array{assets: array<int>, attachments: array<int>, external_urls: array<string>}
     */
    public function extractReferences(string $markdown): array
    {
        $assets = [];
        $attachments = [];
        $externalUrls = [];

        // Extract asset:// references
        if (preg_match_all('/asset:\/\/(\d+)/', $markdown, $matches)) {
            $assets = array_unique(array_map('intval', $matches[1]));
        }

        // Extract attachment:// references
        if (preg_match_all('/attachment:\/\/(\d+)/', $markdown, $matches)) {
            $attachments = array_unique(array_map('intval', $matches[1]));
        }

        // Extract external image URLs (non-S3)
        $s3Domain = config('filesystems.disks.s3.url');
        if (preg_match_all('/!\[.*?\]\((https?:\/\/[^)]+)\)/', $markdown, $matches)) {
            foreach ($matches[1] as $url) {
                // Only include external URLs (not S3)
                if (! str_contains($url, $s3Domain)) {
                    $externalUrls[] = $url;
                }
            }
        }

        return [
            'assets' => array_values($assets),
            'attachments' => array_values($attachments),
            'external_urls' => array_values(array_unique($externalUrls)),
        ];
    }

    /**
     * Resolve asset://ID URLs to presigned S3 URLs
     *
     * Supports two formats:
     * - asset://123 (preferred: numeric ID)
     * - asset://filename.png (fallback: lookup by original_filename)
     *
     * @param  string  $markdown  Markdown content
     * @param  int  $minutes  URL validity duration
     * @return string Markdown with resolved asset URLs
     */
    protected function resolveAssetUrls(string $markdown, int $minutes): string
    {
        return preg_replace_callback(
            '/asset:\/\/([^\s\)]+)/',
            function ($matches) use ($minutes) {
                $identifier = $matches[1];
                $asset = null;

                // Try as numeric ID first (preferred)
                if (is_numeric($identifier)) {
                    $asset = Asset::find((int) $identifier);
                }

                // Fallback: try as filename if ID lookup failed
                if (! $asset) {
                    $asset = Asset::where('original_filename', $identifier)->first();

                    if ($asset) {
                        Log::info('BackendMarkdownProcessor: Resolved asset by filename (consider using ID instead)', [
                            'filename' => $identifier,
                            'asset_id' => $asset->id,
                        ]);
                    }
                }

                if (! $asset) {
                    Log::warning('BackendMarkdownProcessor: Asset not found', [
                        'identifier' => $identifier,
                    ]);

                    return $matches[0]; // Return original if not found
                }

                try {
                    return $asset->getPresignedUrl($minutes);
                } catch (\Exception $e) {
                    Log::error('BackendMarkdownProcessor: Failed to generate presigned URL for asset', [
                        'identifier' => $identifier,
                        'asset_id' => $asset->id ?? null,
                        'error' => $e->getMessage(),
                    ]);

                    return $matches[0]; // Return original on error
                }
            },
            $markdown
        );
    }

    /**
     * Resolve attachment://ID URLs to presigned S3 URLs
     *
     * Supports two formats:
     * - attachment://456 (preferred: numeric ID)
     * - attachment://filename.png (fallback: lookup by filename)
     *
     * @param  string  $markdown  Markdown content
     * @param  int  $minutes  URL validity duration
     * @return string Markdown with resolved attachment URLs
     */
    protected function resolveAttachmentUrls(string $markdown, int $minutes): string
    {
        return preg_replace_callback(
            '/attachment:\/\/([^\s\)]+)/',
            function ($matches) use ($minutes) {
                $identifier = $matches[1];
                $attachment = null;

                // Try as numeric ID first (preferred)
                if (is_numeric($identifier)) {
                    $attachment = ChatInteractionAttachment::find((int) $identifier);
                }

                // Fallback: try as filename if ID lookup failed
                if (! $attachment) {
                    $attachment = ChatInteractionAttachment::where('filename', $identifier)->first();

                    if ($attachment) {
                        Log::info('BackendMarkdownProcessor: Resolved attachment by filename (consider using ID instead)', [
                            'filename' => $identifier,
                            'attachment_id' => $attachment->id,
                        ]);
                    }
                }

                if (! $attachment) {
                    Log::warning('BackendMarkdownProcessor: Attachment not found', [
                        'identifier' => $identifier,
                    ]);

                    return $matches[0]; // Return original if not found
                }

                try {
                    $url = $attachment->getPresignedUrl($minutes);

                    if (! $url) {
                        Log::warning('BackendMarkdownProcessor: Attachment file not found in storage', [
                            'identifier' => $identifier,
                            'attachment_id' => $attachment->id,
                            'storage_path' => $attachment->storage_path,
                        ]);

                        return $matches[0];
                    }

                    return $url;
                } catch (\Exception $e) {
                    Log::error('BackendMarkdownProcessor: Failed to generate presigned URL for attachment', [
                        'identifier' => $identifier,
                        'attachment_id' => $attachment->id ?? null,
                        'error' => $e->getMessage(),
                    ]);

                    return $matches[0]; // Return original on error
                }
            },
            $markdown
        );
    }

    /**
     * Refresh expired S3 URLs with new presigned URLs
     *
     * Finds existing S3 URLs in markdown and regenerates them with fresh signatures.
     * This is useful when markdown already contains S3 URLs that may have expired.
     *
     * IMPORTANT: Only processes plain S3 URLs without query strings. URLs with query
     * strings (already presigned) are skipped to avoid double-processing.
     *
     * @param  string  $markdown  Markdown content
     * @param  int  $minutes  URL validity duration
     * @return string Markdown with refreshed S3 URLs
     */
    protected function refreshS3Urls(string $markdown, int $minutes): string
    {
        $s3Domain = config('filesystems.disks.s3.url');
        $bucket = config('filesystems.disks.s3.bucket');

        // Pattern to match ONLY plain S3 URLs WITHOUT query strings (not already presigned)
        // Matches: domain/bucket/path followed by ) or whitespace (no ? for query string)
        $pattern = '#'.preg_quote($s3Domain.'/'.$bucket.'/', '#').'([^)\s?]+)(?=[)\s])#';

        return preg_replace_callback(
            $pattern,
            function ($matches) use ($minutes) {
                $storagePath = $matches[1];

                // Try to find the attachment by storage path
                $attachment = ChatInteractionAttachment::where('storage_path', $storagePath)
                    ->orderBy('created_at', 'desc')
                    ->first();

                if (! $attachment) {
                    Log::debug('BackendMarkdownProcessor: Could not find attachment for S3 URL', [
                        'storage_path' => $storagePath,
                    ]);

                    return $matches[0]; // Return original if not found
                }

                try {
                    $url = $attachment->getPresignedUrl($minutes);

                    if (! $url) {
                        return $matches[0];
                    }

                    return $url;
                } catch (\Exception $e) {
                    Log::error('BackendMarkdownProcessor: Failed to refresh S3 URL', [
                        'storage_path' => $storagePath,
                        'error' => $e->getMessage(),
                    ]);

                    return $matches[0];
                }
            },
            $markdown
        );
    }
}
