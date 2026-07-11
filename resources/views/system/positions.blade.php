<x-app-layout active="positions" :title="'Kraite — Fleet positions'">
    <script>
        // Fleet positions: collapsed account rows poll globally every 10s;
        // each EXPANDED account additionally refreshes its own longs/shorts
        // (sync scoped to what's visible). Alpha-path triage bands:
        // green <= 50, yellow 50-85, red >= 85.
        window.positionsPage = (urls, initial) => ({
            accounts: initial.accounts,
            expanded: {},                       // account id -> { tab, data, loading }
            loading: false,
            _timer: null,

            init() {
                this._timer = setInterval(() => this.tick(), 10000);
            },
            destroy() {
                if (this._timer) { clearInterval(this._timer); this._timer = null; }
            },
            async tick() {
                if (this.loading) return;
                this.loading = true;
                try {
                    const jobs = [hubUiFetch(urls.data).then((res) => {
                        if (res.ok) this.accounts = res.data.accounts;
                    })];
                    for (const id of Object.keys(this.expanded)) {
                        jobs.push(this.loadAccount(id));
                    }
                    await Promise.all(jobs);
                } finally {
                    setTimeout(() => { this.loading = false; }, 450);
                }
            },

            toggle(acc) {
                if (this.expanded[acc.id]) {
                    delete this.expanded[acc.id];
                    return;
                }
                this.expanded[acc.id] = { tab: 'shorts', data: null, loading: true };
                this.loadAccount(acc.id);
            },
            async loadAccount(id) {
                const slot = this.expanded[id];
                if (!slot) return;
                const res = await hubUiFetch(urls.account.replace('__ID__', id));
                if (this.expanded[id] && res.ok) {
                    this.expanded[id].data = res.data;
                    this.expanded[id].loading = false;
                }
            },

            // ---------- formatting ----------
            fmtMoney(v) {
                if (v === null || v === undefined) return '—';
                const n = Number(v);
                const sign = n < 0 ? '−' : '';
                return `${sign}$${Math.abs(n).toLocaleString('en-US', { maximumFractionDigits: 2, minimumFractionDigits: 2 })}`;
            },
            bandColor(band) {
                return { green: 'var(--pnl-up-fg)', yellow: 'var(--warn)', red: 'var(--danger)' }[band] ?? 'var(--fg-mute)';
            },
            marginColor(ratio) {
                if (ratio === null || ratio === undefined) return 'var(--fg-mute)';
                return ratio >= 50 ? 'var(--danger)' : (ratio >= 20 ? 'var(--warn)' : 'var(--fg-1)');
            },
            pnlColor(v) {
                if (v === null || v === undefined) return 'var(--fg-mute)';
                return v < 0 ? 'var(--pnl-down-fg)' : 'var(--pnl-up-fg)';
            },
            tabRows(id) {
                const slot = this.expanded[id];
                if (!slot?.data) return [];
                return slot.tab === 'shorts' ? slot.data.shorts : slot.data.longs;
            },
        });
    </script>

    <div x-data="positionsPage(@js([
            'data' => route('system.positions.data'),
            'account' => route('system.positions.account', '__ID__'),
        ]), @js($positions))">

        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-layers class="w-[13px] h-[13px]" stroke-width="1.75"/>PLATFORM
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Fleet positions</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Every account holding open positions — margin ratio, live PnL, and the worst position's ladder depth at a glance.</div>
            </div>
            <div class="inline-flex items-center gap-[7px] rounded-control border border-line whitespace-nowrap h-[36px] px-3.5 text-[13px] font-sans font-semibold text-fg-3 flex-shrink-0 cursor-default select-none">
                <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="loading && 'animate-spin'"/>
                <span>Auto-sync · 10s</span>
            </div>
        </div>

        {{-- ===================== ACCOUNTS ===================== --}}
        <div class="card card--flat overflow-hidden">
            <x-ui.card-head icon="users" title="Accounts with open positions" :accent="true">
                <x-slot:right>
                    <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                          x-text="`${accounts.length} account${accounts.length === 1 ? '' : 's'} · worst first`"></span>
                </x-slot:right>
            </x-ui.card-head>

            <div x-show="accounts.length === 0" class="py-14 text-center">
                <div class="font-sans text-[14px] font-semibold text-fg-1 mb-1">Nothing open right now.</div>
                <div class="font-mono text-[11px] text-fg-mute">Accounts appear here the moment the engine holds a position for them.</div>
            </div>

            <template x-for="acc in accounts" :key="acc.id">
                <div class="border-b border-line-soft last:border-b-0">
                    {{-- collapsed account row --}}
                    <button type="button" @click="toggle(acc)"
                            class="w-full appearance-none bg-transparent border-0 cursor-pointer flex items-center gap-4 py-3.5 px-5 text-left transition-colors duration-fast hover:bg-hover max-[640px]:px-4 max-[640px]:flex-wrap max-[640px]:gap-y-2"
                            :style="acc.band === 'red' ? 'background: color-mix(in srgb, var(--danger) 6%, transparent)' : (acc.band === 'yellow' ? 'background: color-mix(in srgb, var(--warn) 5%, transparent)' : '')">
                        {{-- roll-up dot: the account's WORST position --}}
                        <span class="w-[9px] h-[9px] rounded-chip flex-shrink-0"
                              :class="acc.band !== 'green' && 'animate-pulse'"
                              :style="`background: ${bandColor(acc.band)}`"
                              :title="`Worst position: ${acc.worst_alpha_pct}% of ladder`"></span>
                        <span class="flex flex-col leading-[1.25] min-w-0 flex-1">
                            <span class="font-sans text-[13.5px] font-semibold text-fg-1 whitespace-nowrap overflow-hidden text-ellipsis" x-text="acc.owner"></span>
                            <span class="font-mono text-[10px] tracking-[0.04em] uppercase text-fg-mute" x-text="acc.exchange"></span>
                        </span>
                        <span class="flex flex-col items-end leading-tight w-[92px] flex-shrink-0">
                            <span class="font-mono text-[13px] font-bold tabular-nums text-fg-1" x-text="acc.positions"></span>
                            <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute" x-text="`position${acc.positions === 1 ? '' : 's'}`"></span>
                        </span>
                        <span class="flex flex-col items-end leading-tight w-[110px] flex-shrink-0">
                            <span class="font-mono text-[13px] font-bold tabular-nums" :style="`color: ${marginColor(acc.margin_ratio)}`"
                                  x-text="acc.margin_ratio === null ? '—' : acc.margin_ratio.toFixed(2) + '%'"></span>
                            <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">margin ratio</span>
                        </span>
                        <span class="flex flex-col items-end leading-tight w-[120px] flex-shrink-0">
                            <span class="font-mono text-[13px] font-bold tabular-nums" :style="`color: ${pnlColor(acc.pnl)}`" x-text="fmtMoney(acc.pnl)"></span>
                            <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">current pnl</span>
                        </span>
                        <x-feathericon-chevron-down class="w-4 h-4 text-fg-mute flex-shrink-0 transition-transform duration-fast" ::class="expanded[acc.id] && 'rotate-180'" stroke-width="1.75"/>
                    </button>

                    {{-- expanded: shorts / longs tabs --}}
                    <div x-show="expanded[acc.id]" x-cloak class="border-t border-line-soft" style="background: color-mix(in srgb, var(--bg-elev-3) 40%, transparent)">
                        <div class="flex items-center gap-1 px-5 pt-2 max-[640px]:px-4">
                            <template x-for="t in ['shorts', 'longs']" :key="t">
                                <button type="button" @click="expanded[acc.id].tab = t"
                                        :class="expanded[acc.id]?.tab === t ? 'text-fg-1 border-accent' : 'text-fg-3 border-transparent hover:text-fg-1'"
                                        class="appearance-none bg-transparent cursor-pointer font-mono text-[11px] font-semibold tracking-[0.05em] uppercase px-3 h-[34px] inline-flex items-center gap-1.5 border-0 border-b-2 whitespace-nowrap transition-colors duration-fast"
                                        style="border-bottom-style: solid">
                                    <span x-text="t === 'shorts' ? 'Shorts' : 'Longs'"></span>
                                    <span class="font-mono text-[9.5px] tabular-nums text-fg-mute"
                                          x-text="expanded[acc.id]?.data ? (t === 'shorts' ? expanded[acc.id].data.shorts.length : expanded[acc.id].data.longs.length) : ''"></span>
                                </button>
                            </template>
                        </div>

                        <div x-show="expanded[acc.id]?.loading" class="py-6 text-center font-mono text-[11px] text-fg-mute">Loading positions…</div>

                        <div x-show="expanded[acc.id]?.data && tabRows(acc.id).length === 0" class="py-6 text-center font-mono text-[11px] text-fg-mute"
                             x-text="`No ${expanded[acc.id]?.tab} open on this account.`"></div>

                        {{-- column labels --}}
                        <div x-show="tabRows(acc.id).length > 0"
                             class="flex items-center gap-4 pt-3 pb-1.5 px-5 max-[640px]:px-4 font-mono text-[8.5px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
                            <span class="w-[130px] flex-shrink-0">Token</span>
                            <span class="w-[80px] flex-shrink-0">Rungs filled</span>
                            <span class="flex-1 min-w-[120px]">Alpha path</span>
                            <span class="w-[90px] text-right flex-shrink-0">Alpha limit</span>
                            <span class="w-[110px] text-right flex-shrink-0">PnL</span>
                        </div>

                        <template x-for="pos in tabRows(acc.id)" :key="pos.id">
                            <div class="flex items-center gap-4 py-2.5 px-5 border-t border-line-soft max-[640px]:px-4 max-[640px]:flex-wrap max-[640px]:gap-y-1.5"
                                 :style="pos.band === 'red' ? 'background: color-mix(in srgb, var(--danger) 7%, transparent)' : (pos.band === 'yellow' ? 'background: color-mix(in srgb, var(--warn) 6%, transparent)' : '')">
                                <span class="font-mono text-[12px] font-semibold text-fg-1 w-[130px] flex-shrink-0 whitespace-nowrap overflow-hidden text-ellipsis" x-text="pos.symbol"></span>
                                <span class="font-mono text-[12px] font-bold tabular-nums text-fg-1 w-[80px] flex-shrink-0" x-text="`${pos.rungs_filled} / ${pos.rungs_total}`"></span>
                                {{-- alpha path: number + corridor bar --}}
                                <span class="flex-1 min-w-[120px] flex items-center gap-2.5">
                                    <span class="font-mono text-[12px] font-bold tabular-nums w-[52px] text-right" :style="`color: ${bandColor(pos.band)}`" x-text="pos.alpha_pct.toFixed(1) + '%'"></span>
                                    <span class="flex-1 h-[5px] rounded-chip bg-surface-3 overflow-hidden">
                                        <span class="block h-full rounded-chip transition-[width] duration-base" :style="`width: ${Math.min(100, pos.alpha_pct)}%; background: ${bandColor(pos.band)}`"></span>
                                    </span>
                                </span>
                                {{-- alpha limit: distance to the next pending rung --}}
                                <span class="font-mono text-[12px] font-bold tabular-nums w-[90px] text-right flex-shrink-0 text-fg-2"
                                      x-text="pos.alpha_limit_pct === null ? '—' : pos.alpha_limit_pct.toFixed(1) + '%'"></span>
                                <span class="font-mono text-[12px] font-bold tabular-nums w-[110px] text-right flex-shrink-0" :style="`color: ${pnlColor(pos.pnl)}`" x-text="fmtMoney(pos.pnl)"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-app-layout>
