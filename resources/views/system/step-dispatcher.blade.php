<x-app-layout :activeSection="'system'" :activeHighlight="'step-dispatcher'" :flush="true">
    <div class="flex flex-col h-full" x-data="stepDispatcher()" x-init="fetchData(); startPolling()">
        <x-hub-ui::live-header
            title="Step Dispatcher"
            description="Class × state pivot for background step orchestration. Click any cell with activity to inspect its blocks."
            last-updated-model="lastUpdated"
        >
            {{-- Cooling-down toggle hidden for now. Routes + controller remain wired so it can be restored later. --}}
            {{-- <x-slot:actions>
                <x-hub-ui::switch
                    state="isCoolingDown"
                    @click="toggleCoolingDown()"
                    label="Cooling Down"
                    onColor="warning"
                    size="sm"
                    x-bind:class="togglingCoolingDown ? 'opacity-50 pointer-events-none' : ''"
                />
            </x-slot:actions> --}}
        </x-hub-ui::live-header>

        {{-- Content --}}
        <div class="flex-1 overflow-auto p-4 sm:p-6 space-y-4 sm:space-y-6">

            {{-- Performance — system throughput + per-API saturation in one row. --}}
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
                    <div class="flex items-baseline gap-2 flex-wrap">
                        <h2 class="text-sm font-semibold ui-text">Performance</h2>
                        <span class="text-[11px] ui-text-muted">throughput saturation vs 5-min peak · API saturation vs throttler cap</span>
                    </div>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">Live</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-4">
                    {{-- System throughput --}}
                    <div class="ui-bg-elevated rounded-lg p-3">
                        <div class="flex items-baseline justify-between mb-2">
                            <span class="text-xs font-semibold ui-text">System</span>
                            <span
                                class="text-[11px] font-mono ui-tabular"
                                :class="throughput.has_data ? '' : 'ui-text-subtle'"
                                x-text="throughput.has_data ? ((throughput.saturation ?? 0).toFixed(1) + '%') : 'no data'"
                                :style="throughput.has_data ? ('color: ' + gaugeColor(throughput.saturation || 0)) : ''"
                            ></span>
                        </div>

                        <x-hub-ui::progress-bar
                            value="throughput.saturation || 0"
                            ticks="10"
                            empty="!throughput.has_data"
                            tick-width="8"
                            tick-height="18"
                            tick-gap="2"
                            class="w-full"
                        />

                        <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                            <span x-text="throughput.has_data ? ((throughput.current_per_10s ?? 0) + ' / 10s') : '—'"></span>
                            <span x-text="throughput.has_data ? ('peak ' + (throughput.peak_per_10s ?? 0)) : ''"></span>
                        </div>
                    </div>

                    {{-- Per-API gauges --}}
                    <template x-for="gauge in apiGauges" :key="gauge.api">
                        <div class="ui-bg-elevated rounded-lg p-3">
                            <div class="flex items-baseline justify-between mb-2">
                                <span class="text-xs font-semibold ui-text" x-text="gauge.label"></span>
                                <span
                                    class="text-[11px] font-mono ui-tabular"
                                    :class="gauge.has_data ? '' : 'ui-text-subtle'"
                                    x-text="gauge.has_data ? (gauge.saturation.toFixed(1) + '%') : 'no data'"
                                    :style="gauge.has_data ? ('color: ' + gaugeColor(gauge.saturation, gauge.is_stale)) : ''"
                                ></span>
                            </div>

                            <x-hub-ui::progress-bar
                                value="gauge.saturation"
                                ticks="10"
                                stale="gauge.is_stale"
                                empty="!gauge.has_data"
                                tick-width="8"
                                tick-height="18"
                                tick-gap="2"
                                class="w-full"
                            />

                            <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                                <span x-text="gauge.has_data ? (gauge.observed_rps + ' / ' + gauge.cap_rps + ' rps') : '—'"></span>
                                <span x-text="gauge.sample_count + ' / ' + gauge.window_seconds + 's'"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="flex items-center gap-3 flex-wrap pt-2">
                <span class="text-[11px] ui-text-subtle font-mono ui-tabular" x-text="'· ' + visibleRows.length + ' / ' + rows.length + ' classes'"></span>
                <span class="flex-1"></span>
                <x-hub-ui::switch
                    x-model="onlyChildren"
                    onColor="success"
                    size="sm"
                    label="Only children"
                    labelPosition="right"
                />
            </div>

            <x-hub-ui::data-table>
                <x-slot:head>
                    <tr>
                        <th class="sticky left-0 ui-bg-elevated" style="min-width: 140px">
                            <div class="flex flex-col gap-1.5">
                                <span>Class</span>
                                <div class="relative">
                                    <x-feathericon-search class="absolute left-2 top-1/2 -translate-y-1/2 w-3 h-3 ui-text-subtle" />
                                    <input
                                        type="text"
                                        x-model="classSearch"
                                        placeholder="Filter classes…"
                                        class="w-full pl-7 pr-2 py-1 text-[11px] rounded border ui-input font-normal normal-case tracking-normal"
                                        @click.stop
                                    />
                                </div>
                            </div>
                        </th>
                        <template x-for="state in states" :key="state.name">
                            <th
                                class="text-center"
                                :class="state.mobile ? '' : 'hidden sm:table-cell'"
                                style="min-width: 90px"
                            >
                                <div class="flex flex-col items-center gap-1">
                                    <span :style="'color: ' + state.color" x-text="state.label"></span>
                                    <span
                                        class="text-sm font-mono"
                                        x-text="totals[state.name] || 0"
                                        :class="(totals[state.name] || 0) === 0 ? 'ui-text-subtle' : ''"
                                        :style="(totals[state.name] || 0) > 0 ? 'color: ' + state.color + '; font-weight: 700' : ''"
                                    ></span>
                                    <span
                                        class="text-[10px] font-mono normal-case tracking-normal ui-text-subtle"
                                        :title="'Leaf steps (child_block_uuid IS NULL) in ' + state.label"
                                        x-text="leafTotals[state.name] || 0"
                                    ></span>
                                </div>
                            </th>
                        </template>
                        <th class="text-center" style="min-width: 80px">
                            <div class="flex flex-col items-center gap-1">
                                <span>Total</span>
                                <span class="text-sm font-mono font-bold ui-text" x-text="totalSteps"></span>
                                <span class="text-[10px] font-mono normal-case tracking-normal ui-text-subtle" x-text="totalLeafSteps"></span>
                            </div>
                        </th>
                        <th class="text-center hidden md:table-cell" style="min-width: 80px" title="Highest retries value across this class — high numbers = recover-stale ping-pong">
                            <div class="flex flex-col items-center gap-1">
                                <span>Max Retry</span>
                                <span class="text-[10px] ui-text-subtle font-normal normal-case tracking-normal">ping-pong</span>
                            </div>
                        </th>
                        <th class="text-center hidden md:table-cell" style="min-width: 100px" title="Oldest Running step age — high numbers = zombies">
                            <div class="flex flex-col items-center gap-1">
                                <span>Oldest Run</span>
                                <span class="text-[10px] ui-text-subtle font-normal normal-case tracking-normal">zombies</span>
                            </div>
                        </th>
                    </tr>
                </x-slot:head>

                <template x-for="row in visibleRows" :key="row.class">
                    <tr>
                        <td class="font-mono sticky left-0" style="background-color: inherit" :title="row.class">
                            <span class="inline-flex items-center gap-1.5">
                                <span x-text="row.short_name"></span>
                                <span
                                    class="text-[10px] font-normal normal-case tracking-normal"
                                    :class="row.is_parent ? 'ui-text-warning' : 'ui-text-success'"
                                    :title="row.is_parent ? 'Parent — spawns child blocks' : 'Child (leaf) — does real work'"
                                    x-text="row.is_parent ? '(parent)' : '(children)'"
                                ></span>
                            </span>
                        </td>
                        <template x-for="state in states" :key="row.class + '-' + state.name">
                            <td
                                class="text-center font-mono"
                                :class="((row.states[state.name] || 0) > 0 ? 'cursor-pointer ' : '') + (state.mobile ? '' : 'hidden sm:table-cell')"
                                @click="(row.states[state.name] || 0) > 0 && fetchBlocks(row.class, state.name)"
                            >
                                <span
                                    x-text="row.states[state.name] || 0"
                                    :class="(row.states[state.name] || 0) === 0 ? 'ui-text-subtle' : ''"
                                    :style="(row.states[state.name] || 0) > 0 ? 'color: ' + state.color + '; font-weight: 600' : ''"
                                ></span>
                            </td>
                        </template>
                        <td class="text-center font-mono font-semibold ui-text">
                            <span x-text="rowTotal(row)"></span>
                        </td>
                        <td class="text-center font-mono ui-tabular hidden md:table-cell">
                            <span
                                x-text="row.max_retries || 0"
                                :class="retryClass(row.max_retries)"
                            ></span>
                        </td>
                        <td class="text-center font-mono ui-tabular hidden md:table-cell">
                            <span
                                x-text="formatAge(row.oldest_running_sec)"
                                :class="oldestRunningClass(row.oldest_running_sec)"
                            ></span>
                        </td>
                    </tr>
                </template>
            </x-hub-ui::data-table>

            {{-- Block Details --}}
            <div x-show="selectedClass" class="mt-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold ui-text">Blocks</h2>
                        <span class="text-xs ui-text-muted font-mono" x-text="selectedShortName"></span>
                        <span
                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                            :class="'ui-badge-' + selectedBadgeType"
                            x-text="selectedState"
                        ></span>
                        <span class="text-xs ui-text-subtle" x-text="blocks.length + ' block(s)'"></span>
                    </div>
                    <button @click="clearBlocks()" class="text-xs ui-text-subtle hover:ui-text transition-colors">Close</button>
                </div>

                {{-- Loading --}}
                <div x-show="loadingBlocks" class="flex items-center gap-2 py-4">
                    <x-hub-ui::spinner size="sm" />
                    <span class="text-xs ui-text-subtle">Loading blocks...</span>
                </div>

                {{-- Block UUID List --}}
                <x-hub-ui::data-table x-show="blocks.length > 0" size="sm">
                    <x-slot:head>
                        <tr>
                            <th style="min-width: 30px"></th>
                            <th style="min-width: 300px">Block UUID</th>
                            <th style="min-width: 80px">Steps</th>
                            <th style="min-width: 140px">Latest</th>
                        </tr>
                    </x-slot:head>

                    <template x-for="block in blocks" :key="block.block_uuid">
                        <tr
                            class="cursor-pointer"
                            @click="toggleBlock(block.block_uuid)"
                        >
                            <td>
                                <svg
                                    class="w-3.5 h-3.5 ui-text-subtle transition-transform"
                                    :class="expandedBlock === block.block_uuid ? 'rotate-90' : ''"
                                    fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </td>
                            <td class="font-mono" x-text="block.block_uuid"></td>
                            <td class="font-mono" x-text="block.step_count"></td>
                            <td class="font-mono ui-text-subtle" x-text="block.latest"></td>
                        </tr>
                    </template>
                </x-hub-ui::data-table>

                {{-- Expanded Block Steps --}}
                <div x-show="expandedBlock && blockSteps.length > 0" class="space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Steps in block</span>
                        <code class="text-[11px] font-mono ui-text-muted" x-text="expandedBlock"></code>
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background-color: rgb(var(--ui-success))"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2" style="background-color: rgb(var(--ui-success))"></span>
                        </span>
                    </div>

                    <div class="overflow-x-auto rounded-lg border ui-border mb-4">
                        <table class="w-full ui-table ui-data-table ui-data-table--sm" style="min-width: 100%">
                            <thead class="ui-bg-elevated">
                                <tr>
                                    <template x-for="col in stepColumns" :key="col.key">
                                        <th
                                            class="relative select-none"
                                            :class="col.mobile ? '' : 'hidden md:table-cell'"
                                            :style="stepColWidths[col.key] ? 'min-width:' + stepColWidths[col.key] + 'px; max-width:' + stepColWidths[col.key] + 'px' : 'min-width:' + col.minW + 'px'"
                                        >
                                            <span x-text="col.label"></span>
                                            <div
                                                @mousedown.prevent.stop="startStepResize($event, col.key)"
                                                class="absolute top-0 right-0 w-2 h-full cursor-col-resize opacity-0 hover:opacity-100 transition-opacity"
                                                style="background-color: rgb(var(--ui-primary) / 0.3)"
                                            ></div>
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="step in blockSteps" :key="step.id">
                                    <tr>
                                        <td class="font-mono ui-text-subtle" x-text="step.id"></td>
                                        <td class="font-mono" x-text="step.index ?? '-'"></td>
                                        <td class="font-mono" :title="step.class">
                                            <span x-text="step.short_name"></span>
                                        </td>
                                        <td>
                                            <span class="font-medium" :style="'color: ' + stateColor(step.state)" x-text="step.state"></span>
                                        </td>
                                        <td class="font-mono ui-text-subtle hidden md:table-cell" x-text="step.child_block_uuid || '-'"></td>
                                        <td class="font-mono hidden md:table-cell" x-text="step.retries || 0"></td>
                                        <td class="font-mono hidden md:table-cell" x-text="step.duration ? step.duration + 'ms' : '-'"></td>
                                        <td class="font-mono ui-text-subtle hidden md:table-cell" x-text="step.started_at || '-'"></td>
                                        <td class="font-mono ui-text-subtle hidden md:table-cell" x-text="step.completed_at || '-'"></td>
                                        <td class="whitespace-normal break-words" x-text="step.error_message || '-'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Loading steps --}}
                <div x-show="loadingSteps" class="flex items-center gap-2 py-2">
                    <x-hub-ui::spinner size="sm" />
                    <span class="text-xs ui-text-subtle">Loading steps...</span>
                </div>
            </div>

        </div>
    </div>

    <script>
        function stepDispatcher() {
            return {
                rows: [],
                totals: {},
                leafTotals: {},
                throughput: { current_per_10s: 0, peak_per_10s: 0, saturation: 0, has_data: false },
                apiGauges: [
                    { api: 'taapi',         label: 'TAAPI',         has_data: false, is_stale: false, saturation: 0, observed_rps: null, cap_rps: null, sample_count: 0, window_seconds: 0 },
                    { api: 'coinmarketcap', label: 'CoinMarketCap', has_data: false, is_stale: false, saturation: 0, observed_rps: null, cap_rps: null, sample_count: 0, window_seconds: 0 },
                    { api: 'binance',       label: 'Binance',       has_data: false, is_stale: false, saturation: 0, observed_rps: null, cap_rps: null, sample_count: 0, window_seconds: 0 },
                    { api: 'bybit',         label: 'Bybit',         has_data: false, is_stale: false, saturation: 0, observed_rps: null, cap_rps: null, sample_count: 0, window_seconds: 0 },
                    { api: 'kucoin',        label: 'KuCoin',        has_data: false, is_stale: false, saturation: 0, observed_rps: null, cap_rps: null, sample_count: 0, window_seconds: 0 },
                    { api: 'bitget',        label: 'Bitget',        has_data: false, is_stale: false, saturation: 0, observed_rps: null, cap_rps: null, sample_count: 0, window_seconds: 0 },
                ],
                loading: true,
                lastUpdated: null,
                _interval: null,

                classSearch: '',
                onlyChildren: false,

                isCoolingDown: false,
                togglingCoolingDown: false,

                selectedClass: null,
                selectedShortName: null,
                selectedState: null,
                blocks: [],
                loadingBlocks: false,
                expandedBlock: null,
                blockSteps: [],
                loadingSteps: false,
                _stepsInterval: null,
                stepColWidths: {},

                stepColumns: [
                    { key: 'id',               label: '#',           minW: 40,  mobile: true  },
                    { key: 'index',            label: 'Idx',         minW: 40,  mobile: true  },
                    { key: 'class',            label: 'Class',       minW: 140, mobile: true  },
                    { key: 'state',            label: 'State',       minW: 70,  mobile: true  },
                    { key: 'child_block_uuid', label: 'Child Block', minW: 100, mobile: false },
                    { key: 'retries',          label: 'Ret',         minW: 35,  mobile: false },
                    { key: 'duration',         label: 'Duration',    minW: 60,  mobile: false },
                    { key: 'started_at',       label: 'Started',     minW: 120, mobile: false },
                    { key: 'completed_at',     label: 'Completed',   minW: 120, mobile: false },
                    { key: 'error',            label: 'Error',       minW: 150, mobile: true  },
                ],

                states: [
                    { name: 'NotRunnable', label: 'Not Runnable', color: 'rgb(var(--ui-text-subtle))', mobile: false },
                    { name: 'Pending',     label: 'Pending',      color: 'rgb(var(--ui-info))',        mobile: true  },
                    { name: 'Throttled',   label: 'Throttled',    color: '#8b5cf6',                    mobile: false },
                    { name: 'Dispatched',  label: 'Dispatched',   color: '#6366f1',                    mobile: false },
                    { name: 'Running',     label: 'Running',      color: 'rgb(var(--ui-warning))',     mobile: true  },
                    { name: 'Completed',   label: 'Completed',    color: 'rgb(var(--ui-success))',     mobile: true  },
                    { name: 'Skipped',     label: 'Skipped',      color: 'rgb(var(--ui-text-muted))',  mobile: false },
                    { name: 'Cancelled',   label: 'Cancelled',    color: '#f97316',                    mobile: false },
                    { name: 'Failed',      label: 'Failed',       color: 'rgb(var(--ui-danger))',      mobile: true  },
                    { name: 'Stopped',     label: 'Stopped',      color: 'rgb(var(--ui-danger))',      mobile: false },
                ],

                get visibleRows() {
                    const q = (this.classSearch || '').trim().toLowerCase();
                    return this.rows.filter(r => {
                        if (this.onlyChildren && r.is_parent) return false;
                        if (!q) return true;
                        return (r.short_name || '').toLowerCase().includes(q)
                            || (r.class || '').toLowerCase().includes(q);
                    });
                },

                get totalSteps() {
                    return Object.values(this.totals).reduce((sum, v) => sum + v, 0);
                },

                get totalLeafSteps() {
                    return Object.values(this.leafTotals).reduce((sum, v) => sum + v, 0);
                },

                rowTotal(row) {
                    return Object.values(row.states).reduce((sum, v) => sum + v, 0);
                },

                retryClass(n) {
                    n = Number(n) || 0;
                    if (n >= 10) return 'ui-text-danger font-bold';
                    if (n >= 5)  return 'ui-text-warning font-semibold';
                    if (n > 0)   return 'ui-text-muted';
                    return 'ui-text-subtle';
                },

                oldestRunningClass(secs) {
                    if (secs === null || secs === undefined) return 'ui-text-subtle';
                    if (secs >= 600) return 'ui-text-danger font-bold';
                    if (secs >= 120) return 'ui-text-warning font-semibold';
                    return 'ui-text-muted';
                },

                formatAge(secs) {
                    if (secs === null || secs === undefined) return '—';
                    if (secs < 60)    return secs + 's';
                    if (secs < 3600)  return Math.floor(secs / 60) + 'm';
                    if (secs < 86400) return Math.floor(secs / 3600) + 'h';
                    return Math.floor(secs / 86400) + 'd';
                },

                // Saturation → Kraite theme color. Inverted vs a typical load
                // meter: riding the throttler cap is the *goal*, so high
                // saturation is green and anemic utilization is red.
                gaugeColor(saturation, stale = false) {
                    if (stale) return '#374151';
                    if (!saturation || saturation <= 0) return 'rgb(var(--ui-text-subtle))';
                    if (saturation >= 70) return 'rgb(var(--ui-success))';
                    if (saturation >= 30) return 'rgb(var(--ui-warning))';
                    return 'rgb(var(--ui-danger))';
                },

                async fetchData() {
                    const [dataRes, coolingRes] = await Promise.all([
                        hubUiFetch('{{ route("system.step-dispatcher.data") }}', { method: 'GET' }),
                        hubUiFetch('{{ route("system.step-dispatcher.cooling-down") }}', { method: 'GET' }),
                    ]);

                    if (dataRes.ok) {
                        this.rows = dataRes.data.rows;
                        this.totals = dataRes.data.totals;
                        this.leafTotals = dataRes.data.leaf_totals || {};
                        const t = dataRes.data.throughput || { current_per_10s: 0, peak_per_10s: 0, saturation: 0 };
                        this.throughput = { ...t, has_data: (t.peak_per_10s ?? 0) > 0 };
                        this.apiGauges = dataRes.data.api_gauges || this.apiGauges;
                        this.lastUpdated = new Date().toLocaleTimeString();
                    }

                    if (coolingRes.ok) {
                        this.isCoolingDown = coolingRes.data.is_cooling_down;
                    }

                    this.loading = false;
                },

                async toggleCoolingDown() {
                    if (this.togglingCoolingDown) return;
                    this.togglingCoolingDown = true;

                    // Optimistic update
                    const previousState = this.isCoolingDown;
                    this.isCoolingDown = !this.isCoolingDown;

                    const { ok, data } = await hubUiFetch('{{ route("system.step-dispatcher.toggle-cooling-down") }}');
                    if (ok) {
                        this.isCoolingDown = data.is_cooling_down;
                    } else {
                        // Revert on failure
                        this.isCoolingDown = previousState;
                    }
                    this.togglingCoolingDown = false;
                },

                startPolling() {
                    // 10s feels live enough for an observability dashboard and
                    // aligns with the 3s server-side cache window (fewer than
                    // 4 fetches per cache lifetime). Halves the DB load vs 5s.
                    this._interval = setInterval(() => this.fetchData(), 10000);
                },

                stateColor(state) {
                    const s = this.states.find(s => s.name === state);
                    return s ? s.color : 'rgb(var(--ui-text-subtle))';
                },

                get selectedBadgeType() {
                    const map = { Failed: 'danger', Stopped: 'danger', Cancelled: 'warning', Running: 'warning', Completed: 'success', Skipped: 'secondary', Pending: 'info', Throttled: 'warning', Dispatched: 'info', NotRunnable: 'default' };
                    return map[this.selectedState] || 'default';
                },

                async fetchBlocks(cls, state) {
                    this.selectedClass = cls;
                    this.selectedShortName = cls.split('\\').pop();
                    this.selectedState = state;
                    this.blocks = [];
                    this.expandedBlock = null;
                    this.blockSteps = [];
                    this.loadingBlocks = true;

                    const { ok, data } = await hubUiFetch(
                        '{{ route("system.step-dispatcher.blocks") }}?class=' + encodeURIComponent(cls) + '&state=' + encodeURIComponent(state),
                        { method: 'GET' }
                    );

                    if (ok) {
                        this.blocks = data.blocks;
                    }

                    this.loadingBlocks = false;
                },

                async toggleBlock(uuid) {
                    if (this.expandedBlock === uuid) {
                        this.expandedBlock = null;
                        this.blockSteps = [];
                        this.stopStepsPolling();
                        return;
                    }

                    this.expandedBlock = uuid;
                    this.blockSteps = [];
                    this.loadingSteps = true;
                    await this.refreshBlockSteps(uuid);
                    this.loadingSteps = false;
                    this.startStepsPolling(uuid);
                },

                async refreshBlockSteps(uuid) {
                    const { ok, data } = await hubUiFetch(
                        '{{ route("system.step-dispatcher.block-steps") }}?block_uuid=' + encodeURIComponent(uuid),
                        { method: 'GET' }
                    );
                    if (ok && this.expandedBlock === uuid) {
                        this.blockSteps = data.steps;
                    }
                },

                startStepsPolling(uuid) {
                    this.stopStepsPolling();
                    this._stepsInterval = setInterval(() => this.refreshBlockSteps(uuid), 5000);
                },

                stopStepsPolling() {
                    if (this._stepsInterval) {
                        clearInterval(this._stepsInterval);
                        this._stepsInterval = null;
                    }
                },

                startStepResize(e, key) {
                    const th = e.target.closest('th');
                    const startX = e.clientX;
                    const startW = th.offsetWidth;
                    const onMove = (ev) => {
                        this.stepColWidths[key] = Math.max(30, startW + ev.clientX - startX);
                    };
                    const onUp = () => {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    };
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                },

                clearBlocks() {
                    this.selectedClass = null;
                    this.selectedState = null;
                    this.blocks = [];
                    this.expandedBlock = null;
                    this.blockSteps = [];
                    this.stopStepsPolling();
                },

                destroy() {
                    if (this._interval) clearInterval(this._interval);
                    this.stopStepsPolling();
                },
            };
        }
    </script>
</x-app-layout>
