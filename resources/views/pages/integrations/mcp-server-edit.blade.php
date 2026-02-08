<x-layouts.app>
    <section class="w-full pb-20 xl:pb-0">
        @include('partials.settings-heading')

        <livewire:mcp-server-edit :integration="$integration" />
    </section>
</x-layouts.app>
