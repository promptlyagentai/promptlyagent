{{--
    Action Message Component

    Purpose: Temporary success message that auto-fades after action completion

    Features:
    - Listens for Livewire events
    - Auto-show on event
    - Auto-hide after 2 seconds
    - Fade out animation (1.5s)
    - Default "Saved." message
    - Customizable via slot

    Component Props:
    - @props string $on Livewire event name to listen for

    Behavior:
    - Triggers on specified Livewire event
    - Clears previous timeout (prevents stacking)
    - Shows message immediately
    - Hides after 2000ms
    - Smooth opacity transition

    Usage:
    <x-action-message :on="'profile-updated'" />
    <x-action-message :on="'saved'">Custom message!</x-action-message>

    Alpine.js Data:
    - shown: Boolean visibility state
    - timeout: Timeout handle for clearing

    Styling:
    - text-sm class by default
    - Can be overridden via attributes
    - Transition handled by Alpine directives
--}}
@props([
    'on',
])

<div
    x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 2000); })"
    x-show.transition.out.opacity.duration.1500ms="shown"
    x-transition:leave.opacity.duration.1500ms
    style="display: none"
    {{ $attributes->merge(['class' => 'text-sm']) }}
>
    {{ $slot->isEmpty() ? __('Saved.') : $slot }}
</div>
