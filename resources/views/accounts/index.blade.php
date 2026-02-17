<x-hub-ui::layouts.dashboard title="Accounts - {{ config('app.name') }}">
    <x-slot:sidebar>
        @include('partials.sidebar', ['sidebarSection' => 'accounts', 'sidebarHighlight' => 'accounts.manage'])
    </x-slot:sidebar>

    <x-hub-ui::page-header
        title="Accounts"
        description="Manage trading accounts."
    />

    <x-hub-ui::card>
        <x-hub-ui::empty-state
            title="No accounts yet"
            description="Trading accounts will appear here."
        />
    </x-hub-ui::card>
</x-hub-ui::layouts.dashboard>
