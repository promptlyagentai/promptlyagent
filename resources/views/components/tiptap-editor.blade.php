@props([
    'placeholder' => 'Start writing...',
    'class' => '',
    'rows' => '12',
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $wireModelKey = $wireModel ?: 'content';
    $id = 'tiptap-' . Str::random(8);
@endphp

<div 
    x-data="tiptapEditor()"
    x-init="
        content = $wire.{{ $wireModelKey }} || '';
        init();
        
        $watch('content', value => {
            $wire.set('{{ $wireModelKey }}', value);
        });
    "
    x-on:content-updated="content = $event.detail.content"
    class="tiptap-wrapper {{ $class }}"
>
    <!-- Editor Toolbar -->
    <div class="tiptap-toolbar flex items-center space-x-1 p-2 border-b border-default bg-surface rounded-t-lg">
        <!-- Format Buttons -->
        <button 
            type="button"
            @click="bold()"
            :class="{ 'bg-accent/20 text-accent': isActive('bold') }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Bold (Ctrl+B)"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6V4zm0 8h9a4 4 0 014 4 4 4 0 01-4 4H6v-8z"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="italic()"
            :class="{ 'bg-accent/20 text-accent': isActive('italic') }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Italic (Ctrl+I)"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 4l4 16m-4-8h8"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="code()"
            :class="{ 'bg-accent/20 text-accent': isActive('code') }"
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
            @click="heading(1)"
            :class="{ 'bg-accent/20 text-accent': isActive('heading', { level: 1 }) }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-sm font-medium"
            title="Heading 1"
        >
            H1
        </button>
        
        <button 
            type="button"
            @click="heading(2)"
            :class="{ 'bg-accent/20 text-accent': isActive('heading', { level: 2 }) }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-sm font-medium"
            title="Heading 2"
        >
            H2
        </button>
        
        <button 
            type="button"
            @click="heading(3)"
            :class="{ 'bg-accent/20 text-accent': isActive('heading', { level: 3 }) }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-sm font-medium"
            title="Heading 3"
        >
            H3
        </button>
        
        <div class="h-4 w-px border-subtle mx-1"></div>
        
        <!-- List Buttons -->
        <button 
            type="button"
            @click="bulletList()"
            :class="{ 'bg-accent/20 text-accent': isActive('bulletList') }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Bullet List"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="orderedList()"
            :class="{ 'bg-accent/20 text-accent': isActive('orderedList') }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Numbered List"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M3 8h18M3 12h18M3 16h18M3 20h18"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="blockquote()"
            :class="{ 'bg-accent/20 text-accent': isActive('blockquote') }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Quote"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
            </svg>
        </button>
        
        <button 
            type="button"
            @click="codeBlock()"
            :class="{ 'bg-accent/20 text-accent': isActive('codeBlock') }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            title="Code Block"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
        </button>
        
        <div class="flex-1"></div>
        
        <!-- Markdown Toggle -->
        <button 
            type="button"
            @click="toggleMarkdownView()"
            :class="{ 'bg-accent/20 text-accent': showMarkdown }"
            class="p-1.5 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors text-xs font-medium"
            title="Toggle Markdown View"
        >
            MD
        </button>
    </div>
    
    <!-- Editor Content -->
    <div class="relative">
        <!-- Rich Text Editor -->
        <div 
            x-ref="editor"
            x-show="!showMarkdown"
            class="prose prose-sm max-w-none dark:prose-invert min-h-[200px] w-full rounded-b-lg border border-t-0 border-default bg-surface focus-within:ring-2 focus-within:ring-accent dark:focus-within:ring-accent"
        ></div>
        
        <!-- Markdown Source View -->
        <textarea 
            x-show="showMarkdown"
            x-model="content"
            @input="updateMarkdownContent($event.target.value)"
            class="w-full min-h-[200px] rounded-b-lg border border-t-0 border-default bg-surface px-4 py-3 text-sm font-mono focus:ring-2 focus:ring-accent dark:focus:ring-accent focus:border-transparent"
            placeholder="# Markdown Content

Write your content in Markdown format..."
            rows="{{ $rows }}"
        ></textarea>
    </div>
    
    <!-- Status Bar -->
    <div class="flex items-center justify-between px-3 py-2 bg-surface border border-t-0 border-default rounded-b-lg text-xs text-tertiary">
        <div class="flex items-center space-x-4">
            <span x-text="`${getCharacterCount()} characters`"></span>
            <span x-text="`${getWordCount()} words`"></span>
        </div>
        <div class="flex items-center space-x-2">
            <span class="text-accent">✓ Content saved</span>
            <span>Markdown supported</span>
        </div>
    </div>
</div>