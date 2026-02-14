<?php

namespace App\Http\Controllers;

use App\Models\Artifact;
use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Models\Document;
use App\Services\Assets\AssetService;
use App\Services\Pandoc\PandocConversionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class ArtifactController extends Controller
{
    /**
     * Create artifact from chat interaction (API endpoint for PWA)
     */
    public function store(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'chat_interaction_id' => 'required|exists:chat_interactions,id',
            'chat_session_id' => 'required|exists:chat_sessions,id',
            'content' => 'required|string',
            'title' => 'required|string|max:255',
            'filetype' => 'required|string|max:50',
            'privacy_level' => 'required|in:private,public',
        ]);

        // DEFENSE IN DEPTH: Ensure session title is generated
        // This handles edge cases where title generation hasn't completed
        $session = ChatSession::find($validated['chat_session_id']);
        $interaction = ChatInteraction::find($validated['chat_interaction_id']);

        if ($session && $interaction) {
            if (\App\Services\SessionTitleService::isDefaultDatetimeTitle($session->title)) {
                // Trigger synchronous title generation if still default
                \App\Services\SessionTitleService::generateTitleIfNeeded($interaction);
                $session->refresh(); // Reload to get updated title
            }
        }

        // Create artifact
        $artifact = Artifact::create([
            'author_id' => auth()->id(),
            'title' => $validated['title'],
            'content' => $validated['content'],
            'filetype' => $validated['filetype'],
            'privacy_level' => $validated['privacy_level'],
            'word_count' => str_word_count(strip_tags($validated['content'])),
        ]);

        // Create relationship with chat interaction (broadcasts Echo event)
        \App\Models\ChatInteractionArtifact::createOrUpdate(
            $validated['chat_interaction_id'],
            $artifact->id,
            'created',
            'pwa_create_artifact',
            'User created artifact from chat interaction via PWA'
        );

        return response()->json([
            'success' => true,
            'artifact' => $artifact,
        ], 201);
    }

    /**
     * Show artifact details (API endpoint)
     */
    public function show(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this artifact.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'artifact' => $artifact->load('author'),
        ]);
    }

    /**
     * Update artifact metadata and content
     */
    public function update(\Illuminate\Http\Request $request, Artifact $artifact)
    {
        // Authorization check
        if (! $this->canEditArtifact($artifact)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to edit this artifact.',
            ], 403);
        }

        // Validation
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'privacy_level' => 'nullable|in:private,public',
            'filetype' => 'nullable|string|max:50',
        ]);

        // Update artifact (skip version creation in v1)
        $artifact->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Artifact updated successfully.',
            'artifact' => $artifact->load('author'),
        ]);
    }

    /**
     * Check if user can edit artifact
     */
    private function canEditArtifact(Artifact $artifact): bool
    {
        $user = auth()->user();

        // Owner can edit
        if ($artifact->author_id === $user->id) {
            return true;
        }

        // Add team/shared editing logic if needed
        // For now, only owner can edit
        return false;
    }

    /**
     * Queue async conversion of artifact (API endpoint)
     */
    public function queueConversion(\Illuminate\Http\Request $request, Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to convert this artifact.',
            ], 403);
        }

        $validated = $request->validate([
            'output_format' => 'required|in:pdf,docx,odt,latex',
            'template' => 'nullable|string|in:eisvogel,elegant,academic',
        ]);

        // Check for existing pending/processing conversion
        $existing = $artifact->conversions()
            ->where('output_format', $validated['output_format'])
            ->where('template', $validated['template'] ?? null)
            ->whereIn('status', ['pending', 'processing'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Conversion already in progress.',
                'conversion' => $existing,
            ]);
        }

        // Create conversion record
        $conversion = $artifact->conversions()->create([
            'output_format' => $validated['output_format'],
            'template' => $validated['template'] ?? config('pandoc.default_template'),
            'created_by' => auth()->id(),
            'status' => 'pending',
        ]);

        // Dispatch job
        \App\Jobs\ConvertArtifactToPandoc::dispatch($conversion);

        return response()->json([
            'success' => true,
            'message' => 'Conversion queued successfully.',
            'conversion' => $conversion,
        ], 202);
    }

    /**
     * Get conversion status (for polling)
     */
    public function getConversionStatus(\App\Models\ArtifactConversion $conversion)
    {
        // Check authorization
        if ($conversion->created_by !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $downloadUrl = null;
        if ($conversion->status === 'completed' && $conversion->asset_id) {
            // Generate signed URL valid for 15 minutes
            $downloadUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'api.v1.artifacts.conversions.download',
                now()->addMinutes(15),
                ['artifact' => $conversion->artifact_id, 'conversion' => $conversion->id]
            );
        }

        return response()->json([
            'success' => true,
            'conversion' => [
                'id' => $conversion->id,
                'artifact_id' => $conversion->artifact_id,
                'status' => $conversion->status,
                'output_format' => $conversion->output_format,
                'error_message' => $conversion->error_message,
                'download_url' => $downloadUrl,
                'created_at' => $conversion->created_at,
                'completed_at' => $conversion->completed_at,
            ],
        ]);
    }

    public function downloadArtifact(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to download this artifact.');
        }

        $content = $artifact->forDownload();
        $extension = $artifact->getFileExtensionForDownload();
        $mimeType = $artifact->getMimeTypeForDownload();

        $filename = ($artifact->title ? str_replace(['/', '\\'], '_', $artifact->title) : 'artifact').'.'.$extension;

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Content-Length', strlen($content));
    }

    /**
     * Get conversions for an artifact (API endpoint for PWA)
     */
    public function getConversions(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to view this artifact.');
        }

        $conversions = $artifact->conversions()
            ->with('asset')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($conversion) {
                // Generate signed URL valid for 15 minutes
                $downloadUrl = null;
                if ($conversion->asset_id) {
                    $downloadUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'api.v1.artifacts.conversions.download',
                        now()->addMinutes(15),
                        ['artifact' => $conversion->artifact_id, 'conversion' => $conversion->id]
                    );
                }

                return [
                    'id' => $conversion->id,
                    'format' => strtoupper($conversion->output_format),
                    'template' => $conversion->template,
                    'status' => $conversion->status,
                    'created_at' => $conversion->created_at->format('M j, Y g:i A'),
                    'file_size' => $conversion->asset_id ? $conversion->formatted_file_size : null,
                    'download_url' => $downloadUrl,
                    'error_message' => $conversion->error_message,
                ];
            });

        return response()->json([
            'success' => true,
            'conversions' => $conversions,
        ]);
    }

    public function downloadConversion(Artifact $artifact, \App\Models\ArtifactConversion $conversion)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to download this artifact.');
        }

        if ($conversion->artifact_id !== $artifact->id) {
            abort(404, 'Conversion not found for this artifact.');
        }

        if (! $conversion->isCompleted() || ! $conversion->asset) {
            abort(404, 'Conversion not yet completed or file not available.');
        }

        $asset = $conversion->asset;

        if (! \Illuminate\Support\Facades\Storage::exists($asset->storage_key)) {
            abort(404, 'Conversion file not found.');
        }

        return \Illuminate\Support\Facades\Storage::response($asset->storage_key, $asset->original_filename, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$asset->original_filename.'"',
        ]);
    }

    /**
     * Download conversion via API (uses signed URLs)
     *
     * This endpoint uses signed URLs for secure, time-limited access.
     * The 'signed' middleware validates the URL signature, ensuring
     * the URL was generated by our server and hasn't expired.
     */
    public function downloadConversionApi(Artifact $artifact, \App\Models\ArtifactConversion $conversion)
    {
        // Signed URLs are pre-authorized - if we reach here, signature is valid
        // Just verify the conversion belongs to the artifact
        if ($conversion->artifact_id !== $artifact->id) {
            abort(404, 'Conversion not found for this artifact.');
        }

        if (! $conversion->isCompleted() || ! $conversion->asset) {
            abort(404, 'Conversion not yet completed or file not available.');
        }

        $asset = $conversion->asset;

        if (! \Illuminate\Support\Facades\Storage::exists($asset->storage_key)) {
            abort(404, 'Conversion file not found.');
        }

        return \Illuminate\Support\Facades\Storage::response($asset->storage_key, $asset->original_filename, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$asset->original_filename.'"',
        ]);
    }

    public function downloadAsPdf(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to download this artifact.');
        }

        return $this->downloadWithPandoc($artifact, 'pdf', 'application/pdf');
    }

    public function downloadAsOdt(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to download this artifact.');
        }

        return $this->downloadWithPandoc($artifact, 'odt', 'application/vnd.oasis.opendocument.text');
    }

    public function downloadAsLatex(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to download this artifact.');
        }

        return $this->downloadWithPandoc($artifact, 'latex', 'application/x-latex');
    }

    public function downloadAsDocx(Artifact $artifact)
    {
        if (! $this->canViewArtifact($artifact)) {
            abort(403, 'You do not have permission to download this artifact.');
        }

        if (config('services.pandoc.enabled') && $this->isPandocAvailable()) {
            return $this->downloadWithPandoc($artifact, 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }

        return $this->downloadAsDocxLegacy($artifact);
    }

    protected function getUserPdfPreferences(): array
    {
        $user = auth()->user();
        $preferences = $user->preferences ?? [];

        return $preferences['pdf_export'] ?? [];
    }

    protected function getTemplateForExport(): string
    {
        $urlTemplate = request()->query('template');
        $userPrefs = $this->getUserPdfPreferences();
        $appDefault = config('pandoc.default_template');

        $template = $urlTemplate ?? $userPrefs['default_template'] ?? $appDefault;

        $availableTemplates = array_keys(config('pandoc.templates'));
        if (! in_array($template, $availableTemplates)) {
            $template = $appDefault;
        }

        return $template;
    }

    protected function downloadWithPandoc(Artifact $artifact, string $format, string $mimeType)
    {
        try {
            $conversionService = app(PandocConversionService::class);

            // Extract asset references from ORIGINAL content BEFORE URL resolution
            $assets = $conversionService->extractAssetReferences($artifact->content ?? '');

            // Prepare content (resolves URLs to presigned S3, preprocesses mermaid, etc.)
            $content = $conversionService->prepareContentForPandoc($artifact);

            $userPrefs = $this->getUserPdfPreferences();

            $requestData = [
                'content' => $content,
                'output_format' => $format,
                'template' => $this->getTemplateForExport(),
                'title' => $artifact->title ?? 'Untitled Artifact',
                'author' => $artifact->author->name ?? 'PromptlyAgent',
            ];

            // Add external URLs that need downloading
            // Note: Content already has presigned S3 URLs after prepareContentForPandoc
            if (! empty($assets['external_urls'])) {
                $requestData['assets'] = json_encode(['urls' => $assets['external_urls']]);
            }

            if (isset($userPrefs['fonts']) && ! empty($userPrefs['fonts'])) {
                $requestData['fonts'] = json_encode($userPrefs['fonts']);
            }

            if (isset($userPrefs['colors']) && ! empty($userPrefs['colors'])) {
                $requestData['colors'] = json_encode($userPrefs['colors']);
            }

            $response = Http::timeout(config('services.pandoc.timeout'))
                ->retry(config('services.pandoc.retry_times'), config('services.pandoc.retry_delay'))
                ->asForm()
                ->post(config('services.pandoc.url').'/convert', $requestData);

            if ($response->failed()) {
                \Log::error('Pandoc conversion failed', [
                    'artifact_id' => $artifact->id,
                    'format' => $format,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                abort(500, 'Failed to generate document. Please try again.');
            }

            $filename = ($artifact->title ? str_replace(['/', '\\'], '_', $artifact->title) : 'artifact').'.'.$format;

            return response($response->body())
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
                ->header('Content-Length', strlen($response->body()));

        } catch (\Exception $e) {
            \Log::error('Pandoc conversion exception', [
                'artifact_id' => $artifact->id,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Failed to generate document: '.$e->getMessage());
        }
    }

    protected function isPandocAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get(config('services.pandoc.url').'/health');

            return $response->successful();
        } catch (\Exception $e) {
            \Log::warning('Pandoc service unavailable', ['error' => $e->getMessage()]);

            return false;
        }
    }

    protected function downloadAsDocxLegacy(Artifact $artifact)
    {
        try {
            $phpWord = new PhpWord;

            // Set document properties
            $properties = $phpWord->getDocInfo();
            $properties->setTitle($artifact->title ?? 'Untitled Artifact');
            if ($artifact->description) {
                $properties->setSubject($artifact->description);
            }
            $properties->setCreator($artifact->author->name ?? 'PromptlyAgent');
            $properties->setCreated($artifact->created_at->timestamp);
            $properties->setModified($artifact->updated_at->timestamp);

            // Add a section
            $section = $phpWord->addSection();

            // Add title
            if ($artifact->title) {
                $section->addTitle($artifact->title, 1);
                $section->addTextBreak(1);
            }

            // Add description if present
            if ($artifact->description) {
                $section->addText($artifact->description, ['italic' => true, 'color' => '666666']);
                $section->addTextBreak(1);
            }

            // Add metadata
            $section->addText('Created: '.$artifact->created_at->format('F j, Y, g:i a'), ['size' => 9, 'color' => '999999']);
            $section->addText('Type: '.strtoupper($artifact->filetype ?? 'text'), ['size' => 9, 'color' => '999999']);
            if ($artifact->word_count) {
                $section->addText('Word Count: '.number_format($artifact->word_count), ['size' => 9, 'color' => '999999']);
            }
            $section->addTextBreak(2);

            // Add content
            $content = $artifact->content ?? '';

            // Simple processing for code blocks and paragraphs
            $lines = explode("\n", $content);
            $inCodeBlock = false;
            $codeContent = '';

            foreach ($lines as $line) {
                // Check for code block markers
                if (str_starts_with(trim($line), '```')) {
                    if ($inCodeBlock) {
                        // End of code block
                        if ($codeContent) {
                            $section->addText(htmlspecialchars($codeContent), [
                                'name' => 'Courier New',
                                'size' => 9,
                            ]);
                        }
                        $section->addTextBreak(1);
                        $codeContent = '';
                        $inCodeBlock = false;
                    } else {
                        // Start of code block
                        $inCodeBlock = true;
                    }

                    continue;
                }

                if ($inCodeBlock) {
                    $codeContent .= $line."\n";
                } else {
                    // Regular text - handle markdown-style headers
                    if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                        $level = strlen($matches[1]);
                        $text = $matches[2];
                        $section->addTitle($text, $level);
                    } elseif (trim($line) === '') {
                        $section->addTextBreak(1);
                    } else {
                        $section->addText(htmlspecialchars($line));
                    }
                }
            }

            // Handle remaining code block content if file didn't close properly
            if ($inCodeBlock && $codeContent) {
                $section->addText(htmlspecialchars($codeContent), [
                    'name' => 'Courier New',
                    'size' => 9,
                ]);
            }

            // Generate filename
            $filename = ($artifact->title ? str_replace(['/', '\\'], '_', $artifact->title) : 'artifact').'.docx';

            // Create temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'artifact_');

            // Save to temp file
            $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save($tempFile);

            // Read and return the file
            $content = file_get_contents($tempFile);
            unlink($tempFile);

            return response($content)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
                ->header('Content-Length', strlen($content));

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to generate DOCX for artifact', [
                'artifact_id' => $artifact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            abort(500, 'Failed to generate DOCX file: '.$e->getMessage());
        }
    }

    protected function canViewArtifact(Artifact $artifact): bool
    {
        // For signed URL routes, user may be null but request is validated by signature
        // For authenticated routes, check user permissions
        $user = Auth::user();

        // Public artifacts can be accessed by anyone (even with just signed URL)
        if ($artifact->privacy_level === 'public') {
            return true;
        }

        // Private artifacts require authentication
        if (! $user) {
            return false;
        }

        if ($user->is_admin ?? false) {
            return true;
        }

        if ($artifact->author_id === $user->id) {
            return true;
        }

        return false;
    }

    public function download(Document $document)
    {
        if (! $this->canViewDocument($document)) {
            abort(403, 'You do not have permission to download this document.');
        }

        if ($document->asset) {
            return $this->downloadAsset($document);
        }

        return $this->downloadTextDocument($document);
    }

    protected function downloadAsset(Document $document)
    {
        $asset = $document->asset;
        $assetService = app(AssetService::class);

        try {
            $content = $assetService->retrieve($asset);

            return response($content)
                ->header('Content-Type', $asset->mime_type ?: 'application/octet-stream')
                ->header('Content-Disposition', 'attachment; filename="'.$asset->original_filename.'"')
                ->header('Content-Length', $asset->size_bytes);

        } catch (\Exception $e) {
            abort(404, 'The file could not be found.');
        }
    }

    protected function downloadTextDocument(Document $document)
    {
        $content = $document->content ?? '';
        $filename = ($document->title ? str_replace(['/', '\\'], '_', $document->title) : 'document').'.md';

        $metadata = "---\n";
        $metadata .= 'title: '.($document->title ?? 'Untitled Document')."\n";
        $metadata .= 'author: '.($document->author->name ?? 'Unknown')."\n";
        $metadata .= 'created: '.$document->created_at->toISOString()."\n";
        $metadata .= 'updated: '.$document->updated_at->toISOString()."\n";
        $metadata .= 'status: '.$document->status."\n";
        $metadata .= 'privacy: '.$document->privacy_level."\n";

        if ($document->description) {
            $metadata .= 'description: '.$document->description."\n";
        }

        if ($document->tags->count() > 0) {
            $metadata .= "tags:\n";
            foreach ($document->tags as $tag) {
                $metadata .= '  - '.$tag->name."\n";
            }
        }

        $metadata .= "---\n\n";

        $fullContent = $metadata.$content;

        return response($fullContent)
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Content-Length', strlen($fullContent));
    }

    protected function canViewDocument(Document $document): bool
    {
        $user = Auth::user();

        if ($user->is_admin ?? false) {
            return true;
        }

        if ($document->author_id === $user->id) {
            return true;
        }

        if ($document->privacy_level === 'public') {
            return true;
        }

        return false;
    }
}
