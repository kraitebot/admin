<x-hub-ui::sidebar :activeSection="$sidebarSection ?? null" :activeHighlight="$sidebarHighlight ?? $sidebarSection ?? 'home'">
    {{-- Dashboard --}}
    <x-hub-ui::sidebar.link name="home" href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')" x-on:click.prevent="open = null; highlight = 'home'; setTimeout(() => Turbo.visit($el.href), 300)">
        <x-slot:icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
        </x-slot:icon>
        Home
    </x-hub-ui::sidebar.link>

    {{-- Accounts --}}
    <x-hub-ui::sidebar.section name="accounts" label="Accounts">
        <x-slot:icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2" />
                <circle cx="9" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 00-3-3.87" />
                <path d="M16 3.13a4 4 0 010 7.75" />
            </svg>
        </x-slot:icon>
        <x-hub-ui::sidebar.link name="accounts.manage" href="{{ route('accounts.index') }}" :active="request()->routeIs('accounts.*')" child>
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3h7a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h7" />
                    <path d="M8 10h8" />
                    <path d="M8 14h4" />
                </svg>
            </x-slot:icon>
            Manage
        </x-hub-ui::sidebar.link>
    </x-hub-ui::sidebar.section>

    {{-- Admin --}}
    <x-hub-ui::sidebar.section name="admin" label="Admin">
        <x-slot:icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12.22 2h-.44a2 2 0 00-2 2v.18a2 2 0 01-1 1.73l-.43.25a2 2 0 01-2 0l-.15-.08a2 2 0 00-2.73.73l-.22.38a2 2 0 00.73 2.73l.15.1a2 2 0 011 1.72v.51a2 2 0 01-1 1.74l-.15.09a2 2 0 00-.73 2.73l.22.38a2 2 0 002.73.73l.15-.08a2 2 0 012 0l.43.25a2 2 0 011 1.73V20a2 2 0 002 2h.44a2 2 0 002-2v-.18a2 2 0 011-1.73l.43-.25a2 2 0 012 0l.15.08a2 2 0 002.73-.73l.22-.39a2 2 0 00-.73-2.73l-.15-.08a2 2 0 01-1-1.74v-.5a2 2 0 011-1.74l.15-.09a2 2 0 00.73-2.73l-.22-.38a2 2 0 00-2.73-.73l-.15.08a2 2 0 01-2 0l-.43-.25a2 2 0 01-1-1.73V4a2 2 0 00-2-2z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
        </x-slot:icon>
        <x-hub-ui::sidebar.link name="admin.users" href="{{ route('admin.users.index') }}" :active="request()->routeIs('admin.users.*')" child>
            <x-slot:icon>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4-4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 00-3-3.87" />
                    <path d="M16 3.13a4 4 0 010 7.75" />
                </svg>
            </x-slot:icon>
            Users
        </x-hub-ui::sidebar.link>
    </x-hub-ui::sidebar.section>

    <x-slot:footer>
        <div class="flex flex-col items-center gap-3">
            {{-- User avatar --}}
            <div class="w-9 h-9 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-sm font-medium">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </div>

            {{-- Logout --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-white/40 hover:text-white/70 transition-colors" title="Sign out">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" y1="12" x2="9" y2="12" />
                    </svg>
                </button>
            </form>
        </div>
    </x-slot:footer>
</x-hub-ui::sidebar>
