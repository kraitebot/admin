@php
    $activeSection = $activeSection ?: match(true) {
        request()->is('accounts/*') => 'accounts',
        request()->is('system/*')   => 'system',
        default                      => 'dashboard',
    };
    $activeHighlight = $activeHighlight ?: match(true) {
        request()->routeIs('projections') => 'projections',
        request()->routeIs('billing') => 'billing',
        request()->routeIs('accounts.positions') => 'positions',
        request()->routeIs('accounts.edit') => 'edit-account',
        request()->routeIs('system.dashboard') => 'system-dashboard',
        request()->routeIs('system.sql-query') => 'sql-query',
        request()->routeIs('system.commands') => 'commands',
        request()->routeIs('system.step-dispatcher') => 'step-dispatcher',
        request()->routeIs('system.backtesting') => 'backtesting',
        request()->routeIs('system.lifecycle*') => 'lifecycle',
        request()->routeIs('system.users*'), request()->routeIs('system.billing.*') => 'system-users',
        request()->routeIs('system.ui-components') => 'ui-components',
        default => $activeSection,
    };
@endphp

<x-hub-ui::layouts.dashboard :title="$title ?? config('app.name')" :flush="$flush">
    <x-slot:sidebar>
        <x-hub-ui::sidebar :activeSection="$activeSection" :activeHighlight="$activeHighlight">
            <x-slot:logo>
                <a href="{{ route('dashboard') }}" wire:navigate>
                    <img src="{{ asset('logos/snake-green.svg') }}" alt="Kraite" class="w-10 h-10" />
                </a>
            </x-slot:logo>

            <a
                href="{{ route('dashboard') }}" wire:navigate
                data-nav-item="dashboard"
                @click="highlight = 'dashboard'; $nextTick(() => open = null)"
                class="flex flex-col items-center gap-1 py-2 rounded-xl cursor-pointer transition-colors relative z-10"
                :class="highlight === 'dashboard' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
            >
                <span class="w-7 h-7">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
                    </svg>
                </span>
                <span class="text-xs">Dashboard</span>
            </a>

            <a
                href="{{ route('projections') }}" wire:navigate
                data-nav-item="projections"
                @click="highlight = 'projections'; $nextTick(() => open = null)"
                class="flex flex-col items-center gap-1 py-2 rounded-xl cursor-pointer transition-colors relative z-10"
                :class="highlight === 'projections' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
            >
                <span class="w-7 h-7">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </span>
                <span class="text-xs">Projections</span>
            </a>

            @if(! auth()->user()?->is_admin)
            <a
                href="{{ route('billing') }}" wire:navigate
                data-nav-item="billing"
                @click="highlight = 'billing'; $nextTick(() => open = null)"
                class="flex flex-col items-center gap-1 py-2 rounded-xl cursor-pointer transition-colors relative z-10"
                :class="highlight === 'billing' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
            >
                <span class="w-7 h-7">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                    </svg>
                </span>
                <span class="text-xs">Billing</span>
            </a>
            @endif

            <a
                href="{{ route('bscs') }}" wire:navigate
                data-nav-item="bscs"
                @click="highlight = 'bscs'; $nextTick(() => open = null)"
                class="flex flex-col items-center gap-1 py-2 rounded-xl cursor-pointer transition-colors relative z-10"
                :class="highlight === 'bscs' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
            >
                <span class="w-7 h-7">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                    </svg>
                </span>
                <span class="text-xs">BSCS</span>
            </a>

            <a
                href="{{ route('accounts.positions') }}" wire:navigate
                data-nav-item="positions"
                @click="highlight = 'positions'; $nextTick(() => open = null)"
                class="flex flex-col items-center gap-1 py-2 rounded-xl cursor-pointer transition-colors relative z-10"
                :class="highlight === 'positions' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
            >
                <span class="w-7 h-7">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </span>
                <span class="text-xs">Positions</span>
            </a>

            @if(auth()->user()?->is_admin)
            <x-hub-ui::sidebar.section name="system" label="System">
                <x-slot:icon>
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.248a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                </x-slot:icon>

                <a
                    href="{{ route('system.dashboard') }}" wire:navigate
                    data-nav-item="system-dashboard"
                    @click="highlight = 'system-dashboard'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'system-dashboard' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
                        </svg>
                    </span>
                    <span class="text-xs">Dashboard</span>
                </a>

                <a
                    href="{{ route('system.users') }}" wire:navigate
                    data-nav-item="system-users"
                    @click="highlight = 'system-users'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'system-users' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                    </span>
                    <span class="text-xs">Billing</span>
                </a>

                <a
                    href="{{ route('system.sql-query') }}" wire:navigate
                    data-nav-item="sql-query"
                    @click="highlight = 'sql-query'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'sql-query' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                        </svg>
                    </span>
                    <span class="text-xs">SQL Query</span>
                </a>

                <a
                    href="{{ route('system.commands') }}" wire:navigate
                    data-nav-item="commands"
                    @click="highlight = 'commands'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'commands' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </span>
                    <span class="text-xs">Commands</span>
                </a>

                <a
                    href="{{ route('system.step-dispatcher') }}" wire:navigate
                    data-nav-item="step-dispatcher"
                    @click="highlight = 'step-dispatcher'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'step-dispatcher' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                        </svg>
                    </span>
                    <span class="text-xs">Steps</span>
                </a>

                @if(auth()->user()?->is_admin)
                <a
                    href="{{ route('system.backtesting') }}" wire:navigate
                    data-nav-item="backtesting"
                    @click="highlight = 'backtesting'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'backtesting' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                    </span>
                    <span class="text-xs">Backtesting</span>
                </a>

                <a
                    href="{{ route('system.lifecycle') }}" wire:navigate
                    data-nav-item="lifecycle"
                    @click="highlight = 'lifecycle'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'lifecycle' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0v3.75c0 .414.336.75.75.75h6a.75.75 0 0 0 .75-.75V16.5m-7.5 0h7.5M9 11.25 12 8.25l3 3" />
                        </svg>
                    </span>
                    <span class="text-xs">Lifecycle</span>
                </a>
                @endif

                <a
                    href="{{ route('system.ui-components') }}" wire:navigate
                    data-nav-item="ui-components"
                    @click="highlight = 'ui-components'"
                    class="flex flex-col items-center gap-1 py-2 rounded-lg transition-colors relative z-10"
                    :class="highlight === 'ui-components' ? 'ui-sidebar-text-active' : 'ui-sidebar-text hover:ui-text-muted'"
                >
                    <span class="w-5 h-5">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9h18" />
                        </svg>
                    </span>
                    <span class="text-xs">UI Kit</span>
                </a>
            </x-hub-ui::sidebar.section>
            @endif

        </x-hub-ui::sidebar>
    </x-slot:sidebar>

    <x-slot:topbar>
        <div class="flex items-center justify-end gap-4 pl-16 pr-4 py-3 sm:px-6 lg:pl-6">
            {{-- Notifications (stub) --}}
            <button type="button"
                    class="relative p-1.5 rounded-md ui-text-muted hover:ui-text transition-colors"
                    title="Notifications"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                </svg>
                <span class="absolute top-1 right-1 w-1.5 h-1.5 rounded-full" style="background-color: rgb(var(--ui-primary))"></span>
            </button>

            {{-- User name + avatar --}}
            <div class="flex items-center gap-2 pl-3 border-l ui-border">
                <div class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-semibold"
                     style="background-color: rgb(var(--ui-primary) / 0.18); color: rgb(var(--ui-primary))">
                    {{ strtoupper(mb_substr(auth()->user()->name ?? '?', 0, 1)) }}
                </div>
                <span class="text-xs font-medium ui-text hidden sm:inline">{{ auth()->user()->name }}</span>
            </div>

            {{-- Theme toggle --}}
            <x-hub-ui::theme-toggle />

            {{-- Profile --}}
            <a href="{{ route('profile.edit') }}" wire:navigate
               class="p-1.5 rounded-md ui-text-muted hover:ui-text transition-colors"
               title="Profile"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
            </a>

            {{-- Logout --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="p-1.5 rounded-md ui-text-muted hover:ui-text-danger transition-colors"
                        title="Sign out"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                    </svg>
                </button>
            </form>
        </div>
    </x-slot:topbar>

    {{ $slot }}

    <x-slot:footerbar>
        <div class="flex items-center justify-between gap-5 px-4 sm:px-6 lg:px-12 py-4 text-xs ui-text-muted flex-wrap">
            <div class="flex items-center gap-4 flex-wrap">
                <span class="inline-flex items-center gap-1.5 font-mono ui-tabular ui-text-subtle">
                    <span class="w-1.5 h-1.5 rounded-full" style="background-color: rgb(var(--ui-success))"></span>
                    v0.1.0
                </span>
                <span class="hidden sm:inline" style="width:1px;height:14px;background-color:rgb(var(--ui-border))"></span>
                <a href="#" class="hover:ui-text-primary transition-colors font-medium">Trade with responsibility</a>
                <span class="hidden sm:inline" style="width:1px;height:14px;background-color:rgb(var(--ui-border))"></span>
                <a href="#" class="hover:ui-text-primary transition-colors font-medium">Know your risks</a>
                <span class="hidden md:inline" style="width:1px;height:14px;background-color:rgb(var(--ui-border))"></span>
                <a href="#" class="hidden md:inline hover:ui-text-primary transition-colors">Terms</a>
                <a href="#" class="hidden md:inline hover:ui-text-primary transition-colors">Privacy</a>
                <a href="#" class="hidden md:inline hover:ui-text-primary transition-colors">Status</a>
            </div>
            <div class="flex items-center gap-2 ui-text-subtle">
                <span>&copy; {{ date('Y') }} Kraite</span>
                <span class="hidden sm:inline">·</span>
                <span class="hidden sm:inline italic">Crypto futures · use at your own discretion</span>
            </div>
        </div>
    </x-slot:footerbar>
</x-hub-ui::layouts.dashboard>
