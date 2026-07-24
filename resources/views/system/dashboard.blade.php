<x-app-layout active="overview" :title="'Kraite — Fleet overview'">
    <style>[x-cloak] { display: none !important; }</style>
    <script>
        // Fleet-overview state: seeded server-side with the same payload the
        // 15s poll returns, so there is no loading flash and every panel
        // (KPIs, fleet, regime, deploy, revenue, venues, incidents) swaps
        // wholesale on each tick.
        window.systemDash = (dataUrl, engageUrl, clearUrl, initial) => ({
            ov: initial,
            loading: false,
            _timer: null,

            // Override-regime inline form state.
            overrideOpen: false,
            overrideReason: '',
            overrideHours: 4,
            overrideBusy: false,
            overrideError: '',

            init() {
                this._timer = setInterval(() => { if (! document.hidden) this.refresh(); }, 10000);
            },
            destroy() {
                if (this._timer) { clearInterval(this._timer); this._timer = null; }
            },
            async refresh() {
                if (this.loading) return;
                this.loading = true;
                try {
                    const res = await window.hubUiFetch(dataUrl);
                    if (res.ok) this.ov = res.data;
                } finally {
                    // Grace so the header spin registers on fast responses.
                    setTimeout(() => { this.loading = false; }, 450);
                }
            },

            // ---------- formatting ----------
            fmtInt(v) {
                return (v === null || v === undefined) ? '—' : Number(v).toLocaleString('en-US');
            },
            fmtMoney(v) {
                if (v === null || v === undefined) return '—';
                const n = Number(v);
                const abs = Math.abs(n);
                if (abs >= 1e6) return '$' + (n / 1e6).toFixed(2) + 'M';
                if (abs >= 1e4) return '$' + (n / 1e3).toFixed(1) + 'k';
                return '$' + n.toLocaleString('en-US', { maximumFractionDigits: 2 });
            },
            fmtDelta(v) {
                return (v >= 0 ? '+' : '−') + Math.abs(v).toFixed(1) + '%';
            },
            throughputPct(f) {
                if (!f) return 0;
                const total = f.processing + f.pending;
                return total > 0 ? Math.round((f.processing / total) * 100) : 0;
            },
            sparkPath(data, w, h) {
                if (!Array.isArray(data) || data.length < 2) return '';
                const min = Math.min(...data);
                const max = Math.max(...data);
                const range = (max - min) || 1;
                return 'M ' + data.map((v, i) =>
                    `${((i / (data.length - 1)) * w).toFixed(2)},${(h - ((v - min) / range) * h).toFixed(2)}`
                ).join(' L ');
            },


            // ---------- market regime ----------
            regimeBands: ['calm', 'elevated', 'fragile', 'critical'],
            regimeMeta(band) {
                const map = {
                    calm:     { label: 'CALM',     color: 'var(--bsi-calm)' },
                    elevated: { label: 'ELEVATED', color: 'var(--bsi-watch)' },
                    fragile:  { label: 'FRAGILE',  color: 'var(--bsi-elevated)' },
                    critical: { label: 'CRITICAL', color: 'var(--bsi-blackswan)' },
                };
                return map[band] ?? { label: 'NO SIGNAL', color: 'var(--fg-mute)' };
            },
            regimeIdx() {
                return this.regimeBands.indexOf(this.ov.regime?.band);
            },
            async engageOverride() {
                if (this.overrideBusy) return;
                this.overrideBusy = true;
                this.overrideError = '';
                const res = await window.hubUiFetch(engageUrl, {
                    body: { reason: this.overrideReason, hours: this.overrideHours },
                });
                this.overrideBusy = false;
                if (res.ok) {
                    this.overrideOpen = false;
                    this.overrideReason = '';
                    await this.refresh();
                } else {
                    this.overrideError = res.data.error || Object.values(res.data.errors || {}).flat()[0] || 'Failed to engage override.';
                }
            },
            async clearOverride() {
                if (this.overrideBusy) return;
                this.overrideBusy = true;
                const res = await window.hubUiFetch(clearUrl, { body: {} });
                this.overrideBusy = false;
                if (res.ok) await this.refresh();
            },

            // ---------- fleet ----------
            fleetCounts() {
                const c = { online: 0, stale: 0, missing: 0 };
                (this.ov.fleet || []).forEach((f) => { c[f.status] = (c[f.status] || 0) + 1; });
                return c;
            },
            statusMeta(status) {
                if (status === 'online') return { label: 'ONLINE', color: 'var(--pnl-up-fg)' };
                if (status === 'stale') return { label: 'STALE', color: 'var(--warn)' };
                return { label: 'MISSING', color: 'var(--danger)' };
            },
            barColor(pct) {
                return pct >= 90 ? 'var(--danger)' : (pct >= 75 ? 'var(--warn)' : 'var(--pnl-up-fg)');
            },
            uptimeHuman(s) {
                if (s === null || s === undefined) return '—';
                if (s < 3600) return Math.floor(s / 60) + 'm';
                if (s < 86400) return Math.floor(s / 3600) + 'h';
                return Math.floor(s / 86400) + 'd';
            },
            unitList(units) {
                return Object.entries(units || {}).map(([name, state]) => ({ name, state }));
            },
            unitOk(state) {
                return state === 'RUNNING';
            },
            serverIssues(node) {
                const issues = [];

                if (node.status === 'missing') {
                    issues.push({
                        key: 'heartbeat-missing',
                        message: 'Fleet heartbeat is missing',
                        color: 'var(--danger)',
                    });
                } else if (node.status === 'stale') {
                    issues.push({
                        key: 'heartbeat-stale',
                        message: 'Fleet heartbeat is stale',
                        color: 'var(--warn)',
                    });
                }

                this.unitList(node.units)
                    .filter((unit) => ! this.unitOk(unit.state))
                    .forEach((unit) => issues.push({
                        key: `service-${unit.name}`,
                        message: `Service ${unit.name} is ${unit.state}`,
                        color: 'var(--danger)',
                    }));

                return issues;
            },

            // ---------- deploy ----------
            deployPct() {
                const d = this.ov.deploy;
                return d && d.reporting > 0 ? Math.round((d.on_latest / d.reporting) * 100) : 0;
            },
            laggingLabel() {
                const rows = this.ov.deploy?.lagging || [];
                if (!rows.length) return '';
                return rows.map((r) => `${r.hostname} on ${r.version}`).join(' · ');
            },

            // ---------- venues ----------
            venueMeta(status) {
                const map = {
                    operational: { label: 'Operational', color: 'var(--pnl-up-fg)', pulse: false },
                    degraded:    { label: 'Degraded',    color: 'var(--warn)',      pulse: true },
                    down:        { label: 'Down',        color: 'var(--danger)',    pulse: true },
                    idle:        { label: 'Idle',        color: 'var(--fg-mute)',   pulse: false },
                };
                return map[status] ?? map.idle;
            },
            degradedCount() {
                return (this.ov.venues || []).filter((v) => v.status === 'degraded' || v.status === 'down').length;
            },

            // ---------- incidents ----------
            sevColor(sev) {
                return {
                    warn: 'var(--warn)',
                    good: 'var(--pnl-up-fg)',
                    bad: 'var(--danger)',
                    mute: 'var(--fg-mute)',
                }[sev] ?? 'var(--fg-mute)';
            },
        });
    </script>

    <div x-data="systemDash(@js(route('system.dashboard.data')), @js(route('system.bscs.override.engage')), @js(route('system.bscs.override.clear')), @js($overview))">

        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-activity class="w-[13px] h-[13px]" stroke-width="1.75"/>PLATFORM
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Fleet overview</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Live health across every Kraite worker, exchange, and customer account.</div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
                <span class="inline-flex items-center gap-[7px] py-[6px] px-3 rounded-chip border font-mono text-[11px] font-bold tracking-[0.06em] uppercase whitespace-nowrap"
                      :style="`color: ${regimeMeta(ov.regime?.band).color}; border-color: color-mix(in srgb, ${regimeMeta(ov.regime?.band).color} 38%, transparent); background: color-mix(in srgb, ${regimeMeta(ov.regime?.band).color} 12%, transparent)`">
                    <span class="w-2 h-2 rounded-chip" :style="`background: ${regimeMeta(ov.regime?.band).color}`"></span>
                    <span x-text="regimeMeta(ov.regime?.band).label"></span>
                    <span class="opacity-70 ml-0.5" x-text="ov.regime?.score ?? '—'"></span>
                </span>
                <div class="w-px h-[22px] bg-line"></div>
                {{-- passive auto-sync indicator — the page refreshes itself
                     every 10s; the icon spins while a refresh is in flight. --}}
                <div class="inline-flex items-center gap-[7px] rounded-control border border-line whitespace-nowrap h-[36px] px-3.5 text-[13px] font-sans font-semibold text-fg-3 flex-shrink-0 cursor-default select-none">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="loading && 'animate-spin'"/>
                    <span>Auto-sync · 10s</span>
                </div>
            </div>
        </div>

        {{-- ===================== KPI ROW ===================== --}}
        {{-- Inline tiles (same classes as x-ui.stat-tile) — the component renders
             statically, and every value here live-updates with the poll. --}}
        <div class="grid grid-cols-5 gap-3 mb-6 max-[1100px]:grid-cols-3 max-[680px]:grid-cols-2">
            {{-- active traders --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
                        <x-feathericon-users class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Active traders
                    </span>
                    <template x-if="ov.kpis.traders.delta_pct !== null">
                        <span class="font-mono text-[10px] font-bold tabular-nums py-0.5 px-1.5 rounded-chip"
                              :class="ov.kpis.traders.delta_pct >= 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg'"
                              x-text="fmtDelta(ov.kpis.traders.delta_pct)"></span>
                    </template>
                </div>
                <div class="min-h-[60px] flex items-center justify-between gap-3">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="fmtInt(ov.kpis.traders.count)"></span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute" x-text="`24H · +${ov.kpis.traders.signups_24h} SIGNUPS`"></span>
            </div>

            {{-- total tradeable tokens --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap overflow-hidden text-ellipsis">
                        <x-feathericon-check-circle class="w-3.5 h-3.5 text-fg-3 flex-shrink-0" stroke-width="1.75"/>Tradeable tokens
                    </span>
                </div>
                {{-- mini table: one row per exchange, Long / Short columns --}}
                <div class="flex-1 flex flex-col justify-center gap-[3px]">
                    <div class="flex items-center gap-2">
                        <span class="flex-1"></span>
                        <span class="w-9 text-right font-mono text-[8.5px] font-semibold tracking-[0.08em] uppercase text-fg-mute">Long</span>
                        <span class="w-9 text-right font-mono text-[8.5px] font-semibold tracking-[0.08em] uppercase text-fg-mute">Short</span>
                    </div>
                    <template x-for="ex in (ov.kpis.tradeable.exchanges || [])" :key="ex.name">
                        <div class="flex items-center gap-2 leading-none">
                            <span class="flex-1 font-mono text-[10px] text-fg-3 whitespace-nowrap overflow-hidden text-ellipsis" x-text="ex.name"></span>
                            <span class="w-9 text-right font-mono text-[11px] font-bold tabular-nums text-pnlup" x-text="fmtInt(ex.longs)"></span>
                            <span class="w-9 text-right font-mono text-[11px] font-bold tabular-nums text-pnldown" x-text="fmtInt(ex.shorts)"></span>
                        </div>
                    </template>
                    <span x-show="!(ov.kpis.tradeable.exchanges || []).length" class="font-mono text-[11px] text-fg-mute">—</span>
                </div>
            </div>

            {{-- capital under mgmt --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
                        <x-feathericon-database class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Capital under mgmt
                    </span>
                    <template x-if="ov.kpis.capital.delta_pct !== null">
                        <span class="font-mono text-[10px] font-bold tabular-nums py-0.5 px-1.5 rounded-chip"
                              :class="ov.kpis.capital.delta_pct >= 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg'"
                              x-text="fmtDelta(ov.kpis.capital.delta_pct)"></span>
                    </template>
                </div>
                <div class="min-h-[60px] flex items-center justify-between gap-3">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="fmtMoney(ov.kpis.capital.aum)"></span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute" x-text="`AUM · ${ov.kpis.capital.accounts} ACCOUNT${ov.kpis.capital.accounts === 1 ? '' : 'S'}`"></span>
            </div>

            {{-- engine throughput — per fleet: processing vs pending --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
                        <x-feathericon-activity class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Engine throughput
                    </span>
                </div>
                <div class="min-h-[60px] flex flex-col justify-center gap-2.5">
                    <template x-for="f in [['default','CALC'],['trading','TRADING']]" :key="f[0]">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-[9px] font-semibold tracking-[0.08em] uppercase text-fg-mute" x-text="f[1]"></span>
                                <span class="font-mono text-[11px] font-bold tabular-nums text-fg-1"
                                      x-text="ov.kpis.throughput.fleets?.[f[0]] ? `${fmtInt(ov.kpis.throughput.fleets[f[0]].processing)} / ${fmtInt(ov.kpis.throughput.fleets[f[0]].pending)}` : '—'"></span>
                            </div>
                            <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden">
                                <div class="h-full rounded-chip transition-[width] duration-base"
                                     :style="`width: ${throughputPct(ov.kpis.throughput.fleets?.[f[0]])}%; background: var(--pnl-up-fg)`"></div>
                            </div>
                        </div>
                    </template>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">Processing / pending · per fleet</span>
            </div>

            {{-- open positions --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
                        <x-feathericon-layers class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Open positions
                    </span>
                </div>
                <div class="min-h-[60px] flex items-center justify-between gap-3">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="fmtInt(ov.kpis.open_positions)"></span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">Platform-wide</span>
            </div>
        </div>

        {{-- ===================== FLEET + SIDE COLUMN ===================== --}}
        <div class="grid grid-cols-[1.6fr_1fr] gap-5 mb-5 max-[1024px]:grid-cols-1">
            {{-- worker fleet — servers roster ⋈ Redis heartbeat keys --}}
            <div class="card card--flat !overflow-visible relative z-20">
                <x-ui.card-head icon="server" title="Worker fleet" :accent="true">
                    <x-slot:right>
                        {{-- the header auto-sync drives this card too --}}
                        <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                              x-text="`${fleetCounts().online} online · ${fleetCounts().stale} stale · ${fleetCounts().missing} missing`"></span>
                    </x-slot:right>
                </x-ui.card-head>
                <div class="hidden md:grid grid-cols-[minmax(150px,1.4fr)_104px_1fr_1fr_1fr_64px_minmax(96px,1fr)] items-center gap-4 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
                    <span>Node</span><span>Status</span><span>CPU</span><span>Memory</span>
                    <span class="max-[1024px]:hidden">Disk</span><span class="text-right max-[1024px]:hidden">Uptime</span><span class="max-[1024px]:hidden">Services</span>
                </div>

                <div x-show="!ov.fleet || ov.fleet.length === 0" class="py-10 text-center font-mono text-[11px] text-fg-mute">No servers registered.</div>

                <template x-for="node in ov.fleet" :key="node.hostname">
                    <div class="border-b border-line-soft last:border-b-0 last:rounded-b-control transition-colors duration-fast"
                         :style="node.status === 'stale' ? 'background: color-mix(in srgb, var(--warn) 7%, transparent)' : (node.status === 'missing' ? 'background: color-mix(in srgb, var(--danger) 6%, transparent)' : '')">
                        <div class="grid grid-cols-[minmax(150px,1.4fr)_104px_1fr_1fr_1fr_64px_minmax(96px,1fr)] items-center gap-4 py-3 px-5 max-[1024px]:grid-cols-[minmax(140px,1.5fr)_104px_1fr_1fr] max-[640px]:px-4">
                        {{-- node: type + hostname + ip --}}
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="font-mono text-[9px] font-bold tracking-[0.06em] uppercase text-fg-mute w-[44px] flex-shrink-0" x-text="node.type ?? '—'"></span>
                            <div class="flex flex-col leading-[1.2] min-w-0">
                                <span class="font-mono text-[12.5px] font-semibold text-fg-1 tracking-[0.01em] whitespace-nowrap inline-flex items-center gap-1.5">
                                    <span x-text="node.hostname"></span>
                                    <span x-show="node.recently_rebooted" class="font-mono text-[8px] font-bold tracking-[0.06em] uppercase py-px px-1 rounded-chip" style="color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent)">rebooted</span>
                                </span>
                                <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] whitespace-nowrap" x-text="node.ip_address ?? '—'"></span>
                            </div>
                        </div>
                        {{-- status --}}
                        <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase" :style="`color: ${statusMeta(node.status).color}`">
                            <span class="w-[6px] h-[6px] rounded-chip" :class="node.status === 'online' && 'animate-pulse'" :style="`background: ${statusMeta(node.status).color}`"></span>
                            <span x-text="statusMeta(node.status).label"></span>
                        </span>
                        {{-- cpu --}}
                        <div>
                            <template x-if="node.cpu && node.cpu.percent !== null">
                                <div class="flex flex-col gap-1 min-w-[64px]">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">CPU</span>
                                        <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${node.cpu.percent >= 75 ? barColor(node.cpu.percent) : 'var(--fg-2)'}`" x-text="node.cpu.percent + '%'"></span>
                                    </div>
                                    <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${node.cpu.percent}%; background: ${barColor(node.cpu.percent)}`"></div></div>
                                </div>
                            </template>
                            <span x-show="!node.cpu || node.cpu.percent === null" class="font-mono text-[11px] text-fg-mute">—</span>
                        </div>
                        {{-- memory --}}
                        <div>
                            <template x-if="node.ram && node.ram.percent !== null">
                                <div class="flex flex-col gap-1 min-w-[64px]">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">MEM</span>
                                        <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${node.ram.percent >= 75 ? barColor(node.ram.percent) : 'var(--fg-2)'}`" x-text="node.ram.percent + '%'"></span>
                                    </div>
                                    <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${node.ram.percent}%; background: ${barColor(node.ram.percent)}`"></div></div>
                                </div>
                            </template>
                            <span x-show="!node.ram || node.ram.percent === null" class="font-mono text-[11px] text-fg-mute">—</span>
                        </div>
                        {{-- disk --}}
                        <div class="max-[1024px]:hidden">
                            <template x-if="node.disk && node.disk.percent !== null">
                                <div class="flex flex-col gap-1 min-w-[64px]">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">DISK</span>
                                        <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${node.disk.percent >= 75 ? barColor(node.disk.percent) : 'var(--fg-2)'}`" x-text="node.disk.percent + '%'"></span>
                                    </div>
                                    <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${node.disk.percent}%; background: ${barColor(node.disk.percent)}`"></div></div>
                                </div>
                            </template>
                            <span x-show="!node.disk || node.disk.percent === null" class="font-mono text-[11px] text-fg-mute">—</span>
                        </div>
                        {{-- uptime --}}
                        <div class="flex flex-col items-end leading-tight max-[1024px]:hidden">
                            <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1" x-text="uptimeHuman(node.uptime_seconds)"></span>
                        </div>
                        {{-- supervisor services — hover a dot for the service name + state --}}
                        <div class="flex items-center gap-[6px] flex-wrap max-[1024px]:hidden">
                            <span x-show="unitList(node.units).length === 0" class="font-mono text-[10px] text-fg-mute">—</span>
                            <template x-for="u in unitList(node.units)" :key="u.name">
                                <span class="relative group inline-flex items-center justify-center w-[13px] h-[13px] cursor-default">
                                    <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0 transition-transform duration-fast group-hover:scale-150" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                    <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-50 hidden group-hover:block whitespace-nowrap bg-surface border border-line-strong rounded-control shadow-3 px-2.5 py-1.5 pointer-events-none">
                                        <div class="font-mono text-[10px] font-bold text-fg-1 flex items-center gap-1.5">
                                            <span class="w-[6px] h-[6px] rounded-chip flex-shrink-0" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                            <span x-text="u.name"></span>
                                        </div>
                                        <div class="font-mono text-[9px] tracking-[0.06em] uppercase mt-0.5" :style="`color: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`" x-text="u.state"></div>
                                    </div>
                                </span>
                            </template>
                        </div>
                        </div>
                        <div x-show="serverIssues(node).length > 0" x-cloak class="flex flex-col gap-1.5 px-5 pb-3 max-[640px]:px-4">
                            <template x-for="issue in serverIssues(node)" :key="issue.key">
                                <div class="flex items-start gap-2 font-mono text-[10px] font-semibold tracking-[0.02em]" :style="`color: ${issue.color}`">
                                    <x-feathericon-alert-triangle class="w-3 h-3 mt-px flex-shrink-0" stroke-width="2"/>
                                    <span x-text="issue.message"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- right column: regime / deploy / revenue --}}
            <div class="flex flex-col gap-5">
                {{-- market regime — live BSCS (4 core bands) + working override --}}
                <div class="card card--flat overflow-hidden">
                    <x-ui.card-head icon="shield" title="Market regime" :accent="true" hint="BSCS · platform-wide"/>
                    <div class="p-5 flex flex-col gap-4">
                        <div class="flex items-end justify-between gap-3">
                            <div class="flex flex-col gap-1.5">
                                <span class="font-sans text-[22px] font-bold tracking-[-0.01em] leading-none" :style="`color: ${regimeMeta(ov.regime?.band).color}`" x-text="regimeMeta(ov.regime?.band).label"></span>
                                <span class="font-mono text-[10.5px] tracking-[0.04em] text-fg-mute" x-text="ov.regime?.posture"></span>
                            </div>
                            <span class="font-mono text-[34px] font-bold tabular-nums leading-none" :style="`color: ${regimeMeta(ov.regime?.band).color}`" x-text="ov.regime?.score ?? '—'"></span>
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex items-stretch gap-1 h-[8px]">
                                <template x-for="(band, i) in regimeBands" :key="band">
                                    <div class="flex-1 rounded-chip transition-colors duration-base"
                                         :style="`background: ${regimeIdx() >= 0 && i <= regimeIdx() ? regimeMeta(band).color : 'var(--bg-elev-3)'}`"></div>
                                </template>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">Calm</span>
                                <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">Critical</span>
                            </div>
                        </div>

                        {{-- override active --}}
                        <template x-if="ov.regime?.override_reason">
                            <div class="flex flex-col gap-2 rounded-control border border-line-soft p-3" style="background: color-mix(in srgb, var(--warn) 7%, transparent)">
                                <span class="font-mono text-[9px] font-bold tracking-[0.08em] uppercase" style="color: var(--warn)">Manual override active</span>
                                <span class="text-[12px] text-fg-2 leading-snug" x-text="ov.regime.override_reason"></span>
                                <button type="button" @click="clearOverride()" :disabled="overrideBusy"
                                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center justify-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-1 border-line-strong hover:bg-hover w-full h-[32px] text-[12.5px] disabled:opacity-50">
                                    <x-feathericon-x class="w-[13px] h-[13px]" stroke-width="1.75"/>Clear override
                                </button>
                            </div>
                        </template>

                        {{-- override form --}}
                        <template x-if="!ov.regime?.override_reason">
                            <div class="flex flex-col gap-2.5">
                                <button type="button" x-show="!overrideOpen" @click="overrideOpen = true"
                                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center justify-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-1 border-line-strong hover:bg-hover w-full h-[34px] mt-1 text-[13px]">
                                    <x-feathericon-sliders class="w-[14px] h-[14px]" stroke-width="1.75"/>Override regime
                                </button>
                                <div x-show="overrideOpen" x-cloak class="flex flex-col gap-2.5">
                                    <input type="text" x-model="overrideReason" placeholder="Reason (audit trail)"
                                           class="w-full h-[34px] px-3 rounded-control border border-line-strong bg-transparent text-[12.5px] text-fg-1 placeholder:text-fg-mute focus:outline-none focus:border-accent">
                                    <div class="flex items-center gap-2.5">
                                        <input type="number" x-model.number="overrideHours" min="0.5" max="24" step="0.5"
                                               class="w-[86px] h-[34px] px-3 rounded-control border border-line-strong bg-transparent text-[12.5px] font-mono tabular-nums text-fg-1 focus:outline-none focus:border-accent">
                                        <span class="font-mono text-[10px] tracking-[0.06em] uppercase text-fg-mute">hours</span>
                                        <div class="flex-1"></div>
                                        <button type="button" @click="overrideOpen = false"
                                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-3 hover:bg-hover hover:text-fg-1 h-[32px] px-2.5 text-[12px]">Cancel</button>
                                        <button type="button" @click="engageOverride()" :disabled="overrideBusy || !overrideReason"
                                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center whitespace-nowrap transition-colors duration-fast ease-out bg-accent text-white hover:opacity-90 h-[32px] px-3 text-[12px] disabled:opacity-50">Engage</button>
                                    </div>
                                    <span x-show="overrideError" class="text-[11.5px]" style="color: var(--danger)" x-text="overrideError"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- deploy — core version reported by each box's heartbeat --}}
                <div class="card card--flat overflow-hidden">
                    <x-ui.card-head icon="zap" title="Deploy" :accent="true" hint="core rollout"/>
                    <div class="p-5 flex flex-col gap-3.5">
                        <template x-if="ov.deploy.version === null">
                            <span class="font-mono text-[11px] text-fg-mute leading-relaxed">No version reports yet — fleet heartbeats predate version reporting.</span>
                        </template>
                        <template x-if="ov.deploy.version !== null">
                            <div class="flex flex-col gap-3.5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-mono text-[12.5px] font-semibold text-fg-1" x-text="'core ' + ov.deploy.version"></span>
                                    <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase"
                                          :style="`color: ${ov.deploy.in_sync ? 'var(--pnl-up-fg)' : 'var(--warn)'}`">
                                        <span class="w-[6px] h-[6px] rounded-chip" :style="`background: ${ov.deploy.in_sync ? 'var(--pnl-up-fg)' : 'var(--warn)'}`"></span>
                                        <span x-text="ov.deploy.in_sync ? 'Fleet in sync' : 'Version drift'"></span>
                                    </span>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <div class="flex items-center justify-between">
                                        <span class="font-mono text-[10px] tracking-[0.06em] uppercase text-fg-mute">Rolled out</span>
                                        <span class="font-mono text-[11px] font-semibold tabular-nums text-fg-1" x-text="`${ov.deploy.on_latest} / ${ov.deploy.reporting} nodes`"></span>
                                    </div>
                                    <div class="h-[6px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip bg-accent transition-[width] duration-base" :style="`width: ${deployPct()}%`"></div></div>
                                </div>
                                <span x-show="laggingLabel()" class="font-mono text-[10px] text-fg-mute tracking-[0.02em]" x-text="laggingLabel()"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- revenue today --}}
                <div class="card card--flat overflow-hidden">
                    <x-ui.card-head icon="credit-card" title="Revenue today" :accent="true"/>
                    <div class="px-5 py-1.5">
                        <div class="flex items-center justify-between gap-3 py-3 border-b border-line-soft">
                            <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-trending-up class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>MRR</span>
                            <span class="flex items-center gap-2">
                                <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] max-[480px]:hidden">committed run-rate</span>
                                <span class="font-mono text-[14px] font-bold tabular-nums text-fg-1" x-text="fmtMoney(ov.revenue.mrr)"></span>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-3 border-b border-line-soft">
                            <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-plus class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>Top-ups today</span>
                            <span class="flex items-center gap-2">
                                <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] max-[480px]:hidden" x-text="`${ov.revenue.topups_count} payment${ov.revenue.topups_count === 1 ? '' : 's'}`"></span>
                                <span class="font-mono text-[14px] font-bold tabular-nums text-fg-1" x-text="fmtMoney(ov.revenue.topups_today)"></span>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-3">
                            <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-credit-card class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>Wallet float held</span>
                            <span class="flex items-center gap-2">
                                <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] max-[480px]:hidden">across all wallets</span>
                                <span class="font-mono text-[14px] font-bold tabular-nums text-fg-1" x-text="fmtMoney(ov.revenue.wallet_float)"></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== VENUES + INCIDENTS ===================== --}}
        <div class="grid grid-cols-[1.6fr_1fr] gap-5 max-[1024px]:grid-cols-1">
            {{-- exchange connectivity — api_request_logs, trailing hour --}}
            <div class="card card--flat overflow-hidden">
                <x-ui.card-head icon="shuffle" title="Exchange connectivity" :accent="true">
                    <x-slot:right>
                        <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                              x-text="`${(ov.venues || []).length} venues · ${degradedCount()} degraded`"></span>
                    </x-slot:right>
                </x-ui.card-head>
                <template x-for="v in ov.venues" :key="v.name">
                    <div class="grid grid-cols-[minmax(130px,1.4fr)_120px_120px_72px_70px] items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[820px]:grid-cols-[minmax(120px,1.4fr)_110px_1fr] max-[640px]:px-4 transition-colors"
                         :style="v.status === 'degraded' || v.status === 'down' ? 'background: color-mix(in srgb, var(--warn) 7%, transparent)' : ''">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="w-[28px] h-[28px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[11px] flex items-center justify-center flex-shrink-0" x-text="v.mono"></span>
                            <span class="font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap" x-text="v.name"></span>
                        </div>
                        <span class="inline-flex items-center gap-[7px] py-[5px] px-[11px] rounded-chip border font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase whitespace-nowrap justify-self-start"
                              :style="`color: ${venueMeta(v.status).color}; border-color: color-mix(in srgb, ${venueMeta(v.status).color} 36%, transparent); background: color-mix(in srgb, ${venueMeta(v.status).color} 12%, transparent)`">
                            <span class="w-[7px] h-[7px] rounded-chip" :class="venueMeta(v.status).pulse && 'animate-pulse-soft'" :style="`background: ${venueMeta(v.status).color}`"></span>
                            <span x-text="venueMeta(v.status).label"></span>
                        </span>
                        <div class="flex items-center gap-2.5 max-[820px]:hidden">
                            <div class="w-[52px] h-[20px] flex-shrink-0 opacity-80">
                                <svg viewBox="0 0 52 20" preserveAspectRatio="none" class="w-full h-full">
                                    <path :d="sparkPath(v.spark, 52, 20)" fill="none"
                                          :stroke="v.status === 'operational' ? 'var(--pnl-up-fg)' : 'var(--warn)'"
                                          stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                                </svg>
                            </div>
                            <span class="font-mono text-[11.5px] font-semibold tabular-nums"
                                  :style="`color: ${v.latency_ms === null ? 'var(--fg-mute)' : (v.latency_ms >= 1000 ? 'var(--warn)' : 'var(--fg-1)')}`"
                                  x-text="v.latency_ms === null ? '—' : v.latency_ms + 'ms'"></span>
                        </div>
                        <div class="flex flex-col items-end leading-tight max-[820px]:hidden">
                            <span class="font-mono text-[11.5px] font-semibold tabular-nums"
                                  :style="`color: ${v.error_pct === null ? 'var(--fg-mute)' : (v.error_pct >= 1 ? 'var(--warn)' : 'var(--fg-2)')}`"
                                  x-text="v.error_pct === null ? '—' : v.error_pct.toFixed(2) + '%'"></span>
                            <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">errors</span>
                        </div>
                        <div class="flex flex-col items-end leading-tight">
                            <span class="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1" x-text="v.accounts"></span>
                            <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">accts</span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- incidents & events — latest occurrence per notification canonical --}}
            <div class="card card--flat overflow-hidden">
                <x-ui.card-head icon="activity" title="Incidents & events" :accent="true"/>
                <div class="px-5 py-1">
                    <div x-show="!ov.incidents || ov.incidents.length === 0" class="py-8 text-center font-mono text-[11px] text-fg-mute">No events yet.</div>
                    <template x-for="(ev, i) in ov.incidents" :key="ev.title + ev.age">
                        <div class="flex items-start gap-3 py-3" :class="i < ov.incidents.length - 1 && 'border-b border-line-soft'">
                            <span class="w-[26px] h-[26px] rounded-control flex items-center justify-center flex-shrink-0 mt-px"
                                  :style="`background: color-mix(in srgb, ${sevColor(ev.severity)} 14%, transparent); color: ${sevColor(ev.severity)}`">
                                {{-- one feather glyph per severity --}}
                                <template x-if="ev.severity === 'bad'"><x-feathericon-alert-octagon class="w-3.5 h-3.5" stroke-width="1.75"/></template>
                                <template x-if="ev.severity === 'warn'"><x-feathericon-alert-triangle class="w-3.5 h-3.5" stroke-width="1.75"/></template>
                                <template x-if="ev.severity === 'good'"><x-feathericon-check-circle class="w-3.5 h-3.5" stroke-width="1.75"/></template>
                                <template x-if="ev.severity === 'mute'"><x-feathericon-info class="w-3.5 h-3.5" stroke-width="1.75"/></template>
                            </span>
                            <span class="flex-1 min-w-0 text-[12.5px] text-fg-2 leading-snug">
                                <span x-text="ev.title"></span>
                                <span class="font-mono text-[10px] text-fg-mute" x-show="ev.channel" x-text="' · ' + ev.channel"></span>
                            </span>
                            <span class="font-mono text-[10.5px] text-fg-mute tabular-nums flex-shrink-0 mt-0.5" x-text="ev.age"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
