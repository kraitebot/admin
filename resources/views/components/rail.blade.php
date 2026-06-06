{{-- `active` is accepted (every page passes it) but no longer drives the
     highlight — Livewire's data-current does, see the <nav> comment. --}}
@props(['active' => 'dashboard'])
@php
    // Surface follows the host: the console domain gets the sysadmin
    // rail, every other host gets the trader rail. Same component, same
    // styling — only the item set differs. Console items are provisional
    // until the console surface gets its design pass.
    $console = request()->getHost() === config('domains.console');

    $items = $console
        ? [
            ['id' => 'dashboard',   'label' => 'Dashboard',   'route' => 'system.dashboard',     'params' => [],          'icon' => 'grid'],
            ['id' => 'users',       'label' => 'Users',       'route' => 'system.users',         'params' => [],          'icon' => 'users'],
            ['id' => 'commands',    'label' => 'Commands',    'route' => 'system.commands',      'params' => [],          'icon' => 'terminal'],
            ['id' => 'steps',       'label' => 'Steps',       'route' => 'system.steps',         'params' => ['default'], 'icon' => 'git-branch'],
            ['id' => 'backtesting', 'label' => 'Backtesting', 'route' => 'system.backtesting',   'params' => [],          'icon' => 'bar-chart-2'],
            ['id' => 'billing',     'label' => 'Billing',     'route' => 'system.billing.plans', 'params' => [],          'icon' => 'credit-card'],
            ['id' => 'sql',         'label' => 'SQL',         'route' => 'system.sql-query',     'params' => [],          'icon' => 'database'],
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
{{-- The rail lives inside @persist — it survives wire:navigate swaps, so the
     active item can't be server-rendered. Alpine owns the `data-current`
     attribute end-to-end (Livewire's own stamping is disabled via
     wire:current.ignore — its re-stamp after each swap raced the optimistic
     click toggle and made the previous link flick): set optimistically on
     click, re-synced from the URL on every `livewire:navigated` (which also
     fires on the initial page load and back/forward pops). --}}
<nav x-data="{
        hl: null,
        measureEl(el) { if (!el) { this.hl = null; return; } this.hl = { left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight }; },
        setActive(el) {
            this.$el.querySelectorAll('[data-current]').forEach(a => a !== el && a.removeAttribute('data-current'));
            el.setAttribute('data-current', '');
            this.measureEl(el);
        },
        syncFromUrl() {
            const here = location.origin + location.pathname.replace(/\/$/, '');
            const match = Array.from(this.$el.querySelectorAll('a[href]'))
                .find(a => a.href.replace(/\/$/, '') === here);
            if (match) { this.setActive(match); return; }
            this.$el.querySelectorAll('[data-current]').forEach(a => a.removeAttribute('data-current'));
            this.hl = null;
        },
     }"
     x-init="document.addEventListener('livewire:navigated', () => $nextTick(() => syncFromUrl())); window.addEventListener('resize', () => syncFromUrl()); if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => syncFromUrl())"
     class="relative z-30 h-full flex flex-col items-stretch bg-[#07090b] pt-3 pb-2
            max-[640px]:fixed max-[640px]:inset-x-0 max-[640px]:bottom-0 max-[640px]:top-auto max-[640px]:z-[60] max-[640px]:h-[62px] max-[640px]:w-full max-[640px]:flex-row max-[640px]:border-t max-[640px]:border-ink-3 max-[640px]:p-0
            max-[420px]:h-[56px]">
    <div class="flex items-center justify-center h-11 mb-4 max-[640px]:hidden">
        <img src="{{ asset('svg/snake-green.svg') }}" alt="Kraite" class="block w-[30px] h-[30px]"/>
    </div>
    <div class="relative flex flex-col gap-0.5 flex-1 justify-center px-2
                max-[640px]:flex-row max-[640px]:justify-around max-[640px]:items-center max-[640px]:px-1 max-[640px]:gap-0">
        <span aria-hidden="true"
              x-show="hl"
              x-cloak
              :style="hl ? `left:${hl.left}px;top:${hl.top}px;width:${hl.width}px;height:${hl.height}px` : ''"
              class="absolute z-0 bg-green-500 rounded-control pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)]
                     before:content-[''] before:absolute before:-left-3 before:top-1/2 before:-translate-y-1/2 before:w-[3px] before:h-[22px] before:bg-green-500 before:rounded-chip
                     max-[640px]:before:hidden"></span>

        @foreach($items as $item)
            <a href="{{ $item['route'] ? route($item['route'], $item['params']) : '#' }}"
               wire:navigate.hover
               wire:current.ignore
               @click="setActive($el)"
               {{-- color transition matches the pill slide (420ms, same curve) so the
                    label darkens in sync with the green arriving beneath it — an instant
                    snap leaves dark text on the dark rail until the pill lands --}}
               class="appearance-none border-0 cursor-pointer bg-transparent flex flex-col items-center gap-[5px] pt-2.5 pb-2 px-1 rounded-control font-mono text-[10px] font-medium tracking-[0.06em] uppercase relative z-[1] transition-colors duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)] no-underline
                      max-[640px]:flex-1 max-[640px]:py-2 max-[640px]:px-0.5 max-[640px]:text-[9px]
                      max-[420px]:p-0
                      text-ink-7 hover:text-ink-9 data-[current]:text-fg-on-accent data-[current]:hover:text-fg-on-accent">
                <x-dynamic-component :component="'feathericon-' . $item['icon']" class="w-[22px] h-[22px]" stroke-width="1.75"/>
                <span class="whitespace-nowrap max-[420px]:hidden">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>
    <div class="flex flex-col items-center gap-1.5 pt-2 mx-2 border-t border-ink-2 max-[640px]:hidden">
        <div class="w-2 h-2 rounded-chip bg-green-500" title="Engine online"></div>
    </div>
</nav>
