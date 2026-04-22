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

            {{-- Vitals strip — CPU / RAM / HDD on horizontal lanes --}}
            <div class="ui-card overflow-hidden">
                <div class="flex items-center gap-2 px-4 py-2.5 border-b ui-border ui-bg-elevated">
                    <x-feathericon-cpu class="w-3.5 h-3.5 ui-text-muted" />
                    <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Vitals</span>
                </div>
                <div class="divide-y ui-border">
                    <template x-for="gauge in gauges" :key="gauge.label">
                        <div class="px-4 py-3 flex items-center gap-4 flex-wrap">
                            <div class="flex items-center gap-3 min-w-[110px]">
                                <span class="text-[11px] font-semibold uppercase tracking-[0.18em] ui-text-muted w-9" x-text="gauge.label"></span>
                                <span
                                    class="text-xl font-bold font-mono ui-tabular leading-none"
                                    :style="'color: ' + gaugeColor(gauge.percent)"
                                    x-text="gauge.percent.toFixed(0) + '%'"
                                ></span>
                            </div>

                            <div class="flex-1 min-w-[200px] order-3 sm:order-none flex items-center">
                                <x-hub-ui::progress-bar
                                    value="gauge.percent"
                                    ticks="20"
                                    tick-width="6"
                                    tick-height="18"
                                    tick-gap="2"
                                    class="w-full"
                                />
                            </div>

                            <div class="flex flex-col sm:items-end min-w-[180px]">
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] font-mono ui-text-muted" x-text="gauge.detail"></span>
                                    <x-hub-ui::trend-delta value="gauge.delta" suffix="pt" precision="1" />
                                </div>
                                <span x-show="gauge.sub" class="text-[10px] ui-text-subtle uppercase tracking-[0.12em] mt-0.5" x-text="gauge.sub"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Dispatcher lane — single horizontal row --}}
            <div class="ui-card overflow-hidden relative">
                <span
                    class="absolute top-0 left-0 bottom-0 w-[3px] pointer-events-none"
                    :style="'background-color: ' + (stepDispatcher.running ? 'rgb(var(--ui-success))' : 'rgb(var(--ui-danger))')"
                ></span>
                <div class="pl-5 pr-4 py-3 flex items-center gap-4 flex-wrap">
                    <div class="flex items-center gap-2">
                        <x-feathericon-layers class="w-3.5 h-3.5 ui-text-muted" />
                        <span class="text-[11px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Step Dispatcher</span>
                        <template x-if="stepDispatcher.running">
                            <x-hub-ui::badge type="success" size="sm" :dot="true">Running</x-hub-ui::badge>
                        </template>
                        <template x-if="!stepDispatcher.running">
                            <x-hub-ui::badge type="danger" size="sm" :dot="true">Stopped</x-hub-ui::badge>
                        </template>
                    </div>

                    <div class="hidden sm:block w-px h-4" style="background-color: rgb(var(--ui-border))"></div>

                    <div class="flex items-center gap-4 flex-wrap">
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-lg font-bold font-mono ui-text ui-tabular" x-text="(stepDispatcher.total || 0).toLocaleString()"></span>
                            <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle">total</span>
                        </div>
                        <template x-for="key in ['Running', 'Pending', 'Failed', 'Completed']" :key="key">
                            <div class="flex items-baseline gap-1.5">
                                <span
                                    class="text-base font-bold font-mono ui-tabular"
                                    :style="((stepDispatcher.by_state || {})[key] || 0) > 0 ? 'color: ' + stepStateColor(key) : ''"
                                    :class="((stepDispatcher.by_state || {})[key] || 0) === 0 ? 'ui-text-subtle' : ''"
                                    x-text="((stepDispatcher.by_state || {})[key] || 0).toLocaleString()"
                                ></span>
                                <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle" x-text="key"></span>
                            </div>
                        </template>
                    </div>

                    <span class="flex-1"></span>

                    <span x-show="stepDispatcher.last_tick" class="text-[10px] ui-text-subtle font-mono hidden lg:inline">
                        last tick <span class="ui-text-muted" x-text="stepDispatcher.last_tick"></span>
                    </span>
                    <a href="{{ route('system.step-dispatcher') }}" wire:navigate class="text-[11px] font-medium flex items-center gap-1 hover:opacity-80 transition-opacity" style="color: rgb(var(--ui-primary))">
                        <span>Details</span>
                        <x-feathericon-chevron-right class="w-3.5 h-3.5" />
                    </a>
                </div>
            </div>

            {{-- Two-column: Supervisor + Schedule --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                {{-- Supervisor --}}
                <div class="ui-card overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-2.5 ui-bg-elevated border-b ui-border">
                        <div class="flex items-center gap-2">
                            <x-feathericon-server class="w-3.5 h-3.5 ui-text-muted" />
                            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Supervisor</span>
                            <span x-show="supervisor.processes" class="text-[10px] ui-text-subtle font-mono" x-text="'· ' + (supervisor.processes || []).length"></span>
                        </div>
                        <template x-if="supervisor.available && runningCount === (supervisor.processes || []).length && runningCount > 0">
                            <x-hub-ui::badge type="success" size="sm" :dot="true">
                                <span><span x-text="runningCount"></span>/<span x-text="(supervisor.processes || []).length"></span> up</span>
                            </x-hub-ui::badge>
                        </template>
                        <template x-if="supervisor.available && runningCount !== (supervisor.processes || []).length">
                            <x-hub-ui::badge type="warning" size="sm" :dot="true">
                                <span><span x-text="runningCount"></span>/<span x-text="(supervisor.processes || []).length"></span> up</span>
                            </x-hub-ui::badge>
                        </template>
                    </div>
                    <div>
                        <template x-if="!supervisor.available">
                            <div class="p-4">
                                <x-hub-ui::alert type="warning">
                                    <span x-text="supervisor.error || 'Supervisor not available'"></span>
                                </x-hub-ui::alert>
                            </div>
                        </template>
                        <template x-if="supervisor.available && supervisor.processes && supervisor.processes.length > 0">
                            <div class="overflow-x-auto">
                                <table class="w-full ui-table ui-data-table ui-data-table--sm">
                                    <thead class="ui-bg-elevated">
                                        <tr>
                                            <th>Process</th>
                                            <th>State</th>
                                            <th>PID</th>
                                            <th>Uptime</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="proc in supervisor.processes" :key="proc.name">
                                            <tr>
                                                <td class="font-mono" x-text="proc.name"></td>
                                                <td>
                                                    <template x-if="proc.state === 'RUNNING'"><x-hub-ui::badge type="success" size="sm" :dot="true"><span x-text="proc.state.toLowerCase()"></span></x-hub-ui::badge></template>
                                                    <template x-if="proc.state === 'STOPPED' || proc.state === 'BACKOFF'"><x-hub-ui::badge type="warning" size="sm" :dot="true"><span x-text="proc.state.toLowerCase()"></span></x-hub-ui::badge></template>
                                                    <template x-if="proc.state === 'FATAL' || proc.state === 'EXITED'"><x-hub-ui::badge type="danger" size="sm" :dot="true"><span x-text="proc.state.toLowerCase()"></span></x-hub-ui::badge></template>
                                                    <template x-if="proc.state === 'STARTING'"><x-hub-ui::badge type="info" size="sm" :dot="true"><span x-text="proc.state.toLowerCase()"></span></x-hub-ui::badge></template>
                                                    <template x-if="!['RUNNING','STOPPED','BACKOFF','FATAL','EXITED','STARTING'].includes(proc.state)"><x-hub-ui::badge type="default" size="sm" :dot="true"><span x-text="proc.state.toLowerCase()"></span></x-hub-ui::badge></template>
                                                </td>
                                                <td class="font-mono ui-text-subtle ui-tabular" x-text="proc.pid || '—'"></td>
                                                <td class="font-mono ui-text-subtle ui-tabular" x-text="proc.uptime || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <template x-if="supervisor.available && (!supervisor.processes || supervisor.processes.length === 0)">
                            <div class="p-6 text-center">
                                <p class="text-xs ui-text-subtle">No supervisor processes found</p>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Scheduled Commands --}}
                <div class="ui-card overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-2.5 ui-bg-elevated border-b ui-border">
                        <div class="flex items-center gap-2">
                            <x-feathericon-clock class="w-3.5 h-3.5 ui-text-muted" />
                            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Schedule</span>
                            <span x-show="schedule.tasks" class="text-[10px] ui-text-subtle font-mono" x-text="'· ' + (schedule.tasks || []).length"></span>
                        </div>
                        <span x-show="nextTask" class="text-[10px] ui-text-subtle font-mono flex items-center gap-1">
                            <span>next</span>
                            <span class="ui-text-muted" x-text="nextTask?._countdown || '—'"></span>
                        </span>
                    </div>
                    <div>
                        <template x-if="schedule.tasks && schedule.tasks.length > 0">
                            <div class="overflow-x-auto">
                                <table class="w-full ui-table ui-data-table ui-data-table--sm">
                                    <thead class="ui-bg-elevated">
                                        <tr>
                                            <th>Command</th>
                                            <th>Cron</th>
                                            <th class="text-right">In</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="task in schedule.tasks" :key="task.command">
                                            <tr>
                                                <td class="font-mono" :title="task.next_run" x-text="task.command"></td>
                                                <td class="font-mono ui-text-subtle" x-text="task.expression"></td>
                                                <td class="font-mono ui-tabular text-right" :class="task === nextTask ? 'ui-text-success font-semibold' : 'ui-text-muted'" x-text="task._countdown || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <template x-if="!schedule.tasks || schedule.tasks.length === 0">
                            <div class="p-6 text-center">
                                <p class="text-xs ui-text-subtle">No scheduled tasks</p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Slow Queries --}}
            <div class="ui-card overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2.5 ui-bg-elevated border-b ui-border">
                    <div class="flex items-center gap-2">
                        <x-feathericon-zap class="w-3.5 h-3.5 ui-text-muted" />
                        <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Slow Queries</span>
                        <span class="text-[10px] ui-text-subtle font-mono">·
                            <span class="ui-tabular" x-text="slowQueries.last_hour_count"></span>
                            <span>last hour</span>
                        </span>
                    </div>
                </div>
                <div>
                    <template x-if="slowQueries.recent && slowQueries.recent.length > 0">
                        <div class="overflow-x-auto">
                            <table class="w-full ui-table ui-data-table ui-data-table--sm">
                                <thead class="ui-bg-elevated">
                                    <tr>
                                        <th style="width: 100px">Duration</th>
                                        <th>Query</th>
                                        <th>Connection</th>
                                        <th class="text-right">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="q in slowQueries.recent" :key="q.id">
                                        <tr>
                                            <td>
                                                <template x-if="q.time_ms > 500"><x-hub-ui::badge type="danger" size="sm" :dot="true"><span x-text="q.time_ms + 'ms'"></span></x-hub-ui::badge></template>
                                                <template x-if="q.time_ms >= 100 && q.time_ms <= 500"><x-hub-ui::badge type="warning" size="sm" :dot="true"><span x-text="q.time_ms + 'ms'"></span></x-hub-ui::badge></template>
                                                <template x-if="q.time_ms < 100"><x-hub-ui::badge type="success" size="sm" :dot="true"><span x-text="q.time_ms + 'ms'"></span></x-hub-ui::badge></template>
                                            </td>
                                            <td class="font-mono max-w-md truncate" :title="q.sql" x-text="q.sql"></td>
                                            <td class="font-mono ui-text-subtle" x-text="q.connection || '—'"></td>
                                            <td class="font-mono ui-text-subtle ui-tabular text-right" x-text="q.created_at"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template x-if="!slowQueries.recent || slowQueries.recent.length === 0">
                        <div class="px-5 py-4 flex items-center gap-3">
                            <div class="flex-shrink-0 w-8 h-8 rounded-lg ui-bg-elevated flex items-center justify-center">
                                <x-feathericon-check-circle class="w-4 h-4" style="color: rgb(var(--ui-success))" />
                            </div>
                            <div>
                                <div class="text-sm font-medium ui-text">All clear</div>
                                <div class="text-[11px] ui-text-subtle mt-0.5 font-mono">No slow queries in the last hour.</div>
                            </div>
                        </div>
                    </template>
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
                _countdownInterval: null,
                _hasSnapshot: false,

                gauges: [
                    { label: 'CPU', percent: 0, delta: 0, detail: '—', sub: null },
                    { label: 'RAM', percent: 0, delta: 0, detail: '—', sub: null },
                    { label: 'HDD', percent: 0, delta: 0, detail: '—', sub: null },
                ],

                supervisor: { available: false, processes: [] },
                schedule: { tasks: [] },
                stepDispatcher: { running: false, total: 0, by_state: {}, last_tick: null },
                slowQueries: { last_hour_count: 0, recent: [] },

                get runningCount() {
                    return (this.supervisor.processes || []).filter(p => p.state === 'RUNNING').length;
                },

                get nextTask() {
                    const tasks = this.schedule.tasks || [];
                    if (!tasks.length) return null;
                    return tasks
                        .filter(t => t.next_run_iso)
                        .sort((a, b) => new Date(a.next_run_iso) - new Date(b.next_run_iso))[0] || null;
                },

                get systemHealthy() {
                    if (!this.supervisor.available) return false;
                    const procs = this.supervisor.processes || [];
                    if (procs.some(p => ['FATAL', 'EXITED', 'BACKOFF'].includes(p.state))) return false;
                    if (this.gauges.some(g => g.percent >= 90)) return false;
                    if ((this.stepDispatcher.by_state || {}).Failed > 0) return false;
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

                        this.supervisor = data.supervisor;
                        this.schedule = data.schedule;
                        this.updateCountdowns();
                        this.stepDispatcher = data.step_dispatcher;
                        this.slowQueries = data.slow_queries;

                        this.lastUpdated = new Date().toLocaleTimeString();
                    }

                    this.loading = false;
                },

                startPolling() {
                    this._interval = setInterval(() => this.fetchData(), 5000);
                    this._countdownInterval = setInterval(() => this.updateCountdowns(), 1000);
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

                supervisorBadgeType(state) {
                    const map = {
                        'RUNNING': 'success',
                        'STOPPED': 'warning',
                        'FATAL': 'danger',
                        'STARTING': 'info',
                        'BACKOFF': 'warning',
                        'EXITED': 'danger',
                    };
                    return map[state] || 'default';
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

                slowQueryBadgeType(ms) {
                    if (ms > 500) return 'danger';
                    if (ms >= 100) return 'warning';
                    return 'success';
                },

                updateCountdowns() {
                    if (!this.schedule.tasks) return;
                    const now = new Date();
                    this.schedule.tasks.forEach(task => {
                        if (!task.next_run_iso) { task._countdown = '—'; return; }
                        const next = new Date(task.next_run_iso);
                        const diff = Math.max(0, Math.floor((next - now) / 1000));
                        if (diff <= 0) task._countdown = 'now';
                        else if (diff < 60) task._countdown = diff + 's';
                        else if (diff < 3600) task._countdown = Math.floor(diff / 60) + 'm ' + (diff % 60) + 's';
                        else task._countdown = Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm';
                    });
                },
            };
        }
    </script>
</x-app-layout>
