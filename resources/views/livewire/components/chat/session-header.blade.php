@props(['currentSessionId', 'sessions', 'query'])

<!-- Session Header -->
<div class="flex-shrink-0 pb-4">
    <div class="flex items-center justify-between mb-4">
        <!-- Editable Title -->
        <div class="flex items-center gap-3 flex-1 min-w-0"
             x-data="{
                 editing: false,
                 tempTitle: '',
                 originalTitle: ''
             }"
             wire:key="session-title-{{ $currentSessionId }}">
            @php
                $currentSession = $currentSessionId ? $sessions->firstWhere('id', $currentSessionId) : null;
                $currentTitle = $currentSession?->title ?? ($query ?: 'Chat Session');
                $isTriggerSession = $currentSession?->isTriggerInitiated() ?? false;
                $triggerIcon = $isTriggerSession ? ($currentSession->metadata['trigger_icon'] ?? '🔗') : null;
                $triggerSource = $isTriggerSession ? ($currentSession->metadata['trigger_name'] ?? 'Triggered') : null;
            @endphp

            <!-- Display Mode -->
            <div x-show="!editing" class="flex items-center gap-2 cursor-pointer group" @click="editing = true; tempTitle = @js($currentTitle); originalTitle = @js($currentTitle)">
                @if($isTriggerSession)
                    <span class="flex-shrink-0 text-2xl" title="{{ $triggerSource }}">{{ $triggerIcon }}</span>
                @endif
                <h2 class="text-2xl font-semibold truncate group-hover:text-accent transition-colors">{{ $currentTitle }}</h2>
            </div>

            <!-- Edit Mode -->
            <form x-show="editing"
                  @submit.prevent="if(tempTitle.trim()) { $wire.updateSessionTitle(tempTitle.trim(), {{ $currentSessionId ?: 'null' }}); editing = false; }"
                  @keydown.escape="editing = false; tempTitle = originalTitle"
                  class="flex-1">
                <input type="text"
                       x-model="tempTitle"
                       x-ref="titleInput"
                       @blur="if(tempTitle.trim()) { $wire.updateSessionTitle(tempTitle.trim(), {{ $currentSessionId ?: 'null' }}); editing = false; } else { editing = false; tempTitle = originalTitle; }"
                       class="text-2xl font-semibold bg-transparent border-b-2 border-accent focus:outline-none focus:border-accent w-full"
                       placeholder="Session Title">
            </form>

            <!-- Action Icons -->
            <div class="flex items-center gap-1">
                <!-- Edit Icon -->
                <button x-show="!editing"
                        @click="editing = true; tempTitle = @js($currentTitle); originalTitle = @js($currentTitle); setTimeout(() => $refs.titleInput.focus(), 50)"
                        class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface"
                        title="Edit Title">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </button>

                <!-- Refresh Icon -->
                <button wire:click="regenerateTitle({{ $currentSessionId ?: 'null' }})"
                        wire:loading.attr="disabled"
                        {{ $currentSessionId ? '' : 'disabled' }}
                        class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface disabled:opacity-50"
                        title="Regenerate Title with AI">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                         wire:loading.class="animate-spin">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Right Side Actions -->
        <div class="flex items-center gap-2">
            <!-- Share Session Button -->
            @php
                $currentSession = $currentSessionId ? $sessions->firstWhere('id', $currentSessionId) : null;
                $isPublic = $currentSession?->is_public ?? false;
            @endphp
            <button wire:click="showShareModal"
                    {{ $currentSessionId ? '' : 'disabled' }}
                    class="p-3 rounded-lg hover:bg-surface disabled:opacity-50 disabled:cursor-not-allowed {{ $isPublic ? 'text-accent' : 'text-secondary hover:text-primary' }}"
                    title="{{ $isPublic ? 'Session is publicly shared' : 'Share Session' }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                </svg>
            </button>

            <!-- Export Session Button -->
            <button wire:click="exportSessionAsMarkdown"
                    {{ $currentSessionId ? '' : 'disabled' }}
                    class="p-3 text-secondary hover:text-primary rounded-lg hover:bg-surface disabled:opacity-50 disabled:cursor-not-allowed"
                    title="Export Session as Markdown">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
            </button>

            <button @click="sidebarOpen = true"
                    class="p-3 text-secondary hover:text-primary rounded-lg hover:bg-surface"
                    title="View Sessions">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
            </button>
            <button wire:click="createSession"
                    class="p-3 text-secondary hover:text-primary rounded-lg hover:bg-surface"
                    title="New Session">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
            </button>
        </div>
    </div>
</div>
