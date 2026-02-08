{{--
    Placeholder Pattern Component

    Purpose: SVG pattern background for empty states and placeholders

    Features:
    - Diagonal stripe pattern
    - Unique pattern ID per instance
    - Responsive sizing (100% width/height)
    - Customizable via attributes

    Component Props:
    - @props string $id Unique pattern identifier (auto-generated)

    Pattern Style:
    - 8x8 pixel repeating grid
    - Thin diagonal lines (0.5 stroke-width)
    - Subtle, non-intrusive design

    Usage:
    <x-placeholder-pattern class="w-full h-32" />
    <x-placeholder-pattern :id="'custom-pattern'" />

    Attributes:
    - Accepts all standard SVG attributes
    - Class, style, viewBox can be customized
    - Pattern scales to container size

    Use Cases:
    - Empty state backgrounds
    - Loading placeholders
    - Visual separators
    - Decorative patterns
--}}
@props([
    'id' => uniqid(),
])

<svg {{ $attributes }} fill="none">
    <defs>
        <pattern id="pattern-{{ $id }}" x="0" y="0" width="8" height="8" patternUnits="userSpaceOnUse">
            <path d="M-1 5L5 -1M3 9L8.5 3.5" stroke-width="0.5"></path>
        </pattern>
    </defs>
    <rect stroke="none" fill="url(#pattern-{{ $id }})" width="100%" height="100%"></rect>
</svg>
