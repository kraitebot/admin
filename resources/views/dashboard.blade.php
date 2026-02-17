<x-hub-ui::layouts.dashboard title="Dashboard - {{ config('app.name') }}">
    <x-slot:sidebar>
        @include('partials.sidebar', ['sidebarSection' => null])
    </x-slot:sidebar>

    <x-hub-ui::page-header
        title="Dashboard"
        description="Welcome back, {{ auth()->user()->name }}."
    />

    <x-hub-ui::card>
        <x-hub-ui::empty-state
            title="Welcome back"
            description="Your dashboard will appear here."
        />
    </x-hub-ui::card>
</x-hub-ui::layouts.dashboard>
