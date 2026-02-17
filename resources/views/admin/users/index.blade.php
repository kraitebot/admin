<x-hub-ui::layouts.dashboard title="Users - {{ config('app.name') }}">
    <x-slot:sidebar>
        @include('partials.sidebar', ['sidebarSection' => 'admin', 'sidebarHighlight' => 'admin.users'])
    </x-slot:sidebar>

    <x-hub-ui::page-header
        title="Users"
        description="Manage platform users."
    />

    <x-hub-ui::card>
        <x-hub-ui::empty-state
            title="No users yet"
            description="Platform users will appear here."
        />
    </x-hub-ui::card>
</x-hub-ui::layouts.dashboard>
