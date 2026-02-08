{{--
    Auth Layout Wrapper

    Purpose: Simple wrapper that delegates to auth.simple layout

    Features:
    - Passes title to simple layout
    - Provides consistent auth layout interface

    Slots:
    - $slot: Page content
    - $title: Optional page title

    Related:
    - components.layouts.auth.simple: Actual layout implementation
--}}
<x-layouts.auth.simple :title="$title ?? null">
    {{ $slot }}
</x-layouts.auth.simple>
