<x-app-layout active="sql" :title="'Kraite — SQL'">
    <script>
        window.sqlWorkbench = (urls, initialTables) => ({
            tables: initialTables,
            tableSearch: '',
            query: '',
            results: [],
            columns: [],
            filters: {},
            page: 1,
            perPage: 10,
            lastPage: 1,
            total: 0,
            duration: null,
            loading: false,
            hasRun: false,
            error: '',
            requestId: 0,

            init() {
                this.$nextTick(() => this.$refs.tableSearch.focus());
            },

            get filteredTables() {
                const needle = this.tableSearch.trim().toLowerCase();

                if (needle === '') {
                    return this.tables;
                }

                return this.tables.filter((table) => table.toLowerCase().includes(needle));
            },

            runOnlyTable() {
                if (this.filteredTables.length === 1) {
                    this.selectTable(this.filteredTables[0]);
                }
            },

            selectTable(table) {
                const escapedTable = table.replaceAll('`', '``');
                this.query = `SELECT * FROM \`${escapedTable}\` LIMIT 10`;
                this.execute(true);
            },

            async execute(resetFilters = true) {
                if (this.query.trim() === '') {
                    this.error = 'Write a SELECT statement first.';
                    this.$refs.editor.focus();

                    return;
                }

                if (resetFilters) {
                    this.filters = {};
                    this.page = 1;
                    this.columns = [];
                }

                const activeRequest = ++this.requestId;
                this.loading = true;
                this.error = '';

                const response = await hubUiFetch(urls.execute, {
                    body: {
                        query: this.query,
                        page: this.page,
                        per_page: this.perPage,
                        filters: this.filters,
                    },
                });

                if (activeRequest !== this.requestId) {
                    return;
                }

                this.loading = false;
                this.hasRun = true;

                if (! response.ok) {
                    this.error = response.data?.error
                        || response.data?.message
                        || 'The query could not be executed.';

                    return;
                }

                const previousColumns = this.columns;
                this.results = response.data.results;
                this.columns = response.data.columns.length > 0
                    ? response.data.columns
                    : (resetFilters ? [] : previousColumns);
                this.page = response.data.page;
                this.perPage = response.data.per_page;
                this.lastPage = response.data.last_page;
                this.total = response.data.total;
                this.duration = response.data.duration;

                const nextFilters = {};
                for (const column of this.columns) {
                    nextFilters[column] = this.filters[column] ?? '';
                }
                this.filters = nextFilters;
            },

            applyFilters() {
                this.page = 1;
                this.execute(false);
            },

            clearFilters() {
                this.filters = Object.fromEntries(this.columns.map((column) => [column, '']));
                this.page = 1;
                this.execute(false);
            },

            goTo(page) {
                if (this.loading || page < 1 || page > this.lastPage || page === this.page) {
                    return;
                }

                this.page = page;
                this.execute(false);
            },

            changePageSize() {
                this.page = 1;
                this.execute(false);
            },

            displayValue(value) {
                if (value === null) {
                    return 'NULL';
                }

                if (typeof value === 'object') {
                    return JSON.stringify(value);
                }

                return String(value);
            },
        });
    </script>

    <div
        x-data="sqlWorkbench(@js([
            'execute' => route('system.sql-query.execute'),
        ]), @js($tables))"
        data-sql-workbench
    >
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-database class="w-[13px] h-[13px]" stroke-width="1.75"/>
                    Sysadmin
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">
                    SQL workbench
                </h1>
                <div class="text-[13px] text-fg-3 mt-1.5">
                    Inspect database records with SELECT statements. This workspace cannot edit data.
                </div>
            </div>
            <div class="inline-flex items-center gap-2 rounded-control border border-line h-[36px] px-3.5 font-mono text-[10.5px] font-semibold tracking-[0.06em] uppercase text-fg-3">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                Read only
            </div>
        </div>

        <div class="grid grid-cols-[280px_minmax(0,1fr)] gap-4 items-start max-[900px]:grid-cols-1">
            <aside class="card card--flat overflow-hidden max-[900px]:max-h-[300px]">
                <x-ui.card-head icon="list" title="Tables" :accent="true">
                    <x-slot:right>
                        <span
                            class="font-mono text-[10px] text-fg-mute tabular-nums"
                            x-text="`${filteredTables.length}/${tables.length}`"
                        ></span>
                    </x-slot:right>
                </x-ui.card-head>

                <div class="p-3 border-b border-line-soft">
                    <label for="sql-table-search" class="sr-only">Search tables</label>
                    <div class="h-9 w-full inline-flex items-center gap-2 bg-surface border border-line rounded-control px-3 transition-colors focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/15">
                        <x-feathericon-search class="w-3.5 h-3.5 text-fg-mute flex-shrink-0" stroke-width="1.75"/>
                        <input
                            id="sql-table-search"
                            x-ref="tableSearch"
                            x-model="tableSearch"
                            @keydown.enter.prevent="runOnlyTable()"
                            @keydown.escape="tableSearch = ''"
                            type="text"
                            autocomplete="off"
                            autofocus
                            placeholder="Filter tables…"
                            data-sql-table-search
                            class="h-full flex-1 min-w-0 border-0 bg-transparent p-0 font-mono text-[12px] leading-none text-fg-1 placeholder:text-fg-mute outline-none"
                        >
                        <button
                            type="button"
                            x-show="tableSearch !== ''"
                            x-cloak
                            @click="tableSearch = ''; $refs.tableSearch.focus()"
                            aria-label="Clear table search"
                            class="appearance-none bg-transparent border-0 p-0 flex-shrink-0 inline-flex items-center cursor-pointer text-fg-mute hover:text-fg-1"
                        >
                            <x-feathericon-x class="w-[13px] h-[13px]" stroke-width="2"/>
                        </button>
                    </div>
                    <div class="mt-2 font-mono text-[9.5px] leading-relaxed text-fg-mute">
                        Enter opens the only matching table.
                    </div>
                </div>

                <div class="max-h-[calc(100vh-335px)] min-h-[220px] overflow-y-auto max-[900px]:min-h-0 max-[900px]:max-h-[190px]">
                    <template x-for="table in filteredTables" :key="table">
                        <button
                            type="button"
                            @click="selectTable(table)"
                            class="w-full flex items-center gap-2.5 px-4 py-2.5 border-0 border-b border-line-soft bg-transparent text-left cursor-pointer transition-colors hover:bg-hover focus-visible:outline-none focus-visible:bg-hover"
                        >
                            <x-feathericon-database class="w-3.5 h-3.5 text-fg-mute flex-shrink-0" stroke-width="1.75"/>
                            <span class="font-mono text-[11.5px] text-fg-2 truncate" x-text="table"></span>
                        </button>
                    </template>

                    <div x-show="filteredTables.length === 0" x-cloak class="px-4 py-10 text-center">
                        <div class="font-sans text-[13px] font-semibold text-fg-2">No matching table</div>
                        <div class="mt-1 font-mono text-[10px] text-fg-mute">Try a shorter name.</div>
                    </div>
                </div>
            </aside>

            <main class="min-w-0 space-y-4">
                <section class="card card--flat flex items-center gap-3 p-3 bg-[#0b0d10] max-[640px]:gap-2">
                    <label for="sql-editor" class="inline-flex items-center gap-2 font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap max-[640px]:sr-only">
                        <x-feathericon-terminal class="w-4 h-4 text-accent" stroke-width="1.75"/>
                        Query
                    </label>
                    <input
                        id="sql-editor"
                        x-ref="editor"
                        x-model="query"
                        @keydown.meta.enter.prevent="execute(true)"
                        @keydown.ctrl.enter.prevent="execute(true)"
                        type="text"
                        maxlength="5000"
                        spellcheck="false"
                        autocapitalize="off"
                        autocomplete="off"
                        placeholder="SELECT * FROM accounts LIMIT 10"
                        title="Run with ⌘/Ctrl + Enter"
                        data-sql-editor
                        class="block h-10 min-w-0 flex-1 rounded-control border border-white/10 bg-[#07090b] px-3.5 font-mono text-[12px] text-slate-200 caret-violet-400 placeholder:text-slate-600 outline-none transition-colors focus:border-violet-500/70 focus:ring-2 focus:ring-violet-500/15"
                    >
                    <button
                        type="button"
                        @click="execute(true)"
                        :disabled="loading"
                        class="inline-flex h-10 flex-shrink-0 items-center justify-center gap-2 rounded-control border-0 bg-accent px-4 font-sans text-[12px] font-bold text-white cursor-pointer transition-opacity hover:opacity-90 disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-feathericon-play class="w-3.5 h-3.5" stroke-width="2"/>
                        <span class="max-[480px]:hidden" x-text="loading ? 'Running…' : 'Run SELECT'"></span>
                    </button>
                </section>

                <div
                    x-show="error"
                    x-cloak
                    class="rounded-control border border-red-500/30 bg-red-500/10 px-4 py-3 font-mono text-[11px] leading-5 text-red-400"
                    role="alert"
                    x-text="error"
                ></div>

                <section class="card card--flat overflow-hidden">
                    <div class="flex items-center justify-between gap-3 py-[13px] px-5 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                        <h2 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px]">
                            <x-feathericon-grid class="w-4 h-4 text-accent" stroke-width="1.75"/>
                            Results
                        </h2>
                        <div x-show="hasRun && !error" x-cloak class="font-mono text-[10px] text-fg-mute tabular-nums">
                            <span x-text="`${total.toLocaleString()} row${total === 1 ? '' : 's'}`"></span>
                            <span class="mx-1.5 text-line">·</span>
                            <span x-text="`${duration} ms`"></span>
                        </div>
                    </div>

                    <div x-show="!hasRun && !loading" class="py-16 px-5 text-center">
                        <div class="font-sans text-[14px] font-semibold text-fg-1">Ready for inspection.</div>
                        <div class="mt-1 font-mono text-[10.5px] text-fg-mute">
                            Choose a table or run a SELECT statement.
                        </div>
                    </div>

                    <div x-show="loading" x-cloak class="py-16 px-5 text-center">
                        <div class="font-mono text-[11px] text-fg-mute animate-pulse">Executing SELECT…</div>
                    </div>

                    <div x-show="hasRun && !loading && !error && columns.length === 0" x-cloak class="py-16 px-5 text-center">
                        <div class="font-sans text-[14px] font-semibold text-fg-1">Query returned no rows.</div>
                        <div class="mt-1 font-mono text-[10.5px] text-fg-mute">No columns are available to filter.</div>
                    </div>

                    <div x-show="hasRun && !loading && !error && columns.length > 0" x-cloak>
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-line-soft bg-surface max-[640px]:items-start max-[640px]:flex-col">
                            <div class="font-mono text-[9.5px] text-fg-mute">
                                Exact by default · use <span class="text-fg-2">*</span> as wildcard · use <span class="text-fg-2">NULL</span> for empty database values
                            </div>
                            <button
                                type="button"
                                @click="clearFilters()"
                                class="appearance-none border-0 bg-transparent p-0 font-mono text-[9.5px] font-semibold uppercase tracking-[0.06em] text-accent cursor-pointer hover:underline"
                            >
                                Clear filters
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[760px] border-collapse">
                                <thead>
                                    <tr class="align-middle bg-surface-2 border-b border-line-soft">
                                        <template x-for="column in columns" :key="`head-${column}`">
                                            <th class="px-3 pt-3 pb-2 text-left font-mono text-[10px] font-bold tracking-[0.04em] text-fg-2 whitespace-nowrap" x-text="column"></th>
                                        </template>
                                    </tr>
                                    <tr class="align-middle bg-surface border-b border-line">
                                        <template x-for="column in columns" :key="`filter-${column}`">
                                            <th class="p-2 text-left">
                                                <label :for="`sql-filter-${column}`" class="sr-only" x-text="`Filter ${column}`"></label>
                                                <input
                                                    :id="`sql-filter-${column}`"
                                                    x-model="filters[column]"
                                                    @input.debounce.350ms="applyFilters()"
                                                    @keydown.enter.prevent="applyFilters()"
                                                    type="text"
                                                    autocomplete="off"
                                                    placeholder="Filter…"
                                                    data-sql-column-filter
                                                    class="h-8 w-full min-w-[120px] rounded-control border border-line bg-surface-2 px-2.5 font-mono text-[10.5px] text-fg-1 placeholder:text-fg-mute outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/15"
                                                >
                                            </th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, rowIndex) in results" :key="`${page}-${rowIndex}`">
                                        <tr class="align-middle border-b border-line-soft last:border-b-0 hover:bg-hover">
                                            <template x-for="column in columns" :key="`${rowIndex}-${column}`">
                                                <td
                                                    class="max-w-[360px] px-3 py-2.5 text-left font-mono text-[10.5px] leading-5 text-fg-2 whitespace-nowrap overflow-hidden text-ellipsis"
                                                    :class="row[column] === null && 'italic text-fg-mute'"
                                                    :title="displayValue(row[column])"
                                                    x-text="displayValue(row[column])"
                                                ></td>
                                            </template>
                                        </tr>
                                    </template>
                                    <tr x-show="results.length === 0" class="align-middle">
                                        <td :colspan="columns.length" class="px-4 py-12 text-center font-mono text-[10.5px] text-fg-mute">
                                            No rows match these filters.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between gap-4 px-4 py-3 border-t border-line-soft bg-surface-2 max-[640px]:items-start max-[640px]:flex-col">
                            <div class="flex items-center gap-2 font-mono text-[10px] text-fg-mute">
                                <label for="sql-page-size">Rows</label>
                                <select
                                    id="sql-page-size"
                                    x-model.number="perPage"
                                    @change="changePageSize()"
                                    class="h-8 rounded-control border border-line bg-surface px-2 font-mono text-[10px] text-fg-2 outline-none focus:border-accent"
                                >
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                                <span x-text="total === 0 ? '0 results' : `${((page - 1) * perPage) + 1}–${Math.min(page * perPage, total)} of ${total}`"></span>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="goTo(page - 1)"
                                    :disabled="loading || page <= 1"
                                    aria-label="Previous page"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-control border border-line bg-surface text-fg-2 cursor-pointer hover:bg-hover disabled:cursor-not-allowed disabled:opacity-35"
                                >
                                    <x-feathericon-chevron-left class="w-3.5 h-3.5" stroke-width="1.75"/>
                                </button>
                                <span class="min-w-[92px] text-center font-mono text-[10px] text-fg-3 tabular-nums" x-text="`Page ${page} / ${lastPage}`"></span>
                                <button
                                    type="button"
                                    @click="goTo(page + 1)"
                                    :disabled="loading || page >= lastPage"
                                    aria-label="Next page"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-control border border-line bg-surface text-fg-2 cursor-pointer hover:bg-hover disabled:cursor-not-allowed disabled:opacity-35"
                                >
                                    <x-feathericon-chevron-right class="w-3.5 h-3.5" stroke-width="1.75"/>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>
</x-app-layout>
