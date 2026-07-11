<x-app-layout active="infra" :title="'Kraite — Infrastructure'">
    <script>
        // Infra page — SERVER layer only (exchange connectivity lives on its own
        // page). One tile per server merging what used to be three cards:
        // reachability + vitals (kraite.fleet heartbeat via system.dashboard.data)
        // and the egress-IP allowlist (server-rendered apiable roster, passed in
        // as `egress` = { hostname: ip }). The dispatcher pulse + slow queries
        // (system.dashboard.health) are platform-wide, not per-server, so they
        // live in a slim strip above the tiles.
        window.infraPage = (dataUrl, healthUrl, egress) => ({
            fleet: [],
            control: null,
            loaded: false,
            loadedHealth: false,
            loading: false,
            copied: null,
            copiedAll: false,
            _timer: null,

            init() {
                this.refresh();
                this._timer = setInterval(() => this.refresh(), 15000);
            },
            destroy() {
                if (this._timer) { clearInterval(this._timer); this._timer = null; }
            },
            async refresh() {
                if (this.loading) return;
                this.loading = true;
                try {
                    // 8s cap per request: one stalled response must never
                    // wedge `loading` and silently kill every future tick.
                    const signal = AbortSignal.timeout(8000);
                    const [dataRes, healthRes] = await Promise.allSettled([
                        fetch(dataUrl, { headers: { Accept: 'application/json' }, signal }),
                        fetch(healthUrl, { headers: { Accept: 'application/json' }, signal }),
                    ]);
                    if (dataRes.status === 'fulfilled' && dataRes.value.ok) {
                        const d = await dataRes.value.json();
                        this.fleet = Array.isArray(d.fleet) ? d.fleet : [];
                        this.loaded = true;
                    }
                    if (healthRes.status === 'fulfilled' && healthRes.value.ok) {
                        this.control = await healthRes.value.json();
                        this.loadedHealth = true;
                    }
                } finally {
                    this.loading = false;
                }
            },

            // ---- fleet ----
            counts() {
                const c = { online: 0, stale: 0, missing: 0 };
                this.fleet.forEach((f) => { c[f.status] = (c[f.status] || 0) + 1; });
                return c;
            },
            attention() {
                const c = this.counts();
                return c.stale + c.missing;
            },
            statusMeta(status) {
                if (status === 'online') return { label: 'REACHABLE', color: 'var(--pnl-up-fg)' };
                if (status === 'stale') return { label: 'STALE', color: 'var(--warn)' };
                return { label: 'UNREACHABLE', color: 'var(--danger)' };
            },
            ageHuman(s) {
                if (s === null || s === undefined) return '—';
                if (s < 60) return s + 's';
                if (s < 3600) return Math.floor(s / 60) + 'm';
                return Math.floor(s / 3600) + 'h';
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
            barColor(pct) {
                return pct >= 90 ? 'var(--danger)' : (pct >= 75 ? 'var(--warn)' : 'var(--pnl-up-fg)');
            },

            // ---- egress allowlist (server-rendered apiable roster) ----
            isAllowlisted(node) {
                return Object.prototype.hasOwnProperty.call(egress, node.hostname);
            },
            egressCount() {
                return Object.keys(egress).length;
            },
            copyIp(node) {
                navigator.clipboard?.writeText(node.ip_address ?? '');
                this.copied = node.hostname;
                setTimeout(() => { this.copied = null; }, 1400);
            },
            copyAllEgress() {
                navigator.clipboard?.writeText(Object.values(egress).join('\n'));
                this.copiedAll = true;
                setTimeout(() => { this.copiedAll = false; }, 1400);
            },
        });
    </script>

    {{-- no x-init: Alpine auto-runs the component's init(); a second call
         would stack a duplicate 15s poll timer and leak the first one --}}
    <div x-data="infraPage(@js(route('system.dashboard.data')), @js(route('system.dashboard.health')), @js(collect($egressIps)->pluck('ip', 'id')))">
        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-server class="w-[13px] h-[13px]" stroke-width="1.75"/>INFRASTRUCTURE
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Infrastructure</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Every server in the fleet — health, vitals, services and its egress IP, one tile per box.</div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <button type="button" @click="refresh()" :disabled="loading"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out h-[36px] px-3.5 text-[13px] bg-transparent text-fg-1 border-line-strong hover:bg-hover disabled:opacity-50">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="loading && 'animate-spin'"/>Re-check
                </button>
            </div>
        </div>

        {{-- ===================== KPI STRIP ===================== --}}
        <div class="grid grid-cols-4 gap-3 mb-5 max-[760px]:grid-cols-2">
            {{-- nodes monitored --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]"><x-feathericon-server class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Fleet nodes</span>
                <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="loaded ? fleet.length : '—'"></span>
                <span class="font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">HOSTS MONITORED</span>
            </div>
            {{-- reachable --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]"><x-feathericon-activity class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Reachable</span>
                <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] leading-none" :style="`color: ${loaded ? 'var(--pnl-up-fg)' : 'var(--fg-1)'}`" x-text="loaded ? counts().online : '—'"></span>
                <span class="font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">REPORTING IN</span>
            </div>
            {{-- needs attention --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]"><x-feathericon-alert-triangle class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Needs attention</span>
                <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] leading-none" :style="`color: ${loaded && attention() > 0 ? 'var(--warn)' : 'var(--fg-1)'}`" x-text="loaded ? attention() : '—'"></span>
                <span class="font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">STALE · UNREACHABLE</span>
            </div>
            {{-- egress IPs (server-rendered, real) --}}
            <x-ui.stat-tile icon="shield" label="Egress IPs" value="{{ count($egressIps) }}" sub="ALLOWLISTED"/>
        </div>

        {{-- ===================== PLATFORM PULSE STRIP ===================== --}}
        {{-- not per-server signals: dispatcher fleets + DB slow queries --}}
        <div class="card card--flat overflow-hidden mb-5">
            <div class="flex items-center gap-8 py-3 px-5 flex-wrap max-[640px]:px-4 max-[640px]:gap-4">
                <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-git-branch class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>Step dispatcher</span>
                <span class="flex items-center gap-2.5">
                    <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em]" x-text="control?.step_dispatcher?.last_tick_age_seconds != null ? 'tick ' + ageHuman(control.step_dispatcher.last_tick_age_seconds) + ' ago' : 'no tick'"></span>
                    <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase" :style="`color: ${control?.step_dispatcher?.running ? 'var(--pnl-up-fg)' : 'var(--danger)'}`">
                        <span class="w-[6px] h-[6px] rounded-chip" :class="control?.step_dispatcher?.running && 'animate-pulse'" :style="`background: ${control?.step_dispatcher?.running ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                        <span x-text="loadedHealth ? (control?.step_dispatcher?.running ? 'Running' : 'Stalled') : '…'"></span>
                    </span>
                </span>
                <span class="w-px h-[18px] bg-line-soft max-[640px]:hidden"></span>
                <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-database class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>Slow queries</span>
                <span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip"
                      :style="(control?.slow_queries?.last_hour_count ?? 0) > 0 ? 'color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent)' : 'color: var(--fg-2)'">
                    <span x-text="control?.slow_queries?.last_hour_count ?? 0"></span><span class="text-fg-mute ml-0.5">/ 1h</span>
                </span>
            </div>
        </div>

        {{-- ===================== FLEET SERVERS — ONE TILE PER BOX ===================== --}}
        <div class="card card--flat overflow-hidden">
            <x-ui.card-head icon="server" title="Fleet servers" :accent="true" hint="heartbeat · 15s">
                <x-slot:right>
                    <span class="flex items-center gap-3">
                        <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                              x-text="loaded ? `${counts().online} reachable · ${attention()} need attention` : 'loading…'"></span>
                        <button type="button" @click="copyAllEgress()"
                                :style="copiedAll ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                class="appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[30px] px-3">
                            <span x-show="!copiedAll"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                            <span x-show="copiedAll" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                            <span x-text="copiedAll ? 'Copied' : 'Copy egress IPs'"></span>
                        </button>
                    </span>
                </x-slot:right>
            </x-ui.card-head>
            <p class="text-[12px] text-fg-3 leading-snug px-5 py-3 border-b border-line-soft max-[640px]:px-4">Tiles marked <span class="font-mono text-[10px] font-bold tracking-[0.05em] uppercase text-pnlup">allowlisted</span> are the API-calling hosts whose egress IPs traders allowlist on the exchange side — rotating any of those needs a coordinated announcement.</p>

            <div x-show="!loaded" class="py-12 text-center font-mono text-[11px] text-fg-mute">Pinging fleet…</div>

            <div x-show="loaded" x-cloak class="grid grid-cols-3 gap-3 p-4 max-[1200px]:grid-cols-2 max-[760px]:grid-cols-1">
                <template x-for="node in fleet" :key="node.hostname">
                    <div class="bg-surface border rounded-control p-4 flex flex-col gap-3"
                         :style="node.status === 'stale' ? 'border-color: color-mix(in srgb, var(--warn) 40%, transparent)' : (node.status === 'missing' ? 'border-color: color-mix(in srgb, var(--danger) 45%, transparent)' : 'border-color: var(--line)')">
                        {{-- head: hostname + type | status --}}
                        <div class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2 min-w-0">
                                <span class="font-mono text-[13.5px] font-bold text-fg-1 whitespace-nowrap overflow-hidden text-ellipsis" x-text="node.hostname"></span>
                                <span class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase py-[2px] px-1.5 rounded-chip bg-surface-3 text-fg-3 flex-shrink-0" x-text="node.type ?? '—'"></span>
                                <span x-show="node.recently_rebooted" class="font-mono text-[8px] font-bold tracking-[0.06em] uppercase py-px px-1 rounded-chip flex-shrink-0" style="color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent)">rebooted</span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase flex-shrink-0" :style="`color: ${statusMeta(node.status).color}`">
                                <span class="w-[6px] h-[6px] rounded-chip" :class="node.status === 'online' && 'animate-pulse'" :style="`background: ${statusMeta(node.status).color}`"></span>
                                <span x-text="statusMeta(node.status).label"></span>
                            </span>
                        </div>

                        {{-- ip + allowlist + copy --}}
                        <div class="flex items-center gap-2.5">
                            <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1 tracking-[0.02em]" x-text="node.ip_address ?? '—'"></span>
                            <span x-show="isAllowlisted(node)" class="font-mono text-[8.5px] font-bold tracking-[0.06em] uppercase text-pnlup">Allowlisted</span>
                            <button type="button" x-show="node.ip_address" @click="copyIp(node)"
                                    :style="copied === node.hostname ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                    class="ml-auto appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[24px] px-2">
                                <span x-show="copied !== node.hostname"><x-feathericon-copy class="w-[12px] h-[12px]" stroke-width="1.75"/></span>
                                <span x-show="copied === node.hostname" x-cloak><x-feathericon-check class="w-[12px] h-[12px]" stroke-width="2"/></span>
                                <span x-text="copied === node.hostname ? 'Copied' : 'Copy'"></span>
                            </button>
                        </div>

                        {{-- vitals --}}
                        <div class="grid grid-cols-3 gap-3">
                            <template x-for="metric in [['CPU', node.cpu], ['MEM', node.ram], ['DISK', node.disk]]" :key="metric[0]">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute" x-text="metric[0]"></span>
                                        <span class="font-mono text-[11px] font-semibold tabular-nums"
                                              :style="`color: ${metric[1]?.percent >= 75 ? barColor(metric[1].percent) : 'var(--fg-2)'}`"
                                              x-text="metric[1]?.percent != null ? metric[1].percent + '%' : '—'"></span>
                                    </div>
                                    <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${metric[1]?.percent ?? 0}%; background: ${barColor(metric[1]?.percent ?? 0)}`"></div></div>
                                </div>
                            </template>
                        </div>

                        {{-- footer: services | uptime + last sync --}}
                        <div class="flex items-center gap-3 pt-2.5 border-t border-line-soft">
                            <div class="flex items-center gap-[6px] flex-wrap min-h-[13px]">
                                <span x-show="unitList(node.units).length === 0" class="font-mono text-[10px] text-fg-mute">no services</span>
                                <template x-for="u in unitList(node.units)" :key="u.name">
                                    <span class="relative group inline-flex items-center justify-center w-[13px] h-[13px] cursor-default">
                                        <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0 transition-transform duration-fast group-hover:scale-150" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                        <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-20 hidden group-hover:block whitespace-nowrap bg-surface border border-line-strong rounded-control shadow-3 px-2.5 py-1.5 pointer-events-none">
                                            <div class="font-mono text-[10px] font-bold text-fg-1 flex items-center gap-1.5">
                                                <span class="w-[6px] h-[6px] rounded-chip flex-shrink-0" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                                <span x-text="u.name"></span>
                                            </div>
                                            <div class="font-mono text-[9px] tracking-[0.06em] uppercase mt-0.5" :style="`color: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`" x-text="u.state"></div>
                                        </div>
                                    </span>
                                </template>
                            </div>
                            <span class="ml-auto font-mono text-[10px] text-fg-mute tracking-[0.04em] uppercase whitespace-nowrap">
                                up <span class="text-fg-2 font-semibold tabular-nums" x-text="uptimeHuman(node.uptime_seconds)"></span>
                                · sync <span class="text-fg-2 font-semibold tabular-nums" x-text="node.status === 'missing' ? '—' : ageHuman(node.age_seconds)"></span>
                            </span>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-app-layout>
