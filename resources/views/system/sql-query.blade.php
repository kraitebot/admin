<x-app-layout :activeSection="'system'" :activeHighlight="'sql-query'">
    <div class="flex -mx-12 -my-12 overflow-hidden"
         x-data="sqlQuery()"
         x-init="$el.parentElement.classList.remove('overflow-y-auto'); $el.parentElement.classList.add('overflow-hidden')"
         x-destroy="$el.parentElement.classList.add('overflow-y-auto'); $el.parentElement.classList.remove('overflow-hidden')"
         style="height: 100%">
        {{-- Secondary Sidebar: Table Browser --}}
        <div class="w-72 flex-shrink-0 border-r ui-border overflow-hidden flex flex-col" style="background-color: rgb(var(--ui-bg-sidebar))">
            {{-- Search --}}
            <div class="p-3 border-b ui-border">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        type="text"
                        x-model="tableSearch"
                        placeholder="Filter tables..."
                        class="w-full pl-9 pr-3 py-2 text-xs rounded-lg border ui-input"
                    />
                </div>
            </div>

            {{-- Table List --}}
            <div class="flex-1 overflow-y-auto py-2 ui-scrollbar">
                <div class="px-3 pb-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Tables</span>
                    <span class="text-[10px] ui-text-subtle ml-1">({{ count($tables) }})</span>
                </div>

                <template x-for="table in filteredTables" :key="table.name">
                    <div>
                        {{-- Table row --}}
                        <button
                            @click="handleTableClick(table.name)"
                            @dblclick.stop="handleTableDblClick(table.name)"
                            class="w-full flex items-center gap-2 px-3 py-1.5 text-left transition-colors hover:ui-bg-elevated group"
                            :class="expandedTable === table.name ? 'ui-bg-elevated' : ''"
                        >
                            <svg class="w-3.5 h-3.5 ui-text-subtle transition-transform" :class="expandedTable === table.name ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                            <svg class="w-4 h-4 flex-shrink-0" style="color: rgb(var(--ui-warning))" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M12 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M21.375 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125M12 17.25v-5.25" />
                            </svg>
                            <span class="text-xs ui-text truncate" x-text="table.name"></span>
                            <span class="text-[10px] ui-text-subtle ml-auto opacity-0 group-hover:opacity-100" x-text="table.rows"></span>
                        </button>

                        {{-- Columns --}}
                        <div x-show="expandedTable === table.name" x-collapse>
                            <template x-for="col in table.columns" :key="col.name">
                                <button
                                    @click="insertColumnName(col.name)"
                                    class="w-full flex items-center gap-2 pl-10 pr-3 py-1 text-left transition-colors hover:ui-bg-elevated"
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

        {{-- Main Content Area --}}
        <div class="flex-1 flex flex-col overflow-hidden">
            {{-- Tabs --}}
            <div class="flex border-b ui-border px-6 pt-4" style="background-color: rgb(var(--ui-bg-body))">
                <button
                    @click="activeTab = 'query'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px"
                    :class="activeTab === 'query'
                        ? 'ui-text border-current'
                        : 'ui-text-muted border-transparent hover:ui-text hover:border-current'"
                    :style="activeTab === 'query' ? 'border-color: rgb(var(--ui-primary))' : ''"
                >
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        SQL Query
                    </span>
                </button>
                <button
                    @click="activeTab = 'favorites'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px"
                    :class="activeTab === 'favorites'
                        ? 'ui-text border-current'
                        : 'ui-text-muted border-transparent hover:ui-text hover:border-current'"
                    :style="activeTab === 'favorites' ? 'border-color: rgb(var(--ui-primary))' : ''"
                >
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
                        </svg>
                        Favorites
                    </span>
                </button>
                <button
                    @click="activeTab = 'history'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px"
                    :class="activeTab === 'history'
                        ? 'ui-text border-current'
                        : 'ui-text-muted border-transparent hover:ui-text hover:border-current'"
                    :style="activeTab === 'history' ? 'border-color: rgb(var(--ui-primary))' : ''"
                >
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        History
                    </span>
                </button>
            </div>

            {{-- Tab Content --}}
            <div class="flex-1 overflow-y-auto">
                {{-- SQL Query Tab --}}
                <div x-show="activeTab === 'query'" class="flex flex-col h-full">
                    {{-- Editor --}}
                    <div class="p-6 border-b ui-border">
                        <form method="POST" action="{{ route('system.sql-query.execute') }}" class="space-y-3">
                            @csrf
                            <div>
                                <textarea
                                    name="query"
                                    x-ref="queryInput"
                                    placeholder="SELECT * FROM users LIMIT 10"
                                    rows="6"
                                    required
                                    class="w-full px-4 py-3 text-sm border rounded-lg shadow-sm focus:ring-2 focus:ring-offset-2 transition ui-input font-mono"
                                    style="font-family: 'JetBrains Mono', monospace; tab-size: 4;"
                                    @keydown.ctrl.enter="$el.closest('form').submit()"
                                    @keydown.meta.enter="$el.closest('form').submit()"
                                >{{ old('query') }}</textarea>
                            </div>
                            <div class="flex items-center gap-3">
                                <x-hub-ui::button type="submit" size="sm">
                                    <x-slot:icon>
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                        </svg>
                                    </x-slot:icon>
                                    Run Query
                                </x-hub-ui::button>
                                <span class="text-xs ui-text-subtle">Ctrl+Enter to run</span>
                            </div>
                        </form>
                    </div>

                    {{-- Results --}}
                    <div class="flex-1 overflow-auto p-6">
                        @if(session('error'))
                            <x-hub-ui::alert type="error">{{ session('error') }}</x-hub-ui::alert>
                        @endif

                        @if(session('results') !== null)
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-medium ui-text-muted">
                                        {{ count(session('results')) }} {{ Str::plural('row', count(session('results'))) }}
                                    </span>
                                    <span class="text-xs ui-text-subtle">{{ session('duration') }}ms</span>
                                </div>
                                <div class="overflow-x-auto rounded-lg border ui-border">
                                    <table class="w-full text-sm text-left ui-table">
                                        @if(count(session('columns')))
                                            <thead class="text-xs uppercase tracking-wider ui-bg-elevated">
                                                <tr>
                                                    @foreach(session('columns') as $col)
                                                        <th class="px-4 py-2.5 font-medium whitespace-nowrap">{{ $col }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                        @endif
                                        <tbody>
                                            @foreach(session('results') as $row)
                                                <tr class="transition-colors">
                                                    @foreach(session('columns') as $col)
                                                        <td class="px-4 py-2 whitespace-nowrap max-w-xs truncate text-xs" title="{{ $row[$col] ?? '' }}">
                                                            @if(is_null($row[$col] ?? null))
                                                                <span class="ui-text-subtle italic">NULL</span>
                                                            @else
                                                                {{ $row[$col] }}
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @elseif(!session('error'))
                            <div class="flex flex-col items-center justify-center py-16 text-center">
                                <div class="w-14 h-14 rounded-full flex items-center justify-center mb-3 ui-bg-elevated">
                                    <svg class="w-7 h-7 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                                    </svg>
                                </div>
                                <p class="text-sm ui-text-muted">Write a query and press Run to see results.</p>
                                <p class="text-xs ui-text-subtle mt-1">Double-click a table name to insert it.</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Favorites Tab --}}
                <div x-show="activeTab === 'favorites'" class="p-6">
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-14 h-14 rounded-full flex items-center justify-center mb-3 ui-bg-elevated">
                            <svg class="w-7 h-7 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
                            </svg>
                        </div>
                        <p class="text-sm ui-text-muted">No favorite queries yet.</p>
                        <p class="text-xs ui-text-subtle mt-1">Save frequently used queries for quick access.</p>
                    </div>
                </div>

                {{-- History Tab --}}
                <div x-show="activeTab === 'history'" class="p-6">
                    <div class="flex flex-col items-center justify-center py-16 text-center">
                        <div class="w-14 h-14 rounded-full flex items-center justify-center mb-3 ui-bg-elevated">
                            <svg class="w-7 h-7 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <p class="text-sm ui-text-muted">No query history yet.</p>
                        <p class="text-xs ui-text-subtle mt-1">Queries you run will appear here.</p>
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
                tableSearch: '',
                tables: @json($tables),
                _clickTimer: null,

                get filteredTables() {
                    if (!this.tableSearch) return this.tables;
                    const search = this.tableSearch.toLowerCase();
                    return this.tables.filter(t => t.name.toLowerCase().includes(search));
                },

                handleTableClick(name) {
                    clearTimeout(this._clickTimer);
                    this._clickTimer = setTimeout(() => this.insertTableName(name), 250);
                },

                handleTableDblClick(name) {
                    clearTimeout(this._clickTimer);
                    this.expandedTable = this.expandedTable === name ? null : name;
                },

                insertTableName(name) {
                    const el = this.$refs.queryInput;
                    if (!el) return;
                    this.activeTab = 'query';
                    el.value = 'SELECT * FROM ' + name + ' LIMIT 10';
                    el.focus();
                    el.closest('form').submit();
                },

                insertColumnName(name) {
                    const el = this.$refs.queryInput;
                    if (!el) return;
                    this.activeTab = 'query';
                    const start = el.selectionStart;
                    const end = el.selectionEnd;
                    const text = el.value;
                    el.value = text.substring(0, start) + name + text.substring(end);
                    el.selectionStart = el.selectionEnd = start + name.length;
                    el.focus();
                },
            };
        }
    </script>
</x-app-layout>
