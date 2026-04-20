<x-app-layout :activeSection="'system'" :activeHighlight="'commands'" :flush="true">
    <div class="flex flex-col h-full" x-data="commandRunner()">
        <x-hub-ui::live-header
            title="Commands"
            description="Run any artisan command from the browser. Arguments and options are introspected from the command signature."
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
                            <span
                                class="w-4 h-4 flex-shrink-0 flex items-center justify-center rounded"
                                :style="'background-color: rgb(var(--ui-primary) / 0.12); color: rgb(var(--ui-primary))'"
                                x-html="namespaceIcon(group.namespace)"
                            ></span>
                            <span class="text-[11px] font-semibold uppercase tracking-wider ui-text-muted" x-text="group.namespace || 'general'"></span>
                            <span class="text-[10px] ui-text-subtle ml-auto font-mono ui-tabular" x-text="group.commands.length"></span>
                        </button>

                        {{-- Commands under this namespace --}}
                        <div x-show="isGroupExpanded(group.namespace)" x-collapse>
                            <template x-for="cmd in group.commands" :key="cmd.name">
                                <button
                                    @click="selectCommand(cmd.name)"
                                    class="w-full flex items-center gap-2 pl-10 pr-3 py-1.5 text-left transition-colors hover:ui-bg-elevated relative"
                                    :class="selectedCommand === cmd.name ? 'ui-bg-elevated' : ''"
                                >
                                    <span
                                        x-show="selectedCommand === cmd.name"
                                        class="absolute left-0 top-1 bottom-1 w-[3px] rounded-r"
                                        style="background-color: rgb(var(--ui-primary))"
                                    ></span>
                                    <span class="text-xs truncate" :class="selectedCommand === cmd.name ? 'ui-text font-semibold' : 'ui-text-muted'" x-text="commandShortName(cmd.name)"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </x-hub-ui::secondary-sidebar>

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
            <div x-show="commandDetails && !loadingDetails" class="flex-1 overflow-y-auto" @keydown.window.ctrl.enter.prevent="commandDetails && executeCommand()" @keydown.window.meta.enter.prevent="commandDetails && executeCommand()">
                <div class="p-6 space-y-6 max-w-5xl">
                    {{-- Identity strip --}}
                    <div class="ui-card p-5">
                        <div class="flex items-center gap-2 font-mono text-[11px] ui-text-subtle">
                            <span class="ui-text-muted">$</span>
                            <span>php artisan</span>
                        </div>
                        <div class="flex items-baseline gap-3 mt-1 flex-wrap">
                            <code class="text-xl font-semibold font-mono tracking-tight" style="color: rgb(var(--ui-primary))" x-text="commandDetails?.name"></code>
                            <button
                                @click="copyCommandName()"
                                class="text-[10px] font-medium uppercase tracking-wider ui-text-subtle hover:ui-text transition-colors"
                                title="Copy command name"
                            >copy</button>
                        </div>
                        <p class="text-sm ui-text-muted mt-2" x-text="commandDetails?.description"></p>
                        <p x-show="commandDetails?.help && commandDetails?.help !== commandDetails?.description"
                           class="text-xs ui-text-subtle mt-2 font-mono" x-html="commandDetails?.help"></p>
                    </div>

                    {{-- Args + Options in 2-col on lg+ when both exist --}}
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {{-- Arguments --}}
                        <div x-show="commandDetails?.arguments?.length > 0">
                            <div class="flex items-center gap-2 mb-3">
                                <h3 class="text-[10px] font-semibold uppercase tracking-wider ui-text-muted">Arguments</h3>
                                <span class="text-[10px] ui-text-subtle font-mono" x-text="commandDetails?.arguments?.length"></span>
                            </div>
                            <div class="space-y-3">
                                <template x-for="arg in commandDetails?.arguments || []" :key="arg.name">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <label class="text-sm font-medium ui-text font-mono" x-text="arg.name"></label>
                                            <span x-show="arg.required"
                                                  class="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase tracking-wider ui-badge-danger">required</span>
                                        </div>
                                        <p x-show="arg.description" class="text-xs ui-text-subtle" x-text="arg.description"></p>
                                        <input
                                            type="text"
                                            class="w-full px-3 py-2 text-sm border rounded-lg ui-input font-mono"
                                            :placeholder="arg.default !== null ? String(arg.default) : 'value...'"
                                            x-model="argumentValues[arg.name]"
                                        />
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Options --}}
                        <div x-show="commandDetails?.options?.length > 0">
                            <div class="flex items-center gap-2 mb-3">
                                <h3 class="text-[10px] font-semibold uppercase tracking-wider ui-text-muted">Options</h3>
                                <span class="text-[10px] ui-text-subtle font-mono" x-text="commandDetails?.options?.length"></span>
                            </div>
                            <div class="space-y-3">
                                <template x-for="opt in commandDetails?.options || []" :key="opt.name">
                                    <div>
                                        <template x-if="!opt.accept_value">
                                            <label class="flex items-start gap-3 cursor-pointer">
                                                <input type="checkbox" class="rounded border ui-input mt-0.5" x-model="optionValues[opt.name]" />
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-sm font-medium ui-text font-mono" x-text="'--' + opt.name"></span>
                                                        <span x-show="opt.shortcut" class="text-xs ui-text-subtle font-mono" x-text="'-' + opt.shortcut"></span>
                                                    </div>
                                                    <p x-show="opt.description" class="text-xs ui-text-subtle" x-text="opt.description"></p>
                                                </div>
                                            </label>
                                        </template>
                                        <template x-if="opt.accept_value">
                                            <div class="space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <label class="text-sm font-medium ui-text font-mono" x-text="'--' + opt.name"></label>
                                                    <span x-show="opt.shortcut" class="text-xs ui-text-subtle font-mono" x-text="'-' + opt.shortcut"></span>
                                                    <span x-show="opt.value_required"
                                                          class="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase tracking-wider ui-badge-warning">required</span>
                                                </div>
                                                <p x-show="opt.description" class="text-xs ui-text-subtle" x-text="opt.description"></p>
                                                <input
                                                    type="text"
                                                    class="w-full px-3 py-2 text-sm border rounded-lg ui-input font-mono"
                                                    :placeholder="opt.default !== null && opt.default !== false ? String(opt.default) : 'value...'"
                                                    x-model="optionValues[opt.name]"
                                                />
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Execute bar --}}
                    <div class="flex items-center gap-4 pt-2 border-t ui-border pt-5">
                        <button @click="executeCommand()" :disabled="executing" class="ui-btn ui-btn-primary ui-btn-md">
                            <template x-if="executing">
                                <x-hub-ui::spinner size="sm" />
                            </template>
                            <template x-if="!executing">
                                <x-feathericon-play class="w-4 h-4" />
                            </template>
                            <span x-text="executing ? 'Executing…' : 'Execute'"></span>
                        </button>
                        <span class="flex items-center gap-1.5 text-xs ui-text-subtle">
                            <kbd class="ui-kbd">Ctrl</kbd>
                            <span>+</span>
                            <kbd class="ui-kbd">Enter</kbd>
                        </span>
                        <div class="ml-auto flex items-center gap-3" x-show="executionExitCode !== null || executionDuration !== null">
                            <span x-show="executionDuration !== null" class="text-[11px] ui-text-subtle font-mono ui-tabular">
                                <x-feathericon-clock class="inline w-3 h-3 mr-0.5" />
                                <span x-text="executionDuration + 'ms'"></span>
                            </span>
                            <span
                                x-show="executionExitCode !== null"
                                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider"
                                :class="executionExitCode === 0 ? 'ui-badge-success' : 'ui-badge-danger'"
                            >
                                <span x-text="executionExitCode === 0 ? '✓ success' : '✕ exit ' + executionExitCode"></span>
                            </span>
                        </div>
                    </div>

                    {{-- Error --}}
                    <template x-if="executionError">
                        <x-hub-ui::alert type="error">
                            <span class="font-mono text-xs" x-text="executionError"></span>
                        </x-hub-ui::alert>
                    </template>

                    {{-- Terminal output --}}
                    <div x-show="executionOutput !== null" class="ui-card overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-2 ui-bg-elevated border-b ui-border">
                            <div class="flex items-center gap-3 text-[11px] font-mono">
                                <span class="flex gap-1.5">
                                    <span class="w-2.5 h-2.5 rounded-full" style="background-color: rgb(var(--ui-danger))"></span>
                                    <span class="w-2.5 h-2.5 rounded-full" style="background-color: rgb(var(--ui-warning))"></span>
                                    <span class="w-2.5 h-2.5 rounded-full" style="background-color: rgb(var(--ui-success))"></span>
                                </span>
                                <span class="ui-text-muted">stdout</span>
                                <span class="ui-text-subtle" x-text="'· ' + (executionDuration !== null ? executionDuration + 'ms' : '')"></span>
                                <span class="ui-text-subtle" x-text="'· exit ' + (executionExitCode ?? '?')"></span>
                            </div>
                            <button @click="copyOutput()" class="text-[10px] font-medium uppercase tracking-wider ui-text-subtle hover:ui-text transition-colors">copy</button>
                        </div>
                        <div class="p-4 overflow-x-auto" style="background-color: rgb(var(--ui-bg-body))">
                            <pre class="text-xs ui-text font-mono whitespace-pre-wrap leading-relaxed" x-text="executionOutput || '(no output)'"></pre>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Empty state --}}
            <div x-show="!commandDetails && !loadingDetails" class="flex-1 flex items-center justify-center">
                <x-hub-ui::empty-state
                    title="Select a command"
                    description="Browse or search commands in the sidebar to view their details."
                >
                    <x-slot:icon>
                        <x-feathericon-terminal class="w-full h-full" />
                    </x-slot:icon>
                </x-hub-ui::empty-state>
            </div>
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
                sidebarWidth: 288,

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

                commandShortName(fullName) {
                    // Strip 'kraite:' prefix and known group prefix for compact display
                    const knownGroups = ['cron', 'debug', 'ingestion'];
                    let short = fullName.replace(/^kraite:/, '');
                    for (const g of knownGroups) {
                        if (short.startsWith(g + '-')) return short.slice(g.length + 1);
                    }
                    return short;
                },

                namespaceIcon(ns) {
                    // Feather-style inline SVG paths, rendered as 12px icons in the sidebar
                    const svg = (body) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3">' + body + '</svg>';
                    const icons = {
                        cron:      svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'),
                        debug:     svg('<rect x="8" y="6" width="8" height="14" rx="4"/><path d="M19 7l-3 2M5 7l3 2M19 13h-3M5 13h3M19 19l-3-2M5 19l3-2M12 6V3"/>'),
                        ingestion: svg('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'),
                        general:   svg('<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>'),
                    };
                    return icons[ns] || icons.general;
                },

                async copyCommandName() {
                    if (!this.commandDetails) return;
                    await navigator.clipboard.writeText('php artisan ' + this.commandDetails.name);
                    window.showToast('Command copied', 'success', 1500);
                },

                async copyOutput() {
                    if (!this.executionOutput) return;
                    await navigator.clipboard.writeText(this.executionOutput);
                    window.showToast('Output copied', 'success', 1500);
                },
            };
        }
    </script>
</x-app-layout>
