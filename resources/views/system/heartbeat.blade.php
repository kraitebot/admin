<x-app-layout :activeSection="'system'" :activeHighlight="'heartbeat'" :flush="true">
    <div class="flex flex-col h-full" x-data="heartbeat()" x-init="fetchData(); startPolling()">
        <x-hub-ui::live-header
            title="Heartbeat"
            description="Real-time server health and background process telemetry."
            last-updated-model="lastUpdated"
        >
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <span
                        class="relative flex h-2 w-2 rounded-full ui-pulse-ring"
                        :style="systemHealthy ? 'background-color: rgb(var(--ui-success))' : 'background-color: rgb(var(--ui-danger))'"
                    ></span>
                    <span
                        class="text-[10px] font-semibold uppercase tracking-wider"
                        :style="systemHealthy ? 'color: rgb(var(--ui-success))' : 'color: rgb(var(--ui-danger))'"
                        x-text="systemHealthy ? 'OPERATIONAL' : 'DEGRADED'"
                    ></span>
                </div>
            </x-slot:actions>
        </x-hub-ui::live-header>

        <div class="flex-1 overflow-auto p-6 space-y-6">

            {{-- Gauges — hero row --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <template x-for="gauge in gauges" :key="gauge.label">
                    <div class="ui-card overflow-hidden">
                        <div class="flex items-center gap-5 p-5">
                            {{-- SVG gauge --}}
                            <div class="relative flex-shrink-0" style="width: 120px; height: 120px;">
                                <svg viewBox="0 0 120 120" class="w-full h-full" style="transform: rotate(-90deg);">
                                    <circle
                                        cx="60" cy="60" r="50"
                                        fill="none"
                                        stroke-width="8"
                                        stroke-linecap="round"
                                        style="stroke: rgb(var(--ui-border))"
                                        stroke-dasharray="314.16"
                                        stroke-dashoffset="78.54"
                                    />
                                    <circle
                                        cx="60" cy="60" r="50"
                                        fill="none"
                                        stroke-width="8"
                                        stroke-linecap="round"
                                        :style="'stroke: ' + gaugeColor(gauge.percent) + '; stroke-dasharray: 314.16; stroke-dashoffset: ' + (314.16 - (235.62 * gauge.percent / 100)) + '; transition: stroke-dashoffset 0.7s ease, stroke 0.5s ease;'"
                                    />
                                </svg>
                                <div class="absolute inset-0 flex flex-col items-center justify-center" style="padding-top: 8px;">
                                    <span
                                        class="text-3xl font-bold ui-tabular"
                                        :style="'color: ' + gaugeColor(gauge.percent)"
                                    >
                                        <x-hub-ui::number value="gauge.percent" format="float" decimals="0" />
                                    </span>
                                    <span class="text-[10px] font-medium uppercase tracking-wider ui-text-subtle -mt-0.5">percent</span>
                                </div>
                            </div>

                            {{-- Labels --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold ui-text" x-text="gauge.label"></span>
                                    <x-hub-ui::trend-delta value="gauge.delta" suffix="pt" precision="1" />
                                </div>
                                <p class="text-xs ui-text-subtle mt-1 font-mono" x-text="gauge.detail"></p>
                                <p x-show="gauge.sub" class="text-[11px] ui-text-subtle mt-0.5 font-mono" x-text="gauge.sub"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Step Dispatcher summary — full width --}}
            <div class="ui-card overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-semibold ui-text">Step Dispatcher</span>
                        <span class="flex items-center gap-2">
                            <span class="relative flex h-2 w-2">
                                <template x-if="stepDispatcher.running">
                                    <span>
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ui-bg-success"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 ui-bg-success"></span>
                                    </span>
                                </template>
                                <template x-if="!stepDispatcher.running">
                                    <span class="relative inline-flex rounded-full h-2 w-2 ui-bg-danger"></span>
                                </template>
                            </span>
                            <span
                                class="text-[10px] font-semibold uppercase tracking-wider"
                                :style="stepDispatcher.running ? 'color: rgb(var(--ui-success))' : 'color: rgb(var(--ui-danger))'"
                                x-text="stepDispatcher.running ? 'Running' : 'Stopped'"
                            ></span>
                        </span>
                    </div>
                    <a href="{{ route('system.step-dispatcher') }}" class="text-[11px] font-medium hover:underline" style="color: rgb(var(--ui-primary))">
                        View Details &rarr;
                    </a>
                </div>
                <div class="px-4 py-5">
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold ui-text ui-tabular">
                                <x-hub-ui::number value="stepDispatcher.total || 0" />
                            </div>
                            <div class="text-[10px] font-medium uppercase tracking-wider ui-text-subtle mt-1">Total</div>
                        </div>
                        <template x-for="key in ['Running', 'Pending', 'Failed', 'Completed']" :key="key">
                            <div class="text-center">
                                <div
                                    class="text-2xl font-bold ui-tabular"
                                    :style="'color: ' + stepStateColor(key)"
                                    x-text="(stepDispatcher.by_state || {})[key] || 0"
                                ></div>
                                <div class="text-[10px] font-medium uppercase tracking-wider ui-text-subtle mt-1" x-text="key"></div>
                            </div>
                        </template>
                    </div>
                    <div x-show="stepDispatcher.last_tick" class="mt-4 pt-3 border-t ui-border text-[11px] ui-text-subtle text-center">
                        Last tick <span class="font-mono ui-text-muted" x-text="stepDispatcher.last_tick"></span>
                    </div>
                </div>
            </div>

            {{-- Two-column: Supervisor + Schedule --}}
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                {{-- Supervisor --}}
                <div class="ui-card overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                        <div class="flex items-center gap-2">
                            <x-feathericon-server class="w-4 h-4 ui-text-muted" />
                            <span class="text-sm font-semibold ui-text">Supervisor</span>
                            <span x-show="supervisor.processes" class="text-[11px] ui-text-subtle" x-text="'· ' + (supervisor.processes || []).length + ' processes'"></span>
                        </div>
                        <span x-show="supervisor.available && runningCount > 0" class="text-[10px] font-semibold uppercase tracking-wider" style="color: rgb(var(--ui-success))">
                            <span x-text="runningCount"></span> / <span x-text="(supervisor.processes || []).length"></span> UP
                        </span>
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
                                                    <span
                                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider"
                                                        :class="supervisorBadgeClass(proc.state)"
                                                        x-text="proc.state"
                                                    ></span>
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
                                <p class="text-sm ui-text-subtle">No supervisor processes found</p>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Scheduled Commands --}}
                <div class="ui-card overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                        <div class="flex items-center gap-2">
                            <x-feathericon-clock class="w-4 h-4 ui-text-muted" />
                            <span class="text-sm font-semibold ui-text">Schedule</span>
                            <span x-show="schedule.tasks" class="text-[11px] ui-text-subtle" x-text="'· ' + (schedule.tasks || []).length + ' tasks'"></span>
                        </div>
                        <span x-show="nextTask" class="text-[10px] ui-text-subtle">
                            next in <span class="font-mono ui-text-muted" x-text="nextTask?._countdown || '—'"></span>
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
                                            <th>Countdown</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="task in schedule.tasks" :key="task.command">
                                            <tr>
                                                <td class="font-mono" :title="task.next_run" x-text="task.command"></td>
                                                <td class="font-mono ui-text-subtle" x-text="task.expression"></td>
                                                <td class="font-mono ui-tabular" :class="task === nextTask ? 'ui-text-success font-semibold' : 'ui-text-muted'" x-text="task._countdown || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <template x-if="!schedule.tasks || schedule.tasks.length === 0">
                            <div class="p-8 text-center">
                                <p class="text-sm ui-text-subtle">No scheduled tasks</p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Slow Queries --}}
            <div class="ui-card overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                    <div class="flex items-center gap-2">
                        <x-feathericon-zap class="w-4 h-4 ui-text-muted" />
                        <span class="text-sm font-semibold ui-text">Slow Queries</span>
                        <span class="text-[11px] ui-text-subtle">·
                            <span class="font-mono ui-tabular" x-text="slowQueries.last_hour_count"></span>
                            <span>in last hour</span>
                        </span>
                    </div>
                </div>
                <div>
                    <template x-if="slowQueries.recent && slowQueries.recent.length > 0">
                        <div class="overflow-x-auto">
                            <table class="w-full ui-table ui-data-table ui-data-table--sm">
                                <thead class="ui-bg-elevated">
                                    <tr>
                                        <th>Duration</th>
                                        <th>Query</th>
                                        <th>Connection</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="q in slowQueries.recent" :key="q.id">
                                        <tr>
                                            <td>
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider ui-tabular"
                                                    :class="slowQueryBadgeClass(q.time_ms)"
                                                    x-text="q.time_ms + 'ms'"
                                                ></span>
                                            </td>
                                            <td class="font-mono max-w-md truncate" :title="q.sql" x-text="q.sql"></td>
                                            <td class="font-mono ui-text-subtle" x-text="q.connection || '—'"></td>
                                            <td class="font-mono ui-text-subtle ui-tabular" x-text="q.created_at"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template x-if="!slowQueries.recent || slowQueries.recent.length === 0">
                        <div class="p-10 text-center">
                            <div class="inline-flex flex-col items-center gap-2">
                                <div class="w-12 h-12 rounded-full flex items-center justify-center ui-bg-elevated">
                                    <x-feathericon-check-circle class="w-6 h-6" style="color: rgb(var(--ui-success))" />
                                </div>
                                <p class="text-sm font-medium ui-text">All clear</p>
                                <p class="text-xs ui-text-subtle">No slow queries recorded.</p>
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

                // Server gauges — carry previous percent for trend delta
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
                            {
                                label: 'CPU',
                                percent: s.cpu_percent,
                                detail: 'Load average across 32 vCPUs',
                                sub: null,
                            },
                            {
                                label: 'RAM',
                                percent: Math.round(ramPercent * 10) / 10,
                                detail: this.formatMb(s.ram_used_mb) + ' / ' + this.formatMb(s.ram_total_mb),
                                sub: 'memory in use',
                            },
                            {
                                label: 'HDD',
                                percent: Math.round(hddPercent * 10) / 10,
                                detail: s.hdd_used_gb + ' GB / ' + s.hdd_total_gb + ' GB',
                                sub: 'root filesystem',
                            },
                        ];

                        // Compute delta only after first snapshot — first load has no baseline to compare
                        this.gauges = newGauges.map((g, i) => {
                            const prev = this._hasSnapshot && this.gauges[i] ? this.gauges[i].percent : g.percent;
                            return {
                                ...g,
                                delta: Math.round((g.percent - prev) * 10) / 10,
                            };
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

                supervisorBadgeClass(state) {
                    const map = {
                        'RUNNING': 'ui-badge-success',
                        'STOPPED': 'ui-badge-warning',
                        'FATAL': 'ui-badge-danger',
                        'STARTING': 'ui-badge-info',
                        'BACKOFF': 'ui-badge-warning',
                        'EXITED': 'ui-badge-danger',
                    };
                    return map[state] || 'ui-badge-default';
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

                slowQueryBadgeClass(ms) {
                    if (ms > 500) return 'ui-badge-danger';
                    if (ms >= 100) return 'ui-badge-warning';
                    return 'ui-badge-success';
                },

                updateCountdowns() {
                    if (!this.schedule.tasks) return;
                    const now = new Date();
                    this.schedule.tasks.forEach(task => {
                        if (!task.next_run_iso) {
                            task._countdown = '—';
                            return;
                        }
                        const next = new Date(task.next_run_iso);
                        const diff = Math.max(0, Math.floor((next - now) / 1000));
                        if (diff <= 0) {
                            task._countdown = 'now';
                        } else if (diff < 60) {
                            task._countdown = diff + 's';
                        } else if (diff < 3600) {
                            task._countdown = Math.floor(diff / 60) + 'm ' + (diff % 60) + 's';
                        } else {
                            task._countdown = Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm';
                        }
                    });
                },
            };
        }
    </script>
</x-app-layout>
