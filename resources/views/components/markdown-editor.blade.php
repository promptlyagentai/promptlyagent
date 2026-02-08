{{--
    Markdown Editor Component

    Purpose: Rich markdown editor with live preview, toolbar, and syntax support

    Features:
    - WYSIWYG-style toolbar for common markdown operations
    - Live preview toggle
    - Character and word count
    - Keyboard shortcuts (Ctrl+B for bold, Ctrl+I for italic)
    - Auto-syncing with Livewire properties

    Component Props:
    - @props string $placeholder Placeholder text for editor
    - @props string $class Additional CSS classes
    - @props string $rows Initial textarea rows

    Alpine.js Functions:
    - insertMarkdown(type): Inserts markdown syntax (bold, italic, heading, etc.)
    - togglePreview(): Switches between edit and preview modes
    - getCharacterCount(): Returns character count
    - getWordCount(): Returns word count
    - markdownToHtml(content): Renders markdown to HTML for preview

    Toolbar Buttons:
    - Bold (**text**)
    - Italic (*text*)
    - Code (`code`)
    - H1/H2/H3 (# heading)
    - List (- item)
    - Quote (> text)
    - Link ([text](url))

    Wire Model:
    Automatically detects and binds to wire:model attribute on component
    Example: <x-markdown-editor wire:model="content" />
--}}
@props([
    'placeholder' => 'Write your content in Markdown...',
    'class' => '',
    'rows' => '12',
])

@php
    // Extract wire:model attribute for Livewire binding
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $wireModelKey = $wireModel ?: 'content';
@endphp

<div
    x-data="markdownEditor()"
    x-init="
        content = $wire.{{ $wireModelKey }} || '';
        
        $watch('content', value => {
            $wire.set('{{ $wireModelKey }}', value);
        });
    "
    x-on:content-updated="content = $event.detail.content"
    class="markdown-editor {{ $class }}"
>
    <!-- Editor Toolbar -->
    <div class="toolbar flex items-center space-x-1 p-2 border-b border-default bg-surface rounded-t-lg">
        <!-- Format Buttons -->
        <button 
            type="button"
            @click="insertMarkdown('bold')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Bold (Ctrl+B)"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6V4zm0 8h9a4 4 0 014 4 4 4 0 01-4 4H6v-8z"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="insertMarkdown('italic')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Italic (Ctrl+I)"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 4l4 16m-4-8h8"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="insertMarkdown('code')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Inline Code"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
            </svg>
        </button>
        
        <div class="h-4 w-px border-subtle mx-1"></div>
        
        <!-- Heading Buttons -->
        <button 
            type="button"
            @click="insertMarkdown('h1')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-sm font-medium"
            title="Heading 1"
        >
            H1
        </button>
        
        <button 
            type="button"
            @click="insertMarkdown('h2')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-sm font-medium"
            title="Heading 2"
        >
            H2
        </button>
        
        <button 
            type="button"
            @click="insertMarkdown('h3')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-sm font-medium"
            title="Heading 3"
        >
            H3
        </button>
        
        <div class="h-4 w-px border-subtle mx-1"></div>
        
        <!-- More Buttons -->
        <button 
            type="button"
            @click="insertMarkdown('list')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="List"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="insertMarkdown('quote')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Quote"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="insertMarkdown('link')"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Link"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
            </svg>
        </button>
        
        <div class="flex-1"></div>
        
        <!-- Preview Toggle -->
        <button 
            type="button"
            @click="togglePreview()"
            :class="{ 'bg-accent/20 text-accent': showPreview }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-xs font-medium"
            title="Toggle Preview"
        >
            Preview
        </button>
    </div>
    
    <!-- Editor Content -->
    <div class="relative">
        <!-- Markdown Input -->
        <div x-show="!showPreview">
            <textarea 
                x-ref="textarea"
                x-model="content"
                @input="updateContent($event.target.value)"
                class="w-full min-h-[300px] border border-t-0 border-default bg-surface px-4 py-3 text-sm font-mono focus:ring-2 focus:ring-accent dark:focus:ring-accent focus:border-transparent resize-y"
                placeholder="{{ $placeholder }}"
                rows="{{ $rows }}"
            ></textarea>
        </div>
        
        <!-- Preview -->
        <div x-show="showPreview" x-transition>
            <div 
                class="prose prose-lg max-w-none dark:prose-invert min-h-[300px] w-full border border-t-0 border-default bg-surface px-6 py-4 overflow-y-auto"
                style="max-height: 500px;"
                x-html="markdownToHtml(content)"
            ></div>
        </div>
    </div>
    
    <!-- Status Bar -->
    <div class="flex items-center justify-between px-3 py-2 bg-surface border border-t-0 border-default rounded-b-lg text-xs text-tertiary">
        <div class="flex items-center space-x-4">
            <span x-text="`${getCharacterCount()} characters`"></span>
            <span x-text="`${getWordCount()} words`"></span>
        </div>
        <div class="flex items-center space-x-2">
            <span class="text-accent">✓ Markdown</span>
            <span>Simple & reliable</span>
        </div>
    </div>
</div>