{{--
    Help Widget Settings Page

    Purpose: Configure the interactive help widget that appears on all application pages

    Features:
    - Enable/disable help widget globally
    - GitHub username for bug report attribution
    - Persistent conversation history
    - Element selection for contextual help
    - Screenshot attachment for bug reports
    - AI-powered answers using configured agent

    Livewire Component Properties:
    - @property bool $enabled Toggle help widget visibility
    - @property string $github_username GitHub username for @mentions in bug reports

    Help Widget Capabilities:
    - Ask questions with AI-powered answers
    - Select page elements for contextual help
    - Attach files to questions
    - Report bugs with screenshots
    - Persistent conversation across page loads
    - Uses existing authentication (no API token needed)

    Configuration:
    - Agent configured via HELP_WIDGET_AGENT_ID in .env
    - GitHub username is optional but recommended for bug tracking

    Livewire Component Methods:
    - mount(): Load widget preferences from user preferences
    - updateHelpWidget(): Save widget settings

    Events:
    - help-widget-updated: Dispatched when settings saved

    Validation:
    - enabled: required, boolean
    - github_username: nullable, string, max:39, valid GitHub username regex

    Storage:
    - Saved to user preferences JSON column
    - Persists across sessions

    Related:
    - HELP_WIDGET_AGENT_ID: Environment variable for agent configuration
--}}
<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public bool $enabled = false;
    public string $github_username = '';

    public function mount(): void
    {
        $user = Auth::user();
        $helpWidget = $user->preferences['help_widget'] ?? [];

        $this->enabled = $helpWidget['enabled'] ?? false;
        $this->github_username = $helpWidget['github_username'] ?? '';
    }

    public function updateHelpWidget(): void
    {
        $this->validate([
            'enabled' => 'required|boolean',
            'github_username' => 'nullable|string|max:39|regex:/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/',
        ]);

        $user = Auth::user();
        $preferences = $user->preferences ?? [];

        $preferences['help_widget'] = [
            'enabled' => $this->enabled,
            'github_username' => $this->github_username,
        ];

        $user->update(['preferences' => $preferences]);

        $this->dispatch('help-widget-updated');
    }
}; ?>

<x-settings.layout
    :heading="__('Interactive Help')"
    :subheading="__('Configure the help widget that appears on all pages')">
    <form wire:submit="updateHelpWidget" class="my-6 w-full space-y-6">

        <!-- Enable Toggle -->
        <div>
            <flux:switch wire:model.live="enabled" :label="__('Enable Help Widget')" />
            <flux:text class="mt-2 text-sm text-tertiary ">
                {{ __('Show the interactive help widget on the bottom-right of all pages') }}
            </flux:text>
        </div>

        @if ($enabled)
            <!-- GitHub Username -->
            <div>
                <flux:input
                    wire:model="github_username"
                    :label="__('GitHub Username (Optional)')"
                    placeholder="your-github-username"
                />

                <flux:text class="mt-2 text-sm text-tertiary">
                    {{ __('Your GitHub username for bug report attribution. When provided, GitHub issues will @mention you.') }}
                </flux:text>
            </div>

            <!-- Info Box -->
            <div class="rounded-lg bg-[var(--palette-notify-100)] p-4 border border-[var(--palette-notify-200)]">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-[var(--palette-notify-700)]" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-[var(--palette-notify-800)]">
                            {{ __('About the Help Widget') }}
                        </h3>
                        <div class="mt-2 text-sm text-[var(--palette-notify-800)]">
                            <p>{{ __('The help widget allows you to:') }}</p>
                            <ul class="list-disc list-inside mt-2 space-y-1">
                                <li>{{ __('Ask questions and get AI-powered answers') }}</li>
                                <li>{{ __('Select specific page elements for contextual help') }}</li>
                                <li>{{ __('Attach files to your questions') }}</li>
                                <li>{{ __('Report bugs with screenshots') }}</li>
                                <li>{{ __('Persistent conversation history across page loads') }}</li>
                                <li>{{ __('Uses your existing login - no API token required') }}</li>
                            </ul>
                            <p class="mt-3 text-xs">
                                {{ __('Note: The AI agent is configured via HELP_WIDGET_AGENT_ID in your .env file.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Save Button -->
        <div class="flex items-center gap-4">
            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full">
                    {{ __('Save Settings') }}
                </flux:button>
            </div>

            <x-action-message class="me-3" on="help-widget-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</x-settings.layout>
