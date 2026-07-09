<x-app-layout active="engine" :title="'Kraite — Engine'">
    <script>
        // Engine page state. Gauges seed server-side and re-poll every 15s;
        // fleet pivots come from the existing per-prefix steps feed and only
        // the ACTIVE tab polls; the failures tab refreshes on activation and
        // after every triage action.
        window.enginePage = (urls, initial) => ({
            gauges: initial.gauges,
            failures: initial.failures,
            tab: 'default',                    // 'default' | 'trading' | 'failures'
            fleet: { default: null, trading: null },
            fleetLoading: false,
            failuresLoading: false,
            busyClass: null,                   // class currently being AI-triaged
            verdictFor: null,                  // failure row shown in the verdict popup
            toast: null,
            _timer: null,
            _toastTimer: null,

            STATES: ['Pending', 'Throttled', 'Dispatched', 'Running', 'Completed', 'Skipped', 'NotRunnable', 'Failed', 'Cancelled', 'Stopped'],

            init() {
                this.loadFleet(this.tab);
                this._timer = setInterval(() => this.tick(), 10000);
            },
            destroy() {
                if (this._timer) { clearInterval(this._timer); this._timer = null; }
            },
            async tick() {
                const res = await hubUiFetch(urls.data);
                if (res.ok) this.gauges = res.data.gauges;
                if (this.tab === 'failures') this.loadFailures();
                else this.loadFleet(this.tab);
            },

            switchTab(tab) {
                this.tab = tab;
                if (tab === 'failures') this.loadFailures();
                else this.loadFleet(tab);
            },
            async loadFleet(prefix) {
                if (this.fleetLoading) return;
                this.fleetLoading = true;
                try {
                    const res = await hubUiFetch(urls.fleet[prefix]);
                    if (res.ok) this.fleet[prefix] = res.data;
                } finally {
                    this.fleetLoading = false;
                }
            },
            async loadFailures() {
                if (this.failuresLoading) return;
                this.failuresLoading = true;
                try {
                    const res = await hubUiFetch(urls.failures);
                    if (res.ok) this.failures = res.data.failures;
                } finally {
                    this.failuresLoading = false;
                }
            },

            // ---------- triage actions ----------
            async troubleshoot(row) {
                if (this.busyClass) return;
                this.busyClass = row.class;
                try {
                    const res = await hubUiFetch(urls.troubleshoot, { body: { class: row.class } });
                    if (res.ok) {
                        row.verdict = res.data.verdict;
                        this.verdictFor = row;
                    } else {
                        this.flash(res.data.error || 'Troubleshoot failed', 'error');
                    }
                } finally {
                    this.busyClass = null;
                }
            },
            async resolve(row) {
                const res = await hubUiFetch(urls.resolve, { body: { class: row.class } });
                if (res.ok) {
                    this.failures = this.failures.filter((f) => f.class !== row.class);
                    if (this.verdictFor && this.verdictFor.class === row.class) this.verdictFor = null;
                    this.flash(`Resolved ${res.data.resolved} occurrence${res.data.resolved === 1 ? '' : 's'}`, 'ok');
                } else {
                    this.flash(res.data.error || 'Resolve failed', 'error');
                }
            },
            flash(text, kind) {
                this.toast = { text, kind };
                if (this._toastTimer) clearTimeout(this._toastTimer);
                this._toastTimer = setTimeout(() => { this.toast = null; }, 2600);
            },

            // ---------- formatting ----------
            fmt(v) {
                return (v === null || v === undefined) ? '—' : Number(v).toLocaleString('en-US');
            },
            trendMeta() {
                const d = this.gauges.combined?.flow_delta_10m;
                if (d === null || d === undefined) return { label: 'NO DATA', color: 'var(--fg-mute)' };
                if (d > 0) return { label: `GROWING +${this.fmt(d)} / 10M`, color: 'var(--danger)' };
                if (d < 0) return { label: `DRAINING ${this.fmt(d)} / 10M`, color: 'var(--pnl-up-fg)' };
                return { label: 'FLAT / 10M', color: 'var(--fg-mute)' };
            },
            fleetSub(key) {
                const f = this.gauges.fleets || {};
                return `CALC ${this.fmt(f.default?.[key])} · TRADING ${this.fmt(f.trading?.[key])}`;
            },
            stateCount(row, state) {
                return row.states[state] ?? 0;
            },
            rowTotal(row) {
                return Object.values(row.states).reduce((a, b) => a + b, 0);
            },
        });
    </script>

    <div x-data="enginePage(@js([
            'data' => route('system.engine.data'),
            'failures' => route('system.engine.failures'),
            'troubleshoot' => route('system.engine.troubleshoot'),
            'resolve' => route('system.engine.resolve'),
            'fleet' => [
                'default' => route('system.steps.data', 'default'),
                'trading' => route('system.steps.data', 'trading'),
            ],
        ]), @js($engine))">

        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-cpu class="w-[13px] h-[13px]" stroke-width="1.75"/>PLATFORM
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Engine</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Step-dispatcher performance — backlog, throughput, and failure triage across both fleets.</div>
            </div>
            <button type="button" @click="tick()"
                    class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out h-[36px] px-3.5 text-[13px] bg-transparent text-fg-1 border-line-strong hover:bg-hover flex-shrink-0">
                <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/>Sync
            </button>
        </div>

        {{-- ===================== HEALTH STRIP ===================== --}}
        <div class="grid grid-cols-4 gap-3 mb-6 max-[1100px]:grid-cols-2 max-[560px]:grid-cols-1">
            {{-- total processing — leaf steps genuinely Running right now --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]">
                    <x-feathericon-activity class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Total processing
                </span>
                <div class="min-h-[60px] flex items-center gap-2">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] leading-none"
                          :style="`color: ${(gauges.combined?.processing ?? 0) > 0 ? 'var(--pnl-up-fg)' : 'var(--fg-1)'}`"
                          x-text="fmt(gauges.combined?.processing)"></span>
                    <span class="font-mono text-[11px] text-fg-mute">running</span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute" x-text="fleetSub('processing')"></span>
            </div>

            {{-- chew rate --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]">
                        <x-feathericon-zap class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Chew rate
                    </span>
                    {{-- Demonstrated capacity: a low current rate with a healthy
                         peak means "idle", not "sick". --}}
                    <span class="font-mono text-[9px] font-bold tabular-nums py-0.5 px-1.5 rounded-chip whitespace-nowrap"
                          style="color: var(--info, var(--fg-mute)); background: color-mix(in srgb, currentColor 13%, transparent)"
                          x-text="`PEAK ${fmt(gauges.combined?.peak_per_min_1h)} / MIN · 1H`"></span>
                </div>
                <div class="min-h-[60px] flex items-center gap-2">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="fmt(gauges.combined?.chew_per_min)"></span>
                    <span class="font-mono text-[11px] text-fg-mute">steps/min</span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute"
                      x-text="`${fleetSub('chew_per_min')} · PEAK ${fmt(gauges.fleets?.default?.peak_per_min_1h)} / ${fmt(gauges.fleets?.trading?.peak_per_min_1h)}`"></span>
            </div>

            {{-- backlog --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]">
                        <x-feathericon-layers class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Backlog
                    </span>
                    <span class="font-mono text-[9px] font-bold tabular-nums py-0.5 px-1.5 rounded-chip whitespace-nowrap"
                          :style="`color: ${trendMeta().color}; background: color-mix(in srgb, ${trendMeta().color} 13%, transparent)`"
                          x-text="trendMeta().label"></span>
                </div>
                <div class="min-h-[60px] flex items-center gap-2">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="fmt(gauges.combined?.pending)"></span>
                    <span class="font-mono text-[11px] text-fg-mute">pending</span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute" x-text="fleetSub('pending')"></span>
            </div>

            {{-- failures --}}
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px]">
                <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute flex items-center gap-[7px]">
                    <x-feathericon-alert-triangle class="w-3.5 h-3.5 text-fg-3" stroke-width="1.75"/>Failures
                </span>
                <div class="min-h-[60px] flex items-center">
                    <span class="font-mono text-[26px] font-bold tabular-nums tracking-[-0.01em] leading-none"
                          :style="`color: ${(gauges.combined?.failures ?? 0) > 0 ? 'var(--danger)' : 'var(--pnl-up-fg)'}`"
                          x-text="fmt(gauges.combined?.failures)"></span>
                </div>
                <span class="mt-auto font-mono text-[9.5px] tracking-[0.08em] uppercase text-fg-mute">Non-recovered · unresolved</span>
            </div>
        </div>

        {{-- ===================== TABS ===================== --}}
        <div class="flex items-center gap-1 mb-4 border-b border-line overflow-x-auto">
            <template x-for="t in [['default','Calculation'],['trading','Trading'],['failures','Failures']]" :key="t[0]">
                <button type="button" @click="switchTab(t[0])"
                        :class="tab === t[0] ? 'text-fg-1 border-accent' : 'text-fg-3 border-transparent hover:text-fg-1'"
                        class="appearance-none bg-transparent cursor-pointer font-mono text-[12px] font-semibold tracking-[0.05em] uppercase px-4 h-[42px] inline-flex items-center gap-2 border-0 border-b-2 whitespace-nowrap transition-colors duration-fast"
                        style="border-bottom-style: solid">
                    <span x-text="t[1]"></span>
                    <span x-show="t[0] === 'failures' && failures.length"
                          class="font-mono text-[10px] font-bold tabular-nums py-px px-1.5 rounded-chip"
                          style="color: var(--danger); background: color-mix(in srgb, var(--danger) 14%, transparent)"
                          x-text="failures.length"></span>
                </button>
            </template>
        </div>

        {{-- ===================== FLEET PIVOTS ===================== --}}
        <template x-for="prefix in ['default', 'trading']" :key="prefix">
            <div x-show="tab === prefix" class="card card--flat overflow-hidden">
                <div class="flex items-center justify-between gap-3 py-[13px] px-5 bg-surface-2 rounded-t-surface border-b border-line-soft max-[640px]:px-4">
                    <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                        <x-feathericon-git-branch class="w-4 h-4 text-accent" stroke-width="1.75"/>
                        <span x-text="prefix === 'default' ? 'Calculation fleet' : 'Trading fleet'"></span>
                    </h4>
                    <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                          x-text="fleet[prefix] ? `${fleet[prefix].rows.length} classes` : 'loading…'"></span>
                </div>
                <div x-show="!fleet[prefix]" class="py-10 text-center font-mono text-[11px] text-fg-mute">Loading fleet…</div>
                <div x-show="fleet[prefix]" class="overflow-x-auto">
                    <table class="w-full border-collapse min-w-[880px]">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="text-left py-2 px-4 font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">Step class</th>
                                <template x-for="s in STATES" :key="s">
                                    <th class="text-left py-2 px-2 font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint" x-text="s"></th>
                                </template>
                                <th class="text-left py-2 px-3 font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in (fleet[prefix]?.rows || [])" :key="row.class">
                                <tr class="border-b border-line-soft align-middle hover:bg-hover transition-colors duration-fast">
                                    <td class="py-2 px-4">
                                        <span class="font-mono text-[11.5px] font-semibold text-fg-1 whitespace-nowrap" x-text="row.short_name"></span>
                                        <span x-show="row.is_parent" class="ml-1.5 font-mono text-[8px] font-bold tracking-[0.06em] uppercase py-px px-1 rounded-chip text-fg-mute bg-surface-3">block</span>
                                    </td>
                                    <template x-for="s in STATES" :key="s">
                                        <td class="py-2 px-2 font-mono text-[11.5px] tabular-nums"
                                            :class="stateCount(row, s) === 0 ? 'text-fg-faint' : (s === 'Failed' ? 'font-bold' : 'text-fg-2')"
                                            :style="s === 'Failed' && stateCount(row, s) > 0 ? 'color: var(--danger)' : ''"
                                            x-text="stateCount(row, s) || '·'"></td>
                                    </template>
                                    <td class="py-2 px-3 font-mono text-[11.5px] font-bold tabular-nums text-fg-1" x-text="fmt(rowTotal(row))"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="py-2.5 px-4 font-mono text-[10px] font-bold tracking-[0.08em] uppercase text-fg-3">Totals</td>
                                <template x-for="s in STATES" :key="s">
                                    <td class="py-2.5 px-2 font-mono text-[11.5px] font-bold tabular-nums"
                                        :class="(fleet[prefix]?.totals?.[s] ?? 0) === 0 ? 'text-fg-faint' : 'text-fg-1'"
                                        :style="s === 'Failed' && (fleet[prefix]?.totals?.[s] ?? 0) > 0 ? 'color: var(--danger)' : ''"
                                        x-text="fleet[prefix]?.totals?.[s] ?? '·'"></td>
                                </template>
                                <td class="py-2.5 px-3 font-mono text-[11.5px] font-bold tabular-nums text-fg-1"
                                    x-text="fmt(Object.values(fleet[prefix]?.totals || {}).reduce((a, b) => a + b, 0))"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </template>

        {{-- ===================== FAILURES ===================== --}}
        <div x-show="tab === 'failures'" class="card card--flat overflow-hidden">
            <x-ui.card-head icon="alert-triangle" title="Failures — last 2 weeks" :accent="true">
                <x-slot:right>
                    <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                          x-text="`${failures.length} class${failures.length === 1 ? '' : 'es'} · grouped · non-recovered`"></span>
                </x-slot:right>
            </x-ui.card-head>

            <div x-show="failures.length === 0" class="py-12 text-center">
                <div class="font-sans text-[14px] font-semibold text-fg-1 mb-1">Nothing to triage.</div>
                <div class="font-mono text-[11px] text-fg-mute">No unresolved, non-recovered failures in the last two weeks.</div>
            </div>

            <template x-for="row in failures" :key="row.class">
                <div class="flex items-start gap-4 py-3.5 px-5 border-b border-line-soft last:border-b-0 max-[640px]:flex-col max-[640px]:gap-2.5 max-[640px]:px-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <span class="font-mono text-[12.5px] font-semibold text-fg-1" x-text="row.short_name"></span>
                            <span class="font-mono text-[10px] font-bold tabular-nums py-px px-1.5 rounded-chip"
                                  style="color: var(--danger); background: color-mix(in srgb, var(--danger) 14%, transparent)"
                                  x-text="`×${row.occurrences}`"></span>
                            <span class="font-mono text-[10px] text-fg-mute tabular-nums" x-text="`last ${row.age} ago`"></span>
                        </div>
                        <div class="font-mono text-[10px] text-fg-mute tracking-[0.01em] mt-0.5 break-all" x-text="row.class"></div>
                        <div x-show="row.error_snippet" class="font-mono text-[11px] text-fg-3 mt-1.5 leading-snug" x-text="row.error_snippet"></div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap">
                        <button type="button" @click="troubleshoot(row)" :disabled="busyClass !== null"
                                class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out h-[38px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover disabled:opacity-50 max-[640px]:flex-1 max-[640px]:justify-center">
                            <template x-if="busyClass === row.class"><span class="w-[13px] h-[13px] rounded-full border-2 border-current border-t-transparent animate-spin"></span></template>
                            <template x-if="busyClass !== row.class"><x-feathericon-zap class="w-[13px] h-[13px]" stroke-width="1.75"/></template>
                            <span x-text="busyClass === row.class ? 'Analysing…' : 'Troubleshoot with AI'"></span>
                        </button>
                        <button type="button" x-show="row.verdict" @click="verdictFor = row"
                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[6px] whitespace-nowrap transition-colors duration-fast ease-out h-[38px] px-2.5 text-[12px] bg-transparent hover:bg-hover max-[640px]:flex-1 max-[640px]:justify-center"
                                style="color: var(--accent)">
                            <x-feathericon-file-text class="w-[13px] h-[13px]" stroke-width="1.75"/>Verdict
                        </button>
                        <button type="button" @click="resolve(row)"
                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[6px] whitespace-nowrap transition-colors duration-fast ease-out h-[38px] px-2.5 text-[12px] bg-transparent text-fg-3 hover:bg-hover hover:text-fg-1 max-[640px]:flex-1 max-[640px]:justify-center">
                            <x-feathericon-check class="w-[13px] h-[13px]" stroke-width="1.75"/>Resolve
                        </button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ===================== VERDICT POPUP ===================== --}}
        <div x-show="verdictFor" x-cloak
             @keydown.escape.window="verdictFor = null"
             class="fixed inset-0 z-[80] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-[2px]" @click="verdictFor = null"></div>
            <div class="relative bg-surface border border-line-strong rounded-surface shadow-3 w-full max-w-[560px] max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between gap-3 py-3.5 px-5 border-b border-line-soft">
                    <div class="min-w-0">
                        <div class="font-sans text-[14px] font-bold text-fg-1">AI verdict</div>
                        <div class="font-mono text-[10px] text-fg-mute break-all" x-text="verdictFor?.class"></div>
                    </div>
                    <button type="button" @click="verdictFor = null"
                            class="appearance-none bg-transparent border-0 cursor-pointer text-fg-3 hover:text-fg-1 p-1">
                        <x-feathericon-x class="w-[18px] h-[18px]" stroke-width="1.75"/>
                    </button>
                </div>
                <div class="p-5 overflow-y-auto text-[13px] text-fg-2 leading-relaxed whitespace-pre-wrap" x-text="verdictFor?.verdict"></div>
                <div class="flex items-center justify-end gap-2 py-3 px-5 border-t border-line-soft">
                    <button type="button" @click="verdictFor = null"
                            class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-3 hover:bg-hover hover:text-fg-1 h-[34px] px-3 text-[12.5px]">Close</button>
                    <button type="button" @click="resolve(verdictFor)"
                            class="appearance-none font-sans font-semibold rounded-control border-0 cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out text-white hover:opacity-90 h-[34px] px-3.5 text-[12.5px]"
                            style="background: var(--pnl-up-fg)">
                        <x-feathericon-check class="w-[14px] h-[14px]" stroke-width="1.75"/>Mark resolved
                    </button>
                </div>
            </div>
        </div>

        {{-- ===================== TOAST ===================== --}}
        <div x-show="toast" x-cloak
             class="fixed bottom-5 left-1/2 -translate-x-1/2 z-[90] font-mono text-[12px] font-semibold py-2.5 px-4 rounded-control border shadow-3"
             :style="`color: ${toast?.kind === 'error' ? 'var(--danger)' : 'var(--pnl-up-fg)'}; background: var(--bg-elev-3); border-color: color-mix(in srgb, ${toast?.kind === 'error' ? 'var(--danger)' : 'var(--pnl-up-fg)'} 40%, transparent)`"
             x-text="toast?.text"></div>
    </div>
</x-app-layout>
