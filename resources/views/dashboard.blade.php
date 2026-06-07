{{--
    Dashboard — REAL DATA. First paint renders the server-built payload
    (same shape the polling endpoint serves); Alpine then polls
    /dashboard/data every 10s and on manual Sync. Mock remnants: none.
    Activity feed + server connectivity render honest placeholders until
    their sources exist (no fabricated events, no fake latencies).
--}}

<x-app-layout active="dashboard" :title="'Kraite — Dashboard'">

    <script>
        window.dashPage = (initial, accounts, initialAccountId, dataUrl) => ({
            d: initial,
            accounts: accounts,
            accountId: initialAccountId,
            loading: false,
            syncedAt: Date.now(),
            nowTick: Date.now(),
            filter: 'ALL',
            page: 0,
            acctOpen: false,
            _timers: [],

            init() {
                this._timers.push(setInterval(() => { this.nowTick = Date.now(); }, 1000));
                this._timers.push(setInterval(() => this.refresh(), 10000));
            },

            // ---- data ----
            async refresh() {
                if (this.loading || !this.accountId) return;
                this.loading = true;
                try {
                    const res = await fetch(`${dataUrl}?account_id=${this.accountId}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (res.ok) {
                        this.d = await res.json();
                        this.syncedAt = Date.now();
                        this.page = Math.min(this.page, this.pageCount() - 1);
                    }
                } finally {
                    this.loading = false;
                }
            },
            setAccount(id) {
                this.acctOpen = false;
                if (id === this.accountId) return;
                this.accountId = id;
                this.filter = 'ALL';
                this.page = 0;
                this.refresh();
            },
            account() { return this.accounts.find(a => a.id === this.accountId) || null; },
            syncAgo() {
                const s = Math.max(0, Math.round((this.nowTick - this.syncedAt) / 1000));
                return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m`;
            },

            // ---- formatters ----
            usd(v) { return v === null || v === undefined ? '—' : '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            usdSigned(v) {
                if (v === null || v === undefined) return '—';
                const n = Number(v);
                return (n >= 0 ? '+$' : '−$') + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },
            pct(v, dp = 2) { return v === null || v === undefined ? null : (Number(v) >= 0 ? '+' : '−') + Math.abs(Number(v)).toFixed(dp) + '%'; },

            // sparkline → svg paths (line + area), 84×28 viewBox
            spark(series) {
                if (!series || series.length < 2) return null;
                const w = 84, h = 28;
                const min = Math.min(...series), max = Math.max(...series);
                const rng = (max - min) || 1;
                const pts = series.map((v, i) => [
                    (i / (series.length - 1)) * w,
                    h - 3 - ((v - min) / rng) * (h - 6),
                ]);
                const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
                return { line, area: line + ` L${w} ${h} L0 ${h} Z` };
            },

            // ---- BSCS regime ----
            bandMeta() {
                const map = {
                    calm:     { label: 'CALM',     color: 'var(--bsi-calm)' },
                    elevated: { label: 'ELEVATED', color: 'var(--bsi-elevated)' },
                    fragile:  { label: 'FRAGILE',  color: 'var(--bsi-cascade)' },
                    critical: { label: 'CRITICAL', color: 'var(--bsi-blackswan)' },
                };
                return map[this.d?.bscs?.band] || { label: '—', color: 'var(--fg-mute)' };
            },
            scoreDisplay() {
                const s = this.d?.bscs?.score;
                return s === null || s === undefined ? '—' : (s / 100).toFixed(2);
            },
            regimePulse() { return ['fragile', 'critical'].includes(this.d?.bscs?.band); },

            // ---- positions: filter + chunk + carousel ----
            filtered() {
                const all = this.d?.positions || [];
                if (this.filter === 'ALL') return all;
                return all.filter(p => p.direction === this.filter);
            },
            chunks() {
                const per = 6, out = [];
                const list = this.filtered();
                for (let i = 0; i < list.length; i += per) out.push(list.slice(i, i + per));
                return out.length ? out : [[]];
            },
            pageCount() { return Math.max(1, this.chunks().length); },
            safePage() { return Math.min(this.page, this.pageCount() - 1); },
            setFilter(f) { this.filter = f; this.page = 0; },
            rangeLabel() {
                const total = this.filtered().length;
                if ((this.d?.positions || []).length === 0) return 'No positions open right now · engine standing by';
                if (total === 0) return `no ${this.filter.toLowerCase()} positions on this account`;
                if (total <= 6) return `${total} managed across the lifecycle · no manual orders`;
                const from = this.safePage() * 6 + 1;
                return `${total} positions · showing ${from}–${Math.min(from + 5, total)} · max 6 per page`;
            },
            engineEmpty() { return (this.d?.positions || []).length === 0; },
            dotColor(dir) { return dir === 'up' ? 'var(--pnl-up-fg)' : dir === 'down' ? 'var(--pnl-down-fg)' : 'var(--border-strong)'; },
        });
    </script>

    <div x-data="dashPage(@js($initialPayload), @js($accounts), @js($initialAccountId), @js(route('dashboard.data')))">

        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-grid class="w-[13px] h-[13px]" stroke-width="1.75"/>OVERVIEW
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Dashboard</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">
                    Engine running autonomously · <span class="font-mono tabular-nums text-fg-2" x-text="d?.kpis?.open_count ?? 0"></span> open positions · last sync <span class="font-mono tabular-nums text-fg-2" x-text="syncAgo()"></span> ago
                </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
                {{-- BTC reference strip --}}
                <template x-if="d?.btc">
                    <div class="flex items-center gap-2.5 whitespace-nowrap">
                        <img class="w-[26px] h-[26px] rounded-full flex-shrink-0" :src="d.btc.image" alt="BTC"/>
                        <div class="flex flex-col leading-[1.15]">
                            <span class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">Bitcoin · USDT</span>
                            <span class="font-mono text-[15px] font-semibold text-fg-1 tabular-nums tracking-[-0.01em]" x-text="d.btc.mark ?? '—'"></span>
                        </div>
                        <div class="flex gap-[3px] items-center">
                            <template x-for="dot in d.btc.dots" :key="dot.timeframe">
                                <i class="block w-1.5 h-1.5 rounded-chip" :style="`background: ${dotColor(dot.direction)}`" :title="dot.timeframe"></i>
                            </template>
                        </div>
                    </div>
                </template>
                <div class="w-px h-[22px] bg-line"></div>
                {{-- Regime pill (real BSCS band + score/100) --}}
                <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                      :style="`background: color-mix(in srgb, ${bandMeta().color} 12%, transparent); border-color: color-mix(in srgb, ${bandMeta().color} 38%, transparent); color: ${bandMeta().color}`">
                    <span class="w-2 h-2 rounded-chip" :class="regimePulse() ? 'animate-pulse-soft' : ''" :style="`background: ${bandMeta().color}`"></span>
                    <span x-text="bandMeta().label"></span><span class="opacity-70 ml-0.5" x-text="scoreDisplay()"></span>
                </span>
                <div class="w-px h-[22px] bg-line"></div>
                <button type="button" @click="refresh()"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                    <span class="inline-flex" :class="loading ? 'animate-spin' : ''"><x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/></span>Sync
                </button>
                <a href="{{ route('projections') }}" wire:navigate
                   class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] border-transparent bg-accent text-accent-on hover:bg-accent-hover no-underline">
                    <x-feathericon-trending-up class="w-[15px] h-[15px]" stroke-width="1.75"/>View projections
                </a>
            </div>
        </div>

        {{-- ===================== BLOCKED BANNER (real BSCS gate) ===================== --}}
        <div x-show="d?.bscs?.blocked" x-cloak
             class="flex items-center gap-3 py-[13px] px-4 mb-5 rounded-control bg-pnldown-bg text-pnldown border" style="border-color: color-mix(in srgb, var(--danger) 45%, transparent);">
            <span class="flex flex-shrink-0 animate-pulse-soft"><x-feathericon-alert-triangle class="w-[18px] h-[18px]" stroke-width="1.75"/></span>
            <span class="text-[13px] leading-[1.45] flex-1 min-w-0">
                <strong class="text-pnldown-strong font-bold">New position openings paused</strong> — Black Swan regime is
                <span class="font-mono text-pnldown-strong font-semibold"><span x-text="bandMeta().label"></span> <span x-text="scoreDisplay()"></span></span>.
                Existing positions are still managed.
            </span>
        </div>

        {{-- ===================== KPI TILES ===================== --}}
        <div class="grid grid-cols-4 gap-5 mb-6 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-2 max-[640px]:gap-3 max-[420px]:grid-cols-1">

            {{-- Portfolio value --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast ease-out hover:border-line-strong">
                <div class="font-mono text-[10px] font-medium tracking-[0.11em] uppercase text-fg-3 flex items-center gap-[7px]">
                    <x-feathericon-credit-card class="w-3 h-3" stroke-width="1.75"/>Portfolio value
                </div>
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="font-mono font-semibold text-[24px] tracking-[-0.025em] text-fg-1 tabular-nums leading-none" x-text="usd(d?.kpis?.balance)"></span>
                    <template x-if="d?.kpis?.balance_delta_24h_pct !== null && d?.kpis?.balance_delta_24h_pct !== undefined">
                        <span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip"
                              :class="d.kpis.balance_delta_24h_pct >= 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg'">
                            <span x-text="pct(d.kpis.balance_delta_24h_pct)"></span>
                        </span>
                    </template>
                    <template x-if="spark(d?.kpis?.balance_spark)">
                        <div class="ml-auto w-[84px] min-w-0 flex-shrink">
                            <svg class="block w-full" viewBox="0 0 84 28" preserveAspectRatio="none" width="84" height="28">
                                <path :d="spark(d.kpis.balance_spark).area" fill="color-mix(in srgb, var(--pnl-up-fg) 14%, transparent)"/>
                                <path :d="spark(d.kpis.balance_spark).line" fill="none" stroke="var(--pnl-up-fg)" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                            </svg>
                        </div>
                    </template>
                </div>
            </div>

            {{-- P&L today (realized, trade-PnL sourced) --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast ease-out hover:border-line-strong">
                <div class="font-mono text-[10px] font-medium tracking-[0.11em] uppercase text-fg-3 flex items-center gap-[7px]">
                    <x-feathericon-zap class="w-3 h-3" stroke-width="1.75"/>P&amp;L — today
                </div>
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="font-mono font-semibold text-[24px] tracking-[-0.025em] tabular-nums leading-none"
                          :class="d?.kpis?.pnl_today === null ? 'text-fg-mute' : (Number(d?.kpis?.pnl_today) >= 0 ? 'text-fg-1' : 'text-pnldown')"
                          x-text="d?.kpis?.pnl_today === null || d?.kpis?.pnl_today === undefined ? '—' : usdSigned(d.kpis.pnl_today)"></span>
                    <template x-if="d?.kpis?.pnl_today_pct !== null && d?.kpis?.pnl_today_pct !== undefined">
                        <span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip"
                              :class="d.kpis.pnl_today_pct >= 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg'">
                            <span x-text="pct(d.kpis.pnl_today_pct)"></span>
                        </span>
                    </template>
                </div>
                <div class="font-mono text-[9px] tracking-[0.07em] uppercase text-fg-mute">Realized · closed positions</div>
            </div>

            {{-- P&L 30 day --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast ease-out hover:border-line-strong">
                <div class="font-mono text-[10px] font-medium tracking-[0.11em] uppercase text-fg-3 flex items-center gap-[7px]">
                    <x-feathericon-arrow-up-right class="w-3 h-3" stroke-width="1.75"/>P&amp;L — 30 day
                </div>
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="font-mono font-semibold text-[24px] tracking-[-0.025em] tabular-nums leading-none"
                          :class="d?.kpis?.pnl_30d === null ? 'text-fg-mute' : (Number(d?.kpis?.pnl_30d) >= 0 ? 'text-fg-1' : 'text-pnldown')"
                          x-text="d?.kpis?.pnl_30d === null || d?.kpis?.pnl_30d === undefined ? '—' : usdSigned(d.kpis.pnl_30d)"></span>
                    <template x-if="d?.kpis?.pnl_30d_pct !== null && d?.kpis?.pnl_30d_pct !== undefined">
                        <span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip"
                              :class="d.kpis.pnl_30d_pct >= 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg'">
                            <span x-text="pct(d.kpis.pnl_30d_pct)"></span>
                        </span>
                    </template>
                    <template x-if="spark(d?.kpis?.pnl_30d_spark)">
                        <div class="ml-auto w-[84px] min-w-0 flex-shrink">
                            <svg class="block w-full" viewBox="0 0 84 28" preserveAspectRatio="none" width="84" height="28">
                                <path :d="spark(d.kpis.pnl_30d_spark).area" fill="color-mix(in srgb, var(--pnl-up-fg) 14%, transparent)"/>
                                <path :d="spark(d.kpis.pnl_30d_spark).line" fill="none" stroke="var(--pnl-up-fg)" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                            </svg>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Open positions --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast ease-out hover:border-line-strong">
                <div class="font-mono text-[10px] font-medium tracking-[0.11em] uppercase text-fg-3 flex items-center gap-[7px]">
                    <x-feathericon-layers class="w-3 h-3" stroke-width="1.75"/>Open positions
                </div>
                <div class="flex items-center gap-2.5 min-w-0">
                    <span class="font-mono font-semibold text-[24px] tracking-[-0.025em] text-fg-1 tabular-nums leading-none" x-text="d?.kpis?.open_count ?? 0"></span>
                    <div class="ml-auto flex flex-col gap-1 w-24 min-w-0 flex-shrink" x-show="(d?.kpis?.open_count ?? 0) > 0">
                        <div class="flex h-1.5 rounded-chip overflow-hidden gap-0.5">
                            <span class="rounded-chip" :style="`flex: ${d?.kpis?.long_count || 0}; background: var(--pnl-up-fg)`" x-show="(d?.kpis?.long_count ?? 0) > 0"></span>
                            <span class="rounded-chip" :style="`flex: ${d?.kpis?.short_count || 0}; background: var(--pnl-down-fg)`" x-show="(d?.kpis?.short_count ?? 0) > 0"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-mono text-[10px] font-semibold text-pnlup" x-text="(d?.kpis?.long_count ?? 0) + 'L'"></span>
                            <span class="font-mono text-[10px] font-semibold text-pnldown" x-text="(d?.kpis?.short_count ?? 0) + 'S'"></span>
                        </div>
                    </div>
                    <span class="ml-auto font-mono text-[9px] tracking-[0.07em] uppercase text-fg-mute" x-show="(d?.kpis?.open_count ?? 0) === 0">Engine idle</span>
                </div>
            </div>
        </div>

        {{-- ===================== POSITIONS SECTION ===================== --}}
        <section class="mb-6">
            <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
                <div>
                    <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                        <x-feathericon-layers class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Open positions
                    </div>
                    <div class="text-[12.5px] text-fg-3 mt-1 whitespace-nowrap" x-text="rangeLabel()"></div>
                </div>
                <div class="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
                    {{-- Segmented ALL / LONG / SHORT — hidden while the engine holds nothing --}}
                    <div x-show="!engineEmpty()">
                        @include('partials.segmented', ['options' => ['ALL', 'LONG', 'SHORT']])
                    </div>
                    {{-- Account picker (real accounts) --}}
                    <div class="relative" @click.outside="acctOpen = false">
                        <button type="button" @click="acctOpen = !acctOpen"
                                :class="acctOpen ? 'border-accent' : 'border-line hover:border-line-strong'"
                                class="inline-flex items-center gap-[9px] h-[34px] border rounded-control bg-surface px-3 cursor-pointer text-[12.5px] text-fg-2 max-w-[280px] transition-colors duration-fast ease-out max-[640px]:max-w-none max-[640px]:flex-1">
                            <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0" :class="account()?.can_trade ? 'bg-green-500' : 'bg-warn'"></span>
                            <span class="whitespace-nowrap overflow-hidden text-ellipsis" x-text="account() ? `${account().name} · ${account().exchange}` : 'Pick an account'"></span>
                            <x-feathericon-chevron-down class="w-[14px] h-[14px] text-fg-mute" stroke-width="1.75"/>
                        </button>
                        <div x-show="acctOpen" x-cloak
                             class="absolute top-[calc(100%+6px)] right-0 z-[60] min-w-[260px] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in">
                            <template x-for="a in accounts" :key="a.id">
                                <button type="button" @click="setAccount(a.id)"
                                        :class="a.id === accountId ? 'bg-hover' : ''"
                                        class="appearance-none cursor-pointer text-left flex items-center gap-2.5 bg-transparent border-0 rounded-[7px] py-2 px-[9px] transition-colors duration-fast ease-out hover:bg-hover">
                                    <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0" :class="a.can_trade ? 'bg-green-500' : 'bg-warn'"></span>
                                    <span class="flex flex-col leading-[1.2] flex-1 min-w-0">
                                        <span class="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap" x-text="a.name"></span>
                                        <span class="font-mono text-[10px] text-fg-mute" x-text="a.exchange + (a.owner ? ' · ' + a.owner : '')"></span>
                                    </span>
                                    <span x-show="a.id === accountId" class="text-accent"><x-feathericon-check class="w-[14px] h-[14px]" stroke-width="2"/></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- empty state — engine is holding no positions right now --}}
            <div x-show="filtered().length === 0" x-cloak
                 class="flex flex-col items-center justify-center text-center py-[78px] px-5 border border-dashed border-line rounded-surface bg-surface">
                <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><x-feathericon-layers class="w-6 h-6" stroke-width="1.75"/></div>
                <h4 class="font-sans font-semibold text-[20px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-1.5">No open positions</h4>
                <p class="text-[13.5px] text-fg-3 max-w-[400px] leading-[1.5]"
                   x-text="engineEmpty() ? `The engine isn't holding anything right now. New positions will appear here the moment the bot opens one.` : `No ${filter.toLowerCase()} positions on this account right now.`"></p>
                <span class="mt-[18px] inline-flex items-center gap-[7px] font-mono text-[10.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">
                    <span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>Engine running · scanning for entries
                </span>
            </div>

            {{-- tiles — payload-driven --}}
            <div x-show="filtered().length > 0">
                <div class="grid grid-cols-3 gap-5 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-1">
                    <template x-for="p in chunks()[safePage()]" :key="p.id">
                        <div class="ptile bg-surface border-2 rounded-surface overflow-hidden transition-colors duration-fast ease-out"
                             :class="(p.direction === 'LONG' ? 'ptile--long' : 'ptile--short') + (p.status === 'waping' ? ' ptile--waped' : '')">
                            <div :class="p.status === 'opening' ? 'grayscale opacity-[0.62]' : ''" class="pt-4 px-[18px] pb-[14px]">
                                {{-- ribbon header --}}
                                <div class="flex items-start gap-[11px] -mt-4 -mx-[18px] mb-4 px-[18px] pt-4 pb-3.5 border-b"
                                     :style="p.direction === 'LONG'
                                        ? 'background: #0e3f2a; border-color: color-mix(in srgb, var(--pnl-up-fg) 30%, transparent)'
                                        : 'background: #3f1212; border-color: color-mix(in srgb, var(--pnl-down-fg) 32%, transparent)'">
                                    <div class="w-[30px] h-[30px] rounded-full flex items-center justify-center font-mono font-bold text-[12px] text-white flex-shrink-0 overflow-hidden bg-surface-3">
                                        <template x-if="p.token_image"><img :src="p.token_image" :alt="p.token" class="block w-full h-full object-cover"/></template>
                                        <template x-if="!p.token_image"><span x-text="(p.token || '?')[0]"></span></template>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-[7px]">
                                            <span class="font-sans font-bold text-[14px] tracking-[-0.01em] flex-shrink-0" :class="p.direction === 'LONG' ? 'text-[#eafff5]' : 'text-[#ffecec]'" x-text="p.token || p.symbol"></span>
                                            <span class="text-[12px] whitespace-nowrap overflow-hidden text-ellipsis min-w-0" :class="p.direction === 'LONG' ? 'text-[#8fd9b4]' : 'text-[#e0a3a3]'" x-text="p.token_name || ''"></span>
                                            <template x-if="p.status === 'opening'">
                                                <span class="ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap bg-surface-3 text-fg-3">
                                                    <span class="w-1.5 h-1.5 rounded-chip bg-fg-3 animate-pulse-soft"></span>Opening
                                                </span>
                                            </template>
                                            <template x-if="p.status === 'waping'">
                                                <span class="ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap text-warn" style="background: color-mix(in srgb, var(--warn) 16%, transparent);">
                                                    <x-feathericon-layers class="w-[10px] h-[10px]" stroke-width="2"/>WAP'd
                                                </span>
                                            </template>
                                        </div>
                                        <div class="flex items-center gap-[9px] mt-1.5">
                                            <span class="inline-flex items-center gap-1 font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-1 px-2.5 bg-white/10"
                                                  :class="p.direction === 'LONG' ? 'text-[#aef0cf]' : 'text-[#ffc9c9]'">
                                                <template x-if="p.direction === 'LONG'"><span class="inline-flex"><x-feathericon-arrow-up class="w-[11px] h-[11px]" stroke-width="2"/></span></template>
                                                <template x-if="p.direction !== 'LONG'"><span class="inline-flex"><x-feathericon-arrow-down class="w-[11px] h-[11px]" stroke-width="2"/></span></template>
                                                <span x-text="`${p.direction} ${p.leverage}×`"></span>
                                            </span>
                                            <span class="font-mono text-[11px] inline-flex items-center gap-[5px]" :class="p.direction === 'LONG' ? 'text-[#8fd9b4]' : 'text-[#e0a3a3]'">
                                                <x-feathericon-clock class="w-3 h-3" stroke-width="1.75"/><span x-text="p.age_human || '—'"></span>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex gap-1 items-center flex-shrink-0 pt-[3px]">
                                        <template x-for="dot in p.timeframe_dots" :key="dot.timeframe">
                                            <i class="block w-1.5 h-1.5 rounded-chip" :style="`background: ${dotColor(dot.direction)}`" :title="dot.timeframe"></i>
                                        </template>
                                    </div>
                                </div>

                                {{-- lifecycle track (real geometry) --}}
                                <template x-if="p.track">
                                    <div class="relative mt-[22px] mx-0.5 mb-4 h-[30px]"
                                         :style="`--mc: ${p.direction === 'LONG' ? 'var(--accent)' : 'var(--pnl-down-fg)'}`">
                                        <div class="absolute top-1/2 left-0 right-0 h-px bg-line-strong"></div>
                                        {{-- TP marker at 0% --}}
                                        <span class="absolute -top-[14px] -translate-x-1/2 font-mono text-[8.5px] font-bold tracking-[0.06em]" style="left: 0%; color: var(--mc);">TP</span>
                                        <span class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-[9px] h-[9px] rounded-chip border-2" style="left: 0%; background: var(--mc); border-color: var(--bg-elev-1);"></span>
                                        {{-- ladder rungs --}}
                                        <template x-for="rung in p.track.rungs" :key="rung.index">
                                            <span class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 flex flex-col items-center" :style="`left: ${rung.pct}%`">
                                                <span class="w-[7px] h-[7px] rounded-chip" :style="rung.filled ? 'background: var(--mc)' : 'background: var(--bg-elev-1); box-shadow: inset 0 0 0 1.5px var(--border-strong)'"></span>
                                                <span class="absolute top-[10px] font-mono text-[8px] text-fg-mute tabular-nums" x-text="rung.index"></span>
                                            </span>
                                        </template>
                                        {{-- PX marker --}}
                                        <template x-if="p.track.px_pct !== null">
                                            <span class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 transition-[left] duration-slow ease-snap" :style="`left: ${p.track.px_pct}%`">
                                                <span class="absolute -top-[22px] left-1/2 -translate-x-1/2 font-mono text-[8.5px] font-bold tracking-[0.06em] text-fg-1">PX</span>
                                                <span class="block w-[11px] h-[11px] rounded-chip bg-fg-1 border-2" style="border-color: var(--bg-elev-1); box-shadow: 0 0 0 1px rgba(0,0,0,0.3);"></span>
                                            </span>
                                        </template>
                                    </div>
                                </template>

                                {{-- metric rows --}}
                                <div class="grid grid-cols-3 gap-x-3 gap-y-2.5 mb-3">
                                    <div>
                                        <div class="font-mono text-[8.5px] font-semibold tracking-[0.09em] uppercase text-fg-mute flex items-center gap-1"><x-feathericon-flag class="w-[9px] h-[9px]" stroke-width="2"/>Path</div>
                                        <div class="font-mono text-[12.5px] font-semibold tabular-nums mt-0.5" :class="p.direction === 'LONG' ? 'text-accent' : 'text-pnldown'" x-text="(p.alpha_path_pct ?? '0.0') + '%'"></div>
                                    </div>
                                    <div>
                                        <div class="font-mono text-[8.5px] font-semibold tracking-[0.09em] uppercase text-fg-mute flex items-center gap-1"><x-feathericon-arrow-right class="w-[9px] h-[9px]" stroke-width="2"/>Limit</div>
                                        <div class="font-mono text-[12.5px] font-semibold tabular-nums mt-0.5" :class="p.direction === 'LONG' ? 'text-accent' : 'text-pnldown'" x-text="(p.alpha_limit_pct ?? '0.0') + '%'"></div>
                                    </div>
                                    <div>
                                        <div class="font-mono text-[8.5px] font-semibold tracking-[0.09em] uppercase text-fg-mute flex items-center gap-1"><x-feathericon-check class="w-[9px] h-[9px]" stroke-width="2"/>Filled</div>
                                        <div class="font-mono text-[12.5px] font-semibold tabular-nums mt-0.5 text-fg-1" x-text="`${p.filled_count} / ${p.total_limits}`"></div>
                                    </div>
                                    <div>
                                        <div class="font-mono text-[8.5px] font-semibold tracking-[0.09em] uppercase text-fg-mute">● Open</div>
                                        <div class="font-mono text-[12px] tabular-nums mt-0.5 text-fg-1" x-text="p.opening_price ?? '—'"></div>
                                    </div>
                                    <div>
                                        <div class="font-mono text-[8.5px] font-semibold tracking-[0.09em] uppercase" :class="p.direction === 'LONG' ? 'text-accent' : 'text-pnldown'">↑ TP</div>
                                        <div class="font-mono text-[12px] tabular-nums mt-0.5 font-semibold" :class="p.direction === 'LONG' ? 'text-accent' : 'text-pnldown'" x-text="p.profit_price ?? p.first_profit_price ?? '—'"></div>
                                    </div>
                                    <div>
                                        <div class="font-mono text-[8.5px] font-semibold tracking-[0.09em] uppercase text-fg-mute">↓ Next</div>
                                        <div class="font-mono text-[12px] tabular-nums mt-0.5 text-fg-1" x-text="p.next_limit_price ?? '—'"></div>
                                    </div>
                                </div>

                                {{-- footer: mark + PnL --}}
                                <div class="flex items-center justify-between pt-2.5 border-t border-line-soft">
                                    <span class="font-mono text-[10.5px] text-fg-mute tabular-nums">PX <span class="text-fg-2" x-text="p.current_price ?? '—'"></span></span>
                                    <span class="font-mono text-[12px] font-semibold tabular-nums"
                                          :class="p.pnl === null || p.pnl === undefined ? 'text-fg-mute' : (Number(p.pnl) >= 0 ? 'text-pnlup' : 'text-pnldown')"
                                          x-text="p.pnl === null || p.pnl === undefined ? 'PNL —' : usdSigned(p.pnl)"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- pager dots --}}
                <div class="flex justify-center mt-5" x-show="pageCount() > 1">
                    <div class="flex items-center gap-[7px]">
                        <template x-for="(c, i) in chunks()" :key="i">
                            <button type="button" @click="page = i"
                                    class="appearance-none cursor-pointer p-0 border-0 w-[7px] h-[7px] rounded-chip transition-colors duration-fast ease-out"
                                    :class="safePage() === i ? 'bg-accent' : 'bg-line-strong hover:bg-fg-mute'"
                                    :aria-label="`Page ${i + 1}`"></button>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        {{-- Section separator --}}
        <div class="flex items-center gap-4 my-7" role="separator" aria-label="Monitoring">
            <span class="h-px flex-1 bg-line"></span>
            <span class="font-mono text-[10px] font-medium tracking-[0.14em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
                <x-feathericon-activity class="w-[13px] h-[13px]" stroke-width="1.75"/>Monitoring
            </span>
            <span class="h-px flex-1 bg-line"></span>
        </div>

        {{-- ===================== BOTTOM GRID ===================== --}}
        <div class="grid grid-cols-3 gap-5 items-start max-[1080px]:grid-cols-1">

            {{-- Activity feed — honest placeholder until an event source exists --}}
            <div class="flex flex-col gap-5 min-w-0 col-span-2 max-[1080px]:col-auto">
                <div class="card">
                    <div class="flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft">
                        <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                            <x-feathericon-cpu class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Recent bot activity
                        </div>
                    </div>
                    <div class="flex flex-col items-center justify-center text-center py-[52px] px-5">
                        <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><x-feathericon-cpu class="w-6 h-6" stroke-width="1.75"/></div>
                        <h4 class="font-sans font-semibold text-[16px] text-fg-1 mb-1.5">Activity feed coming online</h4>
                        <p class="text-[13px] text-fg-3 max-w-[400px]">Trade opens, closes, regime changes and funding events will stream here once the event source is wired.</p>
                    </div>
                </div>
            </div>

            {{-- Right column --}}
            <div class="flex flex-col gap-5 min-w-0">

                {{-- BSCS card — real score, band, sub-signals --}}
                <div class="card">
                    <div class="flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft">
                        <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                            <x-feathericon-shield class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Black Swan Composite
                        </div>
                        <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                              :style="`background: color-mix(in srgb, ${bandMeta().color} 12%, transparent); border-color: color-mix(in srgb, ${bandMeta().color} 38%, transparent); color: ${bandMeta().color}`">
                            <span class="w-2 h-2 rounded-chip" :class="regimePulse() ? 'animate-pulse-soft' : ''" :style="`background: ${bandMeta().color}`"></span>
                            <span x-text="bandMeta().label"></span>
                        </span>
                    </div>
                    <div class="flex flex-col gap-[13px] pt-4 px-5 pb-[18px]">
                        <div class="flex items-baseline gap-[9px] flex-wrap">
                            <span class="font-mono text-[32px] font-semibold leading-none tracking-[-0.03em]" :style="`color: ${bandMeta().color}`" x-text="scoreDisplay()"></span>
                            <span class="font-mono text-[11.5px] text-fg-mute whitespace-nowrap">/ 1.00 · <span :style="`color: ${bandMeta().color}; font-weight: 600`" x-text="bandMeta().label"></span></span>
                            <span class="font-mono text-[9.5px] tracking-[0.06em] text-fg-mute ml-auto self-center" x-text="d?.bscs?.blocked ? 'NEW POS. PAUSED' : 'NEW POS. ALLOWED'"></span>
                        </div>
                        <div>
                            <div class="h-[7px] rounded-chip relative" style="background: linear-gradient(90deg, var(--bsi-calm) 0%, var(--bsi-watch) 32%, var(--bsi-elevated) 55%, var(--bsi-cascade) 80%, var(--bsi-blackswan) 100%);">
                                {{-- block-threshold tick --}}
                                <template x-if="d?.bscs?.block_threshold">
                                    <span class="absolute top-[-3px] bottom-[-3px] w-px bg-fg-3 opacity-60" :style="`left: ${d.bscs.block_threshold}%`" title="Block threshold"></span>
                                </template>
                                <span class="absolute top-1/2 w-[3px] h-[15px] bg-fg-1 border-2 border-surface rounded-chip -translate-x-1/2 -translate-y-1/2 transition-[left] duration-slow ease-snap"
                                      :style="`left: ${d?.bscs?.score ?? 0}%; box-shadow: 0 0 0 1px rgba(0,0,0,0.25)`"></span>
                            </div>
                            <div class="flex justify-between mt-1.5">
                                @foreach(['CALM','ELEV','FRAGILE','CRIT'] as $lbl)
                                    <span class="font-mono text-[9px] text-fg-mute tracking-[0.04em]">{{ $lbl }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="text-[12px] text-fg-3" x-text="d?.bscs?.status"></div>
                        <div x-show="d?.bscs?.blocked" x-cloak
                             class="flex items-start gap-[9px] py-[11px] px-[13px] rounded-control bg-pnldown-bg text-pnldown text-[12px] leading-[1.45] border" style="border-color: color-mix(in srgb, var(--danger) 38%, transparent);">
                            <x-feathericon-alert-triangle class="w-[15px] h-[15px] flex-shrink-0 mt-0.5" stroke-width="1.75"/>
                            <span>New position openings <strong class="font-bold">paused by the regime gate</strong>. Existing positions are still managed.</span>
                        </div>
                        {{-- sub-signals — raw values + fired state (heterogeneous scales, no fake bars) --}}
                        <div class="flex flex-col gap-[9px] pt-[3px]">
                            <template x-for="c in (d?.bscs?.components || [])" :key="c.label">
                                <div class="flex items-center gap-[11px]">
                                    <span class="text-[12px] text-fg-3 flex-1" x-text="c.label"></span>
                                    <span class="font-mono text-[11px] tabular-nums" :class="c.fired ? 'text-fg-1 font-semibold' : 'text-fg-mute'" x-text="c.value === null ? '—' : c.value"></span>
                                    <span class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase py-[2px] px-1.5 rounded-chip w-[52px] text-center"
                                          :style="c.fired ? 'color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent)' : 'color: var(--fg-faint); background: var(--bg-elev-3)'"
                                          x-text="c.fired ? 'FIRED' : 'QUIET'"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                        <span class="font-mono tabular-nums text-[11px] text-fg-mute" x-text="d?.bscs?.computed_ago ? `COMPUTED ${d.bscs.computed_ago.toUpperCase()}` : 'AWAITING FIRST COMPUTE'"></span>
                        <span class="font-mono text-[11px] text-fg-mute tracking-[0.04em]" x-show="d?.bscs?.is_stale" x-cloak>
                            <span class="text-warn">STALE</span>
                        </span>
                    </div>
                </div>

                {{-- Server connectivity — placeholder until a heartbeat source exists --}}
                <div class="card">
                    <div class="flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft">
                        <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                            <x-feathericon-server class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Server connectivity
                        </div>
                    </div>
                    <div class="flex flex-col items-center justify-center text-center py-[40px] px-5">
                        <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><x-feathericon-server class="w-6 h-6" stroke-width="1.75"/></div>
                        <h4 class="font-sans font-semibold text-[15px] text-fg-1 mb-1.5">Heartbeat wiring pending</h4>
                        <p class="text-[12.5px] text-fg-3 max-w-[280px]">Per-server link state appears here once the fleet heartbeat is exposed to the trader surface.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
