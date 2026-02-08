{{--
    Artifact Card Component

    Purpose: Compact inline card showing artifact with quick actions

    Features:
    - File type icon (code/text/data/generic)
    - Clickable title (opens preview)
    - Filetype badge
    - Action buttons below chip
    - Hover effects

    Display:
    - Max width: 160px
    - Truncated title with tooltip
    - Badge color by file type
    - Icon based on content type

    Actions:
    - Preview: Opens artifact drawer
    - Edit: Opens edit mode in drawer
    - Download: Downloads file
    - Delete: Confirms and deletes artifact

    File Type Icons:
    - Code: Code bracket icon
    - Text: Document icon
    - Data: File icon
    - Generic: Default document

    Livewire Methods:
    - openPreview(): Opens artifact-drawer component
    - openEdit(): Opens drawer in edit mode
    - download(): Triggers file download
    - confirmDelete(): Shows delete confirmation

    Related:
    - livewire.components.artifact-drawer: Full view/edit interface
    - Used in answer-tab inline artifacts display
--}}
<div>
    {{-- Compact Artifact Chip --}}
    <div class="artifact-chip flex items-center gap-2 px-3 py-2 bg-surface border border-default rounded-lg hover:shadow-md hover:border-accent transition-all duration-200 max-w-[160px]">
        <!-- File Type Icon -->
        <div class="flex-shrink-0">
            @if($artifact->is_code_file)
                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                </svg>
            @elseif($artifact->is_text_file)
                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            @elseif($artifact->is_data_file)
                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
            @else
                <svg class="w-4 h-4 text-tertiary " fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            @endif
        </div>

        <!-- Title (Clickable for preview) -->
        <button
            wire:click="openPreview"
            class="flex-1 text-left text-sm font-medium text-primary hover:text-accent truncate transition-colors min-w-0"
            title="{{ $artifact->id }}: {{ $artifact->title ?: 'Untitled Artifact' }}"
        >
            {{ $artifact->id }}: {{ $artifact->title ?: 'Untitled Artifact' }}
        </button>

        <!-- File Type Badge -->
        @if($artifact->filetype)
            <span class="text-xs px-1.5 py-0.5 {{ $artifact->filetype_badge_class }} rounded font-medium flex-shrink-0">
                {{ strtoupper($artifact->filetype) }}
            </span>
        @endif
    </div>

    <!-- Action Buttons - positioned below chip with tight spacing -->
    <div class="flex justify-end">
        <div class="flex gap-2 opacity-75 hover:opacity-100 transition-opacity duration-200 mt-2">
            <!-- Preview Button -->
            <button
                wire:click="openPreview"
                class="p-2 text-gray-500 hover:text-accent dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                title="Preview">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                </svg>
            </button>

            <!-- Edit Button -->
            <button
                wire:click="openEdit"
                class="p-2 text-gray-500 hover:text-accent dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                title="Edit">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
            </button>

            <!-- Download Button -->
            <button
                wire:click="download"
                class="p-2 text-gray-500 hover:text-accent dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                title="Download">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
            </button>

            <!-- Delete Button -->
            <button
                wire:click="confirmDelete"
                class="p-2 text-gray-500 hover:text-[var(--palette-error-700)] dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                title="Delete">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </div>
    </div>
</div>
