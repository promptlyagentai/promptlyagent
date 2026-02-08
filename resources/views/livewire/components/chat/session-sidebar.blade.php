@props(['sessions', 'currentSessionId'])

<!-- Slide-out Session Sidebar -->
<div x-show="sidebarOpen"
     x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="-translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-300"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="-translate-x-full"
     class="fixed left-0 top-0 h-full w-[480px] bg-surface border-r border-default shadow-xl z-50 flex flex-col">

    <!-- Sidebar Header -->
    <div class="p-4 border-b border-default space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-primary">Chat Sessions</h2>
            <div class="flex items-center gap-2">
                <button wire:click="createSession"
                        @click="sidebarOpen = false"
                        class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface"
                        title="Create new session"
                        aria-label="Create new session">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                </button>
                <button @click="sidebarOpen = false"
                        class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface"
                        title="Close sidebar"
                        aria-label="Close sidebar">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="relative">
            <input type="text"
                   wire:model.live.debounce.300ms="sessionSearch"
                   placeholder="Search sessions..."
                   aria-label="Search sessions"
                   class="w-full px-3 py-2 pl-9 text-sm bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-tertiary" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>

        <!-- Filter Buttons -->
        <div class="flex flex-wrap gap-1">
            <button wire:click="$set('sessionSourceFilter', 'all')"
                    class="px-2.5 py-1 text-xs rounded-md {{ $sessionSourceFilter === 'all' ? 'bg-blue-500 text-white' : 'bg-surface-secondary text-secondary hover:bg-surface' }}">
                All
            </button>
            @foreach(app(\App\Services\Chat\SourceTypeRegistry::class)->all() as $source)
                <button wire:click="$set('sessionSourceFilter', '{{ $source['key'] }}')"
                        class="px-2.5 py-1 text-xs rounded-md {{ $sessionSourceFilter === $source['key'] ? 'bg-blue-500 text-white' : 'bg-surface-secondary text-secondary hover:bg-surface' }}"
                        title="{{ $source['label'] }}">
                    {{ $source['icon'] }} {{ $source['label'] }}
                </button>
            @endforeach
        </div>

        <!-- Checkboxes -->
        <div class="flex items-center justify-between">
            <div class="flex gap-4 text-sm">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox"
                           wire:model.live="showArchived"
                           class="rounded border-default text-blue-500 focus:ring-blue-500">
                    <span class="text-secondary">Show archived</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox"
                           wire:model.live="showKeptOnly"
                           class="rounded border-default text-blue-500 focus:ring-blue-500">
                    <span class="text-secondary">Show starred</span>
                </label>
            </div>

            <!-- Bulk Edit Toggle Button -->
            <button wire:click="toggleBulkEditMode"
                    class="inline-flex items-center gap-1.5 px-2 py-1.5 text-sm {{ $bulkEditMode ? 'text-blue-500 bg-blue-50 dark:bg-blue-900' : 'text-secondary hover:text-primary' }} rounded hover:bg-surface transition-colors"
                    title="{{ $bulkEditMode ? 'Exit bulk edit mode' : 'Bulk edit mode' }}"
                    aria-label="{{ $bulkEditMode ? 'Exit bulk edit mode' : 'Bulk edit mode' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="4" y="4" width="16" height="16" rx="2" ry="2"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>
                </svg>
                <span>Bulk Edit</span>
            </button>
        </div>

        <!-- Bulk Selection Controls (shown only in bulk edit mode) -->
        @if($bulkEditMode)
            <div class="flex items-center justify-between text-sm pt-2 border-t border-default">
                <button wire:click="toggleSelectAll"
                        class="px-3 py-1.5 text-xs font-medium rounded-md bg-surface-secondary text-secondary hover:bg-surface">
                    {{ $selectAll ? 'Deselect All' : 'Select All' }}
                </button>
                <span class="text-tertiary text-xs">
                    {{ count($selectedSessionIds) }} selected
                </span>
            </div>
        @endif
    </div>

    <!-- Sessions List -->
    <div class="flex-1 overflow-y-auto">
        @if($sessions && count($sessions) > 0)
            @foreach($sessions as $session)
                <div wire:key="session-{{ $session->id }}-{{ in_array($session->id, $selectedSessionIds) ? 'selected' : 'unselected' }}" class="group relative w-full p-4 border-b border-subtle hover:bg-surface {{ $currentSessionId == $session->id ? 'bg-surface border-l-4 border-l-blue-500' : '' }} {{ $bulkEditMode ? 'pl-12' : '' }}">
                    <!-- Selection Checkbox (bulk edit mode) -->
                    @if($bulkEditMode)
                        <div class="absolute left-4 top-1/2 -translate-y-1/2 z-10">
                            <input type="checkbox"
                                   wire:click.stop="toggleSessionSelection({{ $session->id }})"
                                   @checked(in_array($session->id, $selectedSessionIds))
                                   class="w-4 h-4 rounded border-default text-blue-500 focus:ring-blue-500 cursor-pointer"
                                   aria-label="Select session {{ $session->title }}">
                        </div>
                    @endif

                    <!-- Main session content - clickable link -->
                    <a href="{{ route('dashboard.research-chat.session', ['sessionId' => $session->id]) }}"
                       wire:navigate
                       @click="sidebarOpen = false"
                       class="block w-full">
                        <div class="flex items-center gap-2 font-medium text-sm text-primary pr-20">
                            @php
                                $sourceType = $session->source_type ?? $session->getInitiatedBy();
                                $sourceRegistry = app(\App\Services\Chat\SourceTypeRegistry::class);
                                $sourceIcon = $sourceRegistry->getIcon($sourceType);
                                $sourceLabel = $sourceRegistry->getLabel($sourceType);
                            @endphp
                            <span class="flex-shrink-0 text-base" title="{{ $sourceLabel }}" aria-label="{{ $sourceLabel }}">{{ $sourceIcon }}</span>

                            @if($session->is_kept)
                                <span class="text-yellow-500" title="Kept" aria-label="Kept session">⭐</span>
                            @endif

                            <span class="truncate">{{ $session->title ?: 'New Chat Session' }}</span>
                        </div>

                        <!-- Date -->
                        <div class="text-xs text-tertiary mt-1">
                            <span class="flex-shrink-0">{{ $session->updated_at->format('M j, H:i') }}</span>
                        </div>
                    </a>

                    <!-- Count Badges - absolutely positioned to the right -->
                    <div class="absolute bottom-2 right-2 flex gap-1.5">
                        @if($session->attachments_count > 0)
                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-surface-secondary text-tertiary text-xs rounded" title="{{ $session->attachments_count }} attachments" aria-label="{{ $session->attachments_count }} attachments">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                </svg>
                                {{ $session->attachments_count }}
                            </span>
                        @endif
                        @if($session->artifacts_count > 0)
                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-surface-secondary text-tertiary text-xs rounded" title="{{ $session->artifacts_count }} artifacts" aria-label="{{ $session->artifacts_count }} artifacts">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                </svg>
                                {{ $session->artifacts_count }}
                            </span>
                        @endif
                        @if($session->sources_count > 0)
                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-surface-secondary text-tertiary text-xs rounded" title="{{ $session->sources_count }} sources" aria-label="{{ $session->sources_count }} sources">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                                {{ $session->sources_count }}
                            </span>
                        @endif
                        @if($session->isArchived())
                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 text-xs rounded" title="Archived session" aria-label="Archived session">
                                Archived
                            </span>
                        @endif
                    </div>

                    <!-- Action buttons - hidden in bulk edit mode -->
                    @if(!$bulkEditMode)
                        <div class="absolute top-2 right-2 flex items-center gap-1">
                        <!-- Keep button -->
                        <button wire:click="toggleSessionKeep({{ $session->id }})"
                                class="p-1 {{ $session->is_kept ? 'text-yellow-500' : 'text-tertiary hover:text-yellow-500' }} rounded transition-colors"
                                title="{{ $session->is_kept ? 'Remove keep flag' : 'Keep this session' }}"
                                aria-label="{{ $session->is_kept ? 'Remove keep flag' : 'Keep this session' }}">
                            <svg class="w-4 h-4" fill="{{ $session->is_kept ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                            </svg>
                        </button>

                        <!-- Archive/Unarchive button -->
                        @if($session->isArchived())
                            <button wire:click="unarchiveSession({{ $session->id }})"
                                    class="p-1 text-tertiary hover:text-blue-500 rounded transition-colors"
                                    title="Unarchive session"
                                    aria-label="Unarchive session">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                </svg>
                            </button>
                        @else
                            <button wire:click="archiveSession({{ $session->id }})"
                                    class="p-1 text-tertiary hover:text-orange-500 rounded transition-colors {{ $session->is_kept ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    title="{{ $session->is_kept ? 'Cannot archive kept session' : 'Archive session' }}"
                                    aria-label="{{ $session->is_kept ? 'Cannot archive kept session' : 'Archive session' }}"
                                    {{ $session->is_kept ? 'disabled' : '' }}>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                </svg>
                            </button>
                        @endif

                        <!-- Delete button -->
                        <button @click="event.preventDefault(); event.stopPropagation(); if(confirm('Are you sure you want to delete this session? This cannot be undone.')) { $wire.call('deleteSession', {{ $session->id }}) }"
                                class="p-1 text-tertiary hover:text-[var(--palette-error-700)] rounded transition-colors"
                                title="Delete session"
                                aria-label="Delete session">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                    @endif
                </div>
            @endforeach
        @else
            <div class="p-4 text-center text-tertiary text-sm">
                No sessions yet. Create your first chat session!
            </div>
        @endif
    </div>

    <!-- Floating Action Bar (shown when sessions are selected) -->
    @if($bulkEditMode && count($selectedSessionIds) > 0)
        <div class="absolute bottom-0 left-0 right-0 bg-surface border-t border-default shadow-lg p-4 z-50"
             x-data
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-full"
             x-transition:enter-end="opacity-100 translate-y-0">
            <div class="flex items-center justify-between gap-3">
                <!-- Selected count -->
                <span class="text-sm font-medium text-primary">
                    {{ count($selectedSessionIds) }} session(s) selected
                </span>

                <!-- Action buttons -->
                <div class="flex items-center gap-2">
                    <!-- Keep/Unkeep -->
                    <button wire:click="bulkToggleKeep"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md bg-surface-secondary text-secondary hover:bg-surface disabled:opacity-50">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                        </svg>
                        Keep
                    </button>

                    <!-- Archive -->
                    <button wire:click="bulkArchive"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md bg-surface-secondary text-secondary hover:bg-surface disabled:opacity-50">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                        </svg>
                        Archive
                    </button>

                    <!-- Unarchive -->
                    <button wire:click="bulkUnarchive"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md bg-surface-secondary text-secondary hover:bg-surface disabled:opacity-50">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                        </svg>
                        Unarchive
                    </button>

                    <!-- Delete (destructive) -->
                    <button @click="if(confirm('Are you sure you want to delete ' + {{ count($selectedSessionIds) }} + ' session(s)? This cannot be undone.')) { $wire.call('bulkDelete') }"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md bg-red-500 text-white hover:bg-red-600 disabled:opacity-50">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Delete
                    </button>
                </div>
            </div>

            <!-- Progress indicator -->
            @if($bulkOperationInProgress)
                <div class="mt-3 space-y-2">
                    <div class="flex items-center justify-between text-xs text-tertiary">
                        <span>{{ $bulkOperationProgress['action'] }}...</span>
                        <span>{{ $bulkOperationProgress['current'] }} / {{ $bulkOperationProgress['total'] }}</span>
                    </div>
                    <div class="w-full bg-surface-secondary rounded-full h-1.5">
                        <div class="bg-blue-500 h-1.5 rounded-full transition-all duration-300"
                             style="width: {{ ($bulkOperationProgress['total'] > 0) ? ($bulkOperationProgress['current'] / $bulkOperationProgress['total'] * 100) : 0 }}%">
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>

<!-- Overlay for mobile -->
<div x-show="sidebarOpen"
     x-cloak
     x-transition:enter="transition-opacity ease-linear duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition-opacity ease-linear duration-300"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="sidebarOpen = false"
     class="fixed inset-0 bg-black bg-opacity-50 z-40"></div>
