@php
    // ============================================================
    // MOCK DATA — design-fidelity port. The whole calendar model runs
    // client-side (deterministic seeded rng), exactly like the design:
    // realized history, scenario rates, and forward compounding all
    // derive from the mock account list below. Wire to
    // ProjectionsController::data later.
    // ============================================================
    $regime = 'ELEVATED';
    $score = 0.63;

    // REAL first-run gate: projections are built from realized revenue —
    // an account that has never traded has nothing to project.
    $accountIds = auth()->user()->is_admin
        ? null
        : Kraite\Core\Models\Account::where('user_id', auth()->id())->pluck('id');
    $noPositions = ! Kraite\Core\Models\Position::query()
        ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds))
        ->exists();

    $regimes = [
        'CALM'        => ['color' => 'var(--bsi-calm)'],
        'WATCH'       => ['color' => 'var(--bsi-watch)'],
        'ELEVATED'    => ['color' => 'var(--bsi-cascade)'],
        'CASCADE'     => ['color' => 'var(--bsi-cascade)'],
        'BLACK SWAN'  => ['color' => 'var(--bsi-blackswan)'],
    ];
    $r = $regimes[$regime] ?? $regimes['CALM'];

    $downAccount = ['ex' => 'OKX', 'tag' => 'arb', 'note' => 'last seen 4m ago'];

    // shared control class strings
    $pjArrow = 'appearance-none cursor-pointer w-[34px] h-[34px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2';
    $cardHead = 'flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft';
    $cardTitle = 'font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap';
@endphp

<x-app-layout active="projections" :title="'Kraite — Projections'" :showBanner="true" :downAccount="$downAccount">

    <script>
        // Projections page model — straight port of the design's client-side
        // calendar. Past days replay a seeded rng reconciled to the account
        // wallet; future days compound the wallet at an observed daily rate.
        window.projectionsPage = () => {
            // ---- constants ----
            const TODAY = new Date(Date.UTC(2026, 5, 5));           // frozen: Jun 5 2026 (deterministic mock)
            const CUR_Y = 2026, CUR_M = 5;                           // June (0-indexed)
            const MON = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            const HISTORY_MONTHS = 14;                               // realized history extends this far back
            const FUTURE_MONTHS = 72;                                // ~6 years forward
            const NEUTRAL_M = 0.16;                                  // assumed monthly growth to back-date past start balances
            const ABS_CUR = CUR_Y * 12 + CUR_M;
            const ABS_MIN = ABS_CUR - HISTORY_MONTHS;
            const ABS_MAX = ABS_CUR + FUTURE_MONTHS;

            // scenario tone — chrome only (active segment, projected totals, light cell tint)
            const TONE = {
                pess:    { label: 'Pessimistic', css: 'var(--pnl-down-fg)', activeText: '#fff' },
                neutral: { label: 'Neutral',     css: 'var(--info)',        activeText: '#fff' },
                opt:     { label: 'Optimistic',  css: 'var(--pnl-up-fg)',   activeText: '#04140d' },
            };

            // ---- deterministic rng ----
            const seedOf = (s) => { let h = 2166136261 >>> 0; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; };
            const rngOf = (a) => { a = a >>> 0; return () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), 1 | t); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; };

            // ---- mock exchange accounts ----
            const num = (s) => parseFloat(String(s).replace(/[^0-9.]/g, '')) || 0;
            const ACCTS = [
                { ex: 'Binance', mono: 'B',  tag: 'main',    state: 'ok',   equityStr: '$184,210.08', note: 'Futures · cross' },
                { ex: 'Bybit',   mono: 'BY', tag: 'hedge',   state: 'ok',   equityStr: '$62,840.12',  note: 'Perp · isolated' },
                { ex: 'OKX',     mono: 'O',  tag: 'arb',     state: 'down', equityStr: '$24,980.55',  note: 'Last seen 4m ago' },
                { ex: 'Deribit', mono: 'D',  tag: 'options', state: 'ok',   equityStr: '$12,879.67',  note: 'Options · portfolio' },
            ].map(a => ({ ...a, wallet: num(a.equityStr), seed: seedOf(a.ex + '|' + a.tag) }));

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

            // ---- model ----
            const dimOf = (y, m) => new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
            const idxOf = (y, m, d) => Math.round((Date.UTC(y, m, d) - TODAY.getTime()) / 86400000); // 0 = today
            const monthType = (y, m) => (y === CUR_Y && m === CUR_M) ? 'current'
                : (y > CUR_Y || (y === CUR_Y && m > CUR_M)) ? 'future' : 'past';

            // realized daily revenue for a month (reconciled so the current
            // month's today-balance equals the account wallet exactly).
            const realizedOf = (acct, year, month) => {
                const dim = dimOf(year, month);
                const isCur = (year === CUR_Y && month === CUR_M);
                const upto = isCur ? TODAY.getUTCDate() : dim;
                const rng = rngOf(acct.seed ^ Math.imul(((year * 16 + month) >>> 0), 2654435761));
                const raw = [];
                for (let d = 1; d <= upto; d++) {
                    if (rng() < 0.17) { raw.push({ has: false, r: 0 }); continue; }   // no closes that day
                    let r = 0.0055 + (rng() * 2 - 1) * 0.016;                          // ~ −1.0% … +2.1%
                    if (rng() < 0.10) r = -(0.018 + rng() * 0.020);                    // bad day  −1.8% … −3.8%
                    else if (rng() < 0.12) r = 0.026 + rng() * 0.012;                  // big day  +2.6% … +3.8%
                    raw.push({ has: true, r });
                }
                let target;
                if (isCur) target = acct.wallet;
                else target = acct.wallet / Math.pow(1 + NEUTRAL_M, (CUR_Y - year) * 12 + (CUR_M - month));
                const cumF = raw.reduce((f, x) => f * (1 + (x.has ? x.r : 0)), 1);
                const startedAt = target / cumF;
                let bal = startedAt;
                const days = raw.map(x => {
                    const before = bal; bal = bal * (1 + (x.has ? x.r : 0));
                    return { has: x.has, revenue: x.has ? bal - before : null };
                });
                return { startedAt, days, endBal: bal, upto, dim };
            };

            // scenario daily rates from the CURRENT month's observed days
            const scenarioRates = (acct) => {
                const cur = realizedOf(acct, CUR_Y, CUR_M);
                const rates = [];
                let bal = cur.startedAt;
                cur.days.forEach(x => { const before = bal; bal = before + (x.revenue || 0); if (x.has) rates.push((x.revenue) / before); });
                rates.sort((a, b) => a - b);
                const n = rates.length;
                const worst = n ? rates[0] : -0.012;
                const best = n ? rates[n - 1] : 0.018;
                return {
                    pess: worst,
                    neutral: (worst + best) / 2,          // midpoint of the observed range
                    opt: best,
                    n, wallet: acct.wallet, startedAt: cur.startedAt,
                };
            };

            const buildMonth = (acct, year, month, scenario) => {
                const type = monthType(year, month);
                const dim = dimOf(year, month);
                const rates = scenarioRates(acct);
                const rate = rates[scenario];
                const S0 = rates.wallet;
                const cells = [];
                let startedAt, realized = null, projected = null, endBal;

                if (type === 'past') {
                    const ser = realizedOf(acct, year, month);
                    for (let d = 1; d <= dim; d++) {
                        const x = ser.days[d - 1];
                        cells.push({ day: d, kind: x.has ? 'realized' : 'empty', amount: x.has ? x.revenue : null });
                    }
                    startedAt = ser.startedAt; realized = ser.endBal - ser.startedAt; endBal = ser.endBal;
                } else if (type === 'current') {
                    const ser = realizedOf(acct, year, month);
                    const todayD = TODAY.getUTCDate();
                    for (let d = 1; d <= dim; d++) {
                        if (d < todayD) { const x = ser.days[d - 1]; cells.push({ day: d, kind: x.has ? 'realized' : 'empty', amount: x.has ? x.revenue : null }); }
                        else if (d === todayD) { const x = ser.days[d - 1]; cells.push({ day: d, kind: 'today', amount: x.has ? x.revenue : 0, todayHas: x.has }); }
                        else { const k = d - todayD; cells.push({ day: d, kind: 'projected', amount: S0 * Math.pow(1 + rate, k) - S0 * Math.pow(1 + rate, k - 1) }); }
                    }
                    startedAt = ser.startedAt; realized = S0 - ser.startedAt;
                    endBal = S0 * Math.pow(1 + rate, dim - todayD); projected = endBal - S0;
                } else {
                    for (let d = 1; d <= dim; d++) {
                        const k = idxOf(year, month, d);
                        cells.push({ day: d, kind: 'projected', amount: S0 * Math.pow(1 + rate, k) - S0 * Math.pow(1 + rate, k - 1) });
                    }
                    startedAt = S0 * Math.pow(1 + rate, idxOf(year, month, 1) - 1);
                    endBal = S0 * Math.pow(1 + rate, idxOf(year, month, dim));
                    projected = endBal - startedAt;
                }
                return {
                    type, dim, cells, startedAt, realized, projected, endBal,
                    monthlyPct: (endBal / startedAt - 1) * 100,
                    cumFromToday: endBal - S0, rate, rates, S0,
                    firstWeekday: (new Date(Date.UTC(year, month, 1)).getUTCDay() + 6) % 7,   // Mon=0
                };
            };

            return {
                // ---- exposed statics ----
                accts: ACCTS,
                monNames: MON,
                monShort: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                weekdays: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
                scens: [['pess', 'Pessimistic'], ['neutral', 'Neutral'], ['opt', 'Optimistic']],
                futureYears: Math.floor(FUTURE_MONTHS / 12),

                // ---- state ----
                acctIdx: 0,
                scenario: 'neutral',
                ym: { year: CUR_Y, month: CUR_M },
                loading: false,
                acctOpen: false,
                monOpen: false,
                pYear: CUR_Y,
                m: null,
                totalsCells: [],
                _shimmer: null,

                init() { this.recompute(false); },

                // ---- derived helpers ----
                tone() { return TONE[this.scenario] || TONE.neutral; },
                acct() { return ACCTS[this.acctIdx]; },
                absYm() { return this.ym.year * 12 + this.ym.month; },
                isCurrentMonth() { return this.absYm() === ABS_CUR; },
                atMin() { return this.absYm() <= ABS_MIN; },
                atMax() { return this.absYm() >= ABS_MAX; },
                minYear: Math.floor(ABS_MIN / 12),
                maxYear: Math.floor(ABS_MAX / 12),
                inRange(y, m) { const abs = y * 12 + m; return abs >= ABS_MIN && abs <= ABS_MAX; },
                isCurYm(y, m) { return y === CUR_Y && m === CUR_M; },
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
                setAcct(i) { this.acctIdx = i; this.acctOpen = false; this.recompute(true); },
                setScenario(k) { if (this.m.type === 'past') return; this.scenario = k; this.recompute(false); },
                pickYm(y, m) { this.ym = { year: y, month: m }; this.monOpen = false; this.recompute(true); },
                shift(n) {
                    const abs = Math.min(ABS_MAX, Math.max(ABS_MIN, this.absYm() + n));
                    this.ym = { year: Math.floor(abs / 12), month: abs % 12 };
                    this.recompute(true);
                },
                goToday() { this.pickYm(CUR_Y, CUR_M); },

                recompute(shimmer) {
                    this.m = buildMonth(this.acct(), this.ym.year, this.ym.month, this.scenario);
                    this.totalsCells = this.buildTotals();
                    if (shimmer) {
                        if (this._shimmer) clearTimeout(this._shimmer);
                        this.loading = true;
                        this._shimmer = setTimeout(() => { this.loading = false; this._shimmer = null; }, 340);
                    }
                },

                // ---- totals strip (port of PJTotals) ----
                buildTotals() {
                    const m = this.m, t = m.type, tone = this.tone();
                    const labels = t === 'past'
                        ? ['Started month at', 'Realized this month', 'Projected', 'Ended at', 'Monthly return']
                        : t === 'future'
                            ? ['Starts month at', 'Realized', 'Projected this month', 'Expected end balance', 'Monthly return']
                            : ['Started month at', 'Made so far', 'Projected · rest of month', 'Expected end balance', 'Monthly return'];
                    const realizedCell = t === 'future'
                        ? { value: '—', cls: 'text-fg-faint', sub: '' }
                        : { value: fmtSignedFull(m.realized), cls: m.realized >= 0 ? 'text-pnlup' : 'text-pnldown', sub: 'REALIZED' };
                    const projectedCell = t === 'past'
                        ? { value: '—', cls: 'text-fg-faint', sub: '' }
                        : { value: fmtSignedFull(m.projected), css: tone.css, sub: tone.label.toUpperCase() + ' · PROJ' };
                    const monthlyCss = t === 'past' ? (m.monthlyPct >= 0 ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)') : tone.css;
                    // current month blends realized + projected — surface the split
                    const realPct = (m.S0 / m.startedAt - 1) * 100;
                    const projPct = (m.endBal / m.S0 - 1) * 100;
                    const monthlySub = t === 'past' ? 'REALIZED'
                        : t === 'current' ? `${fmtPct(realPct, 1)} REAL · ${fmtPct(projPct, 1)} PROJ`
                        : tone.label.toUpperCase() + ' · PROJ';
                    return [
                        { label: labels[0], value: fmtFull(m.startedAt), css: 'var(--fg-1)', cls: '', sub: 'OPENING BALANCE' },
                        { label: labels[1], value: realizedCell.value, cls: realizedCell.cls || '', css: null, sub: realizedCell.sub },
                        { label: labels[2], value: projectedCell.value, cls: projectedCell.cls || '', css: projectedCell.css || null, sub: projectedCell.sub },
                        { label: labels[3], value: fmtFull(m.endBal), css: 'var(--fg-1)', cls: '', sub: t === 'past' ? 'REALIZED' : 'EXPECTED' },
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

    <div x-data="projectionsPage()">

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
                <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                      style="background: color-mix(in srgb, {{ $r['color'] }} 12%, transparent); border-color: color-mix(in srgb, {{ $r['color'] }} 38%, transparent); color: {{ $r['color'] }};">
                    <span class="w-2 h-2 rounded-chip {{ in_array($regime, ['CASCADE', 'BLACK SWAN'], true) ? 'animate-pulse-soft' : '' }}" style="background: {{ $r['color'] }};"></span>
                    {{ $regime }}<span class="opacity-70 ml-0.5">{{ number_format($score, 2) }}</span>
                </span>
                <div class="w-px h-[22px] bg-line"></div>
                <button type="button" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/>Sync
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
                                <button type="button" class="{{ $pjArrow }} !w-[28px] !h-[28px]" :disabled="pYear <= minYear" @click="pYear--" aria-label="Previous year">
                                    <x-feathericon-chevron-left class="w-3.5 h-3.5" stroke-width="1.75"/>
                                </button>
                                <span class="font-mono text-[14px] font-semibold text-fg-1 tabular-nums" x-text="pYear"></span>
                                <button type="button" class="{{ $pjArrow }} !w-[28px] !h-[28px]" :disabled="pYear >= maxYear" @click="pYear++" aria-label="Next year">
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
