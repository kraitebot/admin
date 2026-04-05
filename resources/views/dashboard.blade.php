<x-app-layout>
    {{-- Header --}}
    <div class="mb-10">
        <h1 class="text-3xl font-semibold ui-text tracking-tight">Dashboard</h1>
        <p class="text-sm ui-text-subtle mt-1">Welcome back, {{ Auth::user()->name }}.</p>
    </div>

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        {{-- Users --}}
        <div class="ui-card overflow-hidden">
            <div class="px-6 py-5 flex items-center gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background-color: rgb(var(--ui-primary) / 0.12)">
                    <svg class="w-6 h-6" style="color: rgb(var(--ui-primary))" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text-muted">Users</p>
                    <p class="text-2xl font-bold ui-text mt-0.5">{{ \Kraite\Core\Models\User::count() }}</p>
                </div>
            </div>
        </div>

        {{-- Accounts --}}
        <div class="ui-card overflow-hidden">
            <div class="px-6 py-5 flex items-center gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background-color: rgb(var(--ui-success) / 0.12)">
                    <svg class="w-6 h-6" style="color: rgb(var(--ui-success))" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text-muted">Accounts</p>
                    <p class="text-2xl font-bold ui-text mt-0.5">{{ \Kraite\Core\Models\Account::count() }}</p>
                </div>
            </div>
        </div>

        {{-- Positions --}}
        <div class="ui-card overflow-hidden">
            <div class="px-6 py-5 flex items-center gap-4">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background-color: rgb(var(--ui-info) / 0.12)">
                    <svg class="w-6 h-6" style="color: rgb(var(--ui-info))" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium ui-text-muted">Positions</p>
                    <p class="text-2xl font-bold ui-text mt-0.5">{{ \Kraite\Core\Models\Position::count() }}</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
