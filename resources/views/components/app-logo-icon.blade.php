{{--
    App Logo Icon Component

    Purpose: PromptlyAgent icon-only logo (no text)

    Features:
    - Geometric pattern design
    - Uses currentColor (inherits text color)
    - Scalable SVG
    - 40x42 viewBox
    - Fill rule and clip rule for proper rendering

    Usage:
    <x-app-logo-icon class="w-8 h-8" />
    <x-app-logo-icon class="w-16 h-16 text-accent" />

    Styling:
    - Accepts all standard SVG attributes
    - Size controlled via class
    - Color controlled via text color
    - Responsive and crisp at any size

    Use Cases:
    - Sidebar navigation
    - Favicon
    - Mobile header
    - Loading screens
    - Compact branding

    Related:
    - components.app-logo: Full logo with text
    - Used in layouts and navigation
--}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 42" {{ $attributes }}>
    <path
        fill="currentColor"
        fill-rule="evenodd"
        clip-rule="evenodd"
        d="M17.2 5.633 8.6.855 0 5.633v26.51l16.2 9 16.2-9v-8.442l7.6-4.223V9.856l-8.6-4.777-8.6 4.777V18.3l-5.6 3.111V5.633ZM38 18.301l-5.6 3.11v-6.157l5.6-3.11V18.3Zm-1.06-7.856-5.54 3.078-5.54-3.079 5.54-3.078 5.54 3.079ZM24.8 18.3v-6.157l5.6 3.111v6.158L24.8 18.3Zm-1 1.732 5.54 3.078-13.14 7.302-5.54-3.078 13.14-7.3v-.002Zm-16.2 7.89 7.6 4.222V38.3L2 30.966V7.92l5.6 3.111v16.892ZM8.6 9.3 3.06 6.222 8.6 3.143l5.54 3.08L8.6 9.3Zm21.8 15.51-13.2 7.334V38.3l13.2-7.334v-6.156ZM9.6 11.034l5.6-3.11v14.6l-5.6 3.11v-14.6Z"
    />
</svg>
