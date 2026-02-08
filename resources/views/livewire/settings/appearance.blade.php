{{--
    Appearance Settings Page

    Purpose: Customize application appearance with custom color schemes

    Features:
    - Enable/disable custom color scheme
    - Color picker for quick generation
    - Automatic Tailwind-compatible color scale generation
    - Manual CSS custom properties entry
    - Color scheme validation and normalization
    - Admin instructions for implementing as default theme
    - Page reload to apply changes

    Livewire Component Properties:
    - @property bool $customColorSchemeEnabled Toggle custom colors
    - @property string $customColorCss User-entered CSS properties
    - @property bool $saving Loading state
    - @property string $successMessage Success feedback
    - @property string $baseColor Color picker value for generation

    Color Generation:
    - Takes single base color (e.g., #1eae9a)
    - Generates complete 11-shade palette (50-950)
    - Automatically maps to --palette-primary-* variables
    - Includes success, warning, error state colors

    Color Input Formats:
    - Custom names: --color-bermuda-500: #1eae9a;
    - Palette names: --palette-primary-500: #1eae9a;
    - Short names: --color-accent: #1eae9a;

    Validation:
    - Property name validation (must be valid CSS custom property)
    - Color value validation (hex, rgb, hsl formats)
    - Normalization to palette variables

    Storage:
    - Saved to user preferences JSON column
    - Persists across sessions
    - Requires page reload to apply

    Related Services:
    - App\Services\ColorSchemeService: Color generation and validation
--}}
<?php

use App\Services\ColorSchemeService;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $customColorSchemeEnabled = false;

    public string $customColorCss = '';

    public bool $saving = false;

    public string $successMessage = '';

    public string $baseColor = '#1eae9a';

    protected $rules = [
        'customColorCss' => 'nullable|string|max:50000',
    ];

    public function mount(): void
    {
        $preferences = auth()->user()->preferences ?? [];
        $customScheme = $preferences['custom_color_scheme'] ?? null;

        $this->customColorSchemeEnabled = $customScheme['enabled'] ?? false;
        $this->customColorCss = $this->formatColorsForDisplay($customScheme['colors'] ?? []);

        // Set base color from current scheme if available, otherwise default to tropical-teal primary-500
        if (! empty($customScheme['colors']['--palette-primary-500'])) {
            $this->baseColor = $customScheme['colors']['--palette-primary-500'];
        } else {
            // Default to tropical-teal primary-500
            $this->baseColor = '#52a7ad';
        }
    }

    public function generateFromColor(): void
    {
        try {
            // Generate full color scale from the picked base color
            $colors = ColorSchemeService::generateColorScale($this->baseColor);

            // Format colors for display in textarea
            $this->customColorCss = $this->formatColorsForDisplay($colors);

            $this->successMessage = 'Color scheme generated! Click "Save Color Scheme" to apply it.';
        } catch (\Exception $e) {
            $this->addError('baseColor', $e->getMessage());
        }
    }

    public function saveColorScheme(): void
    {
        if (! config('app.custom_color_schemes')) {
            $this->addError('customColorCss', 'Custom color schemes are not enabled.');

            return;
        }

        $this->saving = true;
        $this->successMessage = '';
        $this->resetErrorBag();

        try {
            $this->validate();

            // Parse user CSS
            $parsedColors = ColorSchemeService::parseUserCss($this->customColorCss);

            // Normalize/auto-map to palette (if custom names detected)
            $normalizedColors = ColorSchemeService::normalizeUserColors($parsedColors);

            // Validate each property and color value
            foreach ($normalizedColors as $property => $value) {
                if (! ColorSchemeService::validatePropertyName($property)) {
                    throw new \InvalidArgumentException("Invalid property name: {$property}");
                }
                if (! ColorSchemeService::validateColorValue($value)) {
                    throw new \InvalidArgumentException("Invalid color value for {$property}: {$value}");
                }
            }

            // Save to user preferences
            $user = auth()->user();
            $preferences = $user->preferences ?? [];
            $preferences['custom_color_scheme'] = [
                'enabled' => $this->customColorSchemeEnabled,
                'colors' => $normalizedColors,
            ];

            $user->update(['preferences' => $preferences]);

            $this->dispatch('color-scheme-saved');

            // Reload page to apply color scheme changes
            $this->js('window.location.reload()');
        } catch (\Exception $e) {
            $this->addError('customColorCss', $e->getMessage());
            $this->saving = false;
        }
    }

    private function formatColorsForDisplay(array $colors): string
    {
        $lines = [];
        foreach ($colors as $property => $value) {
            $lines[] = "{$property}: {$value};";
        }

        return implode("\n", $lines);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Appearance')" :subheading=" __('Update the appearance settings for your account')">
        <flux:text>
            {{ __('The application automatically follows your system\'s light or dark mode preference.') }}
        </flux:text>

        <!-- Custom Color Scheme Section -->
        @if(config('app.custom_color_schemes'))
            <flux:separator class="my-8" />

            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Custom Color Scheme') }}</flux:heading>
                <flux:text>
                    {{ __('Paste any color scale (like --color-bermuda-500) and we\'ll automatically map it to your primary theme colors. Colors will take effect after refreshing the page.') }}
                </flux:text>

                <flux:checkbox wire:model.live="customColorSchemeEnabled" label="{{ __('Enable custom color scheme') }}" />

                @if($customColorSchemeEnabled)
                    <!-- Color Generator -->
                    <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-4 border border-zinc-200 dark:border-zinc-700 space-y-3">
                        <flux:heading size="xs">{{ __('Quick Generate') }}</flux:heading>
                        <flux:text size="sm">
                            {{ __('Pick a color to automatically generate a complete Tailwind-compatible color scale.') }}
                        </flux:text>

                        <div class="flex gap-3 items-end">
                            <flux:field class="flex-1">
                                <flux:label>{{ __('Base Color') }}</flux:label>
                                <div class="flex gap-2">
                                    <input
                                        type="color"
                                        wire:model.live="baseColor"
                                        class="h-10 w-16 rounded border border-zinc-300 dark:border-zinc-600 cursor-pointer"
                                    />
                                    <flux:input
                                        wire:model.live="baseColor"
                                        type="text"
                                        placeholder="#1eae9a"
                                        class="flex-1 font-mono text-sm"
                                    />
                                </div>
                                @error('baseColor')
                                    <flux:error>{{ $message }}</flux:error>
                                @enderror
                            </flux:field>

                            <flux:button
                                wire:click="generateFromColor"
                                variant="filled"
                                class="shrink-0">
                                {{ __('Generate Scheme') }}
                            </flux:button>
                        </div>
                    </div>

                    <flux:separator class="my-4" text="{{ __('or') }}" />

                    <flux:field>
                        <flux:label>{{ __('CSS Custom Properties') }}</flux:label>
                        <textarea
                            wire:model.defer="customColorCss"
                            rows="14"
                            class="w-full font-mono text-sm rounded-lg border border-zinc-300 bg-white px-3 py-2 transition focus:ring-2 focus:ring-primary-500 dark:bg-zinc-800 dark:border-zinc-600 dark:text-zinc-100"
                            placeholder="--color-bermuda-50: #f1fcfa;
--color-bermuda-100: #cff8ee;
--color-bermuda-200: #9ef1df;
--color-bermuda-300: #58dfc6;
--color-bermuda-400: #37cab4;
--color-bermuda-500: #1eae9a;
--color-bermuda-600: #158c7e;
--color-bermuda-700: #157066;
--color-bermuda-800: #165953;
--color-bermuda-900: #164b46;
--color-bermuda-950: #072c2b;"></textarea>
                        <flux:description>
                            {{ __('Paste a complete color scale (all 11 colors: 50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950). One property per line or semicolon-separated. Success, warning, and error state colors will be automatically generated from your primary palette.') }}
                        </flux:description>
                        @error('customColorCss')
                            <flux:error>{{ $message }}</flux:error>
                        @enderror
                    </flux:field>

                    <div class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-4 border border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="xs" class="mb-2">{{ __('Example Format') }}</flux:heading>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400 space-y-1 font-mono">
                            <p>--color-bermuda-500: #1eae9a;</p>
                            <p>--palette-primary-600: #41868b;</p>
                            <p>--color-accent: #52a7ad;</p>
                        </div>
                    </div>

                    @if(auth()->user()->is_admin)
                        <div class="bg-[var(--palette-notify-100)] rounded-lg p-4 border border-[var(--palette-notify-200)]">
                            <div class="flex gap-2 items-start">
                                <svg class="w-5 h-5 text-[var(--palette-notify-700)] shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                </svg>
                                <div class="space-y-2">
                                    <flux:heading size="xs" class="text-[var(--palette-notify-800)]">{{ __('Admin: Implement as Main Application Color Scheme') }}</flux:heading>
                                    <flux:text size="sm" class="text-[var(--palette-notify-800)]">
                                        {{ __('To make this the default color scheme for all users:') }}
                                    </flux:text>
                                    <ol class="text-sm text-[var(--palette-notify-800)] space-y-2 list-decimal list-inside">
                                        <li>{{ __('Copy the generated colors from the textarea above') }}</li>
                                        <li>{{ __('Create a new file in') }} <code class="px-1.5 py-0.5 bg-[var(--palette-notify-200)] rounded text-xs font-mono">resources/css/themes/palette-your-theme.css</code></li>
                                        <li>{{ __('Format the colors in @theme directive (see') }} <code class="px-1.5 py-0.5 bg-[var(--palette-notify-200)] rounded text-xs font-mono">palette-tropical-teal.css</code> {{ __('for reference)') }}</li>
                                        <li>{{ __('Update the import in') }} <code class="px-1.5 py-0.5 bg-[var(--palette-notify-200)] rounded text-xs font-mono">resources/css/app.css</code> {{ __('to import your new palette') }}</li>
                                        <li>{{ __('Run') }} <code class="px-1.5 py-0.5 bg-[var(--palette-notify-200)] rounded text-xs font-mono">npm run build</code> {{ __('to compile the new theme') }}</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif

                <flux:button
                    wire:click="saveColorScheme"
                    variant="primary"
                    :disabled="$saving">
                    <span wire:loading.remove wire:target="saveColorScheme">{{ __('Save & Apply Color Scheme') }}</span>
                    <span wire:loading wire:target="saveColorScheme">{{ __('Saving...') }}</span>
                </flux:button>

                @if($successMessage)
                    <div class="text-sm text-[var(--palette-success-700)]">
                        {{ $successMessage }}
                    </div>
                @endif
            </div>
        @endif
    </x-settings.layout>
</section>
