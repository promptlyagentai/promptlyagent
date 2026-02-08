{{--
    Artifact Drawer Component

    Purpose: Full-featured slide-out drawer for viewing, editing, and managing artifacts

    Features:
    - Three modes: Preview, Edit, Execute
    - Version history with restoration
    - Expand/collapse drawer width
    - Syntax highlighting for code
    - Markdown rendering
    - CSV table view
    - Soft wrap toggle
    - Integration sync (Notion, etc.)
    - Download and delete actions
    - Unsaved changes detection

    Modes:
    - Preview: View rendered content (markdown/CSV) or highlighted code
    - Edit: Textarea editor with save/cancel (disabled for old versions)
    - Execute: Run executable artifacts (if supported)

    Version Management:
    - Dropdown selector for version history
    - View any previous version
    - Restore version to current
    - Version count display

    Integration Features:
    - List of synced integrations
    - Auto-sync toggle per integration
    - Save to new integration with page selector
    - Update existing synced integrations
    - External URL links

    Drawer Width:
    - Collapsed: 420px-560px (responsive)
    - Expanded: 70vw
    - Toggle button with expand/collapse icons

    Syntax Highlighting:
    - Uses highlight.js
    - Language detection from filetype
    - JavaScript-based for performance

    Alpine.js Functions:
    - hasChanges(): Detects unsaved edits
    - confirmClose(): Prompts if unsaved changes
    - confirmSwitchMode(): Mode switch with confirmation
    - toggleExpanded(): Expands/collapses drawer
    - applyHighlighting(): Applies syntax highlighting

    Livewire Methods:
    - openPreview/Edit/Execute(): Switch modes
    - saveEdit(): Saves edited content as new version
    - download(): Downloads artifact file
    - delete(): Deletes artifact
    - restoreVersion(): Restores old version
    - toggleSoftWrap(): Toggles code wrapping
    - toggleAutoSync(): Enables/disables auto-sync
    - selectIntegration(): Saves to integration
    - selectParentPage(): Chooses Notion parent page

    Related:
    - artifact-card: Inline trigger for opening drawer
    - Integration sync system
    - Version control system
--}}
<div wire:ignore.self x-data="{
    confirmDelete: @entangle('showDeleteConfirmation'),
    expanded: false,

    hasChanges() {
        if ($wire.mode !== 'edit') return false;
        const editContent = ($wire.editContent || '').trim();
        const originalContent = ($wire.originalContent || '').trim();
        return editContent !== originalContent;
    },

    confirmClose() {
        if (this.hasChanges()) {
            if (confirm('You have unsaved changes. Are you sure you want to close?')) {
                $wire.forceCloseDrawer();
            }
        } else {
            $wire.closeDrawer();
        }
    },

    confirmSwitchMode(mode) {
        if (this.hasChanges()) {
            if (confirm('You have unsaved changes. Switch modes anyway?')) {
                $wire.forceSwitchMode(mode);
            }
        } else {
            $wire.switchMode(mode);
        }
    },

    toggleExpanded() {
        this.expanded = !this.expanded;
    }
}">
    <!-- Backdrop -->
    <div
        x-show="$wire.show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="confirmClose()"
        class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40"
        style="display: none;"
    ></div>

    <!-- Drawer -->
    <div
        x-show="$wire.show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        @click.stop
        :class="expanded ? 'w-[70vw]' : 'w-full sm:w-[420px] md:w-[560px]'"
        class="fixed right-0 top-0 h-full bg-surface shadow-2xl z-50 flex flex-col transition-all duration-300"
        style="display: none;"
    >
        @if($artifact)
            <!-- Sticky Header -->
            <div class="flex-shrink-0 border-b border-default p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        <!-- Expand/Collapse Button -->
                        <button
                            @click="toggleExpanded()"
                            class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface flex-shrink-0"
                            title="Expand/Collapse"
                        >
                            <svg x-show="!expanded" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <!-- Expand icon: arrows pointing outward -->
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                            </svg>
                            <svg x-show="expanded" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;">
                                <!-- Collapse icon: arrows pointing inward -->
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25"/>
                            </svg>
                        </button>

                        <!-- Title Display / Editor -->
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            @if($editingTitle)
                                <!-- Title Editor -->
                                <input
                                    wire:model="titleInput"
                                    wire:keydown.enter="saveTitle"
                                    wire:keydown.escape="cancelEditingTitle"
                                    type="text"
                                    class="text-lg font-semibold bg-surface border border-accent rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-accent flex-1 min-w-0 text-primary"
                                    placeholder="Enter artifact title"
                                    autofocus
                                >
                                <button
                                    wire:click="saveTitle"
                                    class="p-1 text-accent hover:text-accent/80 rounded flex-shrink-0"
                                    title="Save title (Enter)"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </button>
                                <button
                                    wire:click="cancelEditingTitle"
                                    class="p-1 text-secondary hover:text-primary rounded flex-shrink-0"
                                    title="Cancel (Esc)"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            @else
                                <!-- Title Display -->
                                <h2 class="text-lg font-semibold text-primary truncate">
                                    {{ $artifact->id }}: {{ $artifact->title ?: 'Untitled Artifact' }}
                                </h2>
                                <button
                                    wire:click="startEditingTitle"
                                    class="p-1 text-secondary hover:text-primary rounded flex-shrink-0"
                                    title="Edit title"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </button>
                            @endif
                        </div>
                        @if($artifact->filetype)
                            <span class="text-xs px-2 py-1 {{ $artifact->filetype_badge_class }} rounded font-medium flex-shrink-0">
                                {{ strtoupper($artifact->filetype) }}
                            </span>
                        @endif
                    </div>

                    <!-- Close Button -->
                    <button
                        @click="confirmClose()"
                        class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface flex-shrink-0"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Mode Toggle -->
                <div class="flex items-center gap-2">
                    <button
                        @click="confirmSwitchMode('preview')"
                        class="px-4 py-2 text-sm font-medium rounded-lg transition-colors {{ $mode === 'preview' ? 'bg-accent text-accent-foreground' : 'bg-surface text-secondary hover:bg-surface-elevated ' }}"
                    >
                        Preview
                    </button>
                    <button
                        @click="confirmSwitchMode('edit')"
                        class="px-4 py-2 text-sm font-medium rounded-lg transition-colors {{ $mode === 'edit' ? 'bg-accent text-accent-foreground' : 'bg-surface text-secondary hover:bg-surface-elevated ' }}"
                    >
                        Edit
                    </button>
                    @if($artifact->canExecute())
                        <button
                            @click="confirmSwitchMode('execute')"
                            class="px-4 py-2 text-sm font-medium rounded-lg transition-colors {{ $mode === 'execute' ? 'bg-[var(--palette-success-200)] text-[var(--palette-success-900)]' : 'bg-surface text-secondary hover:bg-surface-elevated ' }}"
                        >
                            Execute
                        </button>
                    @endif
                </div>
            </div>

            <!-- Version Navigation -->
            @if(count($versions) > 0)
                <div class="px-4 py-2 bg-surface  border-b border-default">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex flex-col">
                            <span class="text-xs font-medium text-secondary">
                                @if($viewingVersion)
                                    Version {{ $viewingVersion['version'] }}
                                @else
                                    Current Version
                                @endif
                            </span>
                            @if($viewingVersion)
                                <span class="text-xs text-tertiary">{{ \Carbon\Carbon::parse($viewingVersion['created_at'])->diffForHumans() }}</span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if($viewingVersion)
                                <button
                                    wire:click="restoreVersion({{ $viewingVersion['id'] }})"
                                    class="px-3 py-1 text-xs font-medium bg-accent text-accent-foreground rounded hover:bg-accent-hover"
                                    wire:loading.attr="disabled"
                                >
                                    Restore
                                </button>
                            @endif

                            <!-- Version Selector -->
                            <select
                                wire:model.live="currentVersionId"
                                class="text-xs px-2 py-1 bg-surface border border-default  rounded focus:ring-2 focus:ring-accent"
                            >
                                <option value="">Current</option>
                                @foreach($versions as $version)
                                    <option value="{{ $version['id'] }}">
                                        v{{ $version['version'] }} - {{ \Carbon\Carbon::parse($version['created_at'])->format('M d, H:i') }}
                                    </option>
                                @endforeach
                            </select>

                            <span class="text-xs text-tertiary">{{ count($versions) }} {{ Str::plural('version', count($versions)) }}</span>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Content Area (Scrollable) -->
            <div class="flex-1 overflow-y-auto">
                @if($mode === 'preview')
                    <!-- Preview Mode -->
                    <div class="p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-medium text-secondary ">Preview</h3>
                            @if($artifact->filetype !== 'md' && $artifact->filetype !== 'markdown' && $artifact->filetype !== 'csv')
                                <button
                                    wire:click="toggleSoftWrap"
                                    class="text-xs px-2 py-1 bg-surface text-secondary rounded hover:bg-surface-elevated "
                                >
                                    {{ $softWrap ? 'Disable Wrap' : 'Enable Wrap' }}
                                </button>
                            @endif
                        </div>

                        @if($artifact->filetype === 'md' || $artifact->filetype === 'markdown')
                            <!-- Markdown preview without line numbers (rendered HTML) -->
                            <div class="bg-surface rounded-lg border border-default overflow-hidden" wire:key="markdown-preview-{{ $currentVersionId ?? 'current' }}">
                                <div class="p-4">
                                    @if($viewingVersion)
                                        {{-- Render version content directly --}}
                                        <div class="markdown-content markdown-compact" x-data="markdownRenderer()">
                                            <span x-ref="source" class="hidden">{{ $this->getDisplayContent() }}</span>
                                            <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                        </div>
                                    @else
                                        {!! $artifact->render() !!}
                                    @endif
                                </div>
                            </div>
                        @elseif($artifact->filetype === 'csv')
                            <!-- CSV preview as table -->
                            <div class="bg-surface rounded-lg border border-default overflow-hidden" wire:key="csv-preview-{{ $currentVersionId ?? 'current' }}">
                                <div class="p-4">
                                    @if($viewingVersion)
                                        {{-- Render version content using CsvRenderer directly --}}
                                        @php
                                            $csvRenderer = new \App\Services\Artifacts\Renderers\CsvRenderer();
                                            $tempArtifact = new \App\Models\Artifact();
                                            $tempArtifact->content = $this->getDisplayContent();
                                            $tempArtifact->filetype = 'csv';
                                        @endphp
                                        {!! $csvRenderer->render($tempArtifact) !!}
                                    @else
                                        {!! $artifact->render() !!}
                                    @endif
                                </div>
                            </div>
                        @else
                            <!-- Code/text preview with syntax highlighting -->
                            <div class="bg-surface rounded-lg border border-default overflow-hidden"
                                 wire:key="code-preview-{{ $currentVersionId ?? 'current' }}"
                                 x-data="{
                                    applyHighlighting() {
                                        const codeElement = $refs.codeContent;
                                        if (!window.hljs || !codeElement) return;

                                        const language = '{{ $artifact->filetype ?: "plaintext" }}';
                                        const code = codeElement.textContent;

                                        try {
                                            const result = window.hljs.highlight(code, { language, ignoreIllegals: true });
                                            codeElement.innerHTML = result.value;
                                            codeElement.classList.add('hljs');
                                        } catch (error) {
                                            console.warn('Syntax highlighting failed:', error);
                                        }
                                    }
                                 }"
                                 x-init="$nextTick(() => applyHighlighting())"
                                 @soft-wrap-toggled.window="$nextTick(() => applyHighlighting())"
                            >
                                <div x-ref="codeContent" class="p-4 font-mono text-sm overflow-x-auto" :class="$wire.softWrap ? 'whitespace-pre-wrap' : 'whitespace-pre'" style="line-height: 1.5;">{{ $this->getDisplayContent() }}</div>
                            </div>
                        @endif
                    </div>
                @elseif($mode === 'execute')
                    <!-- Execute Mode -->
                    <div class="p-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-medium text-secondary ">Execute Output</h3>
                        </div>

                        <div class="overflow-hidden">
                            {!! $artifact->execute() !!}
                        </div>
                    </div>
                @else
                    <!-- Edit Mode -->
                    <div class="p-4">
                        @if($viewingVersion)
                            <div class="p-4 bg-[var(--palette-warning-100)] border border-[var(--palette-warning-200)] rounded-lg text-center">
                                <p class="text-sm text-[var(--palette-warning-800)] mb-2">Cannot edit old versions</p>
                                <p class="text-xs text-[var(--palette-warning-700)]">Switch to current version to edit, or restore this version first</p>
                            </div>
                        @else
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-sm font-medium text-secondary ">Edit Content</h3>
                                <div class="flex items-center gap-2">
                                    <button
                                        wire:click="cancelEdit"
                                        class="px-3 py-1 text-xs font-medium bg-surface text-secondary rounded hover:bg-surface-elevated"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        wire:click="saveEdit"
                                        class="px-3 py-1 text-xs font-medium bg-accent text-accent-foreground rounded hover:bg-accent-hover"
                                    >
                                        Save Changes
                                    </button>
                                </div>
                            </div>

                            <textarea
                                wire:model.defer="editContent"
                                class="w-full h-[calc(100vh-240px)] p-4 font-mono text-sm bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-accent resize-none text-primary"
                                placeholder="Enter content..."
                            ></textarea>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Footer Actions -->
            <div class="flex-shrink-0 border-t border-default p-4">
                <!-- Existing Integrations and Knowledge References -->
                @if(count($artifactIntegrations) > 0 || count($knowledgeReferences) > 0)
                    <div class="mb-4 pb-4 border-b border-default">
                        <h4 class="text-xs font-medium text-secondary mb-2">Saved In:</h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach($artifactIntegrations as $integration)
                                <div class="inline-flex items-center gap-2 px-3 py-1 bg-accent/10 border border-accent rounded-lg">
                                    <span class="text-xs font-medium text-accent">
                                        {{ $integration['provider_name'] }}
                                    </span>
                                    @if($integration['external_url'])
                                        <a href="{{ $integration['external_url'] }}" target="_blank" class="text-accent hover:text-accent-hover">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                            </svg>
                                        </a>
                                    @endif
                                    <button
                                        wire:click="toggleAutoSync({{ $integration['id'] }})"
                                        class="text-xs {{ $integration['auto_sync_enabled'] ? 'text-success' : 'text-tertiary' }}"
                                        title="{{ $integration['auto_sync_enabled'] ? 'Auto-sync enabled' : 'Auto-sync disabled' }}"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                    </button>
                                </div>
                            @endforeach

                            @foreach($knowledgeReferences as $doc)
                                <a
                                    href="{{ route('dashboard.knowledge', ['document' => $doc['id']]) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-2 px-3 py-1 bg-accent/10 border border-accent rounded-lg hover:bg-accent/20 transition-colors"
                                    title="Saved {{ $doc['created_at'] }}"
                                >
                                    <span class="text-xs font-medium text-accent">
                                        Knowledge
                                    </span>
                                    <svg class="w-3 h-3 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Pandoc Conversions -->
                @if(count($conversions) > 0)
                    <div class="mb-4 pb-4 border-b border-default">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-medium text-secondary">Conversions:</h4>
                            <button
                                wire:click="refreshConversions"
                                class="text-xs text-accent hover:text-accent-hover"
                                title="Refresh conversions"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                        </div>
                        <div class="space-y-2">
                            @foreach($conversions as $conversion)
                                <div class="flex items-center justify-between gap-2 px-3 py-2 bg-surface rounded-lg">
                                    <div class="flex items-center gap-2 flex-1 min-w-0">
                                        <div class="flex-shrink-0">
                                            @if($conversion['status'] === 'completed')
                                                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            @elseif($conversion['status'] === 'failed')
                                                <svg class="w-4 h-4 text-error" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            @elseif($conversion['status'] === 'processing')
                                                <svg class="w-4 h-4 text-warning animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            @endif
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-xs font-medium text-primary">
                                                {{ $conversion['format'] }} - {{ $conversion['template'] }}
                                            </div>
                                            <div class="text-xs text-tertiary truncate">
                                                {{ $conversion['created_at'] }}
                                                @if($conversion['status'] === 'completed')
                                                    · {{ $conversion['file_size'] }}
                                                @elseif($conversion['status'] === 'failed' && $conversion['error'])
                                                    · Error: {{ Str::limit($conversion['error'], 50) }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    @if($conversion['status'] === 'completed' && $conversion['download_url'])
                                        <a
                                            href="{{ $conversion['download_url'] }}"
                                            class="flex-shrink-0 text-accent hover:text-accent-hover"
                                            title="Download"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <button
                            wire:click="download"
                            class="px-4 py-2 text-sm font-medium bg-surface text-secondary rounded-lg hover:bg-surface-elevated  transition-colors"
                        >
                            Download
                        </button>
                        <button
                            wire:click="openIntegrationSelector"
                            class="px-4 py-2 text-sm font-medium bg-accent/20 text-accent rounded-lg hover:bg-accent/30 transition-colors"
                        >
                            Save As...
                        </button>
                    </div>
                    <button
                        wire:click="confirmDelete"
                        class="px-4 py-2 text-sm font-medium bg-[var(--palette-error-200)] text-[var(--palette-error-900)] rounded-lg hover:bg-[var(--palette-error-300)] transition-colors"
                    >
                        Delete Artifact
                    </button>
                </div>
            </div>
        @endif
    </div>

    <!-- Delete Confirmation Modal -->
    <div
        x-show="confirmDelete"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center"
        style="display: none;"
    >
        <div
            @click.stop
            class="bg-surface rounded-xl shadow-2xl p-6 max-w-md mx-4"
        >
            <h3 class="text-lg font-semibold text-primary mb-3">
                Delete Artifact?
            </h3>
            <p class="text-secondary mb-6">
                Are you sure you want to delete this artifact? This action cannot be undone.
            </p>
            <div class="flex items-center justify-end gap-3">
                <button
                    wire:click="cancelDelete"
                    class="px-4 py-2 text-sm font-medium bg-surface text-secondary rounded-lg hover:bg-surface-elevated "
                >
                    Cancel
                </button>
                <button
                    wire:click="delete"
                    class="px-4 py-2 text-sm font-medium bg-[var(--palette-error-700)] text-white rounded-lg hover:bg-[var(--palette-error-800)]"
                >
                    Delete
                </button>
            </div>
        </div>
    </div>

    <!-- Integration Selector Modal -->
    @if($showIntegrationSelector)
        <div
            x-show="$wire.showIntegrationSelector"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="fixed inset-0 bg-black/70 backdrop-blur-sm z-[60] flex items-center justify-center"
        >
            <div
                @click.stop
                class="bg-surface rounded-xl shadow-2xl p-6 max-w-lg w-full mx-4 max-h-[80vh] overflow-y-auto"
            >
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-primary">
                        @if($hasAnySyncedIntegration)
                            Save or Update Integration
                        @else
                            Save Artifact To Integration
                        @endif
                    </h3>
                    <button
                        wire:click="closeIntegrationSelector"
                        class="p-1 text-secondary hover:text-primary"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @if($showPageSelector)
                    <!-- Page Selector -->
                    <div class="space-y-4">
                        <div>
                            <button
                                wire:click="closePageSelector"
                                class="text-sm text-accent hover:underline mb-3"
                            >
                                ← Back to integrations
                            </button>
                            <h4 class="text-sm font-medium text-secondary  mb-2">
                                Select Parent Page
                            </h4>

                            @if($selectedParentPageId && $selectedParentPageTitle)
                                <div class="mb-3 px-3 py-2 bg-accent/10 border border-accent rounded-lg">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-xs text-accent font-medium">Default parent selected:</p>
                                            <p class="text-sm text-accent">{{ $selectedParentPageTitle }}</p>
                                        </div>
                                        <button
                                            wire:click="$set('selectedParentPageId', null); $set('selectedParentPageTitle', null)"
                                            class="text-accent hover:text-accent-hover"
                                            title="Clear selection"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            @endif

                            <input
                                type="text"
                                wire:model.live.debounce.500ms="pageSearchQuery"
                                placeholder="Search pages..."
                                class="w-full px-3 py-2 text-sm border border-default  rounded-lg bg-surface  text-primary"
                            />
                        </div>

                        @if($isSearchingPages)
                            <div class="text-center py-4">
                                <svg class="inline-block w-6 h-6 animate-spin text-accent" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                        @else
                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                @forelse($pageSearchResults as $page)
                                    <button
                                        wire:click="selectParentPage('{{ $page['id'] }}', '{{ addslashes($page['title']) }}')"
                                        class="w-full text-left px-3 py-2 text-sm rounded-lg transition-colors {{ $selectedParentPageId === $page['id'] ? 'bg-accent/20 border-2 border-accent' : 'bg-surface border border-default hover:bg-surface ' }}"
                                    >
                                        <div class="font-medium text-primary">{{ $page['title'] }}</div>
                                    </button>
                                @empty
                                    <p class="text-sm text-tertiary  text-center py-4">
                                        No pages found
                                    </p>
                                @endforelse
                            </div>
                        @endif

                        @if($selectedParentPageId)
                            <div class="pt-4 border-t border-default">
                                <button
                                    wire:click="confirmSaveToIntegration"
                                    @disabled($isSyncingToIntegration)
                                    class="w-full px-4 py-2 text-sm font-medium bg-accent text-accent-foreground rounded-lg hover:bg-accent-hover disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    @if($isSyncingToIntegration)
                                        <span>Saving...</span>
                                    @else
                                        <span>Save as {{ $selectedParentPageTitle }}</span>
                                    @endif
                                </button>
                            </div>
                        @endif
                    </div>
                @else
                    <!-- Integration List -->
                    <div class="space-y-2">
                        <p class="text-sm text-secondary mb-3">
                            @if(collect($availableIntegrations)->contains('is_synced', true))
                                Save as a new integration or update an existing one:
                            @else
                                Choose where to save this artifact:
                            @endif
                        </p>

                        <!-- Export Options -->
                        <div class="space-y-2 pb-3 border-b border-default">
                            <button
                                wire:click="saveAsKnowledge"
                                class="w-full flex items-center gap-3 px-4 py-3 text-sm bg-accent/10 border border-accent/30 rounded-lg hover:bg-accent/20 transition-colors"
                            >
                                <svg class="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                                <div class="text-left flex-1">
                                    <div class="font-medium text-primary">Save as Knowledge</div>
                                    <div class="text-xs text-secondary">Add to knowledge base for future reference</div>
                                </div>
                            </button>

                            <div class="w-full flex items-center gap-3 px-4 py-3 text-sm bg-accent/10 border border-accent/30 rounded-lg hover:bg-accent/20 transition-colors">
                                <svg class="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                </svg>
                                <div
                                    wire:click="exportPdf"
                                    class="text-left flex-1 cursor-pointer"
                                >
                                    <div class="font-medium text-primary">Export as PDF</div>
                                    <div class="text-xs text-secondary">High-quality PDF with LaTeX</div>
                                </div>
                                <!-- Settings Icon (Right Side Inside Box) -->
                                <button
                                    wire:click="toggleTemplateSelector"
                                    class="p-2 text-accent/60 hover:text-accent rounded transition-colors flex-shrink-0"
                                    title="PDF template settings"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                </button>
                            </div>

                            <!-- PDF Template Selector (Collapsible) -->
                            @if($showTemplateSelector)
                                <div class="px-4 py-3 bg-surface border border-default rounded-lg space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-primary mb-2">
                                            PDF Template
                                        </label>
                                        <select
                                            wire:model.live="selectedTemplate"
                                            wire:change="updateSelectedTemplate"
                                            class="w-full px-3 py-2 bg-background border border-default rounded-lg text-sm text-primary focus:outline-none focus:ring-2 focus:ring-accent focus:border-accent"
                                            style="color-scheme: dark;"
                                        >
                                            @foreach(config('pandoc.templates') as $templateKey => $templateData)
                                                <option value="{{ $templateKey }}" class="bg-surface text-primary">
                                                    {{ $templateData['name'] }} - {{ $templateData['description'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <p class="text-xs text-secondary mt-1">
                                            Template for this artifact's PDF exports
                                        </p>
                                    </div>

                                    <!-- Queue Conversion Toggle -->
                                    <div class="flex items-start gap-2 pt-2 border-t border-default">
                                        <input
                                            type="checkbox"
                                            id="queuePdfConversion"
                                            wire:model="queuePdfConversion"
                                            class="mt-0.5 w-4 h-4 text-accent bg-background border-default rounded focus:ring-2 focus:ring-accent"
                                        />
                                        <label for="queuePdfConversion" class="flex-1 cursor-pointer">
                                            <div class="text-sm font-medium text-primary">Queue for background processing</div>
                                            <div class="text-xs text-secondary">
                                                Recommended for large documents. Avoids timeouts and allows you to continue working.
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            @endif

                            <button
                                wire:click="saveAsDocx"
                                class="w-full flex items-center gap-3 px-4 py-3 text-sm bg-accent/10 border border-accent/30 rounded-lg hover:bg-accent/20 transition-colors"
                            >
                                <svg class="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <div class="text-left flex-1">
                                    <div class="font-medium text-primary">Export as DOCX</div>
                                    <div class="text-xs text-secondary">Microsoft Word document</div>
                                </div>
                            </button>

                            <button
                                onclick="window.location.href='{{ route('artifacts.download-odt', $artifact->id) }}'"
                                class="w-full flex items-center gap-3 px-4 py-3 text-sm bg-accent/10 border border-accent/30 rounded-lg hover:bg-accent/20 transition-colors"
                            >
                                <svg class="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <div class="text-left flex-1">
                                    <div class="font-medium text-primary">Export as ODT</div>
                                    <div class="text-xs text-secondary">LibreOffice/OpenOffice format</div>
                                </div>
                            </button>

                            <button
                                onclick="window.location.href='{{ route('artifacts.download-latex', $artifact->id) }}'"
                                class="w-full flex items-center gap-3 px-4 py-3 text-sm bg-accent/10 border border-accent/30 rounded-lg hover:bg-accent/20 transition-colors"
                            >
                                <svg class="w-5 h-5 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                </svg>
                                <div class="text-left flex-1">
                                    <div class="font-medium text-primary">Export as LaTeX</div>
                                    <div class="text-xs text-secondary">Raw LaTeX source code</div>
                                </div>
                            </button>
                        </div>

                        @forelse($availableIntegrations as $integration)
                            <button
                                wire:click="selectIntegration('{{ $integration['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="selectIntegration('{{ $integration['id'] }}')"
                                class="w-full flex items-center justify-between px-4 py-3 text-sm bg-surface border border-default rounded-lg hover:bg-surface  transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <span class="font-medium text-primary">
                                        {{ $integration['provider_name'] }}
                                    </span>

                                    @if($integration['is_synced'])
                                        <!-- Show linked page for synced integrations -->
                                        <span class="text-tertiary ">→</span>
                                        <span class="text-sm text-secondary truncate">
                                            {{ $integration['parent_title'] }}
                                        </span>
                                        @if($integration['external_url'])
                                            <a
                                                href="{{ $integration['external_url'] }}"
                                                target="_blank"
                                                class="text-accent hover:text-accent-hover flex-shrink-0"
                                                onclick="event.stopPropagation()"
                                            >
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                </svg>
                                            </a>
                                        @endif
                                        @if($integration['auto_sync_enabled'])
                                            <span class="text-xs px-1.5 py-0.5 bg-[var(--palette-success-200)] text-[var(--palette-success-900)] rounded" title="Auto-sync enabled">
                                                Auto
                                            </span>
                                        @endif
                                    @endif
                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <!-- Loading spinner -->
                                    <svg wire:loading wire:target="selectIntegration('{{ $integration['id'] }}')" class="animate-spin h-4 w-4 text-accent" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>

                                    <!-- Icon (hidden when loading) -->
                                    <svg wire:loading.remove wire:target="selectIntegration('{{ $integration['id'] }}')" class="w-4 h-4 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        @if($integration['is_synced'])
                                            <!-- Update/sync icon for synced integrations -->
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        @else
                                            <!-- Forward arrow for not-synced integrations -->
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        @endif
                                    </svg>
                                </div>
                            </button>
                        @empty
                            <p class="text-sm text-tertiary  text-center py-4">
                                No integrations available. Please configure an integration first.
                            </p>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
