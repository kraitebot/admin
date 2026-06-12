@php
    // Positions — REAL DATA (DB), rendered server-side from
    // PositionsController::index ($positions, $closed, $details). NOT wired:
    //  - Liq price (exchange-only) shows "—"
    //  - the DB-vs-exchange reconcile sub-row (live data() endpoint) is omitted
    //  - the "All accounts" picker is display-only (positions already span all
    //    of the user's accounts); per-account filtering isn't wired yet
    $accountIds = auth()->user()->is_admin
        ? null
        : Kraite\Core\Models\Account::where('user_id', auth()->id())->pluck('id');
    $noPositions = ! Kraite\Core\Models\Position::query()
        ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds))
        ->exists();

    // ---- formatters (shared with partials.position-detail) ----
    $num = fn (string|int|float $s): float => (float) str_replace(',', '', (string) $s);
    $usd0 = fn (float|int $n): string => '$' . number_format(round($n));
    $usdSigned = fn (float $n): string => ($n >= 0 ? '+$' : '−$') . number_format(abs($n), 2);
    $pctSigned = fn (float $n): string => ($n >= 0 ? '+' : '−') . number_format(abs($n), 2) . '%';
    $fmtAge = function (float $h): string {
        if ($h < 1) {
            return round($h * 60) . 'm';
        }
        if ($h < 24) {
            return round($h) . 'h';
        }
        $d = floor($h / 24);
        $rem = round(fmod($h, 24));
        return $d . 'd' . ($rem ? ' ' . $rem . 'h' : '');
    };
    $fmtTime = fn (?int $ts): string => $ts ? gmdate('M j, H:i', $ts) . ' UTC' : '—';

    // close-reason metadata
    $reasonMeta = [
        'tp'     => ['label' => 'TP HIT', 'color' => 'var(--pnl-up-fg)'],
        'stop'   => ['label' => 'STOP',   'color' => 'var(--pnl-down-fg)'],
        'manual' => ['label' => 'MANUAL', 'color' => 'var(--fg-mute)'],
        'regime' => ['label' => 'REGIME', 'color' => 'var(--bsi-blackswan)'],
    ];

    // ---- aggregate summary strip (from real open positions) ----
    $longCount = count(array_filter($positions, fn ($p) => $p['side'] === 'long'));
    $shortCount = count($positions) - $longCount;
    $exposure = array_sum(array_column($positions, 'notional'));
    $marginUsed = array_sum(array_column($positions, 'margin'));
    $unrealized = array_sum(array_column($positions, 'pnl'));
    $aggRoe = $marginUsed > 0 ? ($unrealized / $marginUsed) * 100 : 0.0;
    $aggCells = [
        ['label' => 'Open positions', 'value' => (string) count($positions), 'sub' => $longCount . 'L · ' . $shortCount . 'S', 'tone' => null],
        ['label' => 'Total exposure', 'value' => $usd0($exposure), 'sub' => 'NOTIONAL', 'tone' => null],
        ['label' => 'Margin used', 'value' => $usd0($marginUsed), 'sub' => ($exposure > 0 ? round(($marginUsed / $exposure) * 100) : 0) . '% OF NOTIONAL', 'tone' => null],
        ['label' => 'Unrealized P&L', 'value' => $usdSigned($unrealized), 'sub' => $pctSigned($aggRoe) . ' ROE', 'tone' => $unrealized >= 0 ? 'up' : 'down'],
        ['label' => 'Capacity', 'value' => count($positions) . ' / 12', 'sub' => 'MAX 6 / DIR', 'tone' => null],
    ];

    // SSR order matches the Alpine defaults (open: notional desc, closed: pnl
    // desc) so first paint and first update() agree.
    usort($positions, fn ($a, $b) => $b['notional'] <=> $a['notional']);
    usort($closed, fn ($a, $b) => $b['pnl'] <=> $a['pnl']);
    $closedPer = 6;

    // shared row cell class strings (color applied per cell to avoid conflicts)
    $tdNum = 'py-[12px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right';
    $tdNumClosed = 'py-[11px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right';
@endphp

<x-app-layout active="positions" :title="'Kraite — Positions'">

    <script>
        // Shared controller for the sortable / filterable / pageable position
        // tables. Rows are server-rendered <tbody data-row> blocks (main row +
        // expandable detail row); sorting reorders them in the DOM, filtering
        // and pagination toggle their visibility.
        window.posTable = (cfg) => ({
            // Per-section UI state (which row is expanded, sort, filter, page)
            // is persisted in a global store keyed by cfg.key, so it survives
            // the 10s content swap — an expanded row stays open, the chosen
            // sort/filter/page stick.
            key: cfg.key || cfg.sortKey,
            filter: 'ALL',
            sortKey: cfg.sortKey,
            sortDir: 'desc',
            per: cfg.per || 0,
            page: 0,
            pageCount: 1,
            count: 0,
            open: null,
            _store() {
                if (! window.Alpine.store('posUi')) window.Alpine.store('posUi', {});
                return window.Alpine.store('posUi');
            },
            init() {
                const s = this._store()[this.key] || {};
                this.filter = s.filter ?? 'ALL';
                this.sortKey = s.sortKey ?? cfg.sortKey;
                this.sortDir = s.sortDir ?? 'desc';
                this.page = s.page ?? 0;
                this.open = s.open ?? null;
                this.update();
            },
            persist() {
                this._store()[this.key] = {
                    filter: this.filter, sortKey: this.sortKey, sortDir: this.sortDir,
                    page: this.page, open: this.open,
                };
            },
            setFilter(f) { this.filter = f; this.page = 0; this.open = null; this.persist(); this.update(); },
            setSort(key) {
                this.sortDir = this.sortKey === key ? (this.sortDir === 'asc' ? 'desc' : 'asc') : 'desc';
                this.sortKey = key;
                this.persist();
                this.update();
            },
            setPage(p) { this.page = p; this.open = null; this.persist(); this.update(); },
            toggle(id) { this.open = this.open === id ? null : id; this.persist(); },
            update() {
                const table = this.$refs.table;
                const rows = Array.from(table.querySelectorAll('tbody[data-row]'));
                const val = (r) => {
                    const raw = r.getAttribute('data-s-' + this.sortKey) ?? '';
                    const n = parseFloat(raw);
                    return isNaN(n) ? raw : n;
                };
                rows.sort((a, b) => {
                    const va = val(a), vb = val(b);
                    const c = (typeof va === 'string' || typeof vb === 'string')
                        ? String(va).localeCompare(String(vb))
                        : va - vb;
                    return this.sortDir === 'asc' ? c : -c;
                });
                rows.forEach((r) => table.appendChild(r));
                const visible = rows.filter((r) => this.filter === 'ALL' || r.dataset.side === this.filter);
                this.count = visible.length;
                if (this.per) {
                    this.pageCount = Math.max(1, Math.ceil(visible.length / this.per));
                    if (this.page > this.pageCount - 1) this.page = this.pageCount - 1;
                }
                const from = this.per ? this.page * this.per : 0;
                const to = this.per ? from + this.per : visible.length;
                rows.forEach((r) => { r.style.display = 'none'; });
                visible.forEach((r, i) => { if (i >= from && i < to) r.style.display = ''; });
            },
        });

        // Page-level auto-refresh + reconcile. Two independent cadences,
        // both only while the user is on this page:
        //   • 10s — re-fetch the page, swap the #posContent fragment, re-init
        //     the Alpine inside (posTable, x-collapse). Cheap DB read.
        //   • 5min — reconcile DB vs exchange via the live data() endpoint
        //     (one call per account). Drift lands in the global `reconcile`
        //     store keyed by position id, so a drifting row auto-expands and
        //     shows a warning icon — and survives the 10s content swaps.
        window.positionsRefresh = (url, dataUrl, accountIds) => ({
            url,
            dataUrl,
            accountIds: accountIds || [],
            spinning: false,
            _timer: null,
            _recTimer: null,
            init() {
                if (! window.Alpine.store('reconcile')) {
                    window.Alpine.store('reconcile', { drift: {}, orderDrift: {}, orphans: 0, checking: false, lastAt: null, apiError: null });
                }
                if (this.$refs.content) {
                    this._timer = setInterval(() => this.refresh(), 10000);
                    this.reconcile();                                       // initial check on landing
                    this._recTimer = setInterval(() => this.reconcile(), 300000);  // every 5 min
                }
            },
            // wire:navigate swaps the body but the intervals outlive the DOM
            destroy() {
                if (this._timer) clearInterval(this._timer);
                if (this._recTimer) clearInterval(this._recTimer);
                this._timer = this._recTimer = null;
            },
            async refresh() {
                const el = this.$refs.content;
                if (! el || this.spinning) {
                    return;
                }
                const started = Date.now();
                this.spinning = true;
                try {
                    const res = await fetch(this.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (res.ok) {
                        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                        const fresh = doc.getElementById('posContent');
                        if (fresh) {
                            if (window.Alpine?.destroyTree) window.Alpine.destroyTree(el);
                            el.innerHTML = fresh.innerHTML;
                            if (window.Alpine?.initTree) window.Alpine.initTree(el);
                        }
                    }
                } finally {
                    // Hold the spin ≥1s so a fast local fetch still reads as a sync.
                    setTimeout(() => { this.spinning = false; }, Math.max(0, 1000 - (Date.now() - started)));
                }
            },
            // Live DB-vs-exchange reconcile across every account on the page.
            async reconcile() {
                const store = window.Alpine.store('reconcile');
                if (store.checking) {
                    return;
                }
                store.checking = true;
                const drift = {};
                const orderDrift = {};
                let orphans = 0;
                let apiError = null;
                try {
                    for (const id of this.accountIds) {
                        const res = await fetch(`${this.dataUrl}?account_id=${id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (! res.ok) {
                            continue;
                        }
                        const json = await res.json();
                        (json.pairs || []).forEach((pair) => {
                            const posFields = pair.position_drift_fields || [];
                            const driftingOrders = (pair.orders || []).filter((o) => o.status && o.status !== 'synced');
                            const misaligned = (pair.status && pair.status !== 'synced') || posFields.length > 0 || driftingOrders.length > 0;
                            // Position-level drift, keyed by DB position id (lines up with rowId).
                            if (misaligned && pair.db && pair.db.id) {
                                drift[pair.db.id] = { status: pair.status, posFields, orderDrift: driftingOrders.length };
                            }
                            // Per-order drift, keyed by DB order id, so the exact
                            // order is marked and its exchange values are shown.
                            driftingOrders.forEach((o) => {
                                if (o.db && o.db.id) {
                                    orderDrift[o.db.id] = { status: o.status, fields: o.drift_fields || [], exchange: o.exchange };
                                }
                            });
                        });
                        orphans += (json.orphan_orders || []).length;
                        if (json.api_error) {
                            apiError = json.api_error;
                        }
                    }
                    store.drift = drift;
                    store.orderDrift = orderDrift;
                    store.orphans = orphans;
                    store.apiError = apiError;
                    store.lastAt = Date.now();
                } finally {
                    store.checking = false;
                }
            },
        });
    </script>

    <div x-data="positionsRefresh('{{ route('accounts.positions') }}', '{{ route('accounts.positions.data') }}', @js($accounts->pluck('id')->values()))"
         @positions-reconcile.window="reconcile()">

    {{-- ===================== PAGE HEADER ===================== --}}
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-layers class="w-[13px] h-[13px]" stroke-width="1.75"/>PORTFOLIO
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Positions</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">{{ $noPositions ? 'Full lifecycle — no positions opened or closed yet on this account.' : 'Full lifecycle — open positions, realized history, and per-market detail.' }}</div>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
            {{-- live reconcile status (5-min DB↔exchange check) --}}
            <span x-show="$store.reconcile && ($store.reconcile.lastAt || $store.reconcile.checking)" x-cloak
                  class="inline-flex items-center gap-[7px] font-mono text-[10.5px] tracking-[0.04em]"
                  :class="(Object.keys($store.reconcile?.drift || {}).length + ($store.reconcile?.orphans || 0)) ? 'text-warn' : 'text-fg-mute'">
                <template x-if="$store.reconcile?.checking">
                    <span class="inline-flex items-center gap-[6px]"><span class="w-1.5 h-1.5 rounded-chip animate-pulse-soft" style="background: var(--info)"></span>Reconciling…</span>
                </template>
                <template x-if="!$store.reconcile?.checking">
                    <span class="inline-flex items-center gap-[6px]">
                        <span class="w-1.5 h-1.5 rounded-chip" :style="`background: ${(Object.keys($store.reconcile?.drift || {}).length + ($store.reconcile?.orphans || 0)) ? 'var(--warn)' : 'var(--pnl-up-fg)'}`"></span>
                        <span x-text="(Object.keys($store.reconcile?.drift || {}).length + ($store.reconcile?.orphans || 0)) ? ((Object.keys($store.reconcile?.drift || {}).length + ($store.reconcile?.orphans || 0)) + ' out of sync') : 'In sync with exchange'"></span>
                    </span>
                </template>
            </span>
            <button type="button" @click="refresh()" :disabled="spinning"
                    class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover disabled:opacity-60">
                <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="spinning ? 'animate-spin' : ''"/>Sync
            </button>
        </div>
    </div>

    @if($noPositions)
        {{-- first-run empty state: this account has never traded --}}
        <div class="flex flex-col items-center justify-center text-center py-[110px] px-5 border border-dashed border-line rounded-surface bg-surface">
            <div class="w-14 h-14 rounded-control border border-line flex items-center justify-center text-fg-mute mb-5">
                <x-feathericon-layers class="w-[26px] h-[26px]" stroke-width="1.75"/>
            </div>
            <h4 class="font-sans font-semibold text-[22px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-2">No positions yet</h4>
            <p class="text-[14px] text-fg-3 max-w-[460px] leading-[1.5]">This account hasn't opened or closed a single position. The moment the engine takes its first trade, open positions and realized history will populate here.</p>
            <span class="mt-5 inline-flex items-center gap-[7px] font-mono text-[10.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">
                <span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>Engine running · scanning for entries
            </span>
        </div>
    @else
    {{-- auto-refreshable region: swapped wholesale on each 10s sync --}}
    <div id="posContent" x-ref="content">
    {{-- ===================== AGGREGATE SUMMARY STRIP ===================== --}}
    <div class="card flex items-stretch mb-7 max-[900px]:flex-wrap">
        @foreach($aggCells as $i => $c)
            <div class="flex-1 min-w-[150px] py-[15px] px-5 flex flex-col gap-1.5 {{ $i ? 'border-l border-line-soft max-[900px]:border-l-0' : '' }}{{ $i >= 3 ? ' max-[900px]:border-t max-[900px]:border-line-soft' : '' }}">
                <span class="font-mono text-[9.5px] font-medium tracking-[0.1em] uppercase text-fg-mute">{{ $c['label'] }}</span>
                <span class="font-mono text-[22px] font-semibold leading-none tabular-nums tracking-[-0.02em] {{ $c['tone'] === 'up' ? 'text-pnlup' : ($c['tone'] === 'down' ? 'text-pnldown' : 'text-fg-1') }}">{{ $c['value'] }}</span>
                <span class="font-mono text-[9.5px] tracking-[0.06em] text-fg-mute">{{ $c['sub'] }}</span>
            </div>
        @endforeach
    </div>

    {{-- ===================== OPEN POSITIONS ===================== --}}
    <section class="mb-8" x-data="posTable({ key: 'open', sortKey: 'notional' })">
        <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
            <div>
                <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px]">
                    <x-feathericon-layers class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Open positions
                </div>
                <div class="text-[12.5px] text-fg-3 mt-1" x-text="count + ' managed across the lifecycle · click any row for detail'">{{ count($positions) }} managed across the lifecycle · click any row for detail</div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
                @include('partials.segmented', ['options' => ['ALL', 'LONG', 'SHORT']])
                <button type="button" class="inline-flex items-center gap-[9px] h-[34px] border border-line rounded-control bg-surface px-3 cursor-pointer text-[12.5px] text-fg-2 max-w-[280px] transition-colors duration-fast ease-out hover:border-line-strong max-[640px]:max-w-none max-[640px]:flex-1">
                    <span class="w-[7px] h-[7px] rounded-chip bg-green-500 flex-shrink-0"></span>
                    <span class="whitespace-nowrap overflow-hidden text-ellipsis">All accounts</span>
                    <x-feathericon-chevron-down class="w-[14px] h-[14px] text-fg-mute" stroke-width="1.75"/>
                </button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[920px]" x-ref="table">
                    <thead>
                        <tr>
                            @include('partials.sort-th', ['id' => 'sym',      'label' => 'Market',   'w' => '200px'])
                            @include('partials.sort-th', ['id' => 'side',     'label' => 'Side',     'w' => null])
                            @include('partials.sort-th', ['id' => 'entry',    'label' => 'Entry',    'w' => null])
                            @include('partials.sort-th', ['id' => 'mark',     'label' => 'Mark',     'w' => null])
                            @include('partials.sort-th', ['id' => 'liq',      'label' => 'Liq.',     'w' => null])
                            @include('partials.sort-th', ['id' => 'notional', 'label' => 'Notional', 'w' => null])
                            @include('partials.sort-th', ['id' => 'margin',   'label' => 'Margin',   'w' => null])
                            @include('partials.sort-th', ['id' => 'pnl',      'label' => 'P&L',      'w' => null])
                            @include('partials.sort-th', ['id' => 'roe',      'label' => 'ROE',      'w' => null])
                            @include('partials.sort-th', ['id' => 'age',      'label' => 'Age',      'w' => null])
                            <th class="w-9 bg-accent"></th>
                        </tr>
                    </thead>
                    @foreach($positions as $p)
                        @php $d = $details[$p['rowId']]; @endphp
                        <tbody data-row
                               data-pid="{{ $p['rowId'] }}"
                               data-side="{{ strtoupper($p['side']) }}"
                               data-s-sym="{{ $p['sym'] }}"
                               data-s-side="{{ $p['side'] === 'long' ? 1 : 0 }}"
                               data-s-entry="{{ $num($p['open']) }}"
                               data-s-mark="{{ $num($p['mark']) }}"
                               data-s-liq="{{ $num($p['liq']) }}"
                               data-s-notional="{{ $p['notional'] }}"
                               data-s-margin="{{ $p['margin'] }}"
                               data-s-pnl="{{ $p['pnl'] }}"
                               data-s-roe="{{ $p['roe'] }}"
                               data-s-age="{{ $p['ageH'] }}">
                            <tr @click="toggle('{{ $p['sym'] }}')"
                                :class="(open === '{{ $p['sym'] }}' || $store.reconcile.drift[{{ $p['rowId'] }}]) ? 'bg-hover' : ''"
                                :style="$store.reconcile.drift[{{ $p['rowId'] }}] ? 'box-shadow: inset 3px 0 0 var(--warn)' : ''"
                                class="cursor-pointer transition-colors duration-fast ease-out hover:bg-hover">
                                <td class="py-[12px] pl-5 pr-3 border-b border-line-soft">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span class="w-[26px] h-[26px] rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden bg-surface-3 font-mono font-bold text-[10px] text-fg-2">
                                            @if($p['icon'])<img src="{{ $p['icon'] }}" alt="{{ $p['sym'] }}" class="block w-full h-full object-cover"/>@else{{ mb_substr($p['sym'], 0, 1) }}@endif
                                        </span>
                                        <div class="flex flex-col leading-[1.2] min-w-0">
                                            <span class="font-sans font-bold text-[13px] text-fg-1 tracking-[-0.01em] whitespace-nowrap">{{ $p['market'] }}</span>
                                            <span class="text-[11px] text-fg-mute whitespace-nowrap overflow-hidden text-ellipsis">{{ $p['name'] }}</span>
                                        </div>
                                        @if(($p['status'] ?? null) === 'opening')
                                            <span class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase text-fg-3 bg-surface-3 rounded-chip py-0.5 px-1.5">OPENING</span>
                                        @endif
                                        @if(($p['status'] ?? null) === 'waped')
                                            <span class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase text-warn rounded-chip py-0.5 px-1.5" style="background: color-mix(in srgb, var(--warn) 16%, transparent);">WAP'D</span>
                                        @endif
                                        {{-- live reconcile: DB ↔ exchange mismatch on this position --}}
                                        <span x-show="$store.reconcile.drift[{{ $p['rowId'] }}]" x-cloak class="inline-flex items-center text-warn flex-shrink-0" title="Out of sync with the exchange">
                                            <x-feathericon-alert-triangle class="w-[14px] h-[14px]" stroke-width="2"/>
                                        </span>
                                    </div>
                                </td>
                                <td class="py-[12px] px-3 border-b border-line-soft text-left">@include('partials.side-tag', ['side' => $p['side'], 'lev' => $p['lev']])</td>
                                <td class="{{ $tdNum }} text-fg-1">{{ $p['open'] }}</td>
                                <td class="{{ $tdNum }} text-fg-1">{{ $p['mark'] }}</td>
                                <td class="{{ $tdNum }} text-warn">{{ $p['liq'] }}</td>
                                <td class="{{ $tdNum }} text-fg-1">{{ $usd0($p['notional']) }}</td>
                                <td class="{{ $tdNum }} text-fg-3">{{ $usd0($p['margin']) }}</td>
                                <td class="{{ $tdNum }} font-semibold {{ $p['pnl'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $usdSigned($p['pnl']) }}</td>
                                <td class="{{ $tdNum }} font-semibold {{ $p['roe'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $pctSigned($p['roe']) }}</td>
                                <td class="{{ $tdNum }} text-fg-3">{{ $fmtAge($p['ageH']) }}</td>
                                <td class="py-[12px] pr-5 pl-1 border-b border-line-soft text-right">
                                    <span class="inline-flex text-fg-mute transition-transform duration-fast ease-out"
                                          :style="(open === '{{ $p['sym'] }}' || $store.reconcile.drift[{{ $p['rowId'] }}]) ? 'transform: rotate(180deg)' : ''">
                                        <x-feathericon-chevron-down class="w-4 h-4" stroke-width="1.75"/>
                                    </span>
                                </td>
                            </tr>
                            <tr :aria-hidden="!(open === '{{ $p['sym'] }}' || $store.reconcile.drift[{{ $p['rowId'] }}])">
                                <td colspan="11" class="p-0 border-0">
                                    <div x-show="open === '{{ $p['sym'] }}' || $store.reconcile.drift[{{ $p['rowId'] }}]" x-collapse.duration.360ms x-cloak>
                                        @include('partials.position-detail', ['p' => $p, 'd' => $d])
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
            <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                <span class="font-mono tabular-nums text-[11px] text-fg-mute" x-text="count + ' OPEN · MAX 6 PER DIRECTION'">{{ count($positions) }} OPEN · MAX 6 PER DIRECTION</span>
                <span class="font-mono text-[11px] text-fg-mute tracking-[0.04em] inline-flex items-center gap-[7px]"><span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>LIVE · SYNC 3s</span>
            </div>
        </div>
    </section>

    {{-- Section separator between open and history --}}
    <div class="flex items-center gap-4 my-7" role="separator" aria-label="History">
        <span class="h-px flex-1 bg-line"></span>
        <span class="font-mono text-[10px] font-medium tracking-[0.14em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
            <x-feathericon-clock class="w-[13px] h-[13px]" stroke-width="1.75"/>History
        </span>
        <span class="h-px flex-1 bg-line"></span>
    </div>

    {{-- ===================== CLOSED POSITIONS ===================== --}}
    <section x-data="posTable({ key: 'closed', sortKey: 'pnl', per: {{ $closedPer }} })">
        <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
            <div>
                <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px]">
                    <x-feathericon-clock class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Closed positions
                </div>
                <div class="text-[12.5px] text-fg-3 mt-1">Realized over the last 48 hours · click any row for detail</div>
            </div>
            @include('partials.segmented', ['options' => ['ALL', 'LONG', 'SHORT']])
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[860px]" x-ref="table">
                    <thead>
                        <tr>
                            @include('partials.sort-th', ['id' => 'sym',    'label' => 'Market',       'w' => '200px'])
                            @include('partials.sort-th', ['id' => 'side',   'label' => 'Side',         'w' => null])
                            @include('partials.sort-th', ['id' => 'entry',  'label' => 'Entry',        'w' => null])
                            @include('partials.sort-th', ['id' => 'exit',   'label' => 'Exit',         'w' => null])
                            @include('partials.sort-th', ['id' => 'dur',    'label' => 'Held',         'w' => null])
                            @include('partials.sort-th', ['id' => 'pnl',    'label' => 'Realized P&L', 'w' => null])
                            @include('partials.sort-th', ['id' => 'roe',    'label' => 'ROE',          'w' => null])
                            @include('partials.sort-th', ['id' => 'reason', 'label' => 'Closed',       'w' => null])
                            <th class="w-9 bg-accent"></th>
                        </tr>
                    </thead>
                    @foreach($closed as $i => $p)
                        @php
                            $d = $details[$p['rowId']];
                            $rm = $reasonMeta[$p['reason']] ?? $reasonMeta['manual'];
                        @endphp
                        <tbody data-row
                               data-pid="{{ $p['rowId'] }}"
                               data-side="{{ strtoupper($p['side']) }}"
                               data-s-sym="{{ $p['sym'] }}"
                               data-s-side="{{ $p['side'] === 'long' ? 1 : 0 }}"
                               data-s-entry="{{ $num($p['entry']) }}"
                               data-s-exit="{{ $num($p['exit']) }}"
                               data-s-pnl="{{ $p['pnl'] }}"
                               data-s-roe="{{ $p['roe'] }}"
                               data-s-dur="{{ $p['durH'] }}"
                               data-s-reason="{{ $p['reason'] }}"
                               @if($i >= $closedPer) style="display: none;" @endif>
                            <tr @click="toggle('{{ $p['sym'] }}')"
                                :class="open === '{{ $p['sym'] }}' ? 'bg-hover' : ''"
                                class="cursor-pointer transition-colors duration-fast ease-out hover:bg-hover">
                                <td class="py-[11px] pl-5 pr-3 border-b border-line-soft">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden bg-surface-3 font-mono font-bold text-[10px] text-fg-2">
                                            @if($p['icon'])<img src="{{ $p['icon'] }}" alt="{{ $p['sym'] }}" class="block w-full h-full object-cover"/>@else{{ mb_substr($p['sym'], 0, 1) }}@endif
                                        </span>
                                        <div class="flex flex-col leading-[1.2]">
                                            <span class="font-sans font-bold text-[12.5px] text-fg-1 tracking-[-0.01em] whitespace-nowrap">{{ $p['sym'] }}-PERP</span>
                                            <span class="text-[10.5px] text-fg-mute">closed {{ $p['closedAgo'] }} ago</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-[11px] px-3 border-b border-line-soft text-left">@include('partials.side-tag', ['side' => $p['side'], 'lev' => $p['lev']])</td>
                                <td class="{{ $tdNumClosed }} text-fg-3">{{ $p['entry'] }}</td>
                                <td class="{{ $tdNumClosed }} text-fg-1">{{ $p['exit'] }}</td>
                                <td class="{{ $tdNumClosed }} text-fg-3">{{ $fmtAge($p['durH']) }}</td>
                                <td class="{{ $tdNumClosed }} font-semibold {{ $p['pnl'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $usdSigned($p['pnl']) }}</td>
                                <td class="{{ $tdNumClosed }} font-semibold {{ $p['roe'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $pctSigned($p['roe']) }}</td>
                                <td class="py-[11px] px-3 border-b border-line-soft text-left">
                                    <span class="inline-flex items-center gap-[6px] font-mono text-[9.5px] font-bold tracking-[0.08em] uppercase rounded-chip py-[3px] px-2 whitespace-nowrap"
                                          style="color: {{ $rm['color'] }}; background: color-mix(in srgb, {{ $rm['color'] }} 13%, transparent);">
                                        <span class="w-1.5 h-1.5 rounded-chip" style="background: {{ $rm['color'] }};"></span>{{ $rm['label'] }}
                                    </span>
                                </td>
                                <td class="py-[11px] pr-5 pl-1 border-b border-line-soft text-right">
                                    <span class="inline-flex text-fg-mute transition-transform duration-fast ease-out"
                                          :style="open === '{{ $p['sym'] }}' ? 'transform: rotate(180deg)' : ''">
                                        <x-feathericon-chevron-down class="w-4 h-4" stroke-width="1.75"/>
                                    </span>
                                </td>
                            </tr>
                            <tr :aria-hidden="open !== '{{ $p['sym'] }}'">
                                <td colspan="9" class="p-0 border-0">
                                    <div x-show="open === '{{ $p['sym'] }}'" x-collapse.duration.360ms x-cloak>
                                        @include('partials.position-detail', ['p' => $p, 'd' => $d])
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
            <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                <span class="font-mono tabular-nums text-[11px] text-fg-mute" x-text="count + ' CLOSED · 48H WINDOW'">{{ count($closed) }} CLOSED · 48H WINDOW</span>
                {{-- Numbered pager --}}
                <div class="flex items-center gap-1.5" x-show="pageCount > 1">
                    <button type="button" :disabled="page === 0" @click="setPage(Math.max(0, page - 1))" aria-label="Previous page"
                            class="appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2">
                        <x-feathericon-chevron-left class="w-3.5 h-3.5" stroke-width="1.75"/>
                    </button>
                    <template x-for="i in pageCount" :key="i">
                        <button type="button" @click="setPage(i - 1)" :aria-current="page === i - 1" :aria-label="'Page ' + i"
                                :class="page === i - 1 ? 'border-accent bg-accent text-accent-on' : 'border-line bg-surface text-fg-3 hover:text-fg-1 hover:border-line-strong'"
                                class="appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control font-mono text-[11px] font-semibold tabular-nums border transition-colors duration-fast ease-out"
                                x-text="i"></button>
                    </template>
                    <button type="button" :disabled="page === pageCount - 1" @click="setPage(Math.min(pageCount - 1, page + 1))" aria-label="Next page"
                            class="appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2">
                        <x-feathericon-chevron-right class="w-3.5 h-3.5" stroke-width="1.75"/>
                    </button>
                </div>
                <a href="#" class="text-[12px] font-sans font-semibold no-underline text-accent inline-flex items-center gap-[5px] hover:text-accent-hover">Full history <x-feathericon-arrow-right class="w-[13px] h-[13px]" stroke-width="1.75"/></a>
            </div>
        </div>
    </section>
    </div>{{-- /#posContent --}}
    @endif

    </div>{{-- /positionsRefresh --}}

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
