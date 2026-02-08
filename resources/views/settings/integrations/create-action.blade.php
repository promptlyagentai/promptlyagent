<x-layouts.app>
    <section class="w-full pb-20 xl:pb-0">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="'Create ' . $provider->getActionTypeName() . ' Action'"
            :subheading="$provider->getDescription()"
            wide>

            {{-- Success/Error Messages --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-success-800)]">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-[var(--palette-error-700)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-error-800)]">{{ session('error') }}</span>
                    </div>
                    @if($errors->any())
                        <div class="mt-2 text-xs">
                            <strong>Validation errors:</strong>
                            <ul class="ml-4 list-disc">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                            <div class="mt-2">
                                <strong>Error keys:</strong> {{ implode(', ', $errors->keys()) }}
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Responsive Grid Container --}}
            <div class="space-y-6 xl:grid xl:grid-cols-12 xl:gap-6 xl:space-y-0">

                {{-- LEFT COLUMN - Create Form (60%) --}}
                <div class="space-y-6 xl:col-span-7">

                    {{-- Create Form Card --}}
                    <div class="rounded-lg border border-default bg-surface p-6  ">
                        <form id="action-create-form" action="{{ route('integrations.store-action', ['provider' => $provider->getActionType()]) }}" method="POST" class="space-y-6">
                            @csrf

                            <div class="flex items-center gap-2 mb-6">
                                @if($svg = $provider->getActionIconSvg())
                                    <div class="h-6 w-6 text-secondary ">{!! $svg !!}</div>
                                @else
                                    <span class="text-2xl">{{ $provider->getActionIcon() }}</span>
                                @endif
                                <flux:heading size="lg">Create Action</flux:heading>
                            </div>

                            {{-- Shared Form Fields --}}
                            <x-output-action.form-fields
                                :provider="$provider"
                                :config-schema="$configSchema" />

                            {{-- Provider-Specific Form Sections --}}
                            @foreach($provider->getCreateFormSections() as $section)
                                @include($section, ['action' => null])
                            @endforeach

                        </form>
                    </div>

                </div>

                {{-- RIGHT COLUMN - Setup Instructions (40%) --}}
                <div class="space-y-6 xl:col-span-5">

                    {{-- Setup Instructions Card --}}
                    <div class="rounded-lg border border-default bg-surface  "
                         x-data="{
                             setupInstructions: @js($setupInstructions),
                             renderedHtml: '',
                             renderMarkdown() {
                                 if (window.marked) {
                                     try {
                                         this.renderedHtml = window.marked.parse(this.setupInstructions);
                                         setTimeout(() => {
                                             if (window.hljs && this.$refs.instructions) {
                                                 this.$refs.instructions.querySelectorAll('pre code').forEach(block => {
                                                     window.hljs.highlightElement(block);
                                                 });
                                             }
                                         }, 10);
                                     } catch (e) {
                                         console.error('Markdown rendering error:', e);
                                     }
                                 }
                             }
                         }"
                         x-init="renderMarkdown()">
                        <div class="border-b border-default px-6 py-4 ">
                            <div class="flex items-center gap-2">
                                @if($svg = $provider->getActionIconSvg())
                                    <div class="h-5 w-5 text-secondary ">{!! $svg !!}</div>
                                @else
                                    <span class="text-xl">{{ $provider->getActionIcon() }}</span>
                                @endif
                                <flux:heading size="sm">Setup Instructions</flux:heading>
                            </div>
                        </div>
                        <div x-ref="instructions" class="markdown-compact">
                            <div class="markdown p-6" x-html="renderedHtml"></div>
                        </div>
                    </div>

                    {{-- Example Payload Card --}}
                    @if($examplePayload)
                        <div class="rounded-lg border border-default bg-surface  ">
                            <div class="border-b border-default px-6 py-4 ">
                                <div class="flex items-center gap-2">
                                    <span class="text-xl">📄</span>
                                    <flux:heading size="sm">Example Payload</flux:heading>
                                </div>
                            </div>
                            <div class="p-6">
                                <pre class="text-xs text-tertiary  overflow-x-auto">{{ json_encode($examplePayload, JSON_PRETTY_PRINT) }}</pre>
                            </div>
                        </div>
                    @endif

                </div>

            </div>

            {{-- Bottom Action Bar --}}
            <div class="mt-6">
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('integrations.index') }}" class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                        {{ __('Cancel') }}
                    </a>

                    <button type="submit" form="action-create-form" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        <span>{{ __('Create Action') }}</span>
                    </button>
                </div>
            </div>

        </x-settings.layout>
    </section>
</x-layouts.app>
