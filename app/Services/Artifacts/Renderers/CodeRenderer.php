<?php

namespace App\Services\Artifacts\Renderers;

use App\Models\Artifact;

/**
 * Code Artifact Renderer - Syntax-Highlighted Source Code Display.
 *
 * Renders source code artifacts with proper MIME types for download.
 * Display rendering uses default <pre><code> wrapper with language
 * classes for client-side syntax highlighting libraries.
 *
 * Supported Languages:
 * - PHP, JavaScript/TypeScript, Python, Java, C/C++/C#
 * - Go, Rust, Ruby, Swift, Kotlin, Scala
 * - HTML, CSS/SCSS, XML, SQL
 * - Shell scripts (sh, bash)
 * - YAML configuration files
 *
 * MIME Type Mapping:
 * - Provides language-specific Content-Type headers for downloads
 * - Falls back to text/plain for unknown languages
 * - Ensures browsers handle code downloads correctly
 *
 * Rendering Strategy:
 * - Uses inherited render() method (wraps in <pre><code>)
 * - Language class enables syntax highlighting via Prism.js or similar
 * - No server-side syntax highlighting (handled client-side)
 *
 * @see \App\Services\Artifacts\Renderers\AbstractArtifactRenderer
 */
class CodeRenderer extends AbstractArtifactRenderer
{
    /**
     * Supported languages with their MIME types
     */
    protected array $languageMimeTypes = [
        'php' => 'text/x-php',
        'js' => 'text/javascript',
        'javascript' => 'text/javascript',
        'ts' => 'text/typescript',
        'typescript' => 'text/typescript',
        'py' => 'text/x-python',
        'python' => 'text/x-python',
        'java' => 'text/x-java',
        'c' => 'text/x-c',
        'cpp' => 'text/x-c++',
        'cs' => 'text/x-csharp',
        'csharp' => 'text/x-csharp',
        'go' => 'text/x-go',
        'rust' => 'text/x-rust',
        'rb' => 'text/x-ruby',
        'ruby' => 'text/x-ruby',
        'swift' => 'text/x-swift',
        'kt' => 'text/x-kotlin',
        'kotlin' => 'text/x-kotlin',
        'scala' => 'text/x-scala',
        'html' => 'text/html',
        'css' => 'text/css',
        'scss' => 'text/x-scss',
        'xml' => 'application/xml',
        'sql' => 'application/sql',
        'sh' => 'text/x-sh',
        'bash' => 'text/x-sh',
        'yaml' => 'text/yaml',
        'yml' => 'text/yaml',
    ];

    /**
     * Get MIME type based on language
     */
    public function getMimeType(Artifact $artifact): string
    {
        $filetype = strtolower($artifact->filetype ?? '');

        return $this->languageMimeTypes[$filetype] ?? 'text/plain; charset=utf-8';
    }
}
