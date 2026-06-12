<x-app-layout active="infra" :title="'Kraite — Infrastructure'">
    <script>
        // Infra page — SERVER layer only (exchange connectivity lives on its own
        // page). Two live feeds, both already built:
        //   • system.dashboard.data  → the fleet roster (kraite.fleet.servers ⋈
        //     Redis heartbeat), here rendered as a reachability/heartbeat lens —
        //     the full per-host vitals card stays on the Overview page.
        //   • system.dashboard.health → the console host's own vitals + the
        //     step-dispatcher pulse + slow-query count (the "control plane").
        window.infraPage = (dataUrl, healthUrl) => ({
            fleet: [],
            control: null,
            loaded: false,
            loadedHealth: false,
            loading: false,
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
                    const [dataRes, healthRes] = await Promise.allSettled([
                        fetch(dataUrl, { headers: { Accept: 'application/json' } }),
                        fetch(healthUrl, { headers: { Accept: 'application/json' } }),
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

            // ---- fleet (reachability lens) ----
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
                return s < 60 ? s + 's' : Math.floor(s / 60) + 'm';
            },
            unitList(units) {
                return Object.entries(units || {}).map(([name, state]) => ({ name, state }));
            },
            unitOk(state) {
                return state === 'RUNNING';
            },

            // ---- control plane (console host) ----
            barColor(pct) {
                return pct >= 90 ? 'var(--danger)' : (pct >= 75 ? 'var(--warn)' : 'var(--pnl-up-fg)');
            },
            ramPct() {
                const s = this.control?.server;
                if (!s || !s.ram_total_mb) return null;
                return Math.round((s.ram_used_mb / s.ram_total_mb) * 100);
            },
            diskPct() {
                const s = this.control?.server;
                if (!s || !s.hdd_total_gb) return null;
                return Math.round((s.hdd_used_gb / s.hdd_total_gb) * 100);
            },
            tickAgo(iso) {
                if (!iso) return '—';
                const secs = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
                if (secs < 60) return secs + 's ago';
                if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
                return Math.floor(secs / 3600) + 'h ago';
            },
        });
    </script>

    <div x-data="infraPage(@js(route('system.dashboard.data')), @js(route('system.dashboard.health')))" x-init="init()">
        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-server class="w-[13px] h-[13px]" stroke-width="1.75"/>INFRASTRUCTURE
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Infrastructure</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">The server layer beneath the fleet — egress-IP allowlist, node reachability, and the console control plane.</div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <button type="button" @click="refresh()" :disabled="loading"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out h-[36px] px-3.5 text-[13px] bg-transparent text-fg-1 border-line-strong hover:bg-hover disabled:opacity-50">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="loading && 'animate-spin'"/>Re-check
                </button>
            </div>
        </div>

        {{-- ===================== KPI STRIP ===================== --}}
        <div class="grid grid-cols-4 gap-3 mb-6 max-[760px]:grid-cols-2">
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

        {{-- ===================== EGRESS IPs + CONTROL PLANE ===================== --}}
        <div class="grid grid-cols-[1.2fr_1fr] gap-5 mb-5 max-[900px]:grid-cols-1">
            {{-- egress IP allowlist — REAL (kraite.fleet.servers apiable hosts) --}}
            <div class="card card--flat overflow-hidden" x-data="{ copiedAll: false, copied: null }">
                <x-ui.card-head icon="shield" title="Egress IP allowlist" :accent="true">
                    <x-slot:right>
                        <button type="button"
                                @click="navigator.clipboard?.writeText(@js(collect($egressIps)->pluck('ip')->implode("\n"))); copiedAll = true; setTimeout(() => copiedAll = false, 1400)"
                                :style="copiedAll ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                class="appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[30px] px-3">
                            <span x-show="!copiedAll"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                            <span x-show="copiedAll" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                            <span x-text="copiedAll ? 'Copied' : 'Copy all'"></span>
                        </button>
                    </x-slot:right>
                </x-ui.card-head>
                <p class="text-[12px] text-fg-3 leading-snug px-5 py-3 border-b border-line-soft max-[640px]:px-4">The canonical outbound addresses traders allowlist on the exchange side — every API-calling host in the fleet. Rotating any of these needs a coordinated announcement.</p>
                @forelse($egressIps as $ip)
                    <div class="flex items-center gap-3 py-2.5 px-5 border-b border-line-soft last:border-b-0 max-[640px]:px-4">
                        <span class="w-[8px] h-[8px] rounded-chip bg-pnlup flex-shrink-0"></span>
                        <span class="font-mono text-[12.5px] font-semibold tabular-nums text-fg-1 tracking-[0.02em]">{{ $ip['ip'] }}</span>
                        <span class="font-mono text-[10px] tracking-[0.07em] uppercase text-fg-mute">{{ $ip['id'] }}</span>
                        @if($ip['type'])
                            <span class="font-mono text-[9px] font-bold tracking-[0.06em] uppercase text-fg-faint">{{ $ip['type'] }}</span>
                        @endif
                        <span class="ml-auto font-mono text-[9.5px] font-bold tracking-[0.06em] uppercase text-pnlup max-[480px]:hidden">Allowlisted</span>
                        <button type="button"
                                @click="navigator.clipboard?.writeText('{{ $ip['ip'] }}'); copied = '{{ $ip['id'] }}'; setTimeout(() => copied = null, 1400)"
                                :style="copied === '{{ $ip['id'] }}' ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                class="ml-2 appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[26px] px-2.5">
                            <span x-show="copied !== '{{ $ip['id'] }}'"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                            <span x-show="copied === '{{ $ip['id'] }}'" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                            <span x-text="copied === '{{ $ip['id'] }}' ? 'Copied' : 'Copy'"></span>
                        </button>
                    </div>
                @empty
                    <div class="py-8 text-center font-mono text-[11px] text-fg-mute">No apiable hosts in the fleet roster.</div>
                @endforelse
            </div>

            {{-- control plane — LIVE (system.dashboard.health: console host + dispatcher) --}}
            <div class="card card--flat overflow-hidden">
                <x-ui.card-head icon="cpu" title="Control plane" :accent="true">
                    <x-slot:right>
                        <span class="font-mono text-[10.5px] text-fg-mute tabular-nums" x-text="control?.server?.hostname ?? 'console host'"></span>
                    </x-slot:right>
                </x-ui.card-head>

                <div x-show="!loadedHealth" class="py-8 text-center font-mono text-[11px] text-fg-mute">Reading host vitals…</div>

                <div x-show="loadedHealth" x-cloak class="p-5 flex flex-col gap-4">
                    {{-- host vitals --}}
                    <div class="grid grid-cols-3 gap-4">
                        {{-- cpu --}}
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">CPU</span>
                                <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${control?.server?.cpu_percent >= 75 ? barColor(control.server.cpu_percent) : 'var(--fg-2)'}`" x-text="control?.server?.cpu_percent !== null && control?.server?.cpu_percent !== undefined ? control.server.cpu_percent + '%' : '—'"></span>
                            </div>
                            <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${control?.server?.cpu_percent ?? 0}%; background: ${barColor(control?.server?.cpu_percent ?? 0)}`"></div></div>
                        </div>
                        {{-- ram --}}
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">MEM</span>
                                <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${ramPct() >= 75 ? barColor(ramPct()) : 'var(--fg-2)'}`" x-text="ramPct() !== null ? ramPct() + '%' : '—'"></span>
                            </div>
                            <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${ramPct() ?? 0}%; background: ${barColor(ramPct() ?? 0)}`"></div></div>
                        </div>
                        {{-- disk --}}
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">DISK</span>
                                <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${diskPct() >= 75 ? barColor(diskPct()) : 'var(--fg-2)'}`" x-text="diskPct() !== null ? diskPct() + '%' : '—'"></span>
                            </div>
                            <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${diskPct() ?? 0}%; background: ${barColor(diskPct() ?? 0)}`"></div></div>
                        </div>
                    </div>

                    {{-- step dispatcher pulse --}}
                    <div class="flex items-center justify-between gap-3 py-3 border-t border-line-soft">
                        <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-git-branch class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>Step dispatcher</span>
                        <span class="flex items-center gap-2.5">
                            <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em]" x-text="control?.step_dispatcher?.last_tick ? 'tick ' + tickAgo(control.step_dispatcher.last_tick) : 'no tick'"></span>
                            <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase" :style="`color: ${control?.step_dispatcher?.running ? 'var(--pnl-up-fg)' : 'var(--danger)'}`">
                                <span class="w-[6px] h-[6px] rounded-chip" :class="control?.step_dispatcher?.running && 'animate-pulse'" :style="`background: ${control?.step_dispatcher?.running ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                <span x-text="control?.step_dispatcher?.running ? 'Running' : 'Stalled'"></span>
                            </span>
                        </span>
                    </div>

                    {{-- slow queries --}}
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-feathericon-database class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>Slow queries</span>
                        <span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip"
                              :style="(control?.slow_queries?.last_hour_count ?? 0) > 0 ? 'color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent)' : 'color: var(--fg-2)'">
                            <span x-text="control?.slow_queries?.last_hour_count ?? 0"></span><span class="text-fg-mute ml-0.5">/ 1h</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== NODE REACHABILITY (LIVE) ===================== --}}
        <div class="card card--flat overflow-hidden">
            <x-ui.card-head icon="server" title="Node reachability" :accent="true" hint="control-plane heartbeat">
                <x-slot:right>
                    <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                          x-text="loaded ? `${counts().online} reachable · ${attention()} need attention` : 'loading…'"></span>
                </x-slot:right>
            </x-ui.card-head>
            <div class="hidden md:grid grid-cols-[minmax(160px,1.6fr)_120px_120px_minmax(120px,1fr)] items-center gap-4 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
                <span>Node</span><span>Status</span><span>Last sync</span><span>Services</span>
            </div>

            <div x-show="!loaded" class="py-10 text-center font-mono text-[11px] text-fg-mute">Pinging fleet…</div>

            <template x-for="node in fleet" :key="node.hostname">
                <div class="grid grid-cols-[minmax(160px,1.6fr)_120px_120px_minmax(120px,1fr)] items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[760px]:grid-cols-[minmax(140px,1.6fr)_110px_1fr] max-[640px]:px-4 transition-colors duration-fast"
                     :style="node.status === 'stale' ? 'background: color-mix(in srgb, var(--warn) 7%, transparent)' : (node.status === 'missing' ? 'background: color-mix(in srgb, var(--danger) 6%, transparent)' : '')">
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
                    {{-- last sync --}}
                    <span class="font-mono text-[11.5px] tabular-nums" :style="`color: ${node.status === 'missing' ? 'var(--fg-mute)' : 'var(--fg-2)'}`"
                          x-text="node.status === 'missing' ? 'no data' : ageHuman(node.age_seconds)"></span>
                    {{-- supervisor services --}}
                    <div class="flex items-center gap-[5px] flex-wrap max-[760px]:hidden">
                        <span x-show="unitList(node.units).length === 0" class="font-mono text-[10px] text-fg-mute">—</span>
                        <template x-for="u in unitList(node.units)" :key="u.name">
                            <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`" :title="`${u.name}: ${u.state}`"></span>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-app-layout>
