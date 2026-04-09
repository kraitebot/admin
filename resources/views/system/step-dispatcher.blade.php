<x-app-layout :activeSection="'system'" :activeHighlight="'step-dispatcher'" :flush="true">
    <div class="flex flex-col h-full" x-data="stepDispatcher()" x-init="fetchData(); startPolling()">
        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b ui-border" style="background-color: rgb(var(--ui-bg-body))">
            <div class="flex items-center gap-3">
                <h1 class="text-base font-semibold ui-text">Step Dispatcher</h1>
                <span class="text-xs ui-text-subtle" x-text="totalSteps + ' steps'"></span>
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
        <div class="flex-1 overflow-auto p-6">
            <x-hub-ui::data-table>
                <x-slot:head>
                    <tr>
                        <th class="sticky left-0 ui-bg-elevated" style="min-width: 200px">Class</th>
                        <template x-for="state in states" :key="state.name">
                            <th class="text-center" style="min-width: 90px">
                                <div class="flex flex-col items-center gap-1">
                                    <span :style="'color: ' + state.color" x-text="state.label"></span>
                                    <span
                                        class="text-sm font-mono"
                                        x-text="totals[state.name] || 0"
                                        :class="(totals[state.name] || 0) === 0 ? 'ui-text-subtle' : ''"
                                        :style="(totals[state.name] || 0) > 0 ? 'color: ' + state.color + '; font-weight: 700' : ''"
                                    ></span>
                                </div>
                            </th>
                        </template>
                        <th class="text-center" style="min-width: 80px">
                            <div class="flex flex-col items-center gap-1">
                                <span>Total</span>
                                <span class="text-sm font-mono font-bold ui-text" x-text="totalSteps"></span>
                            </div>
                        </th>
                    </tr>
                </x-slot:head>

                <template x-for="row in rows" :key="row.class">
                    <tr>
                        <td class="font-mono sticky left-0" style="background-color: inherit" :title="row.class">
                            <span x-text="row.short_name"></span>
                        </td>
                        <template x-for="state in states" :key="row.class + '-' + state.name">
                            <td
                                class="text-center font-mono"
                                :class="(row.states[state.name] || 0) > 0 ? 'cursor-pointer' : ''"
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
                    </tr>
                </template>
            </x-hub-ui::data-table>

            {{-- Block Details --}}
            <div x-show="selectedClass" class="mt-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold ui-text">Blocks</h2>
                        <span class="text-xs ui-text-muted font-mono" x-text="selectedShortName"></span>
                        <x-hub-ui::badge x-text="selectedState" ::type="selectedBadgeType" size="sm" />
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
                                        <td class="font-mono ui-text-subtle" x-text="step.child_block_uuid || '-'"></td>
                                        <td class="font-mono" x-text="step.retries || 0"></td>
                                        <td class="font-mono" x-text="step.duration ? step.duration + 'ms' : '-'"></td>
                                        <td class="font-mono ui-text-subtle" x-text="step.started_at || '-'"></td>
                                        <td class="font-mono ui-text-subtle" x-text="step.completed_at || '-'"></td>
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
                loading: true,
                lastUpdated: null,
                _interval: null,

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
                    { key: 'id', label: '#', minW: 40 },
                    { key: 'index', label: 'Idx', minW: 40 },
                    { key: 'class', label: 'Class', minW: 140 },
                    { key: 'state', label: 'State', minW: 70 },
                    { key: 'child_block_uuid', label: 'Child Block', minW: 100 },
                    { key: 'retries', label: 'Ret', minW: 35 },
                    { key: 'duration', label: 'Duration', minW: 60 },
                    { key: 'started_at', label: 'Started', minW: 120 },
                    { key: 'completed_at', label: 'Completed', minW: 120 },
                    { key: 'error', label: 'Error', minW: 150 },
                ],

                states: [
                    { name: 'NotRunnable', label: 'Not Runnable', color: '#9ca3af' },
                    { name: 'Pending',     label: 'Pending',      color: '#3b82f6' },
                    { name: 'Dispatched',  label: 'Dispatched',   color: '#6366f1' },
                    { name: 'Running',     label: 'Running',      color: '#f59e0b' },
                    { name: 'Completed',   label: 'Completed',    color: '#22c55e' },
                    { name: 'Skipped',     label: 'Skipped',      color: '#94a3b8' },
                    { name: 'Cancelled',   label: 'Cancelled',    color: '#f97316' },
                    { name: 'Failed',      label: 'Failed',       color: '#ef4444' },
                    { name: 'Stopped',     label: 'Stopped',      color: '#dc2626' },
                ],

                get totalSteps() {
                    return Object.values(this.totals).reduce((sum, v) => sum + v, 0);
                },

                rowTotal(row) {
                    return Object.values(row.states).reduce((sum, v) => sum + v, 0);
                },

                async fetchData() {
                    const { ok, data } = await hubUiFetch('{{ route("system.step-dispatcher.data") }}', { method: 'GET' });

                    if (ok) {
                        this.rows = data.rows;
                        this.totals = data.totals;
                        this.lastUpdated = new Date().toLocaleTimeString();
                    }

                    this.loading = false;
                },

                startPolling() {
                    this._interval = setInterval(() => this.fetchData(), 5000);
                },

                stateColor(state) {
                    const s = this.states.find(s => s.name === state);
                    return s ? s.color : '#9ca3af';
                },

                get selectedBadgeType() {
                    const map = { Failed: 'danger', Stopped: 'danger', Cancelled: 'warning', Running: 'warning', Completed: 'success', Skipped: 'secondary', Pending: 'info', Dispatched: 'info', NotRunnable: 'default' };
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
