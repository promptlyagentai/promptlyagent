<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Auth;

class KnowledgeFileController extends Controller
{
    public function download(KnowledgeDocument $document)
    {
        if (! $this->canViewDocument($document)) {
            abort(403, 'You do not have permission to download this document.');
        }

        if ($document->content_type === 'text') {
            return $this->downloadTextDocument($document);
        }

        if ($document->content_type !== 'file') {
            abort(404, 'This document does not have an associated file.');
        }

        if (! $document->asset || ! $document->asset->exists()) {
            abort(404, 'The file could not be found.');
        }

        $asset = $document->asset;
        $fileContent = $asset->getContent();

        return response($fileContent)
            ->header('Content-Type', $asset->mime_type ?: 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename="'.$asset->original_filename.'"')
            ->header('Content-Length', $asset->size_bytes);
    }

    public function preview(KnowledgeDocument $document)
    {
        if (! $this->canViewDocument($document)) {
            abort(403, 'You do not have permission to preview this document.');
        }

        if ($document->content_type === 'text') {
            return $this->previewTextDocument($document);
        }

        if ($document->content_type === 'external') {
            return $this->previewExternal($document);
        }

        if ($document->content_type !== 'file') {
            abort(404, 'This document does not have an associated file.');
        }

        if (! $document->asset || ! $document->asset->exists()) {
            abort(404, 'The file could not be found.');
        }

        $asset = $document->asset;
        $fileContent = $asset->getContent();

        $previewableTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            'application/pdf',
            'text/plain', 'text/markdown', 'text/html', 'text/css', 'text/javascript',
            'application/json', 'application/xml',
        ];

        $disposition = in_array($asset->mime_type, $previewableTypes) ? 'inline' : 'attachment';

        return response($fileContent)
            ->header('Content-Type', $asset->mime_type ?: 'application/octet-stream')
            ->header('Content-Disposition', $disposition.'; filename="'.$asset->original_filename.'"')
            ->header('Content-Length', $asset->size_bytes);
    }

    protected function downloadTextDocument(KnowledgeDocument $document)
    {
        $content = $document->content ?? '';
        $filename = ($document->title ? str_replace(['/', '\\'], '_', $document->title) : 'text_document').'.md';

        return response($content)
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Content-Length', strlen($content));
    }

    protected function previewTextDocument(KnowledgeDocument $document)
    {
        $content = $document->content ?? '';
        $title = $document->title ?? 'Text Document';

        $html = $this->generateTextPreviewHtml($document, $content, $title);

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function previewExternal(KnowledgeDocument $document)
    {
        $content = $document->content ?? '';
        $title = $document->title ?? 'External Knowledge Document';
        $sourceUrl = $document->external_source_identifier ?? '';

        $html = $this->generateExternalPreviewHtml($document, $content, $title, $sourceUrl);

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function generateExternalPreviewHtml(KnowledgeDocument $document, string $content, string $title, string $sourceUrl): string
    {
        $metadata = $document->external_metadata ?? [];
        $createdAt = $document->created_at->format('M j, Y g:i A');
        $wordCount = $document->word_count ?? str_word_count(strip_tags($content));
        $lastFetched = $document->last_fetched_at ? $document->last_fetched_at->diffForHumans() : 'Never';

        $displayContent = $this->formatContentForDisplay($content);

        $domain = $sourceUrl ? parse_url($sourceUrl, PHP_URL_HOST) : 'Unknown source';
        $favicon = $document->favicon_url ?? '';

        $faviconHtml = $favicon ? "<img src=\"{$favicon}\" alt=\"\">" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: {$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8fafc;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.5rem;
        }
        .metadata {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .metadata img {
            width: 16px;
            height: 16px;
            border-radius: 2px;
        }
        .source-link {
            color: #e2e8f0;
            text-decoration: none;
            border-bottom: 1px solid rgba(255,255,255,0.3);
        }
        .source-link:hover {
            color: white;
            border-bottom-color: white;
        }
        .info-bar {
            background: #f1f5f9;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
            color: #64748b;
        }
        .info-item {
            display: inline-block;
            margin-right: 20px;
        }
        .content {
            padding: 30px;
            line-height: 1.8;
        }
        .content h1, .content h2, .content h3 {
            color: #1e293b;
            margin-top: 2rem;
            margin-bottom: 1rem;
        }
        .content h1 { font-size: 1.8rem; }
        .content h2 { font-size: 1.5rem; }
        .content h3 { font-size: 1.3rem; }
        .content p {
            margin-bottom: 1rem;
        }
        .content pre {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            border-left: 4px solid #667eea;
        }
        .content code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.9rem;
        }
        .content blockquote {
            border-left: 4px solid #667eea;
            margin-left: 0;
            padding-left: 20px;
            color: #64748b;
            font-style: italic;
        }
        .content ul, .content ol {
            margin-bottom: 1rem;
            padding-left: 2rem;
        }
        .content li {
            margin-bottom: 0.5rem;
        }
        .content a {
            color: #667eea;
            text-decoration: none;
        }
        .content a:hover {
            text-decoration: underline;
        }
        .empty-content {
            text-align: center;
            color: #64748b;
            font-style: italic;
            padding: 40px 20px;
        }
        .footer {
            background: #f8fafc;
            padding: 15px 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 0.8rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
            <div class="metadata">
                {$faviconHtml}
                <span>{$domain}</span>
                •
                <a href="{$sourceUrl}" target="_blank" rel="noopener" class="source-link">View Original</a>
            </div>
        </div>
        
        <div class="info-bar">
            <span class="info-item"><strong>Added:</strong> {$createdAt}</span>
            <span class="info-item"><strong>Last Updated:</strong> {$lastFetched}</span>
            <span class="info-item"><strong>Word Count:</strong> {$wordCount}</span>
            <span class="info-item"><strong>Source Type:</strong> {$document->source_type}</span>
        </div>
        
        <div class="content">
            {$displayContent}
        </div>
        
        <div class="footer">
            External knowledge document processed and cached by the knowledge management system.
        </div>
    </div>
    
    <!-- Marked.js for markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const markdownContent = document.getElementById('markdown-content');
            const renderedContent = document.getElementById('rendered-content');
            
            if (markdownContent && renderedContent) {
                const markdown = markdownContent.textContent;
                try {
                    // Configure marked.js options for security and formatting
                    marked.setOptions({
                        breaks: true,          // Convert line breaks to <br>
                        gfm: true,            // GitHub Flavored Markdown
                        sanitize: false,      // We'll handle sanitization server-side if needed
                        smartypants: true,    // Smart quotes and dashes
                        pedantic: false,      // Don't be pedantic about markdown syntax
                        headerIds: false      // Don't add IDs to headers
                    });
                    
                    const html = marked.parse(markdown);
                    renderedContent.innerHTML = html;
                } catch (error) {
                    console.error('Error rendering markdown:', error);
                    renderedContent.innerHTML = '<div class="empty-content">Error rendering markdown content.</div>';
                }
            }
        });
    </script>
</body>
</html>
HTML;
    }

    protected function generateTextPreviewHtml(KnowledgeDocument $document, string $content, string $title): string
    {
        $createdAt = $document->created_at->format('M j, Y g:i A');
        $updatedAt = $document->updated_at->format('M j, Y g:i A');
        $wordCount = $document->word_count ?? str_word_count(strip_tags($content));
        $creatorName = $document->creator->name ?? 'Unknown';
        $privacyLevel = $document->privacy_level;

        $escapedContent = htmlspecialchars($content);

        $tagsHtml = '';
        if ($document->tags->count() > 0) {
            $tagsHtml = '<div class="tags">';
            foreach ($document->tags->take(8) as $tag) {
                $tagName = htmlspecialchars($tag->name);
                $tagsHtml .= "<span class=\"tag\">{$tagName}</span>";
            }
            $tagsHtml .= '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: {$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8fafc;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.5rem;
        }
        .metadata {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .info-bar {
            background: #f1f5f9;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
            color: #64748b;
        }
        .info-item {
            display: inline-block;
            margin-right: 20px;
        }
        .content {
            padding: 30px;
            line-height: 1.8;
        }
        .content h1, .content h2, .content h3 {
            color: #1e293b;
            margin-top: 2rem;
            margin-bottom: 1rem;
        }
        .content h1 { font-size: 1.8rem; }
        .content h2 { font-size: 1.5rem; }
        .content h3 { font-size: 1.3rem; }
        .content p {
            margin-bottom: 1rem;
        }
        .content pre {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            border-left: 4px solid #667eea;
        }
        .content code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 0.9rem;
        }
        .content blockquote {
            border-left: 4px solid #667eea;
            margin-left: 0;
            padding-left: 20px;
            color: #64748b;
            font-style: italic;
        }
        .content ul, .content ol {
            margin-bottom: 1rem;
            padding-left: 2rem;
        }
        .content li {
            margin-bottom: 0.5rem;
        }
        .content a {
            color: #667eea;
            text-decoration: none;
        }
        .content a:hover {
            text-decoration: underline;
        }
        .empty-content {
            text-align: center;
            color: #64748b;
            font-style: italic;
            padding: 40px 20px;
        }
        .footer {
            background: #f8fafc;
            padding: 15px 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 0.8rem;
            color: #64748b;
        }
        .tags {
            margin-top: 10px;
        }
        .tag {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
            <div class="metadata">
                <span>📝 Text Document</span>
                •
                <span>by {$creatorName}</span>
            </div>
            {$tagsHtml}
        </div>
        
        <div class="info-bar">
            <span class="info-item"><strong>Created:</strong> {$createdAt}</span>
            <span class="info-item"><strong>Updated:</strong> {$updatedAt}</span>
            <span class="info-item"><strong>Word Count:</strong> {$wordCount}</span>
            <span class="info-item"><strong>Privacy:</strong> {$privacyLevel}</span>
        </div>
        
        <div class="content">
            <div id="markdown-content" style="display:none;">{$escapedContent}</div>
            <div id="rendered-content">Loading...</div>
        </div>
        
        <div class="footer">
            Text knowledge document from the knowledge management system.
        </div>
    </div>
    
    <!-- Marked.js for markdown rendering -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const markdownContent = document.getElementById('markdown-content');
            const renderedContent = document.getElementById('rendered-content');
            
            if (markdownContent && renderedContent) {
                const markdown = markdownContent.textContent;
                try {
                    // Configure marked.js options for security and formatting
                    marked.setOptions({
                        breaks: true,          // Convert line breaks to <br>
                        gfm: true,            // GitHub Flavored Markdown
                        sanitize: false,      // We'll handle sanitization server-side if needed
                        smartypants: true,    // Smart quotes and dashes
                        pedantic: false,      // Don't be pedantic about markdown syntax
                        headerIds: false      // Don't add IDs to headers
                    });
                    
                    const html = marked.parse(markdown);
                    renderedContent.innerHTML = html;
                } catch (error) {
                    console.error('Error rendering markdown:', error);
                    renderedContent.innerHTML = '<div class="empty-content">Error rendering markdown content.</div>';
                }
            }
        });
    </script>
</body>
</html>
HTML;
    }

    protected function formatContentForDisplay(string $content): string
    {
        if (empty($content)) {
            return '<div class="empty-content">No content available for preview.</div>';
        }

        if ($this->isMarkdown($content)) {
            return $this->convertMarkdownToHtml($content);
        }

        if ($this->isHtml($content)) {
            return $this->sanitizeHtml($content);
        }

        return '<div style="white-space: pre-wrap;">'.e($content).'</div>';
    }

    protected function isMarkdown(string $content): bool
    {
        return preg_match('/^#+\s+/', $content) ||
               str_contains($content, '```') ||
               str_contains($content, '**') ||
               str_contains($content, '##') ||
               preg_match('/\[.+\]\(.+\)/', $content);
    }

    protected function isHtml(string $content): bool
    {
        return str_contains($content, '<') && str_contains($content, '>');
    }

    protected function convertMarkdownToHtml(string $markdown): string
    {
        $escapedMarkdown = htmlspecialchars($markdown);

        return "<div id=\"markdown-content\" style=\"display:none;\">{$escapedMarkdown}</div><div id=\"rendered-content\">Loading...</div>";
    }

    protected function sanitizeHtml(string $html): string
    {
        $allowed_tags = '<p><br><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><ul><ol><li><a><img><pre><code><blockquote>';

        return strip_tags($html, $allowed_tags);
    }

    protected function canViewDocument(KnowledgeDocument $document): bool
    {
        $user = Auth::user();

        if ($user->is_admin ?? false) {
            return true;
        }

        if ($document->created_by === $user->id) {
            return true;
        }

        if ($document->privacy_level === 'public') {
            return true;
        }

        return false;
    }
}
