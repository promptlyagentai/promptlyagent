{{--
    Main Application Layout

    Wraps authenticated pages with sidebar navigation. The $title slot is optional
    and passed through to the sidebar component.
--}}
<x-layouts.app.sidebar :title="$title ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
