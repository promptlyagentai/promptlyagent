<div class="space-y-4">
    <!-- URL Input -->
    <flux:field>
        <flux:label>URL</flux:label>
        <div class="flex space-x-2">
            <input
                wire:model.live.debounce.500ms="url"
                type="url"
                placeholder="https://example.com/article"
                class="flex-1 rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent" />
            <flux:button
                type="button"
                wire:click="validateUrl"
                variant="outline"
                size="sm"
                :disabled="empty($url) || $validating">
                @if($validating)
                    <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-accent mr-1"></div>
                    Validating...
                @else
                    Validate
                @endif
            </flux:button>
        </div>
        @error('url')
            <flux:description class="text-[var(--palette-error-700)]">{{ $message }}</flux:description>
        @else
            <flux:description>
                Enter the URL of the external knowledge source (web page, article, documentation, etc.)
            </flux:description>
        @enderror
    </flux:field>

    <!-- Validation Results -->
    @if($validation_results && !$validating)
        <div class="p-4 rounded-lg border {{ $validation_results['isValid'] ? 'bg-[var(--palette-success-100)] border-[var(--palette-success-200)]' : 'bg-[var(--palette-error-100)] border-[var(--palette-error-200)]' }}">
            <div class="flex items-center mb-2">
                @if($validation_results['isValid'])
                    <flux:icon.check-circle class="w-4 h-4 text-[var(--palette-success-700)] mr-2" />
                    <span class="text-sm font-medium text-[var(--palette-success-800)]">URL Validated Successfully</span>
                @else
                    <flux:icon.x-circle class="w-4 h-4 text-[var(--palette-error-700)] mr-2" />
                    <span class="text-sm font-medium text-[var(--palette-error-800)]">URL Validation Failed</span>
                @endif
            </div>

            @if($validation_results['isValid'])
                <div class="space-y-1 text-sm">
                    @if(!empty($validation_results['metadata']['title']))
                        <div class="text-tertiary ">
                            Title: "{{ $validation_results['metadata']['title'] }}"
                        </div>
                    @endif

                    @if(!empty($validation_results['metadata']['description']))
                        <div class="text-tertiary ">
                            Description: "{{ Str::limit($validation_results['metadata']['description'], 100) }}"
                        </div>
                    @endif

                    @if(!empty($validation_results['suggestedTags']))
                        <div class="text-tertiary ">
                            Suggested Tags: {{ implode(', ', array_slice($validation_results['suggestedTags'], 0, 3)) }}
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-[var(--palette-error-700)]">
                    {{ $error ?? 'Unable to access or parse the URL content.' }}
                </p>
            @endif
        </div>
    @endif

    @if($error && !$validation_results)
        <div class="p-3 bg-[var(--palette-error-100)] rounded-lg border border-[var(--palette-error-200)]">
            <p class="text-sm text-[var(--palette-error-700)]">{{ $error }}</p>
        </div>
    @endif

    <!-- Title -->
    <flux:field>
        <flux:label>Title</flux:label>
        <input
            wire:model="title"
            type="text"
            placeholder="Enter a descriptive title..."
            class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent" />
        @error('title')
            <flux:description class="text-[var(--palette-error-700)]">{{ $message }}</flux:description>
        @enderror
    </flux:field>

    <!-- Description -->
    <flux:field>
        <flux:label>Description (Optional)</flux:label>
        <textarea
            wire:model="description"
            rows="3"
            placeholder="Optional description of the knowledge content..."
            class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent"></textarea>
        @error('description')
            <flux:description class="text-[var(--palette-error-700)]">{{ $message }}</flux:description>
        @enderror
    </flux:field>

    <!-- Auto-refresh Settings -->
    <flux:field>
        <flux:label>Auto-refresh Settings</flux:label>
        <div class="space-y-4">
            <div class="flex items-center">
                <input
                    type="checkbox"
                    wire:model="auto_refresh"
                    class="rounded border-default text-accent shadow-sm focus:border-accent focus:ring focus:ring-accent/20 focus:ring-opacity-50"
                />
                <label class="ml-2 text-sm font-medium text-secondary ">
                    Enable automatic refresh
                </label>
            </div>

            @if($auto_refresh)
                <div class="ml-6 space-y-2">
                    <div class="flex items-center space-x-2">
                        <span class="text-sm text-tertiary ">Refresh every</span>
                        <input
                            type="number"
                            wire:model="refresh_interval"
                            min="5"
                            max="10080"
                            class="w-20 rounded border-default text-sm focus:border-accent focus:ring focus:ring-accent/20 focus:ring-opacity-50 bg-surface"
                        />
                        <span class="text-sm text-tertiary ">minutes</span>
                    </div>
                    <flux:description>
                        The system will periodically check for changes and update the content automatically.
                        Minimum: 5 minutes, Maximum: 1 week (10080 minutes)
                    </flux:description>
                </div>
            @endif
        </div>
        <flux:description>
            Enable auto-refresh to keep external knowledge sources up-to-date automatically.
        </flux:description>
    </flux:field>

    <!-- Import Button -->
    <div class="flex justify-end">
        <flux:button
            wire:click="importUrl"
            type="button"
            variant="primary"
            :disabled="!$validation_results || !$validation_results['isValid'] || empty($title)">
            Import URL
        </flux:button>
    </div>
</div>
