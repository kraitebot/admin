<x-app-layout :activeSection="'system'" :activeHighlight="'commands'" :flush="true">
    <div class="flex h-full" x-data="commandRunner()">
        {{-- Secondary Sidebar: Command Browser --}}
        <div class="w-72 flex-shrink-0 border-r ui-border overflow-hidden flex flex-col" style="background-color: rgb(var(--ui-bg-sidebar))">
            {{-- Search --}}
            <div class="p-3 border-b ui-border">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        type="text"
                        x-model="commandSearch"
                        placeholder="Filter commands..."
                        class="w-full pl-9 pr-3 py-2 text-xs rounded-lg border ui-input"
                        x-init="$nextTick(() => $el.focus())"
                        @keydown.enter.prevent="if (filteredCommands.length === 1) selectCommand(filteredCommands[0].name)"
                    />
                </div>
            </div>

            {{-- Command List --}}
            <div class="flex-1 overflow-y-auto py-2 ui-scrollbar">
                <div class="px-3 pb-1 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Commands</span>
                        <span class="text-[10px] ui-text-subtle ml-1" x-text="'(' + filteredCommands.length + ')'"></span>
                    </div>
                </div>

                <template x-for="group in groupedCommands" :key="group.namespace">
                    <div>
                        {{-- Namespace header --}}
                        <button
                            @click="toggleGroup(group.namespace)"
                            class="w-full flex items-center gap-2 px-3 py-1.5 text-left transition-colors hover:ui-bg-elevated"
                        >
                            <svg class="w-3 h-3 ui-text-subtle transition-transform" :class="isGroupExpanded(group.namespace) ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                            <span class="text-xs font-semibold ui-text-muted" x-text="group.namespace || 'general'"></span>
                            <span class="text-[10px] ui-text-subtle ml-auto" x-text="group.commands.length"></span>
                        </button>

                        {{-- Commands under this namespace --}}
                        <div x-show="isGroupExpanded(group.namespace)" x-collapse>
                            <template x-for="cmd in group.commands" :key="cmd.name">
                                <button
                                    @click="selectCommand(cmd.name)"
                                    class="w-full flex items-center gap-2 pl-7 pr-3 py-1.5 text-left transition-colors hover:ui-bg-elevated"
                                    :class="selectedCommand === cmd.name ? 'ui-bg-elevated' : ''"
                                >
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" :style="selectedCommand === cmd.name ? 'color: rgb(var(--ui-primary))' : ''" :class="selectedCommand !== cmd.name ? 'ui-text-subtle' : ''" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m5.25 4.5 7.5 7.5-7.5 7.5m6-15 7.5 7.5-7.5 7.5" />
                                    </svg>
                                    <span class="text-xs ui-text truncate" x-text="cmd.name"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Main Content Area --}}
        <div class="flex-1 flex flex-col overflow-hidden">
            {{-- Loading state --}}
            <div x-show="loadingDetails" class="flex-1 flex items-center justify-center">
                <svg class="w-6 h-6 animate-spin ui-text-subtle" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>

            {{-- Command Details --}}
            <div x-show="commandDetails && !loadingDetails" class="flex-1 overflow-y-auto">
                <div class="p-6 space-y-6">
                    {{-- Header --}}
                    <div>
                        <div class="flex items-center gap-3">
                            <code class="text-base font-semibold ui-text font-mono" x-text="commandDetails?.name"></code>
                        </div>
                        <p class="text-sm ui-text-muted mt-1" x-text="commandDetails?.description"></p>
                        <p x-show="commandDetails?.help && commandDetails?.help !== commandDetails?.description"
                           class="text-xs ui-text-subtle mt-2" x-html="commandDetails?.help"></p>
                    </div>

                    {{-- Arguments --}}
                    <div x-show="commandDetails?.arguments?.length > 0">
                        <h3 class="text-xs font-semibold uppercase tracking-wider ui-text-muted mb-3">Arguments</h3>
                        <div class="space-y-3">
                            <template x-for="arg in commandDetails?.arguments || []" :key="arg.name">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <label class="text-sm font-medium ui-text font-mono" x-text="arg.name"></label>
                                        <span x-show="arg.required"
                                              class="text-[10px] px-1.5 py-0.5 rounded font-medium bg-red-100 text-red-700">required</span>
                                    </div>
                                    <p x-show="arg.description" class="text-xs ui-text-subtle" x-text="arg.description"></p>
                                    <input
                                        type="text"
                                        class="w-full max-w-md px-3 py-2 text-sm border rounded-lg ui-input font-mono"
                                        :placeholder="arg.default !== null ? String(arg.default) : ''"
                                        x-model="argumentValues[arg.name]"
                                    />
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Options --}}
                    <div x-show="commandDetails?.options?.length > 0">
                        <h3 class="text-xs font-semibold uppercase tracking-wider ui-text-muted mb-3">Options</h3>
                        <div class="space-y-3">
                            <template x-for="opt in commandDetails?.options || []" :key="opt.name">
                                <div>
                                    {{-- Boolean flag --}}
                                    <template x-if="!opt.accept_value">
                                        <label class="flex items-start gap-3 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                class="rounded border ui-input mt-0.5"
                                                x-model="optionValues[opt.name]"
                                            />
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-medium ui-text font-mono" x-text="'--' + opt.name"></span>
                                                    <span x-show="opt.shortcut" class="text-xs ui-text-subtle font-mono" x-text="'-' + opt.shortcut"></span>
                                                </div>
                                                <p x-show="opt.description" class="text-xs ui-text-subtle" x-text="opt.description"></p>
                                            </div>
                                        </label>
                                    </template>
                                    {{-- Value option --}}
                                    <template x-if="opt.accept_value">
                                        <div class="space-y-1">
                                            <div class="flex items-center gap-2">
                                                <label class="text-sm font-medium ui-text font-mono" x-text="'--' + opt.name"></label>
                                                <span x-show="opt.shortcut" class="text-xs ui-text-subtle font-mono" x-text="'-' + opt.shortcut"></span>
                                                <span x-show="opt.value_required"
                                                      class="text-[10px] px-1.5 py-0.5 rounded font-medium bg-amber-100 text-amber-700">value required</span>
                                            </div>
                                            <p x-show="opt.description" class="text-xs ui-text-subtle" x-text="opt.description"></p>
                                            <input
                                                type="text"
                                                class="w-full max-w-md px-3 py-2 text-sm border rounded-lg ui-input font-mono"
                                                :placeholder="opt.default !== null && opt.default !== false ? String(opt.default) : ''"
                                                x-model="optionValues[opt.name]"
                                            />
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Execute Button --}}
                    <div class="flex items-center gap-3 pt-2">
                        <button @click="executeCommand()" :disabled="executing" class="ui-btn ui-btn-primary ui-btn-sm">
                            <template x-if="executing">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </template>
                            <template x-if="!executing">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" />
                                </svg>
                            </template>
                            <span x-text="executing ? 'Executing...' : 'Execute'"></span>
                        </button>
                        <span x-show="executionDuration !== null" class="text-xs ui-text-subtle" x-text="executionDuration + 'ms'"></span>
                        <span x-show="executionExitCode !== null && executionExitCode === 0"
                              class="text-xs text-green-600 font-medium">Exit code: 0</span>
                        <span x-show="executionExitCode !== null && executionExitCode !== 0"
                              class="text-xs text-red-600 font-medium" x-text="'Exit code: ' + executionExitCode"></span>
                    </div>

                    {{-- Error --}}
                    <template x-if="executionError">
                        <div class="rounded-md border p-4 ui-alert-error">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm" x-text="executionError"></p>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Output --}}
                    <div x-show="executionOutput !== null">
                        <h3 class="text-xs font-semibold uppercase tracking-wider ui-text-muted mb-2">Output</h3>
                        <div class="rounded-lg border ui-border overflow-hidden">
                            <div class="p-4 overflow-x-auto" style="background-color: rgb(var(--ui-bg-elevated))">
                                <pre class="text-xs ui-text font-mono whitespace-pre-wrap" x-text="executionOutput"></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Empty state --}}
            <div x-show="!commandDetails && !loadingDetails" class="flex-1 flex flex-col items-center justify-center py-16 text-center">
                <div class="w-14 h-14 rounded-full flex items-center justify-center mb-3 ui-bg-elevated">
                    <svg class="w-7 h-7 ui-text-subtle" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </div>
                <p class="text-sm ui-text-muted">Select a command to view its details.</p>
                <p class="text-xs ui-text-subtle mt-1">Browse or search commands in the sidebar.</p>
            </div>
        </div>
    </div>

    <script>
        function commandRunner() {
            return {
                commands: @json($commands),
                commandSearch: '',
                selectedCommand: null,
                commandDetails: null,
                loadingDetails: false,
                expandedGroups: {},

                executing: false,
                executionOutput: null,
                executionError: null,
                executionExitCode: null,
                executionDuration: null,

                argumentValues: {},
                optionValues: {},

                get filteredCommands() {
                    if (!this.commandSearch) return this.commands;
                    const search = this.commandSearch.toLowerCase();
                    return this.commands.filter(c => c.name.toLowerCase().includes(search));
                },

                get groupedCommands() {
                    const knownGroups = ['cron', 'debug', 'ingestion'];
                    const groups = {};
                    for (const cmd of this.filteredCommands) {
                        const suffix = cmd.name.replace('kraite:', '');
                        const group = knownGroups.find(g => suffix.startsWith(g + '-')) || 'general';
                        if (!groups[group]) groups[group] = [];
                        groups[group].push(cmd);
                    }
                    return Object.keys(groups).sort((a, b) => {
                        if (a === 'general') return 1;
                        if (b === 'general') return -1;
                        return a.localeCompare(b);
                    }).map(ns => ({ namespace: ns, commands: groups[ns] }));
                },

                toggleGroup(ns) {
                    this.expandedGroups[ns] = !this.expandedGroups[ns];
                },

                isGroupExpanded(ns) {
                    return this.commandSearch.length > 0 || !!this.expandedGroups[ns];
                },

                async selectCommand(name) {
                    if (this.selectedCommand === name) return;
                    this.selectedCommand = name;
                    this.loadingDetails = true;
                    this.commandDetails = null;
                    this.executionOutput = null;
                    this.executionError = null;
                    this.executionExitCode = null;
                    this.executionDuration = null;
                    this.argumentValues = {};
                    this.optionValues = {};

                    const { ok, data } = await hubUiFetch(
                        '{{ route("system.commands.details") }}?command=' + encodeURIComponent(name),
                        { method: 'GET' }
                    );

                    if (ok) {
                        this.commandDetails = data;
                        for (const arg of data.arguments || []) {
                            if (arg.default !== null) {
                                this.argumentValues[arg.name] = String(arg.default);
                            }
                        }
                        for (const opt of data.options || []) {
                            if (!opt.accept_value) {
                                this.optionValues[opt.name] = opt.default === true;
                            } else if (opt.default !== null && opt.default !== false) {
                                this.optionValues[opt.name] = String(opt.default);
                            }
                        }
                    }

                    this.loadingDetails = false;
                },

                async executeCommand() {
                    if (!this.commandDetails || this.executing) return;

                    this.executing = true;
                    this.executionOutput = null;
                    this.executionError = null;
                    this.executionExitCode = null;
                    this.executionDuration = null;

                    const args = {};
                    for (const arg of this.commandDetails.arguments || []) {
                        const val = this.argumentValues[arg.name];
                        if (val !== undefined && val !== '') {
                            args[arg.name] = val;
                        }
                    }

                    const opts = {};
                    for (const opt of this.commandDetails.options || []) {
                        const val = this.optionValues[opt.name];
                        if (!opt.accept_value) {
                            if (val) opts[opt.name] = true;
                        } else {
                            if (val !== undefined && val !== '') {
                                opts[opt.name] = val;
                            }
                        }
                    }

                    const { ok, data } = await hubUiFetch('{{ route("system.commands.execute") }}', {
                        body: {
                            command: this.commandDetails.name,
                            arguments: args,
                            options: opts,
                        },
                    });

                    if (ok) {
                        this.executionOutput = data.output || '(no output)';
                        this.executionExitCode = data.exit_code;
                        this.executionDuration = data.duration;
                    } else {
                        this.executionError = data.error || data.message || 'An error occurred.';
                    }

                    this.executing = false;
                },
            };
        }
    </script>
</x-app-layout>
