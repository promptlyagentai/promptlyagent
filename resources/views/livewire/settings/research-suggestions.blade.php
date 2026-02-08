{{--
    Research Suggestions Settings Page

    Purpose: Configure AI-powered research topic suggestions in the chat interface

    Features:
    - Enable/disable daily AI-generated topic suggestions
    - Topics personalized based on AI persona (job, skills, location)
    - Cached for 48 hours per user
    - Fallback to config-based topics when disabled or generation fails
    - Manual regeneration trigger

    Livewire Component Properties:
    - @property bool $enabled Toggle AI-generated research topics

    Configuration:
    - Topics generated daily at 3 AM via scheduled job
    - Uses user's AI persona for personalization
    - Falls back to generic topics if no persona configured
    - Cache TTL: 48 hours (config/research_topics.php)

    Livewire Component Methods:
    - mount(): Load feature state from user preferences
    - updateSettings(): Save settings and optionally clear cache
    - regenerateNow(): Force immediate regeneration

    Events:
    - research-suggestions-updated: Dispatched when settings saved
    - research-suggestions-regenerated: Dispatched when manual regeneration triggered

    Storage:
    - Saved to user preferences JSON column
    - Cache key: research_topics:user_{user_id}
    - Cache tags: ['research_topics', 'user:{user_id}']

    Related:
    - AI Persona Settings: /settings/ai-persona
    - Service: App\Services\ResearchTopicService
    - Job: App\Jobs\GenerateResearchTopicsJob
    - Command: research:generate-topics
--}}
<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Jobs\GenerateResearchTopicsJob;
use Livewire\Volt\Component;

new class extends Component {
    public bool $enabled = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->enabled = $user->preferences['research_suggestions']['enabled'] ?? false;
    }

    public function updateSettings(): void
    {
        $this->validate([
            'enabled' => 'required|boolean',
        ]);

        $user = Auth::user();
        $preferences = $user->preferences ?? [];

        $preferences['research_suggestions'] = [
            'enabled' => $this->enabled,
            'updated_at' => now()->toDateTimeString(),
        ];

        $user->update(['preferences' => $preferences]);

        // Clear cache if disabled
        if (! $this->enabled) {
            Cache::tags(["user:{$user->id}"])->flush();
        }

        $this->dispatch('research-suggestions-updated');
    }

    public function regenerateNow(): void
    {
        $user = Auth::user();

        // Clear existing cache
        $cacheKey = config('research_topics.cache.key_prefix').":user_{$user->id}";
        Cache::forget($cacheKey);

        // Dispatch job for immediate generation
        GenerateResearchTopicsJob::dispatch($user);

        $this->dispatch('research-suggestions-regenerated');
    }
}; ?>

<x-settings.layout
    :heading="__('Research Suggestions')"
    :subheading="__('Personalize your research topic recommendations')">

    <form wire:submit="updateSettings" class="my-6 w-full space-y-6">

        <!-- Enable Toggle -->
        <div>
            <flux:switch
                wire:model.live="enabled"
                :label="__('Enable AI-Generated Topics')"
            />
            <flux:text class="mt-2 text-sm text-tertiary">
                {{ __('When enabled, the research interface will show 4 personalized topics generated daily based on your AI persona.') }}
            </flux:text>
        </div>

        @if($enabled)
            <!-- AI Persona Info -->
            <div class="rounded-lg bg-[var(--palette-notify-100)] p-4 border border-[var(--palette-notify-200)]">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-[var(--palette-notify-700)]" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-[var(--palette-notify-800)]">
                            {{ __('Topics are Personalized Using Your AI Persona') }}
                        </h3>
                        <div class="mt-2 text-sm text-[var(--palette-notify-800)]">
                            <p>
                                {{ __('Configure your job description, skills, and interests in') }}
                                <a href="{{ route('settings.ai-persona') }}" class="font-medium underline hover:text-[var(--palette-notify-900)]">
                                    {{ __('AI Persona Settings') }}
                                </a>
                                {{ __('for better recommendations.') }}
                            </p>
                            <p class="mt-2">
                                {{ __('Topics update automatically daily at 3 AM. If you don\'t have a persona configured, you\'ll receive trending technology topics instead.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Regenerate Button -->
            <div>
                <flux:button
                    wire:click="regenerateNow"
                    variant="outline"
                    type="button">
                    {{ __('Regenerate Topics Now') }}
                </flux:button>
                <flux:text class="mt-2 text-sm text-tertiary">
                    {{ __('Force immediate regeneration. Topics are cached for 48 hours and update automatically daily at 3 AM.') }}
                </flux:text>
            </div>
        @endif

        <!-- Save Button -->
        <div class="flex items-center gap-4">
            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full">
                    {{ __('Save Settings') }}
                </flux:button>
            </div>

            <x-action-message class="me-3" on="research-suggestions-updated">
                {{ __('Saved.') }}
            </x-action-message>

            <x-action-message class="me-3" on="research-suggestions-regenerated">
                {{ __('Regenerating... Check back in a few seconds.') }}
            </x-action-message>
        </div>
    </form>
</x-settings.layout>
