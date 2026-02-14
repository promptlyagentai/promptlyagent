{{--
    Settings Layout Component

    Purpose: Consistent layout wrapper for all settings pages with sidebar navigation

    Features:
    - Side navigation menu with active state
    - Responsive design (stacks on mobile)
    - Optional wide content area
    - Heading and subheading slots

    Component Props:
    - @props bool $wide Whether to use full width (default: constrained to max-w-lg)
    - @props string $heading Page heading text
    - @props string $subheading Page subheading/description

    Navigation Links:
    - Profile: User name and email
    - AI Persona: Personal context for AI interactions
    - Password: Change password
    - Appearance: Custom color schemes
    - API Tokens: Programmatic access
    - Interactive Help: Help widget configuration
    - Integrations: External service connections

    Layout Structure:
    - Left sidebar (220px on desktop, full width on mobile)
    - Separator on mobile
    - Main content area (flexible width, optionally constrained)

    Usage:
    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your profile')">
        Form content here...
    </x-settings.layout>

    Related:
    - Used by all livewire.settings.* pages
--}}
@props(['wide' => false])

<div class="flex items-start max-md:flex-col">
    {{-- Sidebar Navigation --}}
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist>
            <flux:navlist.item :href="route('settings.profile')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.ai-persona')" wire:navigate>{{ __('AI Persona') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.password')" wire:navigate>{{ __('Password') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.appearance')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.api-tokens')" wire:navigate>{{ __('API Tokens') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.help-widget')" wire:navigate>{{ __('Interactive Help') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.research-suggestions')" wire:navigate>{{ __('Research Suggestions') }}</flux:navlist.item>
            <flux:navlist.item :href="route('settings.pwa')" wire:navigate>{{ __('PWA/Mobile Access') }}</flux:navlist.item>
            <flux:navlist.item :href="route('integrations.index')">{{ __('Integrations') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full {{ $wide ? '' : 'max-w-lg' }}">
            {{ $slot }}
        </div>
    </div>
</div>
