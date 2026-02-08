<x-layouts.app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <h2 class="text-lg font-semibold">{{ __('Welcome to your dashboard!') }}</h2>
        @if(auth()->check() && auth()->user()->is_admin)
            <livewire:system-status />
        @endif
    </div>
</x-layouts.app>
