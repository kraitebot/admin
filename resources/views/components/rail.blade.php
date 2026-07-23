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
            ['id' => 'billing',    'label' => 'Billing',    'route' => 'system.users',      'params' => [],          'icon' => 'dollar-sign'],
            ['id' => 'settings',   'label' => 'Settings',   'route' => 'system.settings',   'params' => [],          'icon' => 'sliders'],
        ]
        : [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'params' => [], 'icon' => 'grid'],
            ['id' => 'positions', 'label' => 'Positions', 'route' => 'accounts.positions', 'params' => [], 'icon' => 'layers'],
            [
                'id' => 'projections',
                'label' => 'Projections',
                'icon' => 'trending-up',
                'children' => [
                    ['id' => 'projections-monthly', 'label' => 'Monthly', 'route' => 'projections', 'params' => []],
                    ['id' => 'projections-yearly', 'label' => 'Yearly', 'route' => 'projections.yearly', 'params' => []],
                ],
            ],
            ['id' => 'accounts', 'label' => 'Accounts', 'route' => 'accounts.edit', 'params' => [], 'icon' => 'link'],
            ['id' => 'billing', 'label' => 'Billing', 'route' => 'billing', 'params' => [], 'icon' => 'credit-card'],
            ['id' => 'profile', 'label' => 'Profile', 'route' => 'profile.edit', 'params' => [], 'icon' => 'user'],
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
     class="relative z-30 h-full min-h-0 flex flex-col items-stretch bg-[#07090b] pt-3 pb-2 max-[640px]:hidden">
    <div class="flex items-center justify-center h-11 mb-4">
        <img src="{{ asset('svg/snake-green.svg') }}" alt="Kraite" class="block w-[30px] h-[30px]"/>
    </div>
    <div data-rail-scroll class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden overscroll-contain">
        <div data-rail-track class="relative min-h-full flex flex-col gap-0.5 justify-center px-2 py-2">
            <span aria-hidden="true"
                  x-show="$store.rail.hl"
                  x-cloak
                  :style="$store.rail.hl ? `left:${$store.rail.hl.left}px;top:${$store.rail.hl.top}px;width:${$store.rail.hl.width}px;height:${$store.rail.hl.height}px` : ''"
                  {{-- accent-driven: trader green, console violet — follows the surface --accent --}}
                  class="absolute z-0 bg-accent rounded-control pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)]
                         before:content-[''] before:absolute before:-left-3 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[22px] before:bg-accent before:rounded-chip"></span>

            @foreach($items as $item)
                @if(isset($item['children']))
                    <div class="relative z-[1] flex flex-col">
                        <button type="button"
                                @click="$store.rail.toggleSection('{{ $item['id'] }}')"
                                :aria-expanded="$store.rail.openSection === '{{ $item['id'] }}'"
                                :class="$store.rail.activeId?.startsWith('{{ $item['id'] }}-') || $store.rail.openSection === '{{ $item['id'] }}' ? 'text-ink-9' : 'text-ink-7 hover:text-ink-9'"
                                class="appearance-none border-0 cursor-pointer bg-transparent flex flex-col items-center gap-[5px] pt-2.5 pb-2 px-1 rounded-control font-mono text-[10px] font-medium tracking-[0.06em] uppercase transition-colors duration-fast">
                            <x-dynamic-component :component="'feathericon-' . $item['icon']" class="w-[22px] h-[22px]" stroke-width="1.75"/>
                            <span class="flex items-center gap-1 whitespace-nowrap">
                                {{ $item['label'] }}
                                <x-feathericon-chevron-down class="w-2.5 h-2.5 transition-transform duration-fast"
                                                            ::class="$store.rail.openSection === '{{ $item['id'] }}' ? 'rotate-180' : ''"
                                                            stroke-width="2"/>
                            </span>
                        </button>

                        <div x-show="$store.rail.openSection === '{{ $item['id'] }}'"
                             x-cloak
                             x-collapse
                             class="flex flex-col gap-0.5 mt-0.5">
                            @foreach($item['children'] as $child)
                                <a href="{{ route($child['route'], $child['params']) }}"
                                   data-id="{{ $child['id'] }}"
                                   data-parent="{{ $item['id'] }}"
                                   wire:navigate.hover
                                   wire:current.ignore
                                   @click="window.railGo('{{ $child['id'] }}', $event.currentTarget)"
                                   :class="$store.rail.activeId === '{{ $child['id'] }}' ? 'text-fg-on-accent hover:text-fg-on-accent' : 'text-ink-7 hover:text-ink-9'"
                                   class="appearance-none border-0 cursor-pointer bg-transparent flex items-center justify-center h-8 px-1 rounded-control font-mono text-[9px] font-medium tracking-[0.07em] uppercase relative z-[1] transition-colors duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)] no-underline">
                                    <span class="whitespace-nowrap">{{ $child['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
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
                @endif
            @endforeach
        </div>
    </div>
</nav>

{{-- Phone nav drawer (≤640px). Opened by the top-bar hamburger via
     $store.rail.drawerOpen; closes on backdrop tap, Escape, or any
     navigation (railGo).

     Animation anatomy — the wrapper stays mounted (a parent x-show would
     cut the children's leave transitions dead), so it click-blocks only
     while open via the pointer-events binding. Backdrop fades; the panel
     slides on the app's signature curve (same cubic-bezier as the rail
     pill) — brisk in, faster out. Items cascade in with a slight stagger
     (drawer-item-in keyframes, per-row delay). --}}
<div x-data
     @keydown.escape.window="$store.rail.drawerOpen = false"
     {{-- pointer-events via :style, not classes — a static utility class
          can't be stripped by a class binding, and two competing
          pointer-events utilities resolve by stylesheet order, not intent. --}}
     style="pointer-events: none"
     :style="$store.rail.drawerOpen ? 'pointer-events: auto' : 'pointer-events: none'"
     class="fixed inset-0 z-[80] min-[641px]:hidden">
    <div x-show="$store.rail.drawerOpen" x-cloak
         x-transition:enter="transition-opacity duration-[260ms] ease-out"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-[190ms] ease-in"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="$store.rail.drawerOpen = false"
         @touchmove.prevent
         class="absolute inset-0 bg-black/60 backdrop-blur-[2px]"></div>
    <aside x-show="$store.rail.drawerOpen" x-cloak
           x-transition:enter="transition-transform duration-[300ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
           x-transition:enter-start="-translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition-transform duration-[210ms] ease-[cubic-bezier(0.55,0,0.85,0.36)]"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="-translate-x-full"
           class="absolute inset-y-0 left-0 w-[270px] max-w-[80vw] bg-[#07090b] border-r border-ink-3 shadow-3 flex flex-col pt-4 overflow-y-auto overscroll-contain will-change-transform
                  pb-[calc(env(safe-area-inset-bottom)+24px)]">
        <div class="flex items-center gap-3 px-5 mb-4">
            <img src="{{ asset('svg/snake-green.svg') }}" alt="Kraite" class="block w-[26px] h-[26px]"/>
            <span class="font-sans font-bold text-[15px] tracking-[-0.01em] text-ink-9">Kraite</span>
            @if($console)
                <span class="font-mono text-[9px] font-bold tracking-[0.12em] uppercase py-[2px] px-1.5 rounded-chip text-accent"
                      style="background: color-mix(in srgb, var(--accent) 16%, transparent)">Sysadmin</span>
            @endif
        </div>
        <div class="flex flex-col gap-1 px-3">
            @foreach($items as $item)
                @if(isset($item['children']))
                    <div x-bind:style="$store.rail.drawerOpen && 'animation: drawer-item-in 240ms cubic-bezier(0.16,1,0.3,1) both; animation-delay: {{ 40 + $loop->index * 22 }}ms'">
                        <button type="button"
                                @click="$store.rail.toggleSection('{{ $item['id'] }}')"
                                :aria-expanded="$store.rail.openSection === '{{ $item['id'] }}'"
                                :class="$store.rail.activeId?.startsWith('{{ $item['id'] }}-') || $store.rail.openSection === '{{ $item['id'] }}' ? 'text-ink-9 bg-ink-1' : 'text-ink-7 hover:text-ink-9 hover:bg-ink-1'"
                                class="appearance-none border-0 cursor-pointer bg-transparent w-full flex items-center gap-3.5 h-12 px-4 rounded-control font-mono text-[12px] font-medium tracking-[0.06em] uppercase transition-colors duration-fast">
                            <x-dynamic-component :component="'feathericon-' . $item['icon']" class="w-[20px] h-[20px] flex-shrink-0" stroke-width="1.75"/>
                            <span>{{ $item['label'] }}</span>
                            <x-feathericon-chevron-down class="w-3.5 h-3.5 ml-auto transition-transform duration-fast"
                                                        ::class="$store.rail.openSection === '{{ $item['id'] }}' ? 'rotate-180' : ''"
                                                        stroke-width="2"/>
                        </button>

                        <div x-show="$store.rail.openSection === '{{ $item['id'] }}'"
                             x-cloak
                             x-collapse
                             class="flex flex-col gap-1 mt-1 ml-[34px]">
                            @foreach($item['children'] as $child)
                                <a href="{{ route($child['route'], $child['params']) }}"
                                   data-id="{{ $child['id'] }}"
                                   data-parent="{{ $item['id'] }}"
                                   wire:navigate
                                   wire:current.ignore
                                   @click="window.railGo('{{ $child['id'] }}', null)"
                                   :class="$store.rail.activeId === '{{ $child['id'] }}' ? 'bg-accent text-fg-on-accent hover:text-fg-on-accent' : 'text-ink-7 hover:text-ink-9 hover:bg-ink-1'"
                                   class="flex items-center h-10 px-4 rounded-control font-mono text-[11px] font-medium tracking-[0.06em] uppercase no-underline transition-colors duration-fast">
                                    <span>{{ $child['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ route($item['route'], $item['params']) }}"
                       data-id="{{ $item['id'] }}"
                       wire:navigate
                       wire:current.ignore
                       @click="window.railGo('{{ $item['id'] }}', null)"
                       x-bind:style="$store.rail.drawerOpen && 'animation: drawer-item-in 240ms cubic-bezier(0.16,1,0.3,1) both; animation-delay: {{ 40 + $loop->index * 22 }}ms'"
                       :class="$store.rail.activeId === '{{ $item['id'] }}' ? 'bg-accent text-fg-on-accent hover:text-fg-on-accent' : 'text-ink-7 hover:text-ink-9 hover:bg-ink-1'"
                       class="flex items-center gap-3.5 h-12 px-4 rounded-control font-mono text-[12px] font-medium tracking-[0.06em] uppercase no-underline transition-colors duration-fast">
                        <x-dynamic-component :component="'feathericon-' . $item['icon']" class="w-[20px] h-[20px] flex-shrink-0" stroke-width="1.75"/>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    </aside>
</div>
