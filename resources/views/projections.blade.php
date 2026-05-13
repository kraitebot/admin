<x-app-layout :activeHighlight="'projections'">
    <div x-data="projectionsPage()" x-init="init()" class="max-w-4xl">

        <x-hub-ui::page-header
            title="Projections"
            description="Day-by-day realised revenue and forward projections — best / worst / midpoint compounded daily from this month's observed range."
        />

        {{-- Account picker — hidden when a non-admin owns exactly one
             account (auto-selected, dropdown is noise). Sysadmin always
             sees it for cross-user picking. --}}
        <div x-show="isAdmin || accounts.length > 1" x-cloak class="mb-5 flex items-end gap-4 flex-wrap">
            <div class="flex-1 min-w-0 sm:min-w-[280px] max-w-md w-full">
                <label class="block text-[10px] font-semibold uppercase tracking-[0.12em] ui-text-subtle mb-2">Account</label>
                <x-hub-ui::select
                    name="account_id"
                    x-model="selectedAccountId"
                    @change="reloadMonth()"
                    class="w-full"
                >
                    <option value="">— Select an account —</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account['id'] }}">
                            {{ $account['name'] }} · {{ $account['exchange'] }} · {{ $account['owner'] }}
                        </option>
                    @endforeach
                </x-hub-ui::select>
            </div>
        </div>

        {{-- Calendar surface --}}
        <div x-show="selectedAccountId" x-cloak class="ui-card p-5">

            {{-- Header: month label + nav + scenario switcher --}}
            <div class="flex items-center justify-between gap-4 flex-wrap mb-4 pb-3 border-b ui-border-light">
                <div class="flex items-center gap-2">
                    <button type="button" @click="step(-1)" class="p-1.5 rounded-md ui-text-muted hover:ui-text transition-colors" title="Previous month">
                        <x-feathericon-chevron-left class="w-5 h-5" />
                    </button>

                    {{-- Click the month label to open a future-only month/year jumper.
                         Popover state lives on the outer projectionsPage scope to
                         keep $root resolution simple. --}}
                    <div class="relative" @click.outside="jumperOpen = false">
                        <button type="button"
                                @click="jumperOpen = !jumperOpen"
                                class="flex items-center gap-1 min-w-[160px] justify-center text-base font-semibold ui-text hover:ui-text-primary transition-colors"
                                title="Jump to a future month"
                        >
                            <span x-text="monthLabel"></span>
                            <svg class="w-3.5 h-3.5 transition-transform ui-text-muted"
                                 :class="jumperOpen ? 'rotate-180' : ''"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div x-show="jumperOpen" x-cloak
                             class="absolute top-full left-1/2 -translate-x-1/2 mt-2 z-30 ui-card p-3 flex items-center gap-2 shadow-lg"
                        >
                            {{-- Static options — <template x-for> inside a native
                                 <select> gets stripped by the HTML parser before
                                 Alpine runs, leaving an empty dropdown. Server-
                                 render the option set instead. --}}
                            @php
                                $thisYear = (int) now()->year;
                                $thisMonth = (int) now()->month;
                                $monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                            @endphp
                            <select x-model.number="jumpMonth"
                                    class="w-28 px-3 py-1.5 text-xs rounded-md border ui-input cursor-pointer">
                                @foreach ($monthNames as $i => $name)
                                    <option value="{{ $i + 1 }}"
                                            :disabled="jumpYear === {{ $thisYear }} && {{ $i + 1 }} < {{ $thisMonth }}">
                                        {{ $name }}
                                    </option>
                                @endforeach
                            </select>
                            <select x-model.number="jumpYear"
                                    @change="clampJumpMonth()"
                                    class="w-24 px-3 py-1.5 text-xs rounded-md border ui-input cursor-pointer">
                                @for ($y = $thisYear; $y <= $thisYear + 6; $y++)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                            <button type="button"
                                    @click="jumpTo(jumpYear, jumpMonth); jumperOpen = false"
                                    class="ui-btn ui-btn-primary ui-btn-sm">
                                Go
                            </button>
                        </div>
                    </div>

                    <button type="button" @click="step(1)" class="p-1.5 rounded-md ui-text-muted hover:ui-text transition-colors" title="Next month">
                        <x-feathericon-chevron-right class="w-5 h-5" />
                    </button>
                    <button type="button"
                            x-show="!isCurrentMonth"
                            @click="goToToday()"
                            class="ml-1 px-2.5 py-1 text-[11px] font-medium rounded-md ui-text-muted hover:ui-text transition-colors border ui-border"
                            title="Jump to today's month"
                    >
                        Today
                    </button>
                </div>

                {{-- Scenario switcher — only meaningful when projection cells exist (today or future months). --}}
                <div x-show="hasProjection" x-cloak class="flex items-center gap-1 p-1 rounded-lg ui-bg-elevated">
                    <template x-for="opt in scenarioOptions" :key="opt.key">
                        <button
                            type="button"
                            @click="scenario = opt.key"
                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors"
                            :class="scenario === opt.key ? 'ui-text' : 'ui-text-subtle hover:ui-text-muted'"
                            :style="scenario === opt.key ? `background-color: rgb(var(${opt.tint}) / 0.15); color: rgb(var(${opt.tint}))` : ''"
                            x-text="opt.label"
                        ></button>
                    </template>
                </div>
            </div>

            {{-- Loading + empty states --}}
            <div x-show="loading" class="flex items-center justify-center py-16">
                <x-hub-ui::spinner size="lg" />
            </div>

            <div x-show="!loading && !hasAnyData" x-cloak class="py-12 text-center text-xs ui-text-subtle">
                No data for this month — pick another or wait for the engine to ingest balance snapshots.
            </div>

            {{-- Calendar grid — fills the card width with consistent
                 inner padding from the card border. --}}
            <div x-show="!loading && hasAnyData" x-cloak>
                {{-- Weekday header (Mon-first) --}}
                <div class="grid grid-cols-7 gap-1 mb-1 text-[9px] uppercase tracking-[0.1em] ui-text-subtle font-semibold">
                    <template x-for="d in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="d">
                        <div class="text-center" x-text="d"></div>
                    </template>
                </div>

                {{-- Day cells --}}
                <div class="grid grid-cols-7 gap-1">
                    <template x-for="(cell, idx) in cells" :key="idx">
                        <div
                            class="aspect-square rounded-md text-[10px] border ui-border-light relative flex items-center justify-center"
                            :class="cellClasses(cell)"
                            :style="cellStyle(cell)"
                        >
                            {{-- Day number — top-left corner --}}
                            <span class="absolute top-0.5 left-1 font-mono ui-tabular text-[9px] sm:text-[10px] leading-none"
                                  :class="cell.kind === 'spacer' ? 'opacity-0' : ''"
                                  x-text="cell.day || ''"></span>

                            {{-- Tags — top-right corner --}}
                            <span x-show="cell.kind === 'projected'" class="absolute top-0.5 right-1 text-[6px] sm:text-[7px] uppercase tracking-wider opacity-60 leading-none">proj</span>
                            <span x-show="cell.kind === 'today'" class="absolute top-0.5 right-1 text-[6px] sm:text-[7px] uppercase tracking-wider font-semibold leading-none" style="color: rgb(var(--ui-primary))">today</span>

                            {{-- Revenue — centered, responsive sizing so iOS portrait
                                 cells don't overflow the cell box. Scales up on
                                 wider viewports. --}}
                            <div x-show="cell.kind !== 'spacer' && cell.kind !== 'no-data'" class="flex items-baseline gap-0.5 leading-none">
                                <span class="text-[8px] sm:text-[10px] md:text-[11px] opacity-70">$</span>
                                <span class="font-mono font-bold ui-tabular text-[12px] sm:text-[15px] md:text-[18px]" x-text="formatRevenue(cell.revenue)"></span>
                            </div>

                            <div x-show="cell.kind === 'no-data'" class="text-[10px] ui-text-subtle italic">—</div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Month totals strip — start balance, what was made (real),
                 what's projected for the rest of the month, expected close,
                 and the % profit on the month (real + projected). --}}
            <div x-show="!loading && hasAnyData" x-cloak class="mt-4 pt-4 border-t ui-border-light grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <div>
                    <div class="text-[10px] uppercase tracking-[0.1em] ui-text-subtle font-semibold">Started month at</div>
                    <div class="text-base font-mono font-semibold ui-text ui-tabular mt-1" x-text="totals.startWallet === null ? '—' : '$' + totals.startWallet.toFixed(2)"></div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-[0.1em] ui-text-subtle font-semibold">Made so far</div>
                    <div class="text-base font-mono font-semibold ui-tabular mt-1"
                         :style="totals.realized > 0 ? 'color: rgb(var(--ui-success))' : (totals.realized < 0 ? 'color: rgb(var(--ui-danger))' : '')"
                         x-text="(totals.realized >= 0 ? '+' : '') + '$' + totals.realized.toFixed(2)"></div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-[0.1em] ui-text-subtle font-semibold whitespace-nowrap">Projected</div>
                    <div class="text-base font-mono font-semibold ui-text-info ui-tabular mt-1"
                         x-text="(totals.projected >= 0 ? '+' : '') + '$' + totals.projected.toFixed(2)"></div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-[0.1em] ui-text-subtle font-semibold whitespace-nowrap">End balance</div>
                    <div class="text-base font-mono font-semibold ui-text ui-tabular mt-1"
                         x-text="totals.endWallet === null ? '—' : '$' + totals.endWallet.toFixed(2)"></div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-[0.1em] ui-text-subtle font-semibold whitespace-nowrap">Monthly profit %</div>
                    <div class="text-base font-mono font-semibold ui-tabular mt-1"
                         :style="totals.profitPct === null
                             ? 'color: rgb(var(--ui-text-subtle))'
                             : (totals.profitPct >= 0 ? 'color: rgb(var(--ui-success))' : 'color: rgb(var(--ui-danger))')"
                         x-text="totals.profitPct === null
                             ? '—'
                             : (totals.profitPct >= 0 ? '+' : '') + totals.profitPct.toFixed(2) + '%'"></div>
                </div>
            </div>

            {{-- Cumulative-since-today line (only when paged off the
                 current month — for "the rest of this month" the
                 four-tile strip already says it). --}}
            <div x-show="!loading && hasAnyData && totals.cumulativeSinceToday !== null && !isCurrentMonth" x-cloak
                 class="mt-3 pt-3 border-t ui-border-light flex items-baseline justify-between text-[11px]">
                <span class="ui-text-subtle uppercase tracking-[0.1em] font-semibold">Cumulative from today → end of <span x-text="monthLabel"></span></span>
                <span class="font-mono font-bold ui-tabular text-base"
                      :style="totals.cumulativeSinceToday >= 0 ? 'color: rgb(var(--ui-success))' : 'color: rgb(var(--ui-danger))'"
                      x-text="(totals.cumulativeSinceToday >= 0 ? '+' : '') + '$' + totals.cumulativeSinceToday.toFixed(2)"></span>
            </div>

            {{-- Scenario footnote --}}
            <div x-show="hasProjection" x-cloak class="mt-3 pt-3 border-t ui-border-light flex items-start gap-2 text-[11px] ui-text-info leading-snug">
                <svg class="w-3 h-3 mt-0.5 flex-shrink-0 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4M12 8h.01" />
                </svg>
                <span>
                    Projection compounds daily from today's wallet at
                    <strong class="ui-text-info" x-text="(activeScenarioPct * 100).toFixed(3) + '%'"></strong>
                    (<span x-text="scenarioLabel"></span>). Derived from <span x-text="scenarios?.days_observed || 0"></span> day(s) of this month so far.
                </span>
            </div>

            {{-- Disclaimer — projections are illustrative; the engine doesn't
                 guarantee future returns. Sits below the scenario footnote
                 in a warning tint so it reads as a legal-style note rather
                 than informational. --}}
            <div class="mt-3 pt-3 border-t ui-border-light flex items-start gap-2 text-[10px] leading-snug" style="color: rgb(var(--ui-warning))">
                <svg class="w-3 h-3 mt-0.5 flex-shrink-0 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                </svg>
                <span>
                    <strong class="uppercase tracking-wider">Disclaimer:</strong>
                    these are projected values based on historical day-by-day performance.
                    They do not constitute a guarantee or commitment of future revenue by the bot.
                    Trading carries risk and past performance does not guarantee future results.
                </span>
            </div>
        </div>

        <div x-show="!selectedAccountId" x-cloak class="text-center py-16 ui-text-subtle text-xs">
            Pick an account to view its revenue calendar.
        </div>

    </div>

    <script>
        function projectionsPage() {
            return {
                accounts: @json($accounts),
                isAdmin: @json($isAdmin),
                selectedAccountId: '',

                // Currently displayed month
                year: new Date().getFullYear(),
                month: new Date().getMonth() + 1,

                actuals: {},
                currentWallet: null,
                monthStartWallet: null,
                scenarios: null,
                today: null,
                loading: false,

                // Jumper state — bound to the popover dropdowns.
                jumperOpen: false,
                jumpYear: new Date().getFullYear(),
                jumpMonth: new Date().getMonth() + 1,

                scenario: 'neutral',
                scenarioOptions: [
                    { key: 'pessimistic', label: 'Pessimistic', tint: '--ui-danger' },
                    { key: 'neutral',     label: 'Neutral',     tint: '--ui-info' },
                    { key: 'optimistic',  label: 'Optimistic',  tint: '--ui-success' },
                ],

                init() {
                    if (!this.isAdmin && this.accounts.length === 1) {
                        this.selectedAccountId = String(this.accounts[0].id);
                        this.reloadMonth();
                    }
                },

                async reloadMonth() {
                    if (!this.selectedAccountId) {
                        this.actuals = {}; this.scenarios = null; return;
                    }

                    this.loading = true;
                    const { ok, data } = await hubUiFetch(
                        '{{ route("projections.data") }}'
                            + '?account_id=' + this.selectedAccountId
                            + '&year=' + this.year
                            + '&month=' + this.month,
                        { method: 'GET' }
                    );
                    if (ok) {
                        this.actuals = data.actuals || {};
                        this.currentWallet = data.current_wallet ? parseFloat(data.current_wallet) : null;
                        this.monthStartWallet = data.month_start_wallet ? parseFloat(data.month_start_wallet) : null;
                        this.scenarios = data.scenarios || null;
                        this.today = data.today;
                    }
                    this.loading = false;
                },

                step(delta) {
                    let m = this.month + delta;
                    let y = this.year;
                    while (m < 1) { m += 12; y -= 1; }
                    while (m > 12) { m -= 12; y += 1; }
                    this.month = m;
                    this.year = y;
                    this.reloadMonth();
                },

                goToToday() {
                    const now = new Date();
                    this.year = now.getFullYear();
                    this.month = now.getMonth() + 1;
                    this.reloadMonth();
                },

                jumpTo(year, month) {
                    this.year = year;
                    this.month = month;
                    this.reloadMonth();
                },

                /**
                 * Switching the jumper's year to the current year while the
                 * month is still set to a past month would leave the picker
                 * pointing at a disabled option. Bump it forward to the
                 * earliest valid month so the dropdown stays consistent.
                 */
                clampJumpMonth() {
                    const now = new Date();
                    if (this.jumpYear === now.getFullYear() && this.jumpMonth < (now.getMonth() + 1)) {
                        this.jumpMonth = now.getMonth() + 1;
                    }
                },

                /**
                 * Year options exposed to the jumper. Always start from the
                 * current year and walk forward — the projection chain is
                 * computed for ~6 years out, so the dropdown matches that
                 * horizon.
                 */
                get futureYears() {
                    const out = [];
                    const y0 = new Date().getFullYear();
                    for (let i = 0; i <= 6; i++) out.push(y0 + i);
                    return out;
                },

                /**
                 * Month options for the chosen year. For the current year
                 * we filter to today's month onwards (future-only); future
                 * years offer all 12. Returns objects so the dropdown can
                 * show "Apr" instead of raw 4.
                 */
                futureMonthsForYear(year) {
                    const names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    const now = new Date();
                    const startMonth = year === now.getFullYear() ? (now.getMonth() + 1) : 1;
                    const out = [];
                    for (let m = startMonth; m <= 12; m++) out.push({ value: m, label: names[m - 1] });
                    return out;
                },

                get monthLabel() {
                    const names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                    return names[this.month - 1] + ' ' + this.year;
                },

                get activeScenarioPct() {
                    const key = this.scenario + '_pct';
                    const v = this.scenarios?.[key];
                    return v !== null && v !== undefined ? parseFloat(v) : 0;
                },

                get scenarioLabel() {
                    return this.scenarioOptions.find(o => o.key === this.scenario)?.label || '';
                },

                get hasProjection() {
                    return this.scenarios && this.scenarios.pessimistic_pct !== null;
                },

                get hasAnyData() {
                    return Object.keys(this.actuals).length > 0 || this.hasProjection;
                },

                /**
                 * Month-level totals for the strip below the calendar.
                 * Three branches keyed off the displayed month:
                 *
                 * - PAST: start = first snapshot of the month, realised =
                 *   sum of actuals in that month, projected = 0,
                 *   end = start + realised.
                 * - CURRENT: start = currentWallet − realised (anchor
                 *   backward from live wallet), realised = sum of actuals
                 *   so far, projected = sum of projection cells in
                 *   remaining days, end = start + realised + projected.
                 * - FUTURE: start = walletByDate[firstDayOfMonth] (compound
                 *   chain, no real data), realised = 0, projected = sum of
                 *   month's projected cells, end = start + projected.
                 *
                 * Cumulative-since-today (separate footer line) = end of
                 * displayed month − currentWallet, so the operator can
                 * see "from now until end of [Month] you'd be up $X" at
                 * a glance regardless of how far they've paged.
                 */
                get totals() {
                    let realized = 0;
                    let projected = 0;

                    for (const cell of this.cells) {
                        if (cell.kind === 'actual' || cell.kind === 'today') {
                            realized += Number(cell.revenue) || 0;
                        } else if (cell.kind === 'projected') {
                            projected += Number(cell.revenue) || 0;
                        }
                    }

                    let startWallet = null;
                    let endWallet = null;

                    if (this.isPastMonth) {
                        startWallet = this.monthStartWallet;
                        endWallet = startWallet !== null ? startWallet + realized : null;
                    } else if (this.isCurrentMonth && this.currentWallet !== null) {
                        startWallet = this.currentWallet - realized;
                        endWallet = startWallet + realized + projected;
                    } else if (this.isFutureMonth) {
                        const { walletByDate } = this.projection;
                        const firstIso = `${this.year}-${String(this.month).padStart(2,'0')}-01`;
                        const lastDayNum = new Date(this.year, this.month, 0).getDate();
                        const nextMonthFirst = this.month === 12
                            ? `${this.year+1}-01-01`
                            : `${this.year}-${String(this.month+1).padStart(2,'0')}-01`;

                        startWallet = walletByDate[firstIso] ?? null;
                        endWallet = walletByDate[nextMonthFirst] ?? null;
                    }

                    const cumulativeSinceToday = (endWallet !== null && this.currentWallet !== null)
                        ? endWallet - this.currentWallet
                        : null;

                    // Monthly profit % = (realised + projected) / start.
                    // Past months → realised only; future months → projected
                    // only; current month → both. Start-wallet of 0 (or
                    // missing) yields null so the tile renders "—".
                    const monthGain = realized + projected;
                    const profitPct = (startWallet !== null && startWallet > 0)
                        ? (monthGain / startWallet) * 100
                        : null;

                    return { startWallet, realized, projected, endWallet, cumulativeSinceToday, profitPct };
                },

                get isCurrentMonth() {
                    if (!this.today) return false;
                    const t = new Date(this.today + 'T00:00:00');
                    return this.year === t.getFullYear() && this.month === (t.getMonth() + 1);
                },

                get isPastMonth() {
                    if (!this.today) return false;
                    const t = new Date(this.today + 'T00:00:00');
                    if (this.year < t.getFullYear()) return true;
                    if (this.year > t.getFullYear()) return false;
                    return this.month < (t.getMonth() + 1);
                },

                get isFutureMonth() {
                    return !this.isCurrentMonth && !this.isPastMonth;
                },

                /**
                 * Forward projection chain anchored on today's wallet,
                 * compounded daily at the active scenario percent. Returns
                 * two maps keyed by ISO date:
                 *   revByDate     — the day's projected revenue ($).
                 *   walletByDate  — the wallet balance AT THE START of
                 *                   that day (= end of previous day).
                 *
                 * The wallet map is what lets the totals strip stay
                 * accurate when the operator pages into future months —
                 * "started month at" reads `walletByDate[firstDayOfMonth]`,
                 * "expected close" reads `walletByDate[firstDayOfNextMonth]`.
                 */
                get projection() {
                    const pct = this.activeScenarioPct;
                    const walletByDate = {};
                    const revByDate = {};
                    if (!this.today || !this.hasProjection || !(this.currentWallet > 0)) {
                        return { walletByDate, revByDate };
                    }

                    const todayIso = this.today;
                    let wallet = this.currentWallet;
                    walletByDate[todayIso] = wallet;

                    // Walk forward day-by-day for ~6 years so any future
                    // month the operator can reasonably navigate to is
                    // already cached. Cheap — ~2200 multiplications.
                    let cursor = new Date(todayIso + 'T00:00:00');
                    for (let i = 0; i < 365 * 6; i++) {
                        const dayRev = wallet * pct;
                        revByDate[cursor.toISOString().slice(0, 10)] = dayRev;
                        wallet = wallet * (1 + pct);
                        cursor.setDate(cursor.getDate() + 1);
                        walletByDate[cursor.toISOString().slice(0, 10)] = wallet;
                    }

                    return { walletByDate, revByDate };
                },

                /**
                 * Build the calendar cell list for the displayed month.
                 * Mon-first grid; pad start with empty spacer cells so the
                 * first weekday lands in the right column.
                 */
                get cells() {
                    const list = [];
                    const first = new Date(this.year, this.month - 1, 1);
                    const dim = new Date(this.year, this.month, 0).getDate();

                    // Mon-first: JS getDay() returns 0=Sun..6=Sat. Convert.
                    const offset = (first.getDay() + 6) % 7;
                    for (let i = 0; i < offset; i++) list.push({ kind: 'spacer' });

                    const todayDate = this.today ? new Date(this.today + 'T00:00:00') : null;
                    const { revByDate } = this.projection;

                    for (let day = 1; day <= dim; day++) {
                        const iso = `${this.year}-${String(this.month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                        const cellDate = new Date(this.year, this.month - 1, day);

                        const isToday = todayDate && cellDate.getTime() === todayDate.getTime();
                        const isPast  = todayDate && cellDate < todayDate;
                        const isFuture = todayDate && cellDate > todayDate;

                        const actual = this.actuals[iso];

                        if (isToday) {
                            list.push({ kind: 'today', day, revenue: actual !== undefined ? parseFloat(actual) : 0 });
                        } else if (isPast) {
                            if (actual !== undefined) {
                                list.push({ kind: 'actual', day, revenue: parseFloat(actual) });
                            } else {
                                list.push({ kind: 'no-data', day });
                            }
                        } else if (isFuture && revByDate[iso] !== undefined) {
                            list.push({ kind: 'projected', day, revenue: revByDate[iso] });
                        } else {
                            list.push({ kind: 'no-data', day });
                        }
                    }

                    return list;
                },

                /**
                 * Compact dollar formatting — keeps every cell readable
                 * regardless of magnitude. Switches to K / M / B suffixes
                 * once the absolute value crosses the threshold, holding
                 * 2 decimals on the suffixed forms (e.g. 1200 → "1.20K")
                 * so the precision feels consistent across rows.
                 */
                formatRevenue(v) {
                    if (v === null || v === undefined) return '—';
                    const n = parseFloat(v);
                    if (!isFinite(n)) return '—';

                    const abs = Math.abs(n);
                    const sign = n < 0 ? '-' : '';

                    if (abs >= 1e9)  return sign + (abs / 1e9).toFixed(2) + 'B';
                    if (abs >= 1e6)  return sign + (abs / 1e6).toFixed(2) + 'M';
                    if (abs >= 1e3)  return sign + (abs / 1e3).toFixed(2) + 'K';
                    if (abs >= 100)  return sign + abs.toFixed(0);
                    if (abs >= 1)    return sign + abs.toFixed(2);
                    if (abs > 0)     return sign + abs.toFixed(3);
                    return '0';
                },

                cellClasses(cell) {
                    if (cell.kind === 'spacer') return 'opacity-0 pointer-events-none';
                    if (cell.kind === 'no-data') return 'ui-bg-elevated ui-text-subtle';
                    if (cell.kind === 'today') return 'ui-bg-card ui-text';
                    if (cell.kind === 'projected') return 'ui-bg-elevated ui-text-muted';
                    // actual
                    return 'ui-bg-card ui-text';
                },

                cellStyle(cell) {
                    if (cell.kind === 'today') {
                        return 'border-color: rgb(var(--ui-primary)); box-shadow: 0 0 0 1px rgb(var(--ui-primary) / 0.4) inset;';
                    }
                    if (cell.kind === 'actual' && cell.revenue !== undefined) {
                        if (cell.revenue > 0) return 'border-color: rgb(var(--ui-success) / 0.4)';
                        if (cell.revenue < 0) return 'border-color: rgb(var(--ui-danger) / 0.4)';
                    }
                    return '';
                },
            };
        }
    </script>
</x-app-layout>
