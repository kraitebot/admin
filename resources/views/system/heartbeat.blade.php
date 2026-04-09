<x-app-layout :activeSection="'system'" :activeHighlight="'heartbeat'" :flush="true">
    <div class="flex flex-col h-full" x-data="heartbeat()" x-init="fetchData(); startPolling()">
        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b ui-border" style="background-color: rgb(var(--ui-bg-body))">
            <div class="flex items-center gap-3">
                <h1 class="text-base font-semibold ui-text">Heartbeat</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background-color: rgb(var(--ui-success))"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2" style="background-color: rgb(var(--ui-success))"></span>
                    </span>
                    <span class="text-xs ui-text-subtle">Auto-refresh 5s</span>
                </div>
                <span x-show="lastUpdated" class="text-xs ui-text-subtle" x-text="'Updated ' + lastUpdated"></span>
            </div>
        </div>

        {{-- Content --}}
        <div class="flex-1 overflow-auto p-6 space-y-6">

            {{-- Section 1: Server Gauges --}}
            <div class="flex flex-wrap gap-6 justify-center">
                <template x-for="gauge in gauges" :key="gauge.label">
                    <div class="flex flex-col items-center gap-2">
                        <div class="relative" style="width: 140px; height: 140px;">
                            <svg viewBox="0 0 120 120" class="w-full h-full">
                                {{-- Background arc --}}
                                <circle
                                    cx="60" cy="60" r="50"
                                    fill="none"
                                    stroke-width="10"
                                    stroke-linecap="round"
                                    style="stroke: rgb(var(--ui-border))"
                                    stroke-dasharray="251.3"
                                    stroke-dashoffset="0"
                                    transform="rotate(-90 60 60)"
                                />
                                {{-- Foreground arc --}}
                                <circle
                                    cx="60" cy="60" r="50"
                                    fill="none"
                                    stroke-width="10"
                                    stroke-linecap="round"
                                    :style="'stroke: ' + gaugeColor(gauge.percent) + '; stroke-dasharray: 251.3; stroke-dashoffset: ' + (251.3 - (251.3 * gauge.percent / 100)) + '; transition: stroke-dashoffset 0.5s ease, stroke 0.5s ease;'"
                                    transform="rotate(-90 60 60)"
                                />
                            </svg>
                            {{-- Center text --}}
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-bold ui-text" x-text="Math.round(gauge.percent) + '%'"></span>
                            </div>
                        </div>
                        <span class="text-sm font-semibold ui-text" x-text="gauge.label"></span>
                        <span class="text-xs ui-text-subtle" x-text="gauge.detail"></span>
                    </div>
                </template>
            </div>

            {{-- Section 2: Supervisor --}}
            <div class="rounded-lg border ui-border overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold ui-text">Supervisor</span>
                        <span x-show="supervisor.processes" class="text-xs ui-text-subtle" x-text="(supervisor.processes || []).length + ' processes'"></span>
                    </div>
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
                                        <th style="min-width: 200px">Name</th>
                                        <th style="min-width: 100px">State</th>
                                        <th style="min-width: 80px">PID</th>
                                        <th style="min-width: 140px">Uptime</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="proc in supervisor.processes" :key="proc.name">
                                        <tr>
                                            <td class="font-mono" x-text="proc.name"></td>
                                            <td>
                                                <span
                                                    class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium"
                                                    :class="supervisorBadgeClass(proc.state)"
                                                    x-text="proc.state"
                                                ></span>
                                            </td>
                                            <td class="font-mono ui-text-subtle" x-text="proc.pid || '-'"></td>
                                            <td class="font-mono ui-text-subtle" x-text="proc.uptime || '-'"></td>
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

            {{-- Section 3: Scheduled Commands --}}
            <div class="rounded-lg border ui-border overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                    <span class="text-sm font-semibold ui-text">Scheduled Commands</span>
                </div>
                <div>
                    <template x-if="schedule.tasks && schedule.tasks.length > 0">
                        <div class="overflow-x-auto">
                            <table class="w-full ui-table ui-data-table ui-data-table--sm">
                                <thead class="ui-bg-elevated">
                                    <tr>
                                        <th style="min-width: 250px">Command</th>
                                        <th style="min-width: 120px">Expression</th>
                                        <th style="min-width: 180px">Next Run</th>
                                        <th style="min-width: 100px">Countdown</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="task in schedule.tasks" :key="task.command">
                                        <tr>
                                            <td class="font-mono" x-text="task.command"></td>
                                            <td class="font-mono ui-text-subtle" x-text="task.expression"></td>
                                            <td class="font-mono ui-text-subtle" x-text="task.next_run"></td>
                                            <td class="font-mono" x-text="task._countdown || '-'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template x-if="!schedule.tasks || schedule.tasks.length === 0">
                        <div class="p-6 text-center">
                            <p class="text-sm ui-text-subtle">No scheduled tasks</p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Section 4: Step Dispatcher Summary --}}
            <div class="rounded-lg border ui-border overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                    <span class="text-sm font-semibold ui-text">Step Dispatcher</span>
                </div>
                <div class="p-4">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <span class="relative flex h-2.5 w-2.5">
                                <template x-if="stepDispatcher.running">
                                    <span>
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background-color: rgb(var(--ui-success))"></span>
                                        <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background-color: rgb(var(--ui-success))"></span>
                                    </span>
                                </template>
                                <template x-if="!stepDispatcher.running">
                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background-color: rgb(var(--ui-danger))"></span>
                                </template>
                            </span>
                            <span class="text-sm font-medium" :style="stepDispatcher.running ? 'color: rgb(var(--ui-success))' : 'color: rgb(var(--ui-danger))'" x-text="stepDispatcher.running ? 'Running' : 'Stopped'"></span>
                        </div>
                        <a href="{{ route('system.step-dispatcher') }}" class="text-xs font-medium hover:underline" style="color: rgb(var(--ui-primary))">
                            View Details &rarr;
                        </a>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold ui-text" x-text="stepDispatcher.total || 0"></div>
                            <div class="text-xs ui-text-subtle">Total</div>
                        </div>
                        <template x-for="key in ['Running', 'Pending', 'Failed', 'Completed']" :key="key">
                            <div class="text-center">
                                <div class="text-2xl font-bold" :style="'color: ' + stepStateColor(key)" x-text="(stepDispatcher.by_state || {})[key] || 0"></div>
                                <div class="text-xs ui-text-subtle" x-text="key"></div>
                            </div>
                        </template>
                    </div>
                    <div x-show="stepDispatcher.last_tick" class="mt-3 text-xs ui-text-subtle text-center">
                        Last tick: <span class="font-mono" x-text="stepDispatcher.last_tick"></span>
                    </div>
                </div>
            </div>

            {{-- Section 5: Slow Queries --}}
            <div class="rounded-lg border ui-border overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 ui-bg-elevated border-b ui-border">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold ui-text">Slow Queries</span>
                        <span class="text-xs ui-text-subtle" x-text="slowQueries.last_hour_count + ' in last hour'"></span>
                    </div>
                </div>
                <div>
                    <template x-if="slowQueries.recent && slowQueries.recent.length > 0">
                        <div class="overflow-x-auto">
                            <table class="w-full ui-table ui-data-table ui-data-table--sm">
                                <thead class="ui-bg-elevated">
                                    <tr>
                                        <th style="min-width: 90px">Duration</th>
                                        <th style="min-width: 300px">Query</th>
                                        <th style="min-width: 100px">Connection</th>
                                        <th style="min-width: 160px">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="q in slowQueries.recent" :key="q.id">
                                        <tr>
                                            <td>
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                                    :class="slowQueryBadgeClass(q.time_ms)"
                                                    x-text="q.time_ms + 'ms'"
                                                ></span>
                                            </td>
                                            <td class="font-mono max-w-md truncate" :title="q.sql" x-text="q.sql"></td>
                                            <td class="font-mono ui-text-subtle" x-text="q.connection || '-'"></td>
                                            <td class="font-mono ui-text-subtle" x-text="q.created_at"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                    <template x-if="!slowQueries.recent || slowQueries.recent.length === 0">
                        <div class="p-6 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <svg class="w-8 h-8 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                                <p class="text-sm ui-text-subtle">No slow queries recorded</p>
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

                // Server gauges
                gauges: [
                    { label: 'CPU', percent: 0, detail: '-' },
                    { label: 'RAM', percent: 0, detail: '-' },
                    { label: 'HDD', percent: 0, detail: '-' },
                ],

                // Supervisor
                supervisor: { available: false, processes: [] },

                // Schedule
                schedule: { tasks: [] },

                // Step Dispatcher
                stepDispatcher: { running: false, total: 0, by_state: {}, last_tick: null },

                // Slow Queries
                slowQueries: { last_hour_count: 0, recent: [] },

                async fetchData() {
                    const { ok, data } = await hubUiFetch('{{ route("system.heartbeat.data") }}', { method: 'GET' });

                    if (ok) {
                        // Server gauges
                        const s = data.server;
                        const ramPercent = s.ram_total_mb > 0 ? (s.ram_used_mb / s.ram_total_mb * 100) : 0;
                        const hddPercent = s.hdd_total_gb > 0 ? (s.hdd_used_gb / s.hdd_total_gb * 100) : 0;

                        this.gauges = [
                            { label: 'CPU', percent: s.cpu_percent, detail: 'Load avg / 32 vCPUs' },
                            { label: 'RAM', percent: Math.round(ramPercent * 10) / 10, detail: this.formatMb(s.ram_used_mb) + ' / ' + this.formatMb(s.ram_total_mb) },
                            { label: 'HDD', percent: Math.round(hddPercent * 10) / 10, detail: s.hdd_used_gb + ' GB / ' + s.hdd_total_gb + ' GB' },
                        ];

                        // Supervisor
                        this.supervisor = data.supervisor;

                        // Schedule
                        this.schedule = data.schedule;
                        this.updateCountdowns();

                        // Step Dispatcher
                        this.stepDispatcher = data.step_dispatcher;

                        // Slow Queries
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
                            task._countdown = '-';
                            return;
                        }
                        const next = new Date(task.next_run_iso);
                        const diff = Math.max(0, Math.floor((next - now) / 1000));
                        if (diff <= 0) {
                            task._countdown = 'now';
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
