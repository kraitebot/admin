@php
    // Projections — REAL DATA. The calendar consumes ProjectionsController::data:
    // realized daily revenue (actuals), the live wallet, and observed daily-rate
    // scenarios. Forward months compound the live wallet at the scenario rate.

    // REAL first-run gate: projections are built from realized revenue —
    // an account that has never traded has nothing to project.
    $accountIds = auth()->user()->is_admin
        ? null
        : Kraite\Core\Models\Account::where('user_id', auth()->id())->pluck('id');
    $noPositions = ! Kraite\Core\Models\Position::query()
        ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds))
        ->exists();

    $initialAccountId = $accounts->first()['id'] ?? null;

    // shared control class strings
    $pjArrow = 'appearance-none cursor-pointer w-[34px] h-[34px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2';
    $cardHead = 'flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft';
    $cardTitle = 'font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap';
@endphp

<x-app-layout active="projections" :title="'Kraite — Projections'">

    <script>
        // Projections page model — straight port of the design's client-side
        // calendar. Past days replay a seeded rng reconciled to the account
        // wallet; future days compound the wallet at an observed daily rate.
        window.projectionsPage = (accounts, initialAccountId, dataUrl) => {
            // ---- constants ----
            const MON = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            const HISTORY_MONTHS = 14;                               // calendar can page this far back
            const FUTURE_MONTHS = 72;                                // ~6 years forward

            // scenario tone — chrome only (active segment, projected totals, light cell tint)
            const TONE = {
                pess:    { label: 'Pessimistic', css: 'var(--pnl-down-fg)', activeText: '#fff' },
                neutral: { label: 'Neutral',     css: 'var(--info)',        activeText: '#fff' },
                opt:     { label: 'Optimistic',  css: 'var(--pnl-up-fg)',   activeText: '#04140d' },
            };

            // ---- formatters ----
            const fmtAbs = (a) => {
                if (!isFinite(a)) return '$∞';
                if (a < 1000) return '$' + Math.round(a).toLocaleString('en-US');
                if (a >= 1e15) return '$' + a.toExponential(2).replace('e+', 'e');
                for (const [u, v] of [['T', 1e12], ['B', 1e9], ['M', 1e6], ['K', 1e3]]) if (a >= v) return '$' + (a / v).toFixed(2) + u;
                return '$' + Math.round(a).toLocaleString('en-US');
            };
            const fmtAbsFull = (a) => {
                if (!isFinite(a)) return '$∞';
                if (a < 1e7) return '$' + Math.round(a).toLocaleString('en-US');
                if (a >= 1e15) return '$' + a.toExponential(2).replace('e+', 'e');
                for (const [u, v] of [['T', 1e12], ['B', 1e9], ['M', 1e6]]) if (a >= v) return '$' + (a / v).toFixed(2) + u;
                return '$' + Math.round(a).toLocaleString('en-US');
            };
            const fmtSigned     = (n) => (n >= 0 ? '+' : '−') + fmtAbs(Math.abs(n));
            const fmtSignedFull = (n) => (n >= 0 ? '+' : '−') + fmtAbsFull(Math.abs(n));
            const fmtFull       = (n) => (n < 0 ? '−' : '') + fmtAbsFull(Math.abs(n));
            const fmtPct        = (n, d = 2) => (n >= 0 ? '+' : '−') + Math.abs(n).toFixed(d) + '%';
            const dimOf = (y, m) => new Date(Date.UTC(y, m + 1, 0)).getUTCDate();

            // Enrich the real account list for the picker chrome (initials,
            // a per-account equity slot we fill from the live wallet on fetch).
            const ACCTS = (accounts || []).map(a => ({
                id: a.id,
                ex: a.exchange,
                tag: a.name,
                note: a.owner || '',
                mono: (a.exchange || '?').replace(/[^A-Za-z]/g, '').slice(0, 2).toUpperCase() || '?',
                state: 'ok',
                equityStr: '—',
            }));

            return {
                // ---- exposed statics ----
                accts: ACCTS,
                monNames: MON,
                monShort: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                weekdays: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
                scens: [['pess', 'Pessimistic'], ['neutral', 'Neutral'], ['opt', 'Optimistic']],
                futureYears: Math.floor(FUTURE_MONTHS / 12),
                dataUrl,

                // ---- state ----
                acctIdx: Math.max(0, ACCTS.findIndex(a => a.id === initialAccountId)),
                accountId: initialAccountId,
                scenario: 'neutral',
                ym: { year: null, month: null },
                // server-anchored "today" — set from the first data response so
                // past/current/future never depends on the browser clock.
                curY: null, curM: null, curD: null,
                loading: false,
                acctOpen: false,
                monOpen: false,
                pYear: null,
                // Safe stub so the always-rendered control row never reads a
                // null `m` during the first async fetch; replaced on response.
                m: { type: 'current', dim: 30, cells: [], startedAt: null, realized: 0, projected: 0, endBal: 0, monthlyPct: 0, cumFromToday: 0, rate: 0, rates: { pess: 0, neutral: 0, opt: 0, n: 0 }, S0: 0, firstWeekday: 0 },
                fetched: null,
                totalsCells: [],

                init() {
                    const now = new Date();
                    this.ym = { year: now.getUTCFullYear(), month: now.getUTCMonth() };
                    this.pYear = this.ym.year;
                    this.recompute(true);
                },

                // ---- derived helpers ----
                tone() { return TONE[this.scenario] || TONE.neutral; },
                acct() { return ACCTS[this.acctIdx] || { mono: '?', ex: '—', tag: '', equityStr: '—', state: 'ok', note: '' }; },
                absYm() { return this.ym.year * 12 + this.ym.month; },
                absCur() { return this.curY === null ? this.absYm() : this.curY * 12 + this.curM; },
                absMin() { return this.absCur() - HISTORY_MONTHS; },
                absMax() { return this.absCur() + FUTURE_MONTHS; },
                minYear() { return Math.floor(this.absMin() / 12); },
                maxYear() { return Math.floor(this.absMax() / 12); },
                isCurrentMonth() { return this.absYm() === this.absCur(); },
                atMin() { return this.absYm() <= this.absMin(); },
                atMax() { return this.absYm() >= this.absMax(); },
                inRange(y, m) { const abs = y * 12 + m; return abs >= this.absMin() && abs <= this.absMax(); },
                isCurYm(y, m) { return this.curY !== null && y === this.curY && m === this.curM; },
                monthLabel() { return MON[this.ym.month] + ' ' + this.ym.year; },
                typeBadge() { return this.m.type === 'past' ? 'Realized' : this.m.type === 'future' ? 'Projected' : 'Hybrid'; },
                leadCells() { return Array.from({ length: this.m.firstWeekday }); },
                trailCells() { const t = this.m.firstWeekday + this.m.dim; return Array.from({ length: (7 - t % 7) % 7 }); },
                dotCls(state) { return 'w-[8px] h-[8px] rounded-chip flex-shrink-0 ' + (state === 'ok' ? 'bg-green-500' : state === 'down' ? 'bg-danger animate-pulse-soft' : 'bg-warn'); },
                mix(pct, base) { return `color-mix(in srgb, ${this.tone().css} ${pct}%, ${base})`; },

                // formatters (exposed)
                fSigned: fmtSigned,
                fSignedFull: fmtSignedFull,
                fFull: fmtFull,
                fPct: fmtPct,

                // ---- actions ----
                setAcct(i) { this.acctIdx = i; this.accountId = ACCTS[i].id; this.acctOpen = false; this.recompute(true); },
                setScenario(k) { if (this.m && this.m.type === 'past') return; this.scenario = k; if (this.fetched) { this.m = this.buildMonth(); this.totalsCells = this.buildTotals(); } },
                pickYm(y, m) { this.ym = { year: y, month: m }; this.monOpen = false; this.recompute(true); },
                shift(n) {
                    const abs = Math.min(this.absMax(), Math.max(this.absMin(), this.absYm() + n));
                    this.ym = { year: Math.floor(abs / 12), month: abs % 12 };
                    this.recompute(true);
                },
                goToday() { if (this.curY !== null) this.pickYm(this.curY, this.curM); },

                async recompute(shimmer) {
                    if (!this.accountId) return;
                    if (shimmer) this.loading = true;
                    try {
                        const url = `${this.dataUrl}?account_id=${this.accountId}&year=${this.ym.year}&month=${this.ym.month + 1}`;
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (res.ok) {
                            this.fetched = await res.json();
                            const [ty, tm, td] = this.fetched.today.split('-').map(Number);
                            this.curY = ty; this.curM = tm - 1; this.curD = td;
                            if (ACCTS[this.acctIdx]) {
                                ACCTS[this.acctIdx].equityStr = this.fetched.current_wallet != null
                                    ? fmtAbsFull(parseFloat(this.fetched.current_wallet)) : '—';
                            }
                            this.m = this.buildMonth();
                            this.totalsCells = this.buildTotals();
                        }
                    } finally {
                        this.loading = false;
                    }
                },

                // ---- model (server-fed) ----
                monthType(y, m) {
                    const abs = y * 12 + m, cur = this.absCur();
                    return abs === cur ? 'current' : abs > cur ? 'future' : 'past';
                },
                idxOf(y, m, d) { return Math.round((Date.UTC(y, m, d) - Date.UTC(this.curY, this.curM, this.curD)) / 86400000); },
                rates() {
                    const sc = (this.fetched && this.fetched.scenarios) || {};
                    const num = (v) => v == null ? 0 : parseFloat(v);
                    return { pess: num(sc.pessimistic_pct), neutral: num(sc.neutral_pct), opt: num(sc.optimistic_pct), n: sc.days_observed || 0 };
                },

                buildMonth() {
                    const f = this.fetched;
                    const y = this.ym.year, m = this.ym.month;
                    const type = this.monthType(y, m);
                    const dim = dimOf(y, m);
                    const rates = this.rates();
                    const rate = rates[this.scenario] || 0;
                    const S0 = (f.current_wallet != null) ? parseFloat(f.current_wallet) : 0;
                    const monthStart = (f.month_start_wallet != null) ? parseFloat(f.month_start_wallet) : null;
                    const actuals = f.actuals || {};
                    const key = (d) => `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                    const has = (d) => Object.prototype.hasOwnProperty.call(actuals, key(d));
                    const amt = (d) => parseFloat(actuals[key(d)]);
                    const realizedSum = () => Object.values(actuals).reduce((s, v) => s + parseFloat(v), 0);

                    const cells = [];
                    let startedAt, realized = null, projected = null, endBal;

                    if (type === 'past') {
                        for (let d = 1; d <= dim; d++) cells.push({ day: d, kind: has(d) ? 'realized' : 'empty', amount: has(d) ? amt(d) : null });
                        realized = realizedSum();
                        startedAt = monthStart;
                        endBal = (monthStart != null) ? monthStart + realized : null;
                    } else if (type === 'current') {
                        for (let d = 1; d <= dim; d++) {
                            if (d < this.curD) cells.push({ day: d, kind: has(d) ? 'realized' : 'empty', amount: has(d) ? amt(d) : null });
                            else if (d === this.curD) cells.push({ day: d, kind: 'today', amount: has(d) ? amt(d) : 0, todayHas: has(d) });
                            else { const k = d - this.curD; cells.push({ day: d, kind: 'projected', amount: S0 * Math.pow(1 + rate, k) - S0 * Math.pow(1 + rate, k - 1) }); }
                        }
                        startedAt = monthStart;
                        realized = realizedSum();
                        endBal = S0 * Math.pow(1 + rate, dim - this.curD);
                        projected = endBal - S0;
                    } else {
                        for (let d = 1; d <= dim; d++) {
                            const k = this.idxOf(y, m, d);
                            cells.push({ day: d, kind: 'projected', amount: S0 * Math.pow(1 + rate, k) - S0 * Math.pow(1 + rate, k - 1) });
                        }
                        startedAt = S0 * Math.pow(1 + rate, this.idxOf(y, m, 1) - 1);
                        endBal = S0 * Math.pow(1 + rate, this.idxOf(y, m, dim));
                        projected = endBal - startedAt;
                    }

                    return {
                        type, dim, cells, startedAt, realized, projected, endBal,
                        monthlyPct: (endBal != null && startedAt) ? (endBal / startedAt - 1) * 100 : 0,
                        cumFromToday: (endBal != null) ? endBal - S0 : 0,
                        rate, rates, S0,
                        firstWeekday: (new Date(Date.UTC(y, m, 1)).getUTCDay() + 6) % 7,   // Mon=0
                    };
                },

                // ---- totals strip ----
                buildTotals() {
                    const m = this.m, t = m.type, tone = this.tone();
                    const dash = (v) => (v == null || !isFinite(v));
                    const labels = t === 'past'
                        ? ['Started month at', 'Realized this month', 'Projected', 'Ended at', 'Monthly return']
                        : t === 'future'
                            ? ['Starts month at', 'Realized', 'Projected this month', 'Expected end balance', 'Monthly return']
                            : ['Started month at', 'Made so far', 'Projected · rest of month', 'Expected end balance', 'Monthly return'];
                    const realizedCell = t === 'future'
                        ? { value: '—', cls: 'text-fg-faint', sub: '' }
                        : { value: fmtSignedFull(m.realized || 0), cls: (m.realized || 0) >= 0 ? 'text-pnlup' : 'text-pnldown', sub: 'REALIZED' };
                    const projectedCell = t === 'past'
                        ? { value: '—', cls: 'text-fg-faint', sub: '' }
                        : { value: fmtSignedFull(m.projected || 0), css: tone.css, sub: tone.label.toUpperCase() + ' · PROJ' };
                    const monthlyCss = t === 'past' ? (m.monthlyPct >= 0 ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)') : tone.css;
                    const realPct = (m.startedAt && m.S0) ? (m.S0 / m.startedAt - 1) * 100 : 0;
                    const projPct = m.S0 ? (m.endBal / m.S0 - 1) * 100 : 0;
                    const monthlySub = t === 'past' ? 'REALIZED'
                        : t === 'current' ? `${fmtPct(realPct, 1)} REAL · ${fmtPct(projPct, 1)} PROJ`
                        : tone.label.toUpperCase() + ' · PROJ';
                    return [
                        { label: labels[0], value: dash(m.startedAt) ? '—' : fmtFull(m.startedAt), css: 'var(--fg-1)', cls: '', sub: 'OPENING BALANCE' },
                        { label: labels[1], value: realizedCell.value, cls: realizedCell.cls || '', css: null, sub: realizedCell.sub },
                        { label: labels[2], value: projectedCell.value, cls: projectedCell.cls || '', css: projectedCell.css || null, sub: projectedCell.sub },
                        { label: labels[3], value: dash(m.endBal) ? '—' : fmtFull(m.endBal), css: 'var(--fg-1)', cls: '', sub: t === 'past' ? 'REALIZED' : 'EXPECTED' },
                        { label: labels[4], value: fmtPct(m.monthlyPct), css: monthlyCss, cls: '', sub: monthlySub },
                    ];
                },

                // ---- day-cell display helpers ----
                cellAmountSize(c) {
                    const s = fmtSigned(c.amount);
                    return s.length > 11 ? 14 : s.length > 8 ? 16 : (c.kind === 'projected' ? 18 : 19);
                },
            };
        };
    </script>

    <div x-data="projectionsPage(@js($accounts), {{ $initialAccountId ?? 'null' }}, '{{ route('projections.data') }}')">

        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-trending-up class="w-[13px] h-[13px]" stroke-width="1.75"/>PERFORMANCE
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Projections</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Realized revenue and forward projection — where the book is heading from how the engine has actually performed.</div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
                <button type="button" @click="recompute(true)" :disabled="loading"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover disabled:opacity-50">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="loading ? 'animate-spin' : ''"/>Sync
                </button>
            </div>
        </div>

        @if($noPositions)
            {{-- first-run: nothing to project until the engine trades --}}
            <div class="card">
                <div class="flex flex-col items-center justify-center text-center py-[78px] px-5">
                    <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><x-feathericon-trending-up class="w-6 h-6" stroke-width="1.75"/></div>
                    <h4 class="font-sans font-semibold text-[19px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-1.5">Nothing to project yet</h4>
                    <p class="text-[13px] text-fg-3 max-w-[440px] m-0">Projections are built from realized trading revenue. Once the engine opens and closes its first positions, this account's revenue calendar and forward projection appear here.</p>
                    <span class="mt-5 inline-flex items-center gap-[7px] font-mono text-[10.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">
                        <span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>Engine running · scanning for entries
                    </span>
                </div>
            </div>
        @else
        {{-- ===================== CONTROL ROW ===================== --}}
        <div class="flex items-center justify-between gap-4 mb-5 flex-wrap">
            <div class="flex items-center gap-3 flex-wrap">

                {{-- Account picker --}}
                <div class="relative" @click.outside="acctOpen = false">
                    <button type="button" @click="acctOpen = !acctOpen"
                            :class="acctOpen ? 'border-accent' : 'border-line hover:border-line-strong'"
                            class="inline-flex items-center gap-2.5 h-[34px] border rounded-control bg-surface pl-2 pr-3 cursor-pointer transition-colors duration-fast ease-out">
                        <span class="w-[24px] h-[24px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[10px] flex items-center justify-center flex-shrink-0" x-text="acct().mono"></span>
                        <span class="flex flex-col items-start leading-[1.15] min-w-0">
                            <span class="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap"><span x-text="acct().ex"></span> <span class="text-fg-mute font-normal" x-text="'· ' + acct().tag"></span></span>
                            <span class="font-mono text-[10px] text-fg-mute tabular-nums tracking-[0.02em] whitespace-nowrap" x-text="acct().equityStr"></span>
                        </span>
                        <span :class="dotCls(acct().state)"></span>
                        <x-feathericon-chevron-down class="w-[14px] h-[14px] text-fg-mute" stroke-width="1.75"/>
                    </button>
                    <div x-show="acctOpen" x-cloak
                         class="absolute top-[calc(100%+6px)] left-0 z-[60] w-[280px] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in">
                        <div class="font-mono text-[9px] font-semibold tracking-[0.12em] uppercase text-fg-mute px-[9px] pt-1.5 pb-1">Exchange accounts · <span x-text="accts.length"></span></div>
                        <template x-for="(ac, i) in accts" :key="ac.ex + ac.tag">
                            <button type="button" @click="setAcct(i)"
                                    :class="i === acctIdx ? 'bg-hover' : ''"
                                    class="appearance-none cursor-pointer text-left flex items-center gap-2.5 bg-transparent border-0 rounded-[7px] py-2 px-[9px] transition-colors duration-fast ease-out hover:bg-hover">
                                <span class="w-[26px] h-[26px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[10.5px] flex items-center justify-center flex-shrink-0" x-text="ac.mono"></span>
                                <span class="flex flex-col leading-[1.2] flex-1 min-w-0">
                                    <span class="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap"><span x-text="ac.ex"></span> <span class="text-fg-mute font-normal" x-text="'· ' + ac.tag"></span></span>
                                    <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] whitespace-nowrap" x-text="ac.note"></span>
                                </span>
                                <span class="flex flex-col items-end gap-1 flex-shrink-0">
                                    <span class="font-mono text-[11.5px] font-semibold text-fg-1 tabular-nums" x-text="ac.equityStr"></span>
                                    <span class="inline-flex items-center gap-[5px] font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase"
                                          :style="`color: ${ac.state === 'ok' ? 'var(--pnl-up-fg)' : ac.state === 'down' ? 'var(--danger)' : 'var(--warn)'}`">
                                        <span :class="dotCls(ac.state)"></span><span x-text="ac.state === 'ok' ? 'Linked' : ac.state === 'down' ? 'Down' : 'Degraded'"></span>
                                    </span>
                                </span>
                                <span x-show="i === acctIdx" class="flex-shrink-0 text-accent"><x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="w-px h-[26px] bg-line max-[640px]:hidden"></div>

                {{-- Month nav: prev · picker · next --}}
                <div class="flex items-center gap-1.5">
                    <button type="button" class="{{ $pjArrow }}" :disabled="atMin()" @click="shift(-1)" aria-label="Previous month">
                        <x-feathericon-chevron-left class="w-4 h-4" stroke-width="1.75"/>
                    </button>
                    <div class="relative" @click.outside="monOpen = false">
                        <button type="button" @click="monOpen = !monOpen; if (monOpen) pYear = ym.year"
                                :class="monOpen ? 'border-accent' : 'border-line hover:border-line-strong'"
                                class="inline-flex items-center justify-center gap-2 h-[34px] px-3.5 min-w-[156px] rounded-control border bg-surface font-sans font-semibold text-[13.5px] text-fg-1 cursor-pointer transition-colors duration-fast ease-out">
                            <span x-text="monthLabel()"></span>
                            <x-feathericon-chevron-down class="w-[14px] h-[14px] text-fg-mute" stroke-width="1.75"/>
                        </button>
                        <div x-show="monOpen" x-cloak
                             class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-[60] w-[280px] bg-surface border border-line rounded-control shadow-2 p-3 animate-dd-in">
                            <div class="flex items-center justify-between mb-3">
                                <button type="button" class="{{ $pjArrow }} !w-[28px] !h-[28px]" :disabled="pYear <= minYear()" @click="pYear--" aria-label="Previous year">
                                    <x-feathericon-chevron-left class="w-3.5 h-3.5" stroke-width="1.75"/>
                                </button>
                                <span class="font-mono text-[14px] font-semibold text-fg-1 tabular-nums" x-text="pYear"></span>
                                <button type="button" class="{{ $pjArrow }} !w-[28px] !h-[28px]" :disabled="pYear >= maxYear()" @click="pYear++" aria-label="Next year">
                                    <x-feathericon-chevron-right class="w-3.5 h-3.5" stroke-width="1.75"/>
                                </button>
                            </div>
                            <div class="grid grid-cols-3 gap-1.5">
                                <template x-for="(ms, mi) in monShort" :key="ms">
                                    <button type="button" :disabled="!inRange(pYear, mi)" @click="pickYm(pYear, mi)"
                                            :class="(pYear === ym.year && mi === ym.month) ? 'bg-accent text-accent-on border-transparent' : 'bg-surface-3 text-fg-2 border-line hover:border-line-strong hover:text-fg-1'"
                                            class="appearance-none relative h-[38px] rounded-[7px] font-mono text-[12px] font-semibold tracking-[0.02em] cursor-pointer border transition-colors duration-fast ease-out disabled:opacity-25 disabled:cursor-not-allowed">
                                        <span x-text="ms"></span>
                                        <span x-show="isCurYm(pYear, mi) && !(pYear === ym.year && mi === ym.month)" class="absolute top-1.5 right-1.5 w-1 h-1 rounded-chip bg-accent"></span>
                                    </button>
                                </template>
                            </div>
                            <div class="mt-3 pt-2.5 border-t border-line-soft font-mono text-[9.5px] text-fg-mute tracking-[0.04em] text-center">PROJECT UP TO <span x-text="futureYears"></span> YEARS FORWARD</div>
                        </div>
                    </div>
                    <button type="button" class="{{ $pjArrow }}" :disabled="atMax()" @click="shift(1)" aria-label="Next month">
                        <x-feathericon-chevron-right class="w-4 h-4" stroke-width="1.75"/>
                    </button>
                </div>

                {{-- Today shortcut — hidden on the current month --}}
                <button type="button" x-show="!isCurrentMonth()" x-cloak @click="goToday()"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                    <x-feathericon-clock class="w-3.5 h-3.5" stroke-width="1.75"/>Today
                </button>
            </div>

            {{-- Scenario switch — deterministic 3-col segmented, tone-aware --}}
            <div :class="m.type === 'past' ? 'opacity-45 pointer-events-none' : ''"
                 :title="m.type === 'past' ? 'Realized history — scenarios apply only to projected months' : null"
                 class="relative inline-grid grid-cols-3 h-[44px] min-w-[330px] bg-surface-3 border border-line rounded-control transition-opacity">
                <span aria-hidden="true"
                      :style="`left: ${(scens.findIndex(s => s[0] === scenario) * 100 / 3).toFixed(4)}%; margin-left: 3px; width: calc(33.3333% - 6px); background: ${tone().css}`"
                      class="absolute top-[3px] bottom-[3px] z-0 rounded-[7px] shadow-1 pointer-events-none transition-[left] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]"></span>
                <template x-for="s in scens" :key="s[0]">
                    <button type="button" @click="setScenario(s[0])"
                            class="appearance-none bg-transparent border-0 rounded-[7px] flex flex-col items-center justify-center gap-[2px] px-1 cursor-pointer relative z-[1] transition-colors duration-fast ease-out">
                        <span class="font-mono text-[11px] font-semibold tracking-[0.03em] leading-none"
                              :style="`color: ${scenario === s[0] ? tone().activeText : 'var(--fg-3)'}`" x-text="s[1]"></span>
                        <span class="font-mono text-[8.5px] tabular-nums leading-none tracking-[0.01em]"
                              :style="`color: ${scenario === s[0] ? tone().activeText : 'var(--fg-mute)'}; opacity: ${scenario === s[0] ? 0.82 : 1}`"
                              x-text="fPct(m.rates[s[0]] * 100, 2) + '/d'"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- ===================== LOADING SKELETON ===================== --}}
        <template x-if="loading">
            <div>
                <div class="card mb-6 flex items-stretch max-[1000px]:flex-wrap">
                    <template x-for="i in 5" :key="i">
                        <div class="flex-1 min-w-[164px] py-[15px] px-5 flex flex-col gap-2.5" :class="i > 1 ? 'border-l border-line-soft max-[1000px]:border-l-0' : ''">
                            <div class="animate-pulse-soft bg-surface-3 rounded-control h-2.5 w-2/3"></div>
                            <div class="animate-pulse-soft bg-surface-3 rounded-control h-5 w-4/5"></div>
                            <div class="animate-pulse-soft bg-surface-3 rounded-control h-2 w-1/2"></div>
                        </div>
                    </template>
                </div>
                <div class="card p-4">
                    <div class="grid grid-cols-7 gap-1.5">
                        <template x-for="i in 35" :key="i">
                            <div class="animate-pulse-soft bg-surface-3 rounded-control h-[88px]"></div>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="!loading">
            <div>
                {{-- ===================== TOTALS STRIP ===================== --}}
                <div class="card card--flat mb-6">
                    <div class="flex items-stretch max-[1000px]:flex-wrap">
                        <template x-for="(c, i) in totalsCells" :key="c.label">
                            <div class="flex-1 min-w-[164px] py-[15px] px-5 flex flex-col gap-1.5"
                                 :class="(i ? 'border-l border-line-soft max-[1000px]:border-l-0 ' : '') + (i >= 2 ? 'max-[1000px]:border-t max-[1000px]:border-line-soft' : '')">
                                <span class="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute whitespace-nowrap overflow-hidden text-ellipsis" x-text="c.label"></span>
                                <span class="font-mono text-[21px] font-semibold leading-none tabular-nums tracking-[-0.02em]"
                                      :class="c.cls" :style="c.css ? `color: ${c.css}` : ''" x-text="c.value"></span>
                                <span class="font-mono text-[9px] tracking-[0.07em] text-fg-mute uppercase" x-text="c.sub"></span>
                            </div>
                        </template>
                    </div>
                    {{-- future month: cumulative from today --}}
                    <div x-show="m.type === 'future'" x-cloak
                         class="flex items-center gap-3 py-[13px] px-5 border-t border-line-soft" :style="`background: ${mix(6, 'transparent')}`">
                        <span :style="`color: ${tone().css}`" class="flex-shrink-0"><x-feathericon-trending-up class="w-[15px] h-[15px]" stroke-width="1.75"/></span>
                        <span class="text-[12.5px] text-fg-2">Cumulative from <span class="font-semibold text-fg-1">today</span> → end of <span class="font-semibold text-fg-1" x-text="monthLabel()"></span></span>
                        <span class="flex-1"></span>
                        <span class="font-mono text-[17px] font-semibold tabular-nums tracking-[-0.01em]" :style="`color: ${tone().css}`" x-text="fSignedFull(m.cumFromToday)"></span>
                        <span class="font-mono text-[9px] tracking-[0.08em] uppercase" :style="`color: color-mix(in srgb, ${tone().css} 70%, var(--fg-mute))`">PROJECTED</span>
                    </div>
                </div>

                {{-- ===================== CALENDAR CARD ===================== --}}
                <div class="card card--flat">
                    <div class="{{ $cardHead }}">
                        <div class="{{ $cardTitle }}">
                            <x-feathericon-trending-up class="w-4 h-4 text-fg-3" stroke-width="1.75"/>
                            <span x-text="monthLabel()"></span>
                            <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase px-2 py-[3px] rounded-chip ml-1"
                                  :style="m.type === 'past'
                                      ? 'color: var(--fg-mute); background: var(--bg-elev-3)'
                                      : `color: ${tone().css}; background: ${mix(13, 'transparent')}`"
                                  x-text="typeBadge()"></span>
                        </div>
                        <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[640px]:hidden" x-text="acct().ex + ' · ' + acct().tag"></span>
                    </div>

                    <div class="p-4">
                        <div class="overflow-x-auto">
                            <div class="min-w-[680px]">
                                {{-- weekday header --}}
                                <div class="grid grid-cols-7 gap-1.5 mb-1.5">
                                    <template x-for="(w, wi) in weekdays" :key="w">
                                        <div class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-center py-1.5"
                                             :class="wi >= 5 ? 'text-fg-faint' : 'text-fg-mute'" x-text="w"></div>
                                    </template>
                                </div>
                                {{-- day cells --}}
                                <div class="grid grid-cols-7 gap-1.5">
                                    <template x-for="(_, li) in leadCells()" :key="'l' + li"><div aria-hidden="true"></div></template>
                                    <template x-for="c in m.cells" :key="c.day">
                                        <div>
                                            {{-- empty (no closes) --}}
                                            <template x-if="c.kind === 'empty'">
                                                <div class="relative rounded-control border border-line-soft bg-transparent min-h-[88px] p-2.5 flex flex-col">
                                                    <span class="font-mono text-[10.5px] tabular-nums leading-none font-medium text-fg-mute" x-text="String(c.day).padStart(2, '0')"></span>
                                                    <div class="flex-1 flex flex-col items-center justify-center gap-1 -mt-1">
                                                        <span class="font-mono text-[15px] text-fg-faint leading-none">—</span>
                                                        <span class="font-mono text-[8.5px] tracking-[0.1em] uppercase text-fg-faint">no closes</span>
                                                    </div>
                                                </div>
                                            </template>
                                            {{-- today (anchor) --}}
                                            <template x-if="c.kind === 'today'">
                                                <div class="relative rounded-control min-h-[88px] p-2.5 flex flex-col"
                                                     style="background: color-mix(in srgb, var(--fg-1) 7%, transparent); box-shadow: inset 0 0 0 1.5px var(--fg-2);">
                                                    <div class="flex items-start justify-between">
                                                        <span class="font-mono text-[10.5px] tabular-nums leading-none font-bold text-fg-1" x-text="String(c.day).padStart(2, '0')"></span>
                                                        <span class="font-mono text-[8px] font-bold tracking-[0.12em] uppercase px-1.5 py-[2px] rounded-chip" style="background: var(--fg-1); color: var(--bg-elev-1);">TODAY</span>
                                                    </div>
                                                    <div class="flex-1 flex flex-col justify-end">
                                                        <span class="font-mono font-semibold tabular-nums tracking-[-0.01em] leading-none text-[19px]"
                                                              :class="c.todayHas ? (c.amount >= 0 ? 'text-pnlup' : 'text-pnldown') : 'text-fg-mute'"
                                                              x-text="c.todayHas ? fSigned(c.amount) : '$0'"></span>
                                                        <span class="font-mono text-[8.5px] tracking-[0.08em] uppercase text-fg-mute mt-1" x-text="c.todayHas ? 'realized so far' : 'no closes yet'"></span>
                                                    </div>
                                                </div>
                                            </template>
                                            {{-- projected --}}
                                            <template x-if="c.kind === 'projected'">
                                                <div class="relative rounded-control border border-line-soft min-h-[88px] p-2.5 flex flex-col"
                                                     :style="`background: ${mix(7, 'transparent')}`">
                                                    <div class="flex items-start justify-between">
                                                        <span class="font-mono text-[10.5px] tabular-nums leading-none font-medium"
                                                              :style="`color: color-mix(in srgb, ${tone().css} 55%, var(--fg-mute))`" x-text="String(c.day).padStart(2, '0')"></span>
                                                        <span class="font-mono text-[8px] font-bold tracking-[0.1em] uppercase"
                                                              :style="`color: color-mix(in srgb, ${tone().css} 70%, var(--fg-mute))`">PROJ</span>
                                                    </div>
                                                    <div class="flex-1 flex items-end">
                                                        <span class="font-mono font-medium tabular-nums tracking-[-0.01em] leading-none"
                                                              :style="`font-size: ${cellAmountSize(c)}px; color: color-mix(in srgb, ${tone().css} 60%, var(--fg-2))`"
                                                              x-text="fSigned(c.amount)"></span>
                                                    </div>
                                                </div>
                                            </template>
                                            {{-- realized --}}
                                            <template x-if="c.kind === 'realized'">
                                                <div class="relative rounded-control border border-line-soft min-h-[88px] p-2.5 flex flex-col"
                                                     :style="`background: color-mix(in srgb, ${c.amount >= 0 ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 7%, transparent)`">
                                                    <span class="font-mono text-[10.5px] tabular-nums leading-none font-medium text-fg-mute" x-text="String(c.day).padStart(2, '0')"></span>
                                                    <div class="flex-1 flex items-end">
                                                        <span class="font-mono font-semibold tabular-nums tracking-[-0.01em] leading-none"
                                                              :class="c.amount >= 0 ? 'text-pnlup' : 'text-pnldown'"
                                                              :style="`font-size: ${cellAmountSize(c)}px`"
                                                              x-text="fSigned(c.amount)"></span>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-for="(_, ti) in trailCells()" :key="'t' + ti"><div aria-hidden="true"></div></template>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- legend --}}
                    <div class="flex items-center gap-x-5 gap-y-2 flex-wrap py-3 px-5 border-t border-line-soft">
                        <span x-show="m.type !== 'future'" class="inline-flex items-center gap-2 font-mono text-[10.5px] text-fg-3 whitespace-nowrap">
                            <span class="w-3.5 h-2.5 rounded-[3px]" style="background: color-mix(in srgb, var(--pnl-up-fg) 22%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--pnl-up-fg) 40%, transparent);"></span>Realized gain
                        </span>
                        <span x-show="m.type !== 'future'" class="inline-flex items-center gap-2 font-mono text-[10.5px] text-fg-3 whitespace-nowrap">
                            <span class="w-3.5 h-2.5 rounded-[3px]" style="background: color-mix(in srgb, var(--pnl-down-fg) 22%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--pnl-down-fg) 40%, transparent);"></span>Realized loss
                        </span>
                        <span x-show="m.type === 'current'" class="inline-flex items-center gap-2 font-mono text-[10.5px] text-fg-3 whitespace-nowrap">
                            <span class="w-3.5 h-2.5 rounded-[3px]" style="box-shadow: inset 0 0 0 1.5px var(--fg-2);"></span>Today
                        </span>
                        <span x-show="m.type !== 'past'" class="inline-flex items-center gap-2 font-mono text-[10.5px] text-fg-3 whitespace-nowrap">
                            <span class="w-3.5 h-2.5 rounded-[3px]" :style="`background: ${mix(16, 'transparent')}; box-shadow: inset 0 0 0 1px color-mix(in srgb, ${tone().css} 35%, transparent)`"></span>
                            <span>Projected · <span :style="`color: ${tone().css}`" x-text="tone().label"></span></span>
                        </span>
                        <span x-show="m.type !== 'future'" class="inline-flex items-center gap-2 font-mono text-[10.5px] text-fg-3 whitespace-nowrap">
                            <span class="font-mono text-fg-faint text-[12px] leading-none w-3.5 text-center">—</span>No closes
                        </span>
                        <span class="flex-1"></span>
                        <span class="font-mono text-[10px] text-fg-mute tracking-[0.04em]" x-text="m.type === 'past' ? 'REALIZED HISTORY' : m.type === 'future' ? 'PURE PROJECTION' : 'REALIZED → PROJECTED'"></span>
                    </div>
                </div>

                {{-- ===================== FOOTNOTES ===================== --}}
                <div class="mt-6 rounded-control border border-line-soft bg-surface px-5 py-4 flex flex-col gap-2.5">
                    <div class="flex items-start gap-2.5">
                        <span class="flex-shrink-0 mt-0.5 text-fg-mute"><x-feathericon-activity class="w-3.5 h-3.5" stroke-width="1.75"/></span>
                        <p class="text-[12px] leading-[1.55] text-fg-3 m-0" x-show="m.type !== 'past'">
                            Projection compounds daily from today's wallet of <span class="font-mono text-fg-2" x-text="fFull(m.S0)"></span> at
                            <span class="font-mono" :style="`color: ${tone().css}`" x-text="fPct(m.rate * 100, 2) + '/day'"></span> — the
                            <span :style="`color: ${tone().css}; font-weight: 600`" x-text="tone().label.toLowerCase()"></span> daily rate, derived from
                            <span class="font-mono text-fg-2" x-text="m.rates.n"></span> observed trading <span x-text="m.rates.n === 1 ? 'day' : 'days'"></span> this month.
                            Observed range: <span class="font-mono text-pnldown" x-text="fPct(m.rates.pess * 100, 2)"></span> … <span class="font-mono text-pnlup" x-text="fPct(m.rates.opt * 100, 2)"></span> per day.
                        </p>
                        <p class="text-[12px] leading-[1.55] text-fg-3 m-0" x-show="m.type === 'past'" x-cloak>
                            <span class="font-mono text-fg-2" x-text="monthLabel()"></span> is realized history — actual revenue from closed positions. No projection is applied to past months.
                        </p>
                    </div>
                    <div class="flex items-start gap-2.5">
                        <span class="flex-shrink-0 mt-0.5 text-fg-mute"><x-feathericon-alert-triangle class="w-3.5 h-3.5" stroke-width="1.75"/></span>
                        <p class="text-[12px] leading-[1.55] text-fg-mute m-0">
                            Projections are <span class="font-semibold text-fg-3">illustrative only</span> and not a guarantee of future revenue. Compounding assumes a constant daily rate and reinvestment; real markets are volatile and the Black-Swan engine resizes or halts trading as risk escalates. Realized results will differ — potentially materially.
                        </p>
                    </div>
                </div>
            </div>
        </template>
        @endif

    </div>

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
