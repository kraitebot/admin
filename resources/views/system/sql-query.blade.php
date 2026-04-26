<x-app-layout :activeSection="'system'" :activeHighlight="'sql-query'" :flush="true">
    <div class="flex flex-col h-full" x-data="sqlQuery()">
        <x-hub-ui::live-header
            title="SQL Query"
            description="Inspect the database directly. Browse tables in the sidebar, write queries, edit cells inline."
            :live="false"
        />
        <div class="flex flex-1 overflow-hidden">
        <x-hub-ui::secondary-sidebar>
            {{-- Search --}}
            <div class="p-3 border-b ui-border">
                <div class="relative">
                    <x-feathericon-search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 ui-text-subtle" />
                    <input
                        type="text"
                        x-model="tableSearch"
                        placeholder="Filter tables..."
                        class="w-full pl-9 pr-3 py-2 text-xs rounded-lg border ui-input"
                        x-init="$nextTick(() => $el.focus())"
                        @keydown.enter.prevent="if (filteredTables.length === 1) queryTable(filteredTables[0].name)"
                    />
                </div>
            </div>

            {{-- Table List --}}
            <div class="flex-1 overflow-y-auto py-2 ui-scrollbar">
                <div class="px-3 pb-1 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Tables</span>
                        <span class="text-[10px] ui-text-subtle ml-1" x-text="'(' + filteredTables.length + ')'"></span>
                    </div>
                    <button @click="refreshTables()" class="p-0.5 rounded transition-colors ui-text-subtle hover:ui-text" :class="refreshingTables ? 'animate-spin' : ''">
                        <x-feathericon-refresh-cw class="w-3.5 h-3.5" />
                    </button>
                </div>

                <template x-for="group in groupedTables" :key="group.letter">
                    <div>
                        {{-- Letter header --}}
                        <button
                            @click="toggleLetter(group.letter)"
                            class="w-full flex items-center gap-2 px-3 py-1.5 text-left transition-colors hover:ui-bg-elevated"
                        >
                            <svg class="w-3 h-3 ui-text-subtle transition-transform" :class="isLetterExpanded(group.letter) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                            <span class="text-xs font-semibold ui-text-muted" x-text="group.letter"></span>
                            <span class="text-[10px] ui-text-subtle ml-auto" x-text="group.tables.length"></span>
                        </button>

                        {{-- Tables under this letter --}}
                        <div x-show="isLetterExpanded(group.letter)" x-collapse>
                            <template x-for="table in group.tables" :key="table.name">
                                <div>
                                    {{-- Table row --}}
                                    <button
                                        @click="handleTableClick(table.name)"
                                        @dblclick.stop="handleTableDblClick(table.name)"
                                        class="w-full flex items-center gap-2 pl-7 pr-3 py-1.5 text-left transition-colors hover:ui-bg-elevated group"
                                        :class="expandedTable === table.name ? 'ui-bg-elevated' : ''"
                                    >
                                        <svg class="w-3.5 h-3.5 ui-text-subtle transition-transform" :class="expandedTable === table.name ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                        </svg>
                                        <svg class="w-4 h-4 flex-shrink-0" style="color: rgb(var(--ui-warning))" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M12 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M21.375 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125M12 17.25v-5.25" />
                                        </svg>
                                        <span class="text-xs ui-text truncate" x-text="table.name"></span>
                                        <span class="text-[10px] ui-text-subtle ml-auto" x-text="table.rows"></span>
                                    </button>

                                    {{-- Columns --}}
                                    <div x-show="expandedTable === table.name" x-collapse>
                                        <template x-for="col in table.columns" :key="col.name">
                                            <button
                                                @click="insertColumnName(col.name)"
                                                class="w-full flex items-center gap-2 pl-14 pr-3 py-1 text-left transition-colors hover:ui-bg-elevated"
                                            >
                                                <svg x-show="col.key === 'PRI'" class="w-3 h-3 flex-shrink-0" style="color: rgb(var(--ui-warning))" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M8 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm-2 4a2 2 0 1 1 4 0 2 2 0 0 1-4 0Z" clip-rule="evenodd" />
                                                    <path d="M10.5 8a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-1v1.5a.5.5 0 0 1-1 0V8.5h-1v1a.5.5 0 0 1-1 0v-1h-1a.5.5 0 0 1-.5-.5Z" />
                                                </svg>
                                                <svg x-show="col.key !== 'PRI'" class="w-3 h-3 flex-shrink-0 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0 0 15 0m-15 0a7.5 7.5 0 0 1 15 0m-15 0H3m16.5 0H21" />
                                                </svg>
                                                <span class="text-[11px] ui-text truncate" x-text="col.name"></span>
                                                <span class="text-[10px] ui-text-subtle ml-auto" x-text="col.type"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </x-hub-ui::secondary-sidebar>

        {{-- Main Content Area --}}
        <div class="flex-1 flex flex-col overflow-hidden">
            <x-hub-ui::tabs
                active="activeTab"
                :tabs="[
                    ['key' => 'query',     'label' => 'SQL Query', 'icon' => 'code'],
                    ['key' => 'favorites', 'label' => 'Favorites', 'icon' => 'star'],
                    ['key' => 'history',   'label' => 'History',   'icon' => 'clock'],
                ]"
            />

            {{-- Tab Content --}}
            <div class="flex-1 overflow-y-auto">
                {{-- SQL Query Tab --}}
                <div x-show="activeTab === 'query'" class="flex flex-col h-full">
                    {{-- Editor --}}
                    <div class="p-4 sm:p-6 border-b ui-border">
                        <div class="space-y-3">
                            <div>
                                <textarea
                                    x-ref="queryInput"
                                    x-model="query"
                                    placeholder="SELECT * FROM users LIMIT 10"
                                    rows="6"
                                    class="w-full px-4 py-3 text-sm border rounded-lg shadow-sm focus:ring-0 focus:ring-offset-0 focus:border-[rgb(var(--ui-border))] transition ui-input font-mono"
                                    style="font-family: 'JetBrains Mono', monospace; tab-size: 4;"
                                    @keydown.ctrl.enter.prevent="runQuery()"
                                    @keydown.meta.enter.prevent="runQuery()"
                                ></textarea>
                            </div>
                            <div class="flex items-center justify-between flex-wrap gap-3">
                                <div class="flex items-center gap-3 flex-wrap">
                                    <button @click="runQuery()" :disabled="loading || !query.trim()" class="ui-btn ui-btn-primary ui-btn-sm">
                                        <template x-if="loading">
                                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </template>
                                        <template x-if="!loading">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                            </svg>
                                        </template>
                                        <span x-text="loading ? 'Running...' : 'Run Query'"></span>
                                    </button>
                                    <span class="flex items-center gap-1.5 text-[11px] ui-text-subtle">
                                        <kbd class="ui-kbd">Ctrl</kbd>
                                        <span>+</span>
                                        <kbd class="ui-kbd">Enter</kbd>
                                        <span>to run</span>
                                    </span>
                                </div>
                                <div x-show="isBrowsingTable && results && results.length > 0" class="flex items-center gap-2">
                                    <button @click="resetTable()" class="ui-btn ui-btn-ghost ui-btn-sm">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M21.015 4.356v4.992" />
                                        </svg>
                                        <span>Reset</span>
                                    </button>
                                    <button @click="confirmTruncate()" class="ui-btn ui-btn-danger ui-btn-sm">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                        <span>Truncate</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Results --}}
                    <div class="flex-1 overflow-auto p-4 sm:p-6">
                        {{-- Error --}}
                        <template x-if="error">
                            <div class="rounded-md border p-4 ui-alert-error">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm" x-text="error"></p>
                                    </div>
                                </div>
                            </div>
                        </template>

                        {{-- Results table --}}
                        <template x-if="columns.length">
                            <div class="space-y-3">
                                <div class="overflow-x-auto rounded-lg border ui-border mb-4 pb-3">
                                    <table class="text-sm text-left ui-table" style="min-width: 100%">
                                        <thead>
                                            <tr class="text-xs uppercase tracking-wider ui-bg-elevated">
                                                <template x-for="(col, colIdx) in columns" :key="col">
                                                    <th
                                                        class="px-4 py-2.5 font-medium whitespace-nowrap transition-colors relative select-none cursor-pointer"
                                                        :style="(hoveredCol === colIdx ? 'background-color: rgb(var(--ui-primary) / 0.08);' : '') + (columnWidths[col] ? 'min-width:' + columnWidths[col] + 'px; max-width:' + columnWidths[col] + 'px' : '')"
                                                        @click="toggleSort(col)"
                                                    >
                                                        <span class="flex items-center gap-1.5">
                                                            <span x-text="col"></span>
                                                            <span class="inline-flex flex-col -space-y-1">
                                                                <svg class="w-2.5 h-2.5 transition-colors" :class="sortColumn === col && sortDirection === 'asc' ? 'ui-text-primary' : 'ui-text-subtle'" fill="currentColor" viewBox="0 0 10 5"><path d="M5 0l5 5H0z"/></svg>
                                                                <svg class="w-2.5 h-2.5 transition-colors" :class="sortColumn === col && sortDirection === 'desc' ? 'ui-text-primary' : 'ui-text-subtle'" fill="currentColor" viewBox="0 0 10 5"><path d="M5 5L0 0h10z"/></svg>
                                                            </span>
                                                        </span>
                                                        <div
                                                            @mousedown.prevent.stop="startResize($event, col)"
                                                            @click.stop
                                                            class="absolute top-0 right-0 w-2 h-full cursor-col-resize opacity-0 hover:opacity-100 transition-opacity"
                                                            style="background-color: rgb(var(--ui-primary) / 0.3)"
                                                        ></div>
                                                    </th>
                                                </template>
                                            </tr>
                                            <tr x-ref="filterRow" class="ui-bg-elevated" style="opacity: 0.7">
                                                <template x-for="col in columns" :key="'filter-'+col">
                                                    <th class="px-2 py-1.5">
                                                        <input
                                                            type="text"
                                                            :placeholder="'Filter...'"
                                                            class="w-full px-2 py-1 text-xs rounded border ui-input font-normal normal-case"
                                                            style="min-width: 60px;"
                                                            x-model="columnFilters[col]"
                                                            @input.debounce.1000ms="applyFilters()"
                                                        />
                                                    </th>
                                                </template>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-if="results && results.length > 0">
                                                <template x-for="(row, i) in results" :key="i">
                                                    <tr class="transition-colors">
                                                        <template x-for="(col, colIdx) in columns" :key="col">
                                                            <td
                                                                class="px-4 py-2 whitespace-nowrap max-w-xs text-xs cursor-pointer transition-colors"
                                                                :class="editingCell && editingCell.rowIndex === i && editingCell.colName === col ? 'ring-2 ring-inset' : 'truncate'"
                                                                :style="(editingCell && editingCell.rowIndex === i && editingCell.colName === col ? 'ring-color: rgb(var(--ui-primary)); padding: 0;' : '') + (hoveredCol === colIdx && !(editingCell && editingCell.rowIndex === i && editingCell.colName === col) ? 'background-color: rgb(var(--ui-primary) / 0.08);' : '')"
                                                                :title="row[col] ?? ''"
                                                                @mouseenter="hoveredCol = colIdx"
                                                                @mouseleave="hoveredCol = null"
                                                                @click="!(editingCell && editingCell.rowIndex === i && editingCell.colName === col) && copyCell(row[col])"
                                                                @dblclick.stop="startEditing(i, col, row[col])"
                                                            >
                                                                <template x-if="editingCell && editingCell.rowIndex === i && editingCell.colName === col">
                                                                    <input
                                                                        type="text"
                                                                        x-ref="editInput"
                                                                        x-model="editingValue"
                                                                        class="w-full h-full px-4 py-2 bg-transparent outline-none font-mono text-xs"
                                                                        @keydown.enter.prevent="commitEdit()"
                                                                        @keydown.tab.prevent="commitEdit()"
                                                                        @keydown.escape.prevent="cancelEdit()"
                                                                        @blur="commitEdit()"
                                                                    />
                                                                </template>
                                                                <template x-if="!(editingCell && editingCell.rowIndex === i && editingCell.colName === col)">
                                                                    <span>
                                                                        <span x-show="row[col] === null" class="ui-text-subtle italic">NULL</span>
                                                                        <span x-show="row[col] !== null" x-text="row[col]"></span>
                                                                    </span>
                                                                </template>
                                                            </td>
                                                        </template>
                                                    </tr>
                                                </template>
                                            </template>
                                            <template x-if="!results || results.length === 0">
                                                <tr>
                                                    <td :colspan="columns.length" class="px-4 py-6 text-center text-xs ui-text-subtle italic">
                                                        No results
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Pagination --}}
                                <x-hub-ui::pager
                                    x-show="results && results.length > 0"
                                    duration="duration"
                                />

                            </div>
                        </template>

                        {{-- Empty state --}}
                        <div x-show="!results && !error">
                            <x-hub-ui::empty-state
                                title="Write a query and press Run"
                                description="Click a table to query it. Double-click to see columns."
                            >
                                <x-slot:icon>
                                    <x-feathericon-database class="w-full h-full" />
                                </x-slot:icon>
                            </x-hub-ui::empty-state>
                        </div>
                    </div>
                </div>

                {{-- Favorites Tab --}}
                <div x-show="activeTab === 'favorites'" class="p-4 sm:p-6">
                    <x-hub-ui::empty-state
                        title="No favorite queries yet"
                        description="Save frequently used queries for quick access."
                    >
                        <x-slot:icon>
                            <x-feathericon-star class="w-full h-full" />
                        </x-slot:icon>
                    </x-hub-ui::empty-state>
                </div>

                {{-- History Tab --}}
                <div x-show="activeTab === 'history'" class="p-4 sm:p-6">
                    <x-hub-ui::empty-state
                        title="No query history yet"
                        description="Queries you run will appear here."
                    >
                        <x-slot:icon>
                            <x-feathericon-clock class="w-full h-full" />
                        </x-slot:icon>
                    </x-hub-ui::empty-state>
                </div>
            </div>
        </div>
        </div>
    </div>

    <script>
        function sqlQuery() {
            return {
                activeTab: 'query',
                expandedTable: null,
                expandedLetters: {},
                tableSearch: '',
                tables: @json($tables),
                refreshingTables: false,
                _clickTimer: null,
                sidebarWidth: 288,

                query: '',
                baseQuery: '',
                loading: false,
                results: null,
                columns: [],
                duration: 0,
                error: null,
                page: 1,
                perPage: 20,
                total: 0,
                lastPage: 1,
                columnFilters: {},
                hoveredCol: null,
                columnWidths: {},
                _resizing: null,
                sortColumn: null,
                sortDirection: null, // 'asc' or 'desc'

                // Inline editing
                editingCell: null,
                editingValue: '',
                savingCell: false,
                tablePk: null,
                tablePkFetched: false,
                _escPressed: false,

                get isBrowsingTable() {
                    return /^SELECT \* FROM (\w+)$/i.test(this.baseQuery);
                },

                get browsedTableName() {
                    const match = this.baseQuery.match(/^SELECT \* FROM (\w+)$/i);
                    return match ? match[1] : null;
                },

                get visiblePages() {
                    const total = this.lastPage;
                    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
                    const pages = [];
                    const current = this.page;
                    pages.push(1);
                    if (current > 3) pages.push('...');
                    for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
                        pages.push(i);
                    }
                    if (current < total - 2) pages.push('...');
                    if (total > 1) pages.push(total);
                    return pages;
                },

                async refreshTables() {
                    if (this.refreshingTables) return;
                    this.refreshingTables = true;
                    const { ok, data } = await hubUiFetch('{{ route("system.sql-query.tables") }}', { method: 'GET' });
                    if (ok) this.tables = data;
                    this.refreshingTables = false;
                },

                async goToPage(p) {
                    if (p === '...' || p < 1 || p > this.lastPage || p === this.page) return;
                    this.page = p;
                    await this.fetchResults();
                },

                get filteredTables() {
                    if (!this.tableSearch) return this.tables;
                    const search = this.tableSearch.toLowerCase();
                    return this.tables.filter(t => t.name.toLowerCase().startsWith(search));
                },

                get groupedTables() {
                    const groups = {};
                    for (const table of this.filteredTables) {
                        const letter = table.name[0].toUpperCase();
                        if (!groups[letter]) groups[letter] = [];
                        groups[letter].push(table);
                    }
                    return Object.keys(groups).sort().map(letter => ({ letter, tables: groups[letter] }));
                },

                toggleLetter(letter) {
                    this.expandedLetters[letter] = !this.expandedLetters[letter];
                },

                isLetterExpanded(letter) {
                    return this.tableSearch.length > 0 || !!this.expandedLetters[letter];
                },

                async runQuery() {
                    this.baseQuery = this.query.trim();
                    this.columnFilters = {};
                    this.sortColumn = null;
                    this.sortDirection = null;
                    this.page = 1;
                    this.editingCell = null;
                    this.tablePk = null;
                    this.tablePkFetched = false;
                    await this.fetchResults();
                },

                toggleSort(col) {
                    if (this.sortColumn === col) {
                        if (this.sortDirection === 'asc') {
                            this.sortDirection = 'desc';
                        } else if (this.sortDirection === 'desc') {
                            this.sortColumn = null;
                            this.sortDirection = null;
                        }
                    } else {
                        this.sortColumn = col;
                        this.sortDirection = 'asc';
                    }
                    this.page = 1;
                    this.rebuildQuery();
                    this.fetchResults();
                },

                rebuildQuery() {
                    let q = this.baseQuery;

                    // Apply filters
                    const conditions = Object.entries(this.columnFilters)
                        .filter(([_, v]) => v && v.trim())
                        .map(([col, v]) => {
                            const val = v.trim();
                            if (val.toLowerCase() === 'null') return col + ' IS NULL';
                            if (val.startsWith('=')) return col + " = '" + val.slice(1) + "'";
                            if (val.includes('%')) return col + " LIKE '" + val + "'";
                            return col + " LIKE '%" + val + "%'";
                        });

                    if (conditions.length) {
                        q = 'SELECT * FROM (' + q + ') AS _filtered WHERE ' + conditions.join(' AND ');
                    }

                    // Apply sorting
                    if (this.sortColumn && this.sortDirection) {
                        q = 'SELECT * FROM (' + q + ') AS _sorted ORDER BY ' + this.sortColumn + ' ' + this.sortDirection.toUpperCase();
                    }

                    this.query = q;
                },

                applyFilters() {
                    this.page = 1;
                    this.rebuildQuery();
                    this.fetchResults();
                },

                async fetchResults() {
                    const q = this.query.trim();
                    if (!q || this.loading) return;

                    this.loading = true;
                    this.error = null;

                    const { ok, data } = await hubUiFetch('{{ route("system.sql-query.execute") }}', {
                        body: { query: q, page: this.page, per_page: this.perPage },
                    });

                    if (ok) {
                        this.results = data.results;
                        if (data.columns.length) this.columns = data.columns;
                        this.duration = data.duration;
                        this.total = data.total;
                        this.page = data.page;
                        this.lastPage = data.last_page;
                    } else {
                        this.error = data.error || data.message || 'An error occurred.';
                        this.results = null;
                    }

                    this.loading = false;
                },

                handleTableClick(name) {
                    clearTimeout(this._clickTimer);
                    this._clickTimer = setTimeout(() => this.queryTable(name), 250);
                },

                handleTableDblClick(name) {
                    clearTimeout(this._clickTimer);
                    this.expandedTable = this.expandedTable === name ? null : name;
                },

                queryTable(name) {
                    this.activeTab = 'query';
                    this.query = 'SELECT * FROM ' + name;
                    this.baseQuery = this.query;
                    this.columnFilters = {};
                    this.sortColumn = null;
                    this.sortDirection = null;
                    this.page = 1;
                    this.editingCell = null;
                    this.tablePk = null;
                    this.tablePkFetched = false;
                    const table = this.tables.find(t => t.name === name);
                    if (table) this.columns = table.columns.map(c => c.name);
                    this.fetchPrimaryKey(name);
                    this.fetchResults().then(() => {
                        this.$nextTick(() => {
                            const firstFilter = this.$refs.filterRow?.querySelector('input');
                            if (firstFilter) firstFilter.focus();
                        });
                    });
                },

                async fetchPrimaryKey(tableName) {
                    this.tablePk = null;
                    this.tablePkFetched = false;
                    const { ok, data } = await hubUiFetch(
                        '{{ route("system.sql-query.primary-key") }}?table=' + encodeURIComponent(tableName),
                        { method: 'GET' }
                    );
                    if (ok) {
                        this.tablePk = data.pk;
                    }
                    this.tablePkFetched = true;
                },

                async copyCell(value) {
                    const text = value === null ? '' : String(value);
                    await navigator.clipboard.writeText(text);
                    window.showToast('Copied to clipboard', 'success', 2000);
                },

                startSidebarResize(e) {
                    const startX = e.clientX;
                    const startW = this.sidebarWidth;

                    const onMove = (ev) => {
                        this.sidebarWidth = Math.max(200, Math.min(600, startW + ev.clientX - startX));
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

                startResize(e, col) {
                    const th = e.target.closest('th');
                    const startX = e.clientX;
                    const startW = th.offsetWidth;

                    const onMove = (ev) => {
                        const w = Math.max(60, startW + ev.clientX - startX);
                        this.columnWidths[col] = w;
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

                insertColumnName(name) {
                    const el = this.$refs.queryInput;
                    if (!el) return;
                    this.activeTab = 'query';
                    const start = el.selectionStart;
                    const end = el.selectionEnd;
                    const text = this.query;
                    this.query = text.substring(0, start) + name + text.substring(end);
                    this.$nextTick(() => {
                        el.selectionStart = el.selectionEnd = start + name.length;
                        el.focus();
                    });
                },

                resetTable() {
                    this.query = this.baseQuery;
                    this.columnFilters = {};
                    this.sortColumn = null;
                    this.sortDirection = null;
                    this.page = 1;
                    this.fetchResults();
                },

                confirmTruncate() {
                    const tableName = this.browsedTableName;
                    if (!tableName) return;

                    window.showConfirmation({
                        title: 'Truncate Table',
                        message: 'Are you sure you want to truncate "' + tableName + '"? All data will be permanently deleted.',
                        confirmText: 'Truncate',
                        type: 'danger',
                        onConfirm: () => this.truncateTable(tableName),
                    });
                },

                startEditing(rowIndex, colName, currentValue) {
                    if (!this.isBrowsingTable) return;
                    if (!this.tablePk) {
                        window.showToast('Cannot edit: table has no primary key', 'error');
                        return;
                    }
                    this._escPressed = false;
                    this.editingCell = { rowIndex, colName, originalValue: currentValue };
                    this.editingValue = currentValue === null ? '' : String(currentValue);
                    this.$nextTick(() => {
                        const input = this.$refs.editInput;
                        if (input) {
                            input.focus();
                            input.select();
                        }
                    });
                },

                async commitEdit() {
                    if (this._escPressed || !this.editingCell || this.savingCell) return;
                    this.savingCell = true;

                    const { rowIndex, colName, originalValue } = this.editingCell;
                    const tableName = this.browsedTableName;
                    const pkValue = this.results[rowIndex][this.tablePk];
                    const resolvedValue = this.editingValue.toUpperCase() === 'NULL' ? 'NULL' : this.editingValue;

                    const { ok, data } = await hubUiFetch('{{ route("system.sql-query.update") }}', {
                        body: {
                            table: tableName,
                            pk_column: this.tablePk,
                            pk_value: pkValue,
                            column: colName,
                            value: resolvedValue,
                        },
                    });

                    if (ok) {
                        this.results[rowIndex][colName] = data.value;
                        window.showToast('Cell updated', 'success', 2000);
                    } else {
                        this.results[rowIndex][colName] = originalValue;
                        window.showToast(data.error || data.message || 'Failed to update cell', 'error');
                    }

                    this.editingCell = null;
                    this.savingCell = false;
                },

                cancelEdit() {
                    this._escPressed = true;
                    this.editingCell = null;
                },

                async truncateTable(tableName) {
                    const { ok, data } = await hubUiFetch('{{ route("system.sql-query.truncate") }}', {
                        body: { table: tableName },
                    });

                    if (ok) {
                        window.showToast('Table truncated successfully', 'success');
                        this.query = this.baseQuery;
                        this.columnFilters = {};
                        this.page = 1;
                        await this.fetchResults();
                        this.refreshTables();
                    } else {
                        window.showToast(data.error || data.message || 'Failed to truncate table', 'error');
                    }
                },
            };
        }
    </script>
</x-app-layout>
