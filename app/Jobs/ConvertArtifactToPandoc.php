<?php

namespace App\Jobs;

use App\Models\ArtifactConversion;
use App\Models\Asset;
use App\Services\Pandoc\PandocConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ConvertArtifactToPandoc Job
 *
 * Handles async conversion of artifacts to various formats using Pandoc.
 * Creates Asset records for completed conversions and tracks status.
 */
class ConvertArtifactToPandoc implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $timeout = 180;

    public function __construct(
        public ArtifactConversion $conversion
    ) {
        $this->onQueue('conversions');
    }

    public function handle(PandocConversionService $pandocService): void
    {
        try {
            $this->conversion->markAsProcessing();

            $artifact = $this->conversion->artifact;

            // IMPORTANT: Extract asset references BEFORE preparing content
            // prepareContentForPandoc resolves asset:// URLs to S3 URLs, so we need to
            // extract the asset IDs from the original content first
            $assets = $pandocService->extractAssetReferences($artifact->content ?? '');

            // Now prepare content (resolves asset:// to S3 URLs, preprocesses mermaid, etc.)
            $content = $pandocService->prepareContentForPandoc($artifact);

            // Prepare request data
            $requestData = [
                'content' => $content,
                'output_format' => $this->conversion->output_format,
                'template' => $this->conversion->template ?? config('pandoc.default_template'),
                'title' => $artifact->title ?? 'Untitled Artifact',
                'author' => $artifact->author->name ?? 'PromptlyAgent',
            ];

            // Add external URLs that need downloading
            // Note: Content already has presigned S3 URLs after prepareContentForPandoc
            // Pandoc only needs to download external URLs (non-S3)
            if (! empty($assets['external_urls'])) {
                $requestData['assets'] = json_encode(['urls' => $assets['external_urls']]);
            }

            // Call Pandoc service
            $response = Http::timeout(config('services.pandoc.timeout'))
                ->retry(config('services.pandoc.retry_times'), config('services.pandoc.retry_delay'))
                ->asForm()
                ->post(config('services.pandoc.url').'/convert', $requestData);

            if ($response->failed()) {
                $errorBody = $response->body();
                Log::error('Pandoc conversion failed', [
                    'conversion_id' => $this->conversion->id,
                    'artifact_id' => $artifact->id,
                    'format' => $this->conversion->output_format,
                    'status' => $response->status(),
                    'body' => $errorBody,
                ]);
                $this->conversion->markAsFailed('Pandoc service error: HTTP '.$response->status());

                return;
            }

            // Store converted file as asset
            $filename = ($artifact->title ? str_replace(['/', '\\'], '_', $artifact->title) : 'artifact')
                .'.'.$this->conversion->output_format;

            $mimeType = $pandocService->getMimeTypeForFormat($this->conversion->output_format);

            $asset = Asset::createFromContent(
                $response->body(),
                $filename,
                $mimeType,
                'conversions'
            );

            // Mark conversion as completed
            $this->conversion->markAsCompleted($asset->id, $asset->size_bytes);

            // Broadcast completion event to user
            event(new \App\Events\ArtifactConversionCompleted($this->conversion));

            Log::info('Artifact conversion completed successfully', [
                'conversion_id' => $this->conversion->id,
                'artifact_id' => $artifact->id,
                'asset_id' => $asset->id,
                'format' => $this->conversion->output_format,
                'file_size' => $asset->size_bytes,
                'attempt' => $this->attempts(),
            ]);

        } catch (\Exception $e) {
            Log::error('Artifact conversion job failed', [
                'conversion_id' => $this->conversion->id,
                'artifact_id' => $this->conversion->artifact_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->conversion->markAsFailed($e->getMessage());
        }
    }
}
