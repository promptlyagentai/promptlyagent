<div
    class="flex flex-col h-full"
    x-data="{
        showSource: false,
        markdownContent: @js($content),
        renderedHtml: '',

        getSanitizeConfig() {
            return {
                ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'code', 'pre', 'blockquote', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'del', 'span', 'div'],
                ALLOWED_ATTR: ['href', 'src', 'alt', 'title', 'class', 'id'],
                ALLOW_DATA_ATTR: false
            };
        },

        renderMarkdown() {
            if (window.marked && this.markdownContent) {
                try {
                    const rawHtml = window.marked.parse(this.markdownContent);
                    this.renderedHtml = window.DOMPurify ? window.DOMPurify.sanitize(rawHtml, this.getSanitizeConfig()) : rawHtml;
                    setTimeout(() => {
                        if (window.hljs && this.$refs.preview) {
                            this.$refs.preview.querySelectorAll('pre code').forEach(block => {
                                window.hljs.highlightElement(block);
                            });
                        }
                    }, 10);
                } catch (e) {
                    console.error('Markdown rendering error:', e);
                    this.renderedHtml = '<div class=&quot;text-[var(--palette-error-700)] p-4&quot;>Error rendering markdown</div>';
                }
            }
        }
    }"
    x-init="renderMarkdown()"
>
    <!-- Header with Metadata -->
    <div class="bg-surface border-b border-default p-6  ">
        <h2 class="text-2xl font-bold mb-2 text-primary">{{ $document->title }}</h2>

        <div class="flex items-center gap-4 text-sm text-tertiary ">
            @if($document->content_type === 'file' && $document->asset)
                <span class="flex items-center gap-1">
                    <flux:icon.document class="w-4 h-4" />
                    {{ $document->asset->original_filename }}
                </span>
            @elseif($document->content_type === 'external')
                <span class="flex items-center gap-1">
                    <flux:icon.link class="w-4 h-4" />
                    {{ parse_url($document->external_source_identifier, PHP_URL_HOST) ?: 'External Source' }}
                </span>
            @else
                <span class="flex items-center gap-1">
                    <flux:icon.document-text class="w-4 h-4" />
                    @if($document->external_source_identifier)
                        {{ parse_url($document->external_source_identifier, PHP_URL_HOST) ?: 'Text Document' }}
                    @else
                        Text Document
                    @endif
                </span>
            @endif

            <span>•</span>
            <span>{{ $document->word_count ? number_format($document->word_count) . ' words' : 'Unknown length' }}</span>

            @if($document->creator)
                <span>•</span>
                <span>by {{ $document->creator->name }}</span>
            @endif
        </div>

        <!-- Tags -->
        @if($document->tags->count() > 0)
            <div class="flex flex-wrap gap-2 mt-3">
                @foreach($document->tags->take(8) as $tag)
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-surface-elevated text-secondary">
                        {{ $tag->name }}
                    </span>
                @endforeach
                @if($document->tags->count() > 8)
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-surface-elevated text-secondary">
                        +{{ $document->tags->count() - 8 }} more
                    </span>
                @endif
            </div>
        @endif
    </div>

    <!-- Action Bar -->
    <div class="bg-surface border-b border-default px-6 py-3  ">
        <div class="flex items-center justify-between">
            <!-- View Toggle -->
            <div class="flex items-center gap-2">
                <button
                    @click="showSource = false"
                    :class="!showSource ? 'bg-accent text-white' : 'bg-surface text-secondary hover:bg-surface-elevated'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                    <flux:icon.eye class="w-4 h-4 inline mr-1" />
                    Preview
                </button>
                <button
                    @click="showSource = true"
                    :class="showSource ? 'bg-accent text-white' : 'bg-surface text-secondary hover:bg-surface-elevated'"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                    <flux:icon.code-bracket class="w-4 h-4 inline mr-1" />
                    Source
                </button>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-2">
                @php
                    $viewOriginalLinks = $this->getViewOriginalLinks();
                @endphp

                @if(!empty($viewOriginalLinks))
                    @foreach($viewOriginalLinks as $link)
                        <a
                            href="{{ $link['url'] }}"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center px-4 py-2 bg-surface text-secondary rounded-lg text-sm font-medium hover:bg-surface-elevated transition-colors">
                            <flux:icon.arrow-top-right-on-square class="w-4 h-4 mr-1" />
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                @endif

                <a
                    href="{{ $this->getDownloadUrl() }}"
                    class="inline-flex items-center px-4 py-2 bg-surface text-secondary rounded-lg text-sm font-medium hover:bg-surface-elevated transition-colors">
                    <flux:icon.arrow-down-tray class="w-4 h-4 mr-1" />
                    Download
                </a>
            </div>
        </div>
    </div>

    <!-- Content Area -->
    <div class="flex-1 overflow-auto bg-surface ">
        <!-- Preview Mode -->
        <div
            x-show="!showSource"
            x-ref="preview"
            class="markdown p-8"
            x-html="renderedHtml">
        </div>

        <!-- Source Mode -->
        <div x-show="showSource" x-cloak>
            <pre class="p-8 text-sm font-mono leading-relaxed text-primary whitespace-pre-wrap"><code x-text="markdownContent"></code></pre>
        </div>
    </div>

    <!-- Footer -->
    <div class="bg-surface border-t border-default px-6 py-3 text-sm text-tertiary   ">
        <div class="flex items-center justify-between">
            <div>
                <span class="font-medium">Created:</span> {{ $document->created_at->format('M j, Y g:i A') }}
                <span class="mx-2">•</span>
                <span class="font-medium">Updated:</span> {{ $document->updated_at->diffForHumans() }}
            </div>
            <div>
                <span class="font-medium">Privacy:</span>
                <span class="capitalize">{{ $document->privacy_level }}</span>
            </div>
        </div>
    </div>
</div>
