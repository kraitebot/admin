<x-app-layout :activeSection="'system'" :activeHighlight="'heartbeat'" :flush="true">
    <div class="flex flex-col h-full" x-data="heartbeat()" x-init="fetchData(); startPolling()">
        <x-hub-ui::live-header
            title="Heartbeat"
            description="Real-time server health and background process telemetry."
            last-updated-model="lastUpdated"
        >
            <x-slot:actions>
                <template x-if="systemHealthy">
                    <x-hub-ui::badge type="success" size="sm" :dot="true">Operational</x-hub-ui::badge>
                </template>
                <template x-if="!systemHealthy">
                    <x-hub-ui::badge type="danger" size="sm" :dot="true">Degraded</x-hub-ui::badge>
                </template>
            </x-slot:actions>
        </x-hub-ui::live-header>

        <div class="flex-1 overflow-auto p-4 sm:p-6 space-y-4 sm:space-y-6">

            {{-- Vitals zone: CPU / RAM / HDD / Dispatcher / Slow queries --}}
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
                    <div class="flex items-baseline gap-2 flex-wrap">
                        <h2 class="text-sm font-semibold ui-text">Vitals</h2>
                        <span class="text-[11px] ui-text-muted">server load · dispatcher throughput · query health</span>
                    </div>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">Live</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                    {{-- CPU / RAM / HDD tiles --}}
                    <template x-for="gauge in gauges" :key="gauge.label">
                        <div class="ui-bg-elevated rounded-lg p-3">
                            <div class="flex items-baseline justify-between mb-2">
                                <span class="text-xs font-semibold ui-text" x-text="gauge.label"></span>
                                <span
                                    class="text-[11px] font-mono ui-tabular"
                                    :style="'color: ' + gaugeColor(gauge.percent)"
                                    x-text="gauge.percent.toFixed(1) + '%'"
                                ></span>
                            </div>

                            <x-hub-ui::progress-bar
                                value="gauge.percent"
                                ticks="10"
                                tick-width="8"
                                tick-height="18"
                                tick-gap="2"
                                class="w-full"
                            />

                            <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                                <span class="truncate" :title="gauge.detail" x-text="gauge.detail"></span>
                                <x-hub-ui::trend-delta value="gauge.delta" suffix="pt" precision="1" />
                            </div>
                        </div>
                    </template>

                    {{-- Dispatcher tile --}}
                    <div class="ui-bg-elevated rounded-lg p-3">
                        <div class="flex items-baseline justify-between mb-2">
                            <span class="text-xs font-semibold ui-text">Dispatcher</span>
                            <template x-if="stepDispatcher.running">
                                <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-success))">running</span>
                            </template>
                            <template x-if="!stepDispatcher.running">
                                <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-danger))">stopped</span>
                            </template>
                        </div>

                        <div class="flex items-baseline gap-3 leading-none py-1">
                            <span class="text-base font-bold font-mono ui-text ui-tabular" x-text="(stepDispatcher.total || 0).toLocaleString()"></span>
                            <template x-for="key in ['Running', 'Pending', 'Failed']" :key="key">
                                <div class="flex items-baseline gap-1">
                                    <span
                                        class="text-xs font-bold font-mono ui-tabular"
                                        :style="((stepDispatcher.by_state || {})[key] || 0) > 0 ? 'color: ' + stepStateColor(key) : ''"
                                        :class="((stepDispatcher.by_state || {})[key] || 0) === 0 ? 'ui-text-subtle' : ''"
                                        x-text="((stepDispatcher.by_state || {})[key] || 0).toLocaleString()"
                                    ></span>
                                    <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle" x-text="key.slice(0, 3).toLowerCase()"></span>
                                </div>
                            </template>
                        </div>

                        <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                            <span class="truncate" x-text="stepDispatcher.last_tick ? ('last ' + stepDispatcher.last_tick) : '—'"></span>
                            <a href="{{ route('system.step-dispatcher') }}" wire:navigate class="flex items-center gap-0.5 hover:opacity-80 transition-opacity" style="color: rgb(var(--ui-primary))">
                                <span>details</span>
                                <x-feathericon-chevron-right class="w-3 h-3" />
                            </a>
                        </div>
                    </div>

                    {{-- Slow queries tile --}}
                    <div class="ui-bg-elevated rounded-lg p-3">
                        <div class="flex items-baseline justify-between mb-2">
                            <span class="text-xs font-semibold ui-text">Slow Queries</span>
                            <template x-if="slowQueries.last_hour_count === 0">
                                <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-success))">clear</span>
                            </template>
                            <template x-if="slowQueries.last_hour_count > 0">
                                <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-warning))" x-text="slowQueries.last_hour_count + ' hits'"></span>
                            </template>
                        </div>

                        <div class="flex items-baseline gap-2 py-1">
                            <span
                                class="text-base font-bold font-mono ui-tabular leading-none"
                                :class="slowQueries.last_hour_count === 0 ? 'ui-text-subtle' : 'ui-text'"
                                x-text="(slowQueries.last_hour_count || 0).toLocaleString()"
                            ></span>
                            <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle">last hour</span>
                        </div>

                        <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                            <span class="truncate" x-text="(slowQueries.recent?.[0]?.time_ms || 0) + 'ms newest'"></span>
                            <span x-text="(slowQueries.recent?.length || 0) + ' recent'"></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        function heartbeat() {
            return {
                loading: true,
                lastUpdated: null,
                _interval: null,
                _hasSnapshot: false,

                gauges: [
                    { label: 'CPU', percent: 0, delta: 0, detail: '—', sub: null },
                    { label: 'RAM', percent: 0, delta: 0, detail: '—', sub: null },
                    { label: 'HDD', percent: 0, delta: 0, detail: '—', sub: null },
                ],

                stepDispatcher: { running: false, total: 0, by_state: {}, last_tick: null },
                slowQueries: { last_hour_count: 0, recent: [] },

                get systemHealthy() {
                    if (this.gauges.some(g => g.percent >= 90)) return false;
                    if ((this.stepDispatcher.by_state || {}).Failed > 0) return false;
                    if (!this.stepDispatcher.running) return false;
                    return true;
                },

                async fetchData() {
                    const { ok, data } = await hubUiFetch('{{ route("system.heartbeat.data") }}', { method: 'GET' });

                    if (ok) {
                        const s = data.server;
                        const ramPercent = s.ram_total_mb > 0 ? (s.ram_used_mb / s.ram_total_mb * 100) : 0;
                        const hddPercent = s.hdd_total_gb > 0 ? (s.hdd_used_gb / s.hdd_total_gb * 100) : 0;

                        const newGauges = [
                            { label: 'CPU', percent: s.cpu_percent, detail: 'load across 32 vCPU', sub: null },
                            { label: 'RAM', percent: Math.round(ramPercent * 10) / 10, detail: this.formatMb(s.ram_used_mb) + ' / ' + this.formatMb(s.ram_total_mb), sub: 'memory in use' },
                            { label: 'HDD', percent: Math.round(hddPercent * 10) / 10, detail: s.hdd_used_gb + ' / ' + s.hdd_total_gb + ' GB', sub: 'root filesystem' },
                        ];

                        this.gauges = newGauges.map((g, i) => {
                            const prev = this._hasSnapshot && this.gauges[i] ? this.gauges[i].percent : g.percent;
                            return { ...g, delta: Math.round((g.percent - prev) * 10) / 10 };
                        });
                        this._hasSnapshot = true;

                        this.stepDispatcher = data.step_dispatcher;
                        this.slowQueries = data.slow_queries;

                        this.lastUpdated = new Date().toLocaleTimeString();
                    }

                    this.loading = false;
                },

                startPolling() {
                    this._interval = setInterval(() => this.fetchData(), 5000);
                },

                formatMb(mb) {
                    if (mb >= 1024) return (mb / 1024).toFixed(1) + ' GB';
                    return Math.round(mb) + ' MB';
                },

                gaugeColor(percent) {
                    if (percent >= 80) return 'rgb(var(--ui-danger))';
                    if (percent >= 60) return 'rgb(var(--ui-warning))';
                    return 'rgb(var(--ui-success))';
                },

                stepStateColor(state) {
                    const map = {
                        'Running': 'rgb(var(--ui-warning))',
                        'Pending': 'rgb(var(--ui-info))',
                        'Failed': 'rgb(var(--ui-danger))',
                        'Completed': 'rgb(var(--ui-success))',
                    };
                    return map[state] || 'rgb(var(--ui-text-subtle))';
                },
            };
        }
    </script>
</x-app-layout>
