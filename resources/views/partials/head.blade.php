<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $title ?? config('app.name') }}</title>

<!-- PWA Meta Tags -->
@php
    $themeColor = '#6366f1'; // default indigo
    if (auth()->check()) {
        $preferences = auth()->user()->preferences ?? [];
        $customScheme = $preferences['custom_color_scheme'] ?? null;
        if (($customScheme['enabled'] ?? false) && !empty($customScheme['colors'] ?? [])) {
            $themeColor = $customScheme['colors']['accent'] ?? $themeColor;
        }
    }
@endphp
<meta name="theme-color" content="{{ $themeColor }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="PromptlyAgent">

<!-- Icons -->
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon-180x180.png">
<link rel="manifest" href="{{ route('pwa.manifest') }}">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
@fluxAppearance

<!-- File Upload Styles (simple approach) -->
<style>
.file-upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    transition: border-color 0.2s;
}
.file-upload-area:hover {
    border-color: #6366f1;
}
.file-upload-area.dragover {
    border-color: #6366f1;
    background-color: #f0f9ff;
}
/* Mermaid diagram styling (before rendering) */
pre.mermaid {
    padding: 1rem;
    margin: 1rem 0;
    border-radius: 0.5rem;
    overflow-x: auto;
    overflow-y: hidden;
    text-align: center;
    width: 100%;
}
/* Mermaid diagram SVG (after rendering) */
.mermaid-svg-container,
pre.mermaid[data-processed="true"] {
    padding: 1rem;
    margin: 1rem 0;
    overflow-x: auto;
    overflow-y: hidden;
    text-align: center;
    width: 100%;
    display: block;
}
.mermaid-svg-container svg,
pre.mermaid[data-processed="true"] svg {
    max-width: none !important;
    width: auto !important;
    height: auto;
    display: block;
    margin: 0 auto;
}
</style>

<!-- Marked.js Resources for Research Chat and Knowledge Previews -->
<script src="https://cdn.jsdelivr.net/npm/marked@16.1.0/lib/marked.umd.min.js"></script>

<!-- DOMPurify for XSS Protection in Markdown Rendering -->
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.9/dist/purify.min.js" crossorigin="anonymous"></script>

<!-- Mermaid.js for diagram rendering -->
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<script>
// Initialize Mermaid with built-in themes
function initializeMermaid() {
    const isDark = document.documentElement.classList.contains('dark');

    mermaid.initialize({
        startOnLoad: false,
        theme: isDark ? 'dark' : 'default',  // Built-in themes optimized for light/dark
        securityLevel: 'strict'
    });
}

// Initial setup
initializeMermaid();

// Re-initialize on theme change (dark mode toggle)
document.addEventListener('DOMContentLoaded', () => {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.attributeName === 'class') {
                initializeMermaid();

                // Re-render all diagrams with new theme
                document.querySelectorAll('pre.mermaid[data-processed="true"]').forEach(el => {
                    const originalCode = el.getAttribute('data-mermaid-code');
                    if (originalCode) {
                        el.removeAttribute('data-processed');
                        el.textContent = originalCode;
                        mermaid.run({ nodes: [el] });
                    }
                });
            }
        });
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class']
    });
});
</script>

<!-- Secure Markdown Renderer (Alpine.js Component with DOMPurify) -->
<script>
/**
 * Safe markdown renderer with DOMPurify sanitization
 * Used across all Blade templates for XSS protection
 *
 * Security: Prevents XSS attacks by sanitizing markdown-rendered HTML
 * before injecting into DOM via x-html directive.
 *
 * Issue #154: Fixes widespread markdown XSS vulnerability
 */
function markdownRenderer() {
    return {
        renderedHtml: '',
        observer: null,
        isRendering: false,
        isStreaming: false,
        renderTimeout: null,
        streamingCheckInterval: null,
        inputHandler: null,

        init() {
            this.render();

            // Debounced render function to prevent excessive re-renders (increased to 500ms)
            const debouncedRender = () => {
                this.isStreaming = true;
                clearTimeout(this.renderTimeout);
                this.renderTimeout = setTimeout(() => this.render(), 500);
            };

            // Watch for content changes with debounce
            if (this.$refs.source) {
                this.observer = new MutationObserver(debouncedRender);
                this.observer.observe(this.$refs.source, {
                    characterData: true,
                    childList: true,
                    subtree: false,  // Don't watch descendants to avoid sibling mutations
                });
                this.inputHandler = debouncedRender;
                this.$refs.source.addEventListener('input', this.inputHandler);
            }

            window.addEventListener('marked:ready', () => this.render());

            // Check periodically if streaming has stopped (no updates for 2 seconds = complete)
            this.streamingCheckInterval = setInterval(() => {
                if (this.isStreaming) {
                    this.isStreaming = false;
                    this.render();
                }
            }, 2000);
        },

        render() {
            // Guard against re-entry to prevent infinite loops
            if (this.isRendering) {
                console.warn('markdownRenderer: Prevented recursive render call');
                return;
            }

            // Ensure required libraries are loaded
            if (!window.marked || !window.DOMPurify || !this.$refs.source || !this.$refs.target) {
                return;
            }

            this.isRendering = true;

            try {
                // Disconnect observer during render to prevent triggering itself
                if (this.observer) {
                    this.observer.disconnect();
                }

                const raw = this.$refs.source.textContent.trim();

                // Step 1: Extract mermaid blocks before markdown parsing (lightweight approach)
                const mermaidBlocks = [];
                let processedContent = raw;

                // Extract ```mermaid blocks with flexible whitespace handling
                const mermaidRegex = /```mermaid\s*\n([\s\S]*?)\n\s*```/g;
                let match;
                let index = 0;

                while ((match = mermaidRegex.exec(raw)) !== null) {
                    // Use HTML comment format to avoid markdown processing
                    const marker = `<!--MERMAID-DIAGRAM-${index}-->`;
                    mermaidBlocks.push({
                        index: index,
                        code: match[1].trim(),
                        marker: marker,
                        fullMatch: match[0]
                    });
                    processedContent = processedContent.replace(match[0], marker);
                    index++;
                }

                // Step 2: Parse markdown to HTML (without mermaid blocks)
                const dirty = window.marked.parse(processedContent);

                // Step 3: Sanitize HTML with DOMPurify (CRITICAL for XSS protection)
                // Note: target="_blank" and rel attributes are added by marked.js renderer (see markdown-renderer.js)
                let clean = DOMPurify.sanitize(dirty, {
                    ALLOWED_TAGS: [
                        'p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                        'ul', 'ol', 'li', 'a', 'code', 'pre', 'blockquote', 'img', 'table',
                        'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'del', 'span', 'div'
                    ],
                    ALLOWED_ATTR: ['href', 'src', 'alt', 'title', 'class', 'id', 'data-mermaid-code', 'target', 'rel'],
                    ALLOW_DATA_ATTR: false,
                    KEEP_CONTENT: true, // Preserve HTML comments for mermaid markers
                    // Prevent javascript: and data: URLs
                    ALLOWED_URI_REGEXP: /^(?:(?:(?:f|ht)tps?|mailto|tel|callto|sms|cid|xmpp):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i,
                });

                // Step 4: Restore mermaid blocks with proper pre elements
                // HTML comments pass through marked.js and DOMPurify unchanged
                // Store original code in data attribute for theme re-rendering
                mermaidBlocks.forEach(block => {
                    const escapedCode = block.code.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    const mermaidHtml = `<pre class="mermaid" data-mermaid-code="${escapedCode}">${block.code}</pre>`;
                    clean = clean.replace(block.marker, mermaidHtml);
                });

                if (clean !== this.renderedHtml) {
                    // Step 5: Update DOM with sanitized HTML
                    this.$refs.target.innerHTML = clean;
                    this.renderedHtml = clean;

                    // Step 6: Apply Prism.js syntax highlighting (DISABLED FOR DEBUGGING)
                    // Temporarily disabled to rule out conflicts with Mermaid
                    /*
                    if (window.Prism) {
                        try {
                            const codeBlocks = this.$refs.target.querySelectorAll('pre:not(.mermaid) code');
                            codeBlocks.forEach(block => {
                                window.Prism.highlightElement(block);
                            });
                        } catch (error) {
                            console.error('Prism highlighting failed:', error);
                        }
                    }
                    */

                    // Step 7: Render Mermaid diagrams (use nextTick to ensure DOM is ready)
                    if (window.mermaid && mermaidBlocks.length > 0) {
                        requestAnimationFrame(() => {
                            try {
                                const mermaidElements = this.$refs.target.querySelectorAll('pre.mermaid:not([data-processed="true"])');
                                if (mermaidElements.length > 0) {
                                    mermaid.run({
                                        nodes: Array.from(mermaidElements)
                                    });
                                }
                            } catch (error) {
                                console.error('Mermaid rendering failed:', error);
                            }
                        });
                    }

                    // Dispatch scoped Alpine event instead of global event
                    this.$dispatch('markdown-rendered', { content: clean });
                }

                // Reconnect observer after render completes
                if (this.$refs.source && this.observer) {
                    this.observer.observe(this.$refs.source, {
                        characterData: true,
                        childList: true,
                        subtree: false,
                    });
                }
            } catch (error) {
                console.error('Markdown parsing error:', error);

                // SAFE fallback - use textContent (not innerHTML) to prevent XSS
                if (this.$refs.target) {
                    this.$refs.target.textContent = raw;
                }
            } finally {
                this.isRendering = false;
            }
        },

        destroy() {
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
            if (this.$refs.source && this.inputHandler) {
                this.$refs.source.removeEventListener('input', this.inputHandler);
                this.inputHandler = null;
            }
            if (this.renderTimeout) {
                clearTimeout(this.renderTimeout);
                this.renderTimeout = null;
            }
            if (this.streamingCheckInterval) {
                clearInterval(this.streamingCheckInterval);
                this.streamingCheckInterval = null;
            }
        }
    };
}
</script>

<!-- User Custom Color Scheme -->
@auth
    @if(config('app.custom_color_schemes'))
        @php
            $userPreferences = auth()->user()->preferences ?? [];
            $customScheme = $userPreferences['custom_color_scheme'] ?? null;
            $enabled = $customScheme['enabled'] ?? false;
            $colors = $customScheme['colors'] ?? [];
        @endphp

        @if($enabled && !empty($colors))
            {!! \App\Services\ColorSchemeService::generateStyleTag($colors) !!}
        @endif
    @endif
@endauth

@stack('styles')
