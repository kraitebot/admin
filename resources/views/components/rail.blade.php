{{-- `active` is accepted (every page passes it) but no longer drives the
     highlight — the global $store.rail does, see the <nav> comment. --}}
@props(['active' => 'dashboard'])
@php
    // Surface follows the route group, not the host: any `system.*` route gets
    // the sysadmin rail, every other route gets the trader rail. Same
    // component, same styling — only the item set differs. Console items are
    // provisional until the console surface gets its design pass.
    $console = request()->routeIs('system.*');

    $items = $console
        ? [
            ['id' => 'overview',    'label' => 'Overview',   'route' => 'system.dashboard',    'params' => [],          'icon' => 'activity'],
            ['id' => 'positions',   'label' => 'Positions',  'route' => 'system.positions',    'params' => [],          'icon' => 'layers'],
            ['id' => 'engine',      'label' => 'Engine',     'route' => 'system.engine',       'params' => [],          'icon' => 'cpu'],
            ['id' => 'backtesting', 'label' => 'Backtest',   'route' => 'system.backtesting',  'params' => [],          'icon' => 'bar-chart-2'],
            ['id' => 'dispatch',    'label' => 'Dispatch',   'route' => 'system.steps',        'params' => ['default'], 'icon' => 'git-branch'],
            ['id' => 'infra',      'label' => 'Infra',      'route' => 'system.infra',      'params' => [],          'icon' => 'server'],
            ['id' => 'exchanges',  'label' => 'Exchanges',  'route' => 'system.exchanges',  'params' => [],          'icon' => 'shuffle'],
            ['id' => 'sql',        'label' => 'SQL',        'route' => 'system.sql-query',  'params' => [],          'icon' => 'database'],
            ['id' => 'revenue',    'label' => 'Revenue',    'route' => 'system.revenue',    'params' => [],          'icon' => 'credit-card'],
            ['id' => 'settings',   'label' => 'Settings',   'route' => 'system.settings',   'params' => [],          'icon' => 'sliders'],
        ]
        : [
            ['id' => 'dashboard',   'label' => 'Dashboard',   'route' => 'dashboard',         'params' => [], 'icon' => 'grid'],
            ['id' => 'positions',   'label' => 'Positions',   'route' => 'accounts.positions','params' => [], 'icon' => 'layers'],
            ['id' => 'projections', 'label' => 'Projections', 'route' => 'projections',       'params' => [], 'icon' => 'trending-up'],
            ['id' => 'accounts',    'label' => 'Accounts',    'route' => 'accounts.edit',     'params' => [], 'icon' => 'link'],
            ['id' => 'billing',     'label' => 'Billing',     'route' => 'billing',           'params' => [], 'icon' => 'credit-card'],
            ['id' => 'profile',     'label' => 'Profile',     'route' => 'profile.edit',      'params' => [], 'icon' => 'user'],
        ];
@endphp
{{-- The rail is persisted across wire:navigate swaps and Alpine re-inits it
     after each swap — so the active state lives in a GLOBAL Alpine store
     (`$store.rail`) with single module-level handlers in app.js. The markup
     here only BINDS to the store; it owns nothing. --}}
{{-- Desktop vertical rail. Phone widths (≤640px) get the slide-in drawer
     below instead — the old fixed bottom bar couldn't hold ten labeled
     items in the 421-640px band. --}}
<nav data-rail x-data
     class="relative z-30 h-full flex flex-col items-stretch bg-[#07090b] pt-3 pb-2 max-[640px]:hidden">
    <div class="flex items-center justify-center h-11 mb-4">
        <img src="{{ asset('svg/snake-green.svg') }}" alt="Kraite" class="block w-[30px] h-[30px]"/>
    </div>
    <div class="relative flex flex-col gap-0.5 flex-1 justify-center px-2">
        <span aria-hidden="true"
              x-show="$store.rail.hl"
              x-cloak
              :style="$store.rail.hl ? `left:${$store.rail.hl.left}px;top:${$store.rail.hl.top}px;width:${$store.rail.hl.width}px;height:${$store.rail.hl.height}px` : ''"
              {{-- accent-driven: trader green, console violet — follows the surface --accent --}}
              class="absolute z-0 bg-accent rounded-control pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)]
                     before:content-[''] before:absolute before:-left-3 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[22px] before:bg-accent before:rounded-chip"></span>

        @foreach($items as $item)
            <a href="{{ $item['route'] ? route($item['route'], $item['params']) : '#' }}"
               data-id="{{ $item['id'] }}"
               wire:navigate.hover
               wire:current.ignore
               @click="window.railGo('{{ $item['id'] }}', $event.currentTarget)"
               {{-- color transition matches the pill slide (420ms, same curve) so the
                    arriving label darkens in sync with the green sliding beneath it.
                    `hover:text-fg-on-accent` on the active item is load-bearing: the
                    global `a:hover { color: var(--accent) }` (tokens.css) outranks a
                    plain `text-fg-on-accent` on specificity, so without it the active
                    link turns accent-on-accent (invisible) the moment the pointer
                    rests on it after a click. The hover utility ties that selector and
                    wins on source order (utilities layer is emitted last). --}}
               :class="$store.rail.activeId === '{{ $item['id'] }}' ? 'text-fg-on-accent hover:text-fg-on-accent' : 'text-ink-7 hover:text-ink-9'"
               class="appearance-none border-0 cursor-pointer bg-transparent flex flex-col items-center gap-[5px] pt-2.5 pb-2 px-1 rounded-control font-mono text-[10px] font-medium tracking-[0.06em] uppercase relative z-[1] transition-colors duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)] no-underline">
                <x-dynamic-component :component="'feathericon-' . $item['icon']" class="w-[22px] h-[22px]" stroke-width="1.75"/>
                <span class="whitespace-nowrap">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
    <div class="flex flex-col items-center gap-1.5 pt-2 mx-2 border-t border-ink-2">
        <div class="w-2 h-2 rounded-chip bg-green-500" title="Engine online"></div>
    </div>
</nav>

{{-- Phone nav drawer (≤640px). Opened by the top-bar hamburger via
     $store.rail.drawerOpen; closes on backdrop tap, Escape, or any
     navigation (railGo). Rows are 48px — comfortably tappable. --}}
<div x-data x-show="$store.rail.drawerOpen" x-cloak
     @keydown.escape.window="$store.rail.drawerOpen = false"
     class="fixed inset-0 z-[80] min-[641px]:hidden">
    <div class="absolute inset-0 bg-black/60" @click="$store.rail.drawerOpen = false"></div>
    <aside class="absolute inset-y-0 left-0 w-[270px] max-w-[80vw] bg-[#07090b] border-r border-ink-3 flex flex-col pt-4 pb-6 overflow-y-auto"
           x-transition:enter="transition-transform duration-200 ease-out"
           x-transition:enter-start="-translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition-transform duration-150 ease-in"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="-translate-x-full">
        <div class="flex items-center gap-3 px-5 mb-4">
            <img src="{{ asset('svg/snake-green.svg') }}" alt="Kraite" class="block w-[26px] h-[26px]"/>
            <span class="font-sans font-bold text-[15px] tracking-[-0.01em] text-ink-9">Kraite</span>
        </div>
        <div class="flex flex-col gap-1 px-3">
            @foreach($items as $item)
                <a href="{{ route($item['route'], $item['params']) }}"
                   data-id="{{ $item['id'] }}"
                   wire:navigate
                   wire:current.ignore
                   @click="window.railGo('{{ $item['id'] }}', null)"
                   :class="$store.rail.activeId === '{{ $item['id'] }}' ? 'bg-accent text-fg-on-accent hover:text-fg-on-accent' : 'text-ink-7 hover:text-ink-9 hover:bg-ink-1'"
                   class="flex items-center gap-3.5 h-12 px-4 rounded-control font-mono text-[12px] font-medium tracking-[0.06em] uppercase no-underline transition-colors duration-fast">
                    <x-dynamic-component :component="'feathericon-' . $item['icon']" class="w-[20px] h-[20px] flex-shrink-0" stroke-width="1.75"/>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    </aside>
</div>
