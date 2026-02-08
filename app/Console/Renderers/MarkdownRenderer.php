<?php

namespace App\Console\Renderers;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;

/**
 * Markdown Renderer for Terminal Output
 *
 * Converts Markdown to Termwind-compatible HTML with syntax highlighting
 */
class MarkdownRenderer
{
    protected CommonMarkConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        $this->converter = new CommonMarkConverter([], $environment);
    }

    /**
     * Render Markdown to Termwind-compatible HTML
     */
    public function render(string $markdown): string
    {
        $html = $this->converter->convert($markdown)->getContent();

        // Transform HTML for better Termwind compatibility
        $html = $this->enhanceForTermwind($html);

        return $html;
    }

    /**
     * Enhance HTML for Termwind rendering
     */
    protected function enhanceForTermwind(string $html): string
    {
        // Headers
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/s', '<div class="text-cyan font-bold mt-1 mb-1">$1</div>', $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/s', '<div class="text-cyan font-bold mt-1 mb-1">$1</div>', $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/s', '<div class="text-cyan font-bold mt-1 mb-1">$1</div>', $html);
        $html = preg_replace('/<h4[^>]*>(.*?)<\/h4>/s', '<div class="text-cyan mt-1 mb-1">$1</div>', $html);
        $html = preg_replace('/<h5[^>]*>(.*?)<\/h5>/s', '<div class="text-white font-bold mt-1 mb-1">$1</div>', $html);
        $html = preg_replace('/<h6[^>]*>(.*?)<\/h6>/s', '<div class="text-gray mt-1 mb-1">$1</div>', $html);

        // Paragraphs
        $html = preg_replace('/<p[^>]*>(.*?)<\/p>/s', '<div class="my-1">$1</div>', $html);

        // Strong/Bold
        $html = preg_replace('/<strong[^>]*>(.*?)<\/strong>/s', '<span class="font-bold">$1</span>', $html);
        $html = preg_replace('/<b[^>]*>(.*?)<\/b>/s', '<span class="font-bold">$1</span>', $html);

        // Emphasis/Italic
        $html = preg_replace('/<em[^>]*>(.*?)<\/em>/s', '<span class="italic">$1</span>', $html);
        $html = preg_replace('/<i[^>]*>(.*?)<\/i>/s', '<span class="italic">$1</span>', $html);

        // Code blocks - Termwind handles <code> with syntax highlighting
        $html = preg_replace('/<pre><code class="language-(\w+)">(.*?)<\/code><\/pre>/s', '<div class="mt-1 mb-1"><code>$2</code></div>', $html);
        $html = preg_replace('/<pre><code>(.*?)<\/code><\/pre>/s', '<div class="mt-1 mb-1"><code>$1</code></div>', $html);

        // Inline code
        $html = preg_replace('/<code>(.*?)<\/code>/', '<span class="text-yellow bg-gray">$1</span>', $html);

        // Lists
        $html = preg_replace('/<ul[^>]*>/', '<div class="ml-2 my-1">', $html);
        $html = preg_replace('/<\/ul>/', '</div>', $html);
        $html = preg_replace('/<ol[^>]*>/', '<div class="ml-2 my-1">', $html);
        $html = preg_replace('/<\/ol>/', '</div>', $html);
        $html = preg_replace('/<li[^>]*>(.*?)<\/li>/s', '<div>• $1</div>', $html);

        // Blockquotes
        $html = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/s', '<div class="ml-2 pl-2 border-l-2 border-gray italic text-gray my-1">$1</div>', $html);

        // Horizontal rules
        $html = preg_replace('/<hr\s*\/?>/i', '<div class="my-1 text-gray">'.str_repeat('─', 80).'</div>', $html);

        // Links - show URL in parentheses for terminal
        $html = preg_replace('/<a href="(.*?)"[^>]*>(.*?)<\/a>/', '<span class="text-blue underline">$2</span> <span class="text-gray">($1)</span>', $html);

        // Tables (basic support)
        $html = preg_replace('/<table[^>]*>/', '<div class="my-1">', $html);
        $html = preg_replace('/<\/table>/', '</div>', $html);
        $html = preg_replace('/<tr[^>]*>/', '<div class="flex">', $html);
        $html = preg_replace('/<\/tr>/', '</div>', $html);
        $html = preg_replace('/<th[^>]*>(.*?)<\/th>/s', '<div class="font-bold px-2">$1</div>', $html);
        $html = preg_replace('/<td[^>]*>(.*?)<\/td>/s', '<div class="px-2">$1</div>', $html);
        $html = str_replace(['<thead>', '</thead>', '<tbody>', '</tbody>'], '', $html);

        return $html;
    }

    /**
     * Render plain text without Markdown processing
     */
    public function renderPlain(string $text): string
    {
        return '<div class="text-white">'.htmlspecialchars($text).'</div>';
    }

    /**
     * Check if text contains Markdown formatting
     */
    public function hasMarkdown(string $text): bool
    {
        // Simple heuristic to detect common Markdown patterns
        return preg_match('/[#*`\[\]_~>-]{2,}|^\s*[-*+]\s+|^\s*\d+\.\s+/m', $text) === 1;
    }
}
