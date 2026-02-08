{{--
    Share Session Modal Component

    Purpose: Modal dialog for public session sharing with copy functionality

    Features:
    - Public/private toggle
    - Shareable URL display with copy button
    - Visual feedback for public state
    - Livewire for state management
    - Alpine.js for UI interactions

    Livewire Events:
    - show-share-modal: Show modal with session data
    - close-share-modal: Hide modal
--}}
<div x-data="{
        copyButtonText: 'Copy Link'
     }"
     @keydown.escape.window="if ($wire.show) $wire.closeModal()"
     @if($show)
     x-init="document.body.style.overflow = 'hidden'"
     x-destroy="document.body.style.overflow = ''"
     @endif
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: {{ $show ? 'block' : 'none' }}">

    <!-- Backdrop -->
    <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
         @click="$wire.closeModal()">
    </div>

    <!-- Modal -->
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="relative bg-surface rounded-xl shadow-xl max-w-lg w-full border border-default"
             @click.stop>

            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-default">
                <h3 class="text-xl font-semibold">Share Session</h3>
                <button wire:click="closeModal" class="text-secondary hover:text-primary">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Body -->
            <div class="p-6 space-y-6">
                <!-- Public/Private Toggle -->
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="font-medium text-primary">Public Sharing</h4>
                        <p class="text-sm text-secondary mt-1">
                            @if($isPublic)
                                Anyone with the link can view this session
                            @else
                                Only you can view this session
                            @endif
                        </p>
                    </div>
                    <button wire:click.stop="toggleShare"
                            class="relative inline-flex items-center h-8 w-14 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 {{ $isPublic ? 'bg-accent' : 'bg-zinc-300 dark:bg-zinc-600' }}">
                        <span class="inline-block h-6 w-6 transform rounded-full bg-white transition-transform {{ $isPublic ? 'translate-x-7' : 'translate-x-1' }}">
                        </span>
                    </button>
                </div>

                <!-- Warning Banner (shown when public) -->
                @if($isPublic)
                <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                    <div class="flex">
                        <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div class="ml-3">
                            <h4 class="text-sm font-medium text-yellow-800 dark:text-yellow-400">Public Session</h4>
                            <p class="text-sm text-yellow-700 dark:text-yellow-500 mt-1">
                                This session is publicly accessible. Anyone with the link can view the entire conversation.
                            </p>
                        </div>
                    </div>
                </div>
                @endif

                <!-- Shareable URL (shown when public) -->
                @if($isPublic)
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-secondary">Shareable Link</label>
                    <div class="flex items-center gap-2">
                        <input type="text"
                               value="{{ $publicUrl }}"
                               readonly
                               class="flex-1 px-3 py-2 bg-surface-elevated border border-default rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent">
                        <button @click="
                            navigator.clipboard.writeText('{{ $publicUrl }}');
                            copyButtonText = 'Copied!';
                            setTimeout(() => copyButtonText = 'Copy Link', 2000);
                        "
                                class="px-4 py-2 bg-accent hover:bg-accent text-white rounded-lg text-sm font-medium whitespace-nowrap transition-colors">
                            <span x-text="copyButtonText"></span>
                        </button>
                    </div>
                </div>
                @endif
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-end gap-3 p-6 border-t border-default">
                <button wire:click="closeModal"
                        class="px-4 py-2 text-secondary hover:text-primary hover:bg-surface rounded-lg transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>
