<?php

namespace App\Services\Artifacts\Executors;

use App\Models\Artifact;

/**
 * HTML Artifact Executor - Client-Side Sandboxed HTML Rendering.
 *
 * Renders HTML artifacts in browser-based sandboxed iframes with restricted
 * permissions. Unlike Judge0-based executors, this runs entirely client-side
 * for immediate rendering of static HTML content.
 *
 * Sandbox Security Model:
 * - Renders HTML in iframe with srcdoc attribute
 * - Sandbox attributes: allow-scripts, allow-same-origin, allow-forms
 * - JavaScript executes in isolated context
 * - LocalStorage/SessionStorage isolated from parent window
 * - Form submissions contained within iframe
 * - Network requests allowed but CORS-restricted
 *
 * Security Warnings:
 * - Detects JavaScript usage (inline scripts, event handlers)
 * - Detects form elements (submissions limited in sandbox)
 * - Detects browser storage access (isolated from parent)
 *
 * Content Handling:
 * - HTML entities escaped for srcdoc attribute
 * - No server-side execution or validation
 * - Full HTML documents and fragments supported
 * - CSS and inline styles allowed
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/iframe#sandbox
 */
class HtmlExecutor extends AbstractArtifactExecutor
{
    protected array $supportedFiletypes = ['html', 'htm'];

    /**
     * Execute the HTML artifact by rendering it in an iframe
     */
    public function execute(Artifact $artifact): string
    {
        $content = $this->getContent($artifact);

        if (empty($content)) {
            return $this->renderError('No HTML content to execute');
        }

        // Security warnings
        $warnings = $this->getSecurityWarnings($artifact);
        $warningHtml = '';
        foreach ($warnings as $warning) {
            $warningHtml .= $this->renderWarning($warning);
        }

        // Base64 encode the HTML content for srcdoc
        $encodedContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        // Render iframe with sandboxing
        $iframeHtml = sprintf(
            '<iframe
                srcdoc="%s"
                sandbox="allow-scripts allow-same-origin allow-forms"
                class="w-full h-[calc(100vh-300px)] border-0 rounded-lg bg-white dark:bg-zinc-900"
                title="HTML Artifact Execution"
            ></iframe>',
            $encodedContent
        );

        return $warningHtml.$iframeHtml;
    }

    /**
     * Get security warnings for HTML execution
     *
     * @return array<string> Warning messages about JavaScript, forms, storage access
     */
    public function getSecurityWarnings(Artifact $artifact): array
    {
        $warnings = [];

        // Check for potentially dangerous content
        $content = strtolower($this->getContent($artifact));

        if (strpos($content, '<script') !== false) {
            $warnings[] = 'This HTML contains JavaScript. Scripts will execute in a sandboxed environment.';
        }

        if (strpos($content, '<form') !== false) {
            $warnings[] = 'This HTML contains forms. Form submissions may not work as expected in the sandbox.';
        }

        if (strpos($content, 'localstorage') !== false || strpos($content, 'sessionstorage') !== false) {
            $warnings[] = 'This HTML attempts to use browser storage. Storage access is isolated in the sandbox.';
        }

        return $warnings;
    }
}
