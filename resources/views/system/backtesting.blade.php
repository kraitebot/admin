@php
    // ===== TOKEN UNIVERSE (flattened for the Alpine selector) =====
    // The controller groups symbols by quote; the client re-groups (USDT/USDC
    // first), so a flat list with `quote` on each row is all the front-end needs.
    // Each token pre-fills the config form on select.
    $btSymbols = [];
    foreach ($symbols as $group) {
        foreach ($group as $it) {
            $mult = $it['limit_quantity_multipliers'] ?? null;
            if (is_string($mult)) {
                $decoded = json_decode($mult, true);
                $mult = is_array($decoded) ? $decoded : null;
            }

            $btSymbols[] = [
                'id' => $it['id'],
                'token' => $it['token'],
                'img' => $it['image_url'] ?? null,
                'quote' => $it['quote'],
                'exchange' => ucfirst((string) $it['exchange']),
                'rank' => $it['cmc_ranking'],
                'cat' => $it['cmc_category'] ?? '',
                'gapL' => $it['percentage_gap_long'] !== null ? (string) $it['percentage_gap_long'] : '',
                'gapS' => $it['percentage_gap_short'] !== null ? (string) $it['percentage_gap_short'] : '',
                'orders' => $it['total_limit_orders'],
                'mult' => is_array($mult) ? implode(',', $mult) : '',
                'status' => $it['backtesting_review_status'] ?: null,
                'decidedAt' => $it['backtesting_reviewed_at'] ?? null,
                // The configuration currently live on the token — what the
                // standing decision was actually taken against.
                'liveTp' => $it['profit_percentage'] !== null ? (string) $it['profit_percentage'] : '',
                'liveSl' => $it['stop_market_percentage'] !== null ? (string) $it['stop_market_percentage'] : '',
                'direction' => $it['direction'],
                'immediateTradeable' => $it['is_immediately_tradeable'],
            ];
        }
    }

    $btConfig = [
        'symbols' => $btSymbols,
        'defaults' => $defaults,
        'timeframes' => array_values($timeframes),
        'routes' => [
            'fetch' => route('system.backtesting.fetch-candles'),
            'verify' => route('system.backtesting.verify-coverage'),
            'run' => route('system.backtesting.run'),
            'adjust' => route('system.backtesting.suggest-adjustment'),
            'toggle' => route('system.backtesting.toggle-approval'),
            'ai' => route('system.backtesting.ai-insights'),
            'ensure' => route('system.backtesting.ensure-coverage'),
            'status' => route('system.backtesting.coverage-status'),
        ],
    ];
@endphp

<x-app-layout active="backtesting" :title="'Kraite — Backtesting'">
    <script>
        // Backtesting console — single-token ladder backtester wired to the five
        // BacktrackingController endpoints. Flow: Fetch → Verify → Run → Approve,
        // with an optional AI Insights side-trip after Run. All server I/O goes
        // through hubUiFetch (CSRF + JSON + { ok, data }).
        window.btConsole = (config) => ({
            // ---- injected ----
            symbols: config.symbols,
            defaults: config.defaults,
            timeframes: config.timeframes,
            routes: config.routes,

            // ---- selection + form ----
            selId: null,
            // Default to the daily timeframe; fall back to the first available.
            tf: config.timeframes.includes('1d') ? '1d' : (config.timeframes[0] || '1d'),
            cfg: {},
            selOpen: false,
            cfgOpen: false,                      // Config card collapsed by default
            query: '',
            filters: { top100: false, approved: false, notConcluded: false, immediateTradeable: false },

            // ---- async results ----
            cov: null,
            fetchReport: null,
            fetchOpen: true,
            busy: null,                          // 'fetch' | 'verify' | 'run'
            result: null,                        // raw server result { rows, totals, regimes, meta }
            pair: null,
            reviews: {},                         // id -> status override
            decisions: {},                       // id -> decision timestamp override
            ai: { loading: false, text: null, model: null },
            coverageWarning: null,               // data-not-ready alert (blocks the grade)
            coverageProgress: null,              // live "Fetching history… N/M" during the ensure-coverage block
            adjust: { loading: false, done: false, candidates: null, best: null }, // smart-adjustment search (5–10 bad band)

            // ---- SL-coverage card chips (status = failure counts, side = panel filter) ----
            statusFilter: 'all',
            dirFilter: 'all',

            // ---- overlays ----
            toast: null,
            _toastTimer: null,

            // ================= static meta =================
            REVIEW_META: {
                approved: { label: 'Approved',     color: 'var(--pnl-up-fg)' },
                rejected: { label: 'Rejected',     color: 'var(--pnl-down-fg)' },
                null:     { label: 'Not reviewed', color: 'var(--fg-mute)' },
            },
            GRADE_COLOR: { A: 'var(--pnl-up-fg)', B: '#15b8a6', C: 'var(--warn)', D: '#ff8a3d', F: 'var(--danger)' },

            // Explainer copy for every results label. Keyed by the topic the
            // inline "[?]" dots pass to openHelp(); body is markdown rendered
            // through renderMd() in the help modal. Definitions track the
            // simulator + AI-insights semantics so the panel is self-documenting.
            HELP_META: {
                cov_earliest: { t: 'Earliest candle', s: 'Oldest stored candle for this token & timeframe.', b: "Oldest candle present for this token and timeframe. The backtest can only simulate trades from this point forward — anything before it is invisible to the engine, so a token listed mid-window simply has a later earliest." },
                cov_latest: { t: 'Latest candle', s: 'Most recent stored candle — Fetch pulls forward to now.', b: "Newest candle present. The risk gate requires this to be the **last closed candle** (fresh). If the data is stale, grading and approval are **blocked** — a real-money decision is never made on outdated prices." },
                cov_candles: { t: 'Candles', s: 'Total candles available across the window.', b: "Total OHLCV candles in the window. Each candle is a possible simulated entry, so more candles means a statistically stronger grade. Thin history is penalised by the sample-size guard." },
                cov_contiguity: { t: 'Contiguity', s: 'Share of the window with no gaps — gaps weaken the grade.', b: "Percentage of expected candles actually present, with no gaps.\n\n- **100%** — an unbroken series\n- **Below 100%** — missing candles that could bias the result\n\nThe gate requires **≥ 99%** before it will grade." },
                grade_verdict: { t: 'Grade · verdict', s: 'Letter grade and a one-line read on this config.', b: "The simulator's letter grade (**A–F**) for this exact config, plus a one-line plain-English verdict.\n\n- **A / B** — system proposes *approve*\n- **C** — borderline, review manually\n- **D / F** — proposes *reject*\n\nThe grade blends win rate, risk, ladder depth and speed into one call — **capped by the stop-loss decision rule** so it can never contradict the proposal: more than 10 stop-loss hits grades at best **D**, 5–10 at best **C**." },
                overall_score: { t: 'Overall score', s: 'Composite 0–100 score across pass rate, risk and regime stability.', b: "Composite score from **0–100** behind the grade. It blends pass rate, risk (Max MAE), average rung depth and throughput. Higher is better — the single headline number for the config." },
                risk_score: { t: 'Risk score', s: '0–100 risk score — higher means more drawdown / liquidation exposure.', b: "Risk sub-score from **0–100** — **lower is better**. Driven mainly by Max MAE and how often the ladder reaches its deepest rung, i.e. how close the config flirts with liquidation. A high pass rate with a high risk score is still a dangerous config." },
                pass_rate: { t: 'Pass rate', s: 'Resolved sims that closed in profit — TP hit or WAP rebound.', b: "Share of **resolved** simulations that closed in profit — a take-profit on the market leg, or a weighted-average-price rebound. Inconclusive sims are excluded. This is the core *does this config win?* number." },
                max_daily_amplitude: { t: 'Max daily amplitude %', s: 'Largest price range recorded inside one UTC calendar day.', b: "For every **UTC calendar day**, this takes the lowest candle low and highest candle high, then calculates **(high − low) ÷ previous daily close × 100** — matching Binance's daily range percentage.\n\nThe first available day has no prior close and is excluded. This measures market movement only — independent of trade direction, ladder settings and simulated outcomes." },
                avg_rung_depth: { t: 'Avg rung depth', s: 'Average ladder rung reached before close, out of 4.', b: "Average ladder rung reached across all sims, out of **4**. Higher means the strategy averaged-down deeper on average — more capital committed per trade and more exposure if price keeps running." },
                avg_to_profit: { t: 'Avg → profit', s: 'Mean candles from entry to a profitable close.', b: "Average number of candles from entry to a profitable close. Lower is better: faster closes free capital sooner and compound the small edge more often." },
                p95_to_profit: { t: 'P95 → profit', s: '95th-percentile candles to profit — the slow tail.', b: "The 95th-percentile candles-to-profit — **95% of winning trades closed within this many candles**. It exposes the slow tail the average hides: rare trades that lock capital for a long time." },
                sample_size: { t: 'Sample size', s: 'Sims behind these stats — thin samples weaken the verdict.', b: "Number of simulations behind the stats. Every start candle simulates **both LONG and SHORT**, so sims ≈ 2× start candles.\n\nThe statistical threshold is **180 start candles** (~half a year of daily data): below it the grade takes a thinness penalty and the proposal falls back to *review manually* — the verdict simply doesn't have enough evidence yet." },
                verdict_breakdown: { t: 'Verdict breakdown', s: 'How every simulation closed, split by outcome class.', b: "How every simulation ended:\n\n- **TP off market leg** — hit take-profit on the opening market leg alone, before any limit rung filled. The cleanest win.\n- **Reboundable (WAP)** — deeper rungs filled, then a weighted-average-price retrace closed it in profit. The martingale working as designed.\n- **Stopped out** — hit stop-loss after the deepest rung. A realised loss — the failure mode that matters for risk.\n- **Inconclusive** — ran out of forward data before TP or SL fired (usually very recent starts). Not actionable, excluded from pass rate.\n- **Skipped (sizing)** — the trade couldn't even be placed (order sizing rejected for this token's price/lot rules). Shown only when present: many skips mean the run tested less than it looks like." },
                sl_coverage: { t: 'Stop-loss coverage', s: 'The SL width that would have absorbed each share of the stops.', b: "For each share of this run's **stopped trades** (25% / 50% / 75% / all), the stop-loss width that would have absorbed them — shown per direction, since a token often bleeds differently up vs down.\n\nRead it as a *price tag* on rescuing stops by widening SL:\n\n- **Small deltas** — most stops were near-misses; a slightly wider SL is a cheap fix.\n- **A huge \"All\" value** — some stops were deep trend events; no reasonable SL saves them, so attack severity (fewer rungs, less leverage) instead.\n\nRemember the loss math: a wider SL on a fully-filled ladder multiplies the realised loss per stop." },
                rung_distribution: { t: 'Rung distribution', s: 'How deep into the 4-rung ladder sims went before resolving.', b: "How many sims reached each ladder rung — i.e. filled that limit order.\n\n**Rung 1** is shallow; **Rung 4** is the deepest level of averaging-down and carries the most liquidation risk. The reach rate of the **deepest** rung is the single most important risk signal — a config that often hits rung 4 is one bad trend away from a large loss." },
                config_echo: { t: 'Tested config', s: 'The exact ladder parameters this run used.', b: "The exact parameters this run was graded on:\n\n- **TP** — take-profit %, recomputed off WAP after each rung fill\n- **SL** — stop-loss %, arms only after the deepest rung is touched\n- **Gap L / Gap S** — % spacing between ladder rungs for long / short\n- **Lev** — leverage on notional (fixed 20× for backtests; ~5% adverse ≈ liquidation)\n- **Mult** — per-rung quantity multipliers; **[2,2,2,2]** doubles each rung, so 1+2+4+8+16 = **31×** the market leg at full fill\n- **Window** — the history span and timeframe simulated" },
            },

            // Watch the universe filters: if the chosen token drops out of the
            // filtered set, hand selection to the first token that survives
            // (full selectToken reset), or clear it when nothing matches.
            // Search typing (`query`) deliberately does NOT re-select.
            init() {
                // Publish this console's help topics to the global help store so
                // the shared x-ui.help-dot / x-ui.help-modal components can render them.
                this.$store.help.register(this.HELP_META);

                this.$watch('filters', () => {
                    if (this.selId === null || this.tokenLocked) return;
                    if (this.filteredSymbols.some((s) => s.id === this.selId)) return;
                    const first = this.filteredSymbols[0];
                    if (first) { this.selectToken(first); } else { this.selId = null; }
                });
            },

            // ================= derived =================
            get selected() { return this.symbols.find((s) => s.id === this.selId) || null; },
            // Token switching is locked while any server op runs against the
            // current selection — a mid-run swap would show one token's results
            // under another token's header (and applyAdjustment would push the
            // wrong config).
            get tokenLocked() { return !!this.busy || this.adjust.loading; },
            get status() {
                if (!this.selected) return null;
                return this.reviews[this.selected.id] !== undefined ? this.reviews[this.selected.id] : this.selected.status;
            },
            reviewMeta(status) { return this.REVIEW_META[status == null ? 'null' : status]; },

            // When the standing decision was taken. Reads the fresh value first
            // so a decision made in this session dates itself immediately.
            get decidedAt() {
                if (!this.selected) return null;
                const stamp = this.decisions[this.selected.id] !== undefined
                    ? this.decisions[this.selected.id]
                    : this.selected.decidedAt;

                if (!stamp) return null;

                const at = new Date(stamp);

                return isNaN(at.getTime()) ? null : at;
            },
            get decidedAtLabel() {
                const at = this.decidedAt;

                if (!at) return null;

                return at.toLocaleString(undefined, {
                    day: '2-digit', month: 'short', year: 'numeric',
                    hour: '2-digit', minute: '2-digit',
                });
            },

            // Pull the configuration the token is actually live with — the one
            // the standing decision was taken against — back into the form, so
            // a re-test starts from the approved conditions instead of the
            // account defaults selectToken() seeds.
            get hasLiveConfiguration() {
                const s = this.selected;

                return !!s && !!(s.liveTp || s.liveSl || s.gapL || s.gapS);
            },
            loadLiveConfiguration() {
                const s = this.selected;

                if (!s || this.tokenLocked || !this.hasLiveConfiguration) return;

                this.cfg = {
                    ...this.cfg,
                    tp: s.liveTp || this.cfg.tp,
                    sl: s.liveSl || this.cfg.sl,
                    gapL: s.gapL || this.cfg.gapL,
                    gapS: s.gapS || this.cfg.gapS,
                };
                this.cfgOpen = true;
                this.flashToast('Loaded the configuration this token is live with', 'ok');
            },

            quoteOrder(q) { return q === 'USDT' ? 0 : q === 'USDC' ? 1 : 2; },
            // Stable per-token hue → each coin gets its own avatar without inventing brand palettes.
            tokenHue(sym) { let h = 0; for (let i = 0; i < sym.length; i++) { h = (h * 31 + sym.charCodeAt(i)) >>> 0; } return Math.round(((h * 0.6180339887) % 1) * 360); },
            get filteredSymbols() {
                const q = this.query.toLowerCase();
                return this.symbols.filter((s) => {
                    // Token-universe filters (the checkboxes below the selector).
                    if (this.filters.top100 && (s.rank == null || s.rank > 100)) return false;
                    // Every checked filter narrows the current result set. This
                    // keeps combinations such as Immediate Tradeable + Top 100
                    // as a strict intersection.
                    if (this.filters.approved && s.status !== 'approved') return false;
                    if (this.filters.notConcluded && s.status != null) return false;
                    if (this.filters.immediateTradeable && ! s.immediateTradeable) return false;
                    // Search query.
                    return (s.token + ' ' + s.quote).toLowerCase().includes(q);
                });
            },
            // Live universe counts — always over the FULL symbol set, so each
            // checkbox shows its total reach regardless of the others' state.
            get countTop100() { return this.symbols.filter((s) => s.rank != null && s.rank <= 100).length; },
            get countApproved() { return this.symbols.filter((s) => s.status === 'approved').length; },
            get countNotConcluded() { return this.symbols.filter((s) => s.status == null).length; },
            get countImmediateTradeable() { return this.symbols.filter((s) => s.immediateTradeable).length; },
            get quoteGroups() {
                const quotes = [...new Set(this.filteredSymbols.map((s) => s.quote))]
                    .sort((a, b) => this.quoteOrder(a) - this.quoteOrder(b) || a.localeCompare(b));
                return quotes.map((q) => ({ quote: q, items: this.filteredSymbols.filter((s) => s.quote === q) }));
            },

            // ================= selection =================
            selectToken(s) {
                if (this.tokenLocked) return;
                this.selId = s.id;
                this.selOpen = false;
                this.query = '';
                this.cfg = {
                    tp: this.defaults.tp_percent, sl: this.defaults.sl_percent,
                    gapL: s.gapL, gapS: s.gapS,
                    taapi: true, max_months: '',
                };
                this.cov = null; this.fetchReport = null; this.result = null;
                this.ai = { loading: false, text: null, model: null };
                this.coverageWarning = null;
                this.adjust = { loading: false, done: false, candidates: null, best: null };
                this.statusFilter = 'all'; this.dirFilter = 'all';
            },

            // ================= AJAX ops =================
            mapCov(c) {
                return {
                    earliest: c.earliest || '—',
                    latest: c.latest || '—',
                    candles: c.total_present || 0,
                    holes: c.holes_count || 0,
                    contiguity: c.contiguity_percent != null ? c.contiguity_percent : 100,
                    is_fresh: !!c.is_fresh,
                    staleness_hours: c.staleness_hours != null ? c.staleness_hours : null,
                };
            },
            coverageWindowCovered(c) {
                if (!c || !c.earliest || c.earliest === '—') return false;
                const earliest = new Date(c.earliest.replace(' ', 'T') + 'Z');
                const requiredSince = new Date();
                requiredSince.setUTCHours(0, 0, 0, 0);
                requiredSince.setUTCDate(1);
                requiredSince.setUTCMonth(requiredSince.getUTCMonth() - Number(this.cfg.max_months || 24));
                return earliest <= requiredSince;
            },
            coverageHealthy(c) {
                return !!c && c.is_fresh && c.holes === 0 && this.coverageWindowCovered(c);
            },
            coverageLabel(c) {
                if (!c.is_fresh) return `Stale — ${c.staleness_hours != null ? Math.round(c.staleness_hours) + 'h behind' : 'not current'}`;
                if (c.holes > 0) return `${c.holes} gap${c.holes > 1 ? 's' : ''} · ${c.contiguity}% contiguity`;
                if (!this.coverageWindowCovered(c)) return `Thin history — ${c.candles} candles`;
                return 'Complete & live';
            },

            async doFetch() {
                if (!this.selected || this.busy) return false;
                this.busy = 'fetch';
                const { ok, data } = await hubUiFetch(this.routes.fetch, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                    since: this.cfg.since || null,
                    candles_back: this.cfg.candles_back ? Number(this.cfg.candles_back) : null,
                    taapi_topup: !!this.cfg.taapi,
                    max_months: this.cfg.max_months ? Number(this.cfg.max_months) : undefined,
                } });
                this.busy = null;
                if (!ok) { this.flashToast(data.error || 'Fetch failed', 'error'); return false; }

                this.fetchReport = {
                    message: data.message,
                    vision: data.vision
                        ? { text: `${data.vision.months_downloaded || 0} new + ${data.vision.months_already_covered || 0} months covered`, sub: `+${(data.vision.candles_upserted || 0).toLocaleString()} candles` }
                        : (data.vision_error ? { text: 'skipped — ' + data.vision_error, sub: '', err: true } : null),
                    rest: data.rest
                        ? { text: `${data.rest.inserted || 0} forward · ${(data.rest.gaps && data.rest.gaps.inserted) || 0} gap-fill across ${(data.rest.gaps && data.rest.gaps.gaps_found) || 0} gap(s)`, sub: data.rest.skipped ? 'already current' : 'caught up to head' }
                        : (data.rest_error ? { text: 'skipped — ' + data.rest_error, sub: '', err: true } : null),
                    taapi: data.taapi
                        ? (data.taapi.skipped
                            ? { text: 'already current', sub: 'latest ' + (data.taapi.latest || '—') }
                            : { text: `${data.taapi.inserted || 0} candles topped up`, sub: 'latest ' + (data.taapi.latest || '—') })
                        : (data.taapi_error ? { text: 'skipped — ' + data.taapi_error, sub: '', err: true } : null),
                };
                this.fetchOpen = true;
                if (data.coverage) this.cov = this.mapCov(data.coverage);
                this.flashToast('History fetched', 'ok');

                return true;
            },

            async doVerify() {
                if (!this.selected || this.busy) return;
                this.busy = 'verify';
                const { ok, data } = await hubUiFetch(this.routes.verify, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                } });
                this.busy = null;
                if (!ok) { this.flashToast(data.error || 'Verify failed', 'error'); return; }
                this.cov = this.mapCov(data.coverage);
            },

            // Best-effort coverage fetch. Kicks off the ensure-coverage step
            // block (detect period → Vision → REST/fillGaps → TAAPI → verify) on
            // the worker fleet and polls it to completion, topping the candles up
            // as far as the sources allow. It does NOT block the grade: if the
            // data is still stale or gappy afterwards it leaves a warning up and
            // the run grades on what's present (the server attaches the same
            // warning). Approve guards separately on fresh + complete data.
            async ensureCoverage() {
                this.coverageProgress = 'Checking coverage…';
                const { ok, data } = await hubUiFetch(this.routes.ensure, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                    since: this.cfg.since || null,
                    candles_back: this.cfg.candles_back ? Number(this.cfg.candles_back) : null,
                    max_months: this.cfg.max_months ? Number(this.cfg.max_months) : undefined,
                } });
                if (!ok || !data.block_uuid) {
                    this.coverageProgress = null;
                    this.flashToast(data.error || 'Could not start coverage fetch', 'error');
                    return false;
                }
                return await this.pollCoverage(data.block_uuid);
            },

            // Poll the coverage block until it lands. Updates the live progress
            // label + the coverage snapshot. ~5min hard timeout (worker fleet may
            // be down) — the server gate still refuses afterward, so this is a UX
            // bound, not the security boundary.
            async pollCoverage(blockUuid) {
                const POLL_MS = 3000, TIMEOUT_MS = 300000, started = Date.now();
                const labels = {
                    queued: 'Queued for coverage fetch…',
                    checking: 'Checking coverage…',
                    daemon_slow: 'Waiting for worker fleet…',
                    fetching: 'Fetching history…',
                    done: 'Coverage verified',
                    failed: 'Coverage fetch failed',
                };
                while (true) {
                    if (Date.now() - started > TIMEOUT_MS) {
                        this.coverageProgress = null;
                        this.flashToast('Coverage timed out — worker fleet may be down. Try again, or use manual Fetch.', 'error');
                        return false;
                    }
                    const { ok, data } = await hubUiFetch(this.routes.status + '?block_uuid=' + encodeURIComponent(blockUuid), { method: 'GET' });
                    if (ok && data) {
                        let label = labels[data.state] || 'Fetching…';
                        if (data.state === 'fetching' && data.steps_total) label += ` ${data.steps_done}/${data.steps_total}`;
                        this.coverageProgress = label;
                        if (data.coverage) this.cov = this.mapCov(data.coverage);

                        if (data.state === 'done') {
                            this.coverageProgress = null;
                            if (data.ready) return true;
                            this.coverageWarning = 'Coverage warning: ' + (data.reason || 'data incomplete') + ' — graded on the available candles. Fetch more or pick a token with fuller history for a clean read.';
                            this.flashToast('Graded on imperfect data — ' + (data.reason || 'incomplete'), 'warn');
                            return false;
                        }
                        if (data.state === 'failed') {
                            this.coverageProgress = null;
                            this.flashToast(data.error || 'Coverage fetch failed', 'error');
                            return false;
                        }
                    }
                    await new Promise((r) => setTimeout(r, POLL_MS));
                }
            },

            async doRun() {
                if (!this.selected || this.busy) return;
                this.busy = 'run';
                this.result = null;
                this.ai = { loading: false, text: null, model: null };
                this.coverageWarning = null;
                this.coverageProgress = null;
                this.adjust = { loading: false, done: false, candidates: null, best: null };

                // Best-effort: pull fresh candles / fill gaps via the dispatcher
                // fleet first — but DON'T block the grade. If the data is still
                // imperfect (stale or a gap) ensureCoverage leaves a warning up
                // and we grade on what's present anyway; the alert is loud.
                await this.ensureCoverage();
                this.coverageProgress = null;

                const { ok, data } = await hubUiFetch(this.routes.run, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                    tp_percent: Number(this.cfg.tp),
                    sl_percent: Number(this.cfg.sl),
                    gap_long_percent: this.cfg.gapL ? Number(this.cfg.gapL) : null,
                    gap_short_percent: this.cfg.gapS ? Number(this.cfg.gapS) : null,
                    limit_hit: this.cfg.limit_hit ? Number(this.cfg.limit_hit) : null,
                    candles_back: this.cfg.candles_back ? Number(this.cfg.candles_back) : null,
                    max_rows: this.cfg.max_rows ? Number(this.cfg.max_rows) : 500,
                } });
                this.busy = null;
                if (!ok) { this.flashToast(data.error || 'Backtest failed', 'error'); return; }
                this.result = data.result;
                if (data.coverage) this.cov = this.mapCov(data.coverage);
                if (data.coverage_warning) this.coverageWarning = data.coverage_warning;
                this.pair = data.pair;
            },

            async doAI() {
                if (!this.result || this.ai.loading) return;
                this.ai = { loading: true, text: null, model: null };
                const failures = (this.result.rows || []).filter((r) => r.status === 'stopped_out' || r.status === 'inconclusive');
                const { ok, data } = await hubUiFetch(this.routes.ai, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                    totals: this.result.totals,
                    regimes: this.result.regimes,
                    meta: this.result.meta,
                    config: {
                        tp_percent: this.cfg.tp, sl_percent: this.cfg.sl,
                        gap_long_percent: this.cfg.gapL, gap_short_percent: this.cfg.gapS,
                        total_limit_orders: 4, multipliers: '2,2,2,2',
                        leverage: 20, margin: '5000',
                        limit_hit: this.cfg.limit_hit || null,
                        candles_back: this.cfg.candles_back || null,
                    },
                    rows: failures,
                } });
                if (!ok) {
                    this.ai = { loading: false, text: null, model: null };
                    this.flashToast(data.error || (data.status === 429 ? 'Rate limited — wait a moment' : 'AI insights failed'), 'error');
                    return;
                }
                this.ai = { loading: false, text: data.insights, model: data.model };
            },

            // ================= smart adjustment (5–10 bad band) =================
            // Tries small gap / SL bumps (+0.5 / +1.0 / +1.5%) server-side and
            // reports which, if any, drops the token under 5 stop-loss hits.
            async suggestAdjustment() {
                if (!this.selected || this.adjust.loading) return;
                this.adjust = { loading: true, done: false, candidates: null, best: null };
                const { ok, data } = await hubUiFetch(this.routes.adjust, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                    tp_percent: Number(this.cfg.tp),
                    sl_percent: Number(this.cfg.sl),
                    gap_long_percent: Number(this.cfg.gapL),
                    gap_short_percent: Number(this.cfg.gapS),
                } });
                if (!ok) {
                    this.adjust = { loading: false, done: false, candidates: null, best: null };
                    this.flashToast(data.error || 'Adjustment search failed', 'error');
                    return;
                }
                this.adjust = { loading: false, done: true, candidates: data.candidates, best: data.best };
            },
            adjustLabel(c) {
                return c.lever === 'gap'
                    ? `Wider gap +${c.delta}% → ${c.gap_long} / ${c.gap_short}`
                    : `Wider SL +${c.delta}% → ${c.sl}%`;
            },
            // Apply the winning config AND immediately re-run the backtest, so
            // the operator confirms the fix in one click.
            async applyAdjustment(c) {
                if (this.busy) return;
                if (c.lever === 'gap') { this.cfg.gapL = String(c.gap_long); this.cfg.gapS = String(c.gap_short); }
                else { this.cfg.sl = String(c.sl); }
                if (!this.cfgOpen) this.cfgOpen = true;
                await this.doRun();
            },
            // Approve / reject fire immediately — no confirm step. Approve pushes
            // the tested config live; reject just flags the token.
            async submitDecision(approve) {
                const { ok, data } = await hubUiFetch(this.routes.toggle, { body: {
                    exchange_symbol_id: this.selected.id,
                    approve: approve,
                    timeframe: this.tf,
                    gap_long_percent: approve && this.cfg.gapL ? Number(this.cfg.gapL) : null,
                    gap_short_percent: approve && this.cfg.gapS ? Number(this.cfg.gapS) : null,
                    tp_percent: approve && this.cfg.tp ? Number(this.cfg.tp) : null,
                    sl_percent: approve && this.cfg.sl ? Number(this.cfg.sl) : null,
                } });
                if (!ok) { this.flashToast(data.error || 'Could not save decision', 'error'); return; }
                // The server fans the decision out to every listing of the same
                // token (other quotes, other exchanges — ExchangeSymbolObserver,
                // linked by canonical symbol_id). Mirror that client-side so the
                // dropdown dots and filter counts of sibling quote rows update
                // without a reload: dropdown rows + counts read `s.status`,
                // the Decision chip reads the `reviews` override.
                const decidedToken = this.selected.token;
                this.symbols.forEach((s) => {
                    if (s.token !== decidedToken) return;
                    s.status = data.backtesting_review_status;
                    s.decidedAt = data.backtesting_reviewed_at;
                    if (approve) {
                        s.immediateTradeable = false;
                        s.liveTp = this.cfg.tp ? String(this.cfg.tp) : s.liveTp;
                        s.liveSl = this.cfg.sl ? String(this.cfg.sl) : s.liveSl;
                        s.gapL = this.cfg.gapL ? String(this.cfg.gapL) : s.gapL;
                        s.gapS = this.cfg.gapS ? String(this.cfg.gapS) : s.gapS;
                    }
                    this.reviews[s.id] = data.backtesting_review_status;
                    this.decisions[s.id] = data.backtesting_reviewed_at;
                });
                this.flashToast(approve ? 'Approved — config live' : 'Rejected — no config pushed', approve ? 'ok' : 'reject');
                // The decision buttons live at the bottom — jump the viewport
                // back up on EVERY decision (not only when auto-advance moves).
                // Instant, not smooth: Safari aborts a smooth animation when
                // the results grid collapses for the next run, stranding the
                // viewport mid-page. `.content` is the app layout's scroll
                // container (the window never scrolls).
                (this.$root.closest('.content') || window).scrollTo({ top: 0 });
                // Advance landed on a fresh token (config already reset by
                // selectToken) → fire its backtest immediately, so the review
                // loop is decide → results appear → decide, hands-free.
                if (this.advanceToNextToken(decidedToken)) {
                    await this.doRun();
                }
            },

            // After a decision, hand selection to the next token in the
            // dropdown's VISUAL order (quote groups flattened), wrapping past
            // the end back to the items before the current one. Every quote
            // variant of the just-decided token is skipped — the decision
            // already concluded them all. Nothing left to review → keep the
            // decided token selected so its badge stays on screen. Returns
            // whether the selection actually moved.
            advanceToNextToken(decidedToken) {
                const order = this.quoteGroups.flatMap((g) => g.items);
                const idx = order.findIndex((s) => s.id === this.selId);
                const rotated = idx === -1 ? order : [...order.slice(idx + 1), ...order.slice(0, idx)];
                const next = rotated.find((s) => s.token !== decidedToken);
                if (! next) return false;
                this.selectToken(next);
                // selectToken silently refuses while tokenLocked — report the
                // ACTUAL selection change, or the caller would re-run the
                // just-decided token.
                return this.selId === next.id;
            },

            // ================= toast =================
            flashToast(text, kind) {
                this.toast = { text, kind };
                if (this._toastTimer) clearTimeout(this._toastTimer);
                this._toastTimer = setTimeout(() => { this.toast = null; }, 2600);
            },

            // ================= result helpers =================
            get totals() { return this.result ? this.result.totals : {}; },
            get sampleSize() {
                const t = this.totals;
                return (t.tp_market_only || 0) + (t.reboundable || 0) + (t.stops || 0) + (t.inconclusive || 0);
            },
            get resolvedSims() {
                const t = this.totals;
                return (t.tp_market_only || 0) + (t.reboundable || 0) + (t.stops || 0);
            },
            get passRate() {
                const t = this.totals;
                const r = this.resolvedSims;
                return r > 0 ? ((t.tp_market_only || 0) + (t.reboundable || 0)) / r * 100 : 0;
            },
            // System's own decision proposal. Stop-loss-hit driven (< 5 approve ·
            // 5–10 adjust · > 10 reject) — but a recommendation needs evidence
            // first: a run with zero resolved sims (no candles, every sim
            // sizing-skipped, or all inconclusive) also has zero stops and must
            // never read as "approve". Reachable for thin-history tokens since
            // the dropdown opened to all Binance symbols (v0.12.0). Below the
            // simulator's own statistical threshold (180 start candles — the
            // same line its grade penalty ramps on) the proposal falls back to
            // "review manually" instead of recommending. Reason text rides on
            // the same object so banner + reason can never disagree.
            get proposal() {
                if (! this.result) return null;
                const resolved = this.resolvedSims;
                if (resolved === 0) {
                    const skipped = this.totals.skipped || 0;
                    const inconclusive = this.totals.inconclusive || 0;
                    const parts = [];
                    if (skipped) parts.push(`${skipped} skipped (sizing rejected)`);
                    if (inconclusive) parts.push(`${inconclusive} inconclusive`);
                    return {
                        action: 'none', verb: 'Cannot recommend — nothing simulated', color: 'var(--fg-mute)',
                        reason: parts.length ? parts.join(' · ') + ' · 0 resolved' : 'No candles simulated',
                    };
                }
                const starts = this.totals.candles || 0;
                const threshold = this.totals.sample_size_threshold || 180;
                if (starts < threshold) return { action: 'review', verb: 'Thin sample — review manually', color: 'var(--warn)', reason: `${starts} start candles · statistical threshold is ${threshold}` };
                const stops = this.totals.stops ?? 0;
                const stopsReason = stops === 0 ? 'No stop-loss hits across the backtest' : `${stops} stop-loss ${stops === 1 ? 'hit' : 'hits'} · approve under 5`;
                if (stops < 5) return { action: 'approve', verb: 'Recommend approve', color: 'var(--pnl-up-fg)', reason: stopsReason };
                if (stops <= 10) return { action: 'adjust', verb: 'Adjust configuration', color: 'var(--warn)', reason: stopsReason };
                return { action: 'reject', verb: 'Recommend reject', color: 'var(--pnl-down-fg)', reason: stopsReason };
            },
            get proposalReason() { return this.proposal ? this.proposal.reason : ''; },
            get verdictBars() {
                const t = this.totals;
                const bars = [
                    { key: 'tp_market_only', label: 'TP off market leg', n: t.tp_market_only || 0, color: 'var(--pnl-up-fg)' },
                    { key: 'reboundable',    label: 'Reboundable (WAP)', n: t.reboundable || 0,    color: '#15b8a6' },
                    { key: 'stopped_out',    label: 'Stopped out',       n: t.stops || 0,          color: 'var(--pnl-down-fg)' },
                    { key: 'inconclusive',   label: 'Inconclusive',      n: t.inconclusive || 0,   color: 'var(--fg-mute)', striped: true },
                ];
                // Sizing rejections — only shown when present so a run where
                // sims silently skipped is visible instead of vanishing.
                if (t.skipped) bars.push({ key: 'skipped', label: 'Skipped (sizing)', n: t.skipped, color: 'var(--fg-faint)', striped: true });
                return bars;
            },
            verdictTotal() { return this.verdictBars.reduce((a, v) => a + v.n, 0) || 1; },
            get rungBars() {
                const dist = this.totals.rung_distribution || {};
                return Object.keys(dist)
                    .map((k) => ({ rung: parseInt(k, 10), n: dist[k] }))
                    .filter((r) => r.rung >= 1)
                    .sort((a, b) => a.rung - b.rung);
            },
            rungMax() { return Math.max(1, ...this.rungBars.map((r) => r.n)); },
            rungColor(rung) {
                const depth = this.rungBars.length;
                return rung === depth ? 'var(--pnl-down-fg)' : rung === depth - 1 ? 'var(--warn)' : 'var(--accent)';
            },
            // SL coverage tiers — per direction, the SL width that would have
            // absorbed 25/50/75/100% of the stopped trades. Only directions
            // that actually stopped are shown; null when no stops at all.
            SL_TIERS: [['p25', '25%'], ['p50', '50%'], ['p75', '75%'], ['p100', 'All']],
            get slCoverage() {
                const sc = this.totals.sl_coverage;
                if (!sc) return null;
                const dirs = ['LONG', 'SHORT']
                    .map((d) => ({ dir: d, ...(sc[d] || {}) }))
                    .filter((d) => (d.count || 0) > 0);
                return dirs.length ? dirs : null;
            },
            // Direction panels the card actually renders — the Side chips
            // (Both / Long / Short) narrow the coverage view.
            get slCoverageView() {
                const dirs = (this.slCoverage || []).filter((d) => this.dirFilter === 'all' || d.dir === this.dirFilter);
                return dirs.length ? dirs : null;
            },
            relativeAge(value) {
                if (!value) return '—';

                const stoppedAt = new Date(value);
                if (Number.isNaN(stoppedAt.getTime())) return '—';

                let seconds = Math.max(0, Math.floor((Date.now() - stoppedAt.getTime()) / 1000));
                if (seconds < 60) return 'just now';

                const units = [
                    ['year', 31557600],
                    ['month', 2629800],
                    ['day', 86400],
                    ['hour', 3600],
                    ['minute', 60],
                ];
                const parts = [];

                for (const [unit, size] of units) {
                    const amount = Math.floor(seconds / size);
                    if (amount === 0) continue;

                    parts.push(`${amount} ${unit}${amount === 1 ? '' : 's'}`);
                    seconds -= amount * size;
                    if (parts.length === 2) break;
                }

                return `${parts.join(' and ')} ago`;
            },

            // ---- config echo (meta from the run) ----
            get configEcho() {
                const m = this.result ? this.result.meta : {};
                const mult = Array.isArray(m.multipliers) ? m.multipliers.join(',') : (m.multipliers || '');
                return [
                    ['TP', (m.tp_percent ?? '—') + '%'],
                    ['SL', (m.sl_percent ?? '—') + '%'],
                    ['Gap L', (m.gap_long_percent ?? '—') + '%'],
                    ['Gap S', (m.gap_short_percent ?? '—') + '%'],
                    ['Lev', (m.leverage ?? '—') + '×'],
                    ['Mult', '[' + mult + ']'],
                    ['Window', (m.window_since || 'all history') + ' · ' + (m.timeframe || this.tf)],
                ];
            },

            // ---- failure counts (chips strip on the SL-coverage card) ----
            rowCounts() {
                return (this.result.rows || []).reduce((a, r) => { a[r.status] = (a[r.status] || 0) + 1; return a; }, {});
            },
            fmtNum(v) { return v == null ? '—' : Number(v).toLocaleString(); },
            fmtFixed(v, d = 1) { return v == null ? '—' : Number(v).toFixed(d); },

            // ---- markdown (AI insights) — compact, scannable renderer ----
            renderMd(src) {
                if (!src) return '';
                const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const inl = (t) => esc(t)
                    .replace(/`([^`]+)`/g, '<code class="font-mono text-[11.5px] px-1 py-[1px] rounded-r2 bg-surface-3 text-accent">$1</code>')
                    .replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-fg-1">$1</strong>')
                    .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em class="italic text-fg-2">$2</em>')
                    .replace(/_([^_]+)_/g, '<em class="italic text-fg-mute">$1</em>');
                // A bullet renders as a label-column row when it leads with **Label** / **Label:**
                // (the Why / Impact / Trade-off / Class / Max MAE lines), else a plain dot bullet.
                const li = (body) => {
                    const m = body.match(/^\*\*([^*]+?):?\*\*\s*[—–:-]?\s*([\s\S]*)$/);
                    if (m && m[2].trim()) {
                        return '<li class="flex gap-2.5"><span class="font-mono text-[9px] font-bold tracking-[0.06em] uppercase text-fg-3 leading-tight mt-[3px] w-[74px] flex-shrink-0">' + esc(m[1]) + '</span><span class="text-[12.5px] text-fg-2 leading-normal min-w-0">' + inl(m[2]) + '</span></li>';
                    }
                    return '<li class="flex gap-2"><span class="text-accent flex-shrink-0 mt-[1px]">·</span><span class="text-[12.5px] text-fg-2 leading-normal min-w-0">' + inl(body) + '</span></li>';
                };
                const lines = src.split('\n'); const out = []; let list = null;
                const flush = () => { if (list) { out.push('<ul class="flex flex-col gap-[5px] mt-1 mb-2 pl-0.5">' + list.join('') + '</ul>'); list = null; } };
                lines.forEach((raw) => {
                    // Strip leading indent so sub-bullets nested under a numbered
                    // suggestion are still detected as bullets (label-column rows).
                    const ln = raw.replace(/^[ \t]+/, '');
                    if (/^(\s*[-*_]){3,}\s*$/.test(ln)) { flush(); out.push('<div class="my-3 border-t border-line-soft"></div>'); }
                    else if (/^###\s+/.test(ln)) { flush(); out.push('<h5 class="font-mono text-[10px] font-bold tracking-[0.1em] uppercase text-fg-mute mt-3 mb-1">' + inl(ln.replace(/^###\s+/, '')) + '</h5>'); }
                    else if (/^##\s+/.test(ln)) { flush(); out.push('<h4 class="font-sans font-bold text-[14px] text-fg-1 mt-4 first:mt-0 mb-2 pb-1 border-b border-line-soft">' + inl(ln.replace(/^##\s+/, '')) + '</h4>'); }
                    else if (/^\d+\.\s+/.test(ln)) { flush(); const m = ln.match(/^(\d+)\.\s+(.*)$/); out.push('<div class="flex gap-2.5 items-baseline mt-3 first:mt-0"><span class="font-mono text-[11px] font-bold text-accent flex-shrink-0">' + m[1] + '</span><span class="text-[12.5px] font-semibold text-fg-1 leading-snug min-w-0">' + inl(m[2]) + '</span></div>'); }
                    else if (/^[-*]\s+/.test(ln)) { list = list || []; list.push(li(ln.replace(/^[-*]\s+/, ''))); }
                    else if (ln.trim() === '') { flush(); }
                    else { flush(); out.push('<p class="text-[12.5px] text-fg-2 leading-normal my-1.5">' + inl(ln) + '</p>'); }
                });
                flush();
                return out.join('');
            },
        });
    </script>

    <div x-data="btConsole(@js($btConfig))">
        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-bar-chart-2 class="w-[13px] h-[13px]" stroke-width="1.75"/>SYSADMIN
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Backtesting</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Pull history, run the martingale-ladder simulation, read the grade, and approve a token's config for the live engine.</div>
            </div>
        </div>

        <div class="grid grid-cols-[380px_1fr] gap-5 items-start max-[1080px]:grid-cols-1">
            {{-- ===================== LEFT RAIL ===================== --}}
            <div class="flex flex-col gap-4 lg:sticky lg:top-2 max-[1080px]:static">
                {{-- [A] selection — overflow-visible so the token dropdown can escape the card clip --}}
                <div class="card card--flat !overflow-visible relative z-20">
                    <x-ui.card-head icon="dollar-sign" title="Token" :accent="true"/>
                    <div class="p-4 flex flex-col gap-3">
                        {{-- token selector --}}
                        <div class="relative" x-on:click.outside="selOpen = false">
                            <button type="button" x-on:click="selOpen = !selOpen" :disabled="tokenLocked"
                                    :title="tokenLocked ? 'Locked while the backtest is running' : ''"
                                    class="w-full flex items-center gap-2.5 h-[40px] px-3 bg-surface-2 border border-line rounded-control cursor-pointer hover:border-line-strong transition-colors duration-fast text-left disabled:opacity-45 disabled:cursor-not-allowed">
                                <template x-if="selected">
                                    <span class="flex items-center gap-2.5">
                                        <template x-if="selected.img">
                                            <img :src="selected.img" :alt="selected.token" x-on:error="selected.img = null" loading="lazy"
                                                 class="w-[22px] h-[22px] rounded-chip flex-shrink-0 object-contain"/>
                                        </template>
                                        <template x-if="!selected.img">
                                            <span class="flex items-center justify-center flex-shrink-0 rounded-chip font-mono font-bold leading-none"
                                                  :style="`width:22px;height:22px;font-size:9px;color:oklch(0.76 0.13 ${tokenHue(selected.token)});background:oklch(0.76 0.13 ${tokenHue(selected.token)} / 0.15);border:1px solid oklch(0.76 0.13 ${tokenHue(selected.token)} / 0.32)`"
                                                  x-text="selected.token.slice(0, 1)"></span>
                                        </template>
                                        <span class="font-mono font-bold text-[14px] text-fg-1" x-text="selected.token"></span>
                                        <span class="font-mono text-[12px] text-fg-mute" x-text="'· ' + selected.quote"></span>
                                        <span x-show="selected.rank" class="font-mono text-[9.5px] font-bold tabular-nums py-[2px] px-[6px] rounded-chip" style="color: var(--accent); background: color-mix(in srgb, var(--accent) 14%, transparent)" x-text="'#' + selected.rank"></span>
                                        <span x-show="selected.direction"
                                              class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase py-[2px] px-1.5 rounded-chip flex-shrink-0"
                                              :style="selected.direction === 'LONG'
                                                  ? 'color: var(--pnl-up-fg); background: color-mix(in srgb, var(--pnl-up-fg) 13%, transparent)'
                                                  : 'color: var(--pnl-down-fg); background: color-mix(in srgb, var(--pnl-down-fg) 13%, transparent)'"
                                              x-text="selected.direction"></span>
                                    </span>
                                </template>
                                <span x-show="!selected" class="text-[13px] text-fg-mute">Select a token…</span>
                                <x-feathericon-chevron-down class="w-4 h-4 text-fg-mute ml-auto transition-transform" ::class="selOpen && 'rotate-180'" stroke-width="1.75"/>
                            </button>
                            <div x-show="selOpen" x-cloak x-transition
                                 class="absolute top-[calc(100%+6px)] left-0 right-0 z-50 bg-surface border border-line-strong rounded-control shadow-3 overflow-hidden animate-dd-in">
                                <div class="flex items-center gap-2 p-2 border-b border-line-soft bg-surface-2">
                                    <x-feathericon-search class="w-[15px] h-[15px] text-fg-mute" stroke-width="1.75"/>
                                    <input x-model="query" x-ref="tokenSearch" placeholder="Filter tokens…"
                                           class="flex-1 bg-transparent border-0 outline-none font-mono text-[12.5px] text-fg-1 placeholder:text-fg-faint"/>
                                </div>
                                <div class="max-h-[300px] overflow-y-auto">
                                    <template x-for="g in quoteGroups" :key="g.quote">
                                        <div>
                                            <div class="sticky top-0 px-3 py-1.5 bg-surface-2 border-b border-line-soft font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-faint" x-text="g.quote"></div>
                                            <template x-for="s in g.items" :key="s.id">
                                                <button type="button" x-on:click="selectToken(s)"
                                                        class="w-full flex items-center gap-2.5 px-3 py-2 text-left cursor-pointer border-b border-line-soft last:border-b-0 transition-colors duration-fast bg-transparent hover:bg-hover"
                                                        :class="selected && selected.id === s.id ? 'bg-hover' : ''">
                                                    <template x-if="s.img">
                                                        <img :src="s.img" :alt="s.token" x-on:error="s.img = null" loading="lazy"
                                                             class="w-6 h-6 rounded-chip flex-shrink-0 object-contain"/>
                                                    </template>
                                                    <template x-if="!s.img">
                                                        <span class="flex items-center justify-center flex-shrink-0 rounded-chip font-mono font-bold leading-none"
                                                              :style="`width:24px;height:24px;font-size:10px;color:oklch(0.76 0.13 ${tokenHue(s.token)});background:oklch(0.76 0.13 ${tokenHue(s.token)} / 0.15);border:1px solid oklch(0.76 0.13 ${tokenHue(s.token)} / 0.32)`"
                                                              x-text="s.token.slice(0, 1)"></span>
                                                    </template>
                                                    <span class="font-mono font-bold text-[13px] text-fg-1 w-[44px]" x-text="s.token"></span>
                                                    <span class="font-mono text-[11px] text-fg-mute" x-text="s.exchange"></span>
                                                    <span x-show="s.direction"
                                                          class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase py-[2px] px-1.5 rounded-chip flex-shrink-0"
                                                          :style="s.direction === 'LONG'
                                                              ? 'color: var(--pnl-up-fg); background: color-mix(in srgb, var(--pnl-up-fg) 13%, transparent)'
                                                              : 'color: var(--pnl-down-fg); background: color-mix(in srgb, var(--pnl-down-fg) 13%, transparent)'"
                                                          x-text="s.direction"></span>
                                                    <span x-show="s.rank" class="font-mono text-[9.5px] tabular-nums text-fg-faint ml-auto" x-text="'#' + s.rank"></span>
                                                    <span x-show="s.status" class="w-[6px] h-[6px] rounded-chip flex-shrink-0" :class="!s.rank ? 'ml-auto' : ''" :style="`background: ${reviewMeta(s.status).color}`"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <div x-show="!filteredSymbols.length" class="px-3 py-4 text-center text-[12px] text-fg-mute">No tokens match “<span x-text="query"></span>”.</div>
                                </div>
                            </div>
                        </div>

                        {{-- token-universe filters — narrow the dropdown live --}}
                        <div class="flex flex-col gap-2 -mt-0.5">
                            @foreach ([
                                ['key' => 'top100', 'label' => 'Top 100', 'count' => 'countTop100'],
                                ['key' => 'approved', 'label' => 'Only approved', 'count' => 'countApproved'],
                                ['key' => 'notConcluded', 'label' => 'Not concluded', 'count' => 'countNotConcluded'],
                                ['key' => 'immediateTradeable', 'label' => 'Immediate Tradeable', 'count' => 'countImmediateTradeable'],
                            ] as $f)
                                {{-- Filters are locked with the selector while a run is in
                                     flight — a filter flip can auto-switch the selected token. --}}
                                <label class="flex items-center gap-2 cursor-pointer select-none group"
                                       :class="tokenLocked && 'opacity-45 pointer-events-none'">
                                    <span class="relative flex items-center justify-center w-[16px] h-[16px] rounded-[4px] border transition-colors duration-fast flex-shrink-0"
                                          :style="filters.{{ $f['key'] }} ? 'background: var(--accent); border-color: var(--accent)' : 'background: var(--bg-elev-2); border-color: var(--border-strong)'">
                                        <input type="checkbox" x-model="filters.{{ $f['key'] }}" :disabled="tokenLocked" class="sr-only"/>
                                        <x-feathericon-check x-show="filters.{{ $f['key'] }}" x-cloak class="w-[11px] h-[11px]" style="color: var(--on-accent)" stroke-width="3"/>
                                    </span>
                                    <span class="text-[12px] font-medium text-fg-2 group-hover:text-fg-1 transition-colors whitespace-nowrap">{{ $f['label'] }}</span>
                                    <span class="font-mono text-[10px] tabular-nums text-fg-faint ml-auto" x-text="{{ $f['count'] }}"></span>
                                </label>
                            @endforeach
                        </div>

                        {{-- selected token header --}}
                        <template x-if="selected">
                            <div class="flex items-center gap-3 flex-wrap py-3 px-4 bg-surface-2 border border-line rounded-control">
                                <div class="flex items-baseline gap-1.5">
                                    <span class="font-mono font-bold text-[16px] text-fg-1" x-text="selected.token"></span>
                                    <span class="font-mono text-[12px] text-fg-mute" x-text="'/ ' + selected.quote"></span>
                                </div>
                                <span class="inline-flex items-center py-[4px] px-[10px] rounded-chip border font-mono text-[10px] font-bold tracking-[0.06em] uppercase whitespace-nowrap" style="color: var(--accent); border-color: color-mix(in srgb, var(--accent) 36%, transparent); background: color-mix(in srgb, var(--accent) 11%, transparent)" x-text="selected.exchange"></span>
                                <span x-show="selected.cat" class="font-mono text-[11px] text-fg-mute tracking-[0.02em]" x-text="selected.cat"></span>
                                <span class="ml-auto inline-flex items-center gap-[6px] py-[4px] px-[10px] rounded-chip border font-mono text-[10px] font-bold tracking-[0.06em] uppercase whitespace-nowrap"
                                      :style="`color: ${reviewMeta(status).color}; border-color: color-mix(in srgb, ${reviewMeta(status).color} 36%, transparent); background: color-mix(in srgb, ${reviewMeta(status).color} 11%, transparent)`">
                                    <span class="w-[6px] h-[6px] rounded-chip" :style="`background: ${reviewMeta(status).color}`"></span><span x-text="reviewMeta(status).label"></span>
                                </span>
                            </div>
                        </template>

                        {{-- timeframe --}}
                        <label class="flex flex-col gap-[6px]">
                            <span class="font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-mute">Timeframe</span>
                            <div class="flex gap-1.5 flex-wrap">
                                <template x-for="t in timeframes" :key="t">
                                    <button type="button" x-on:click="tf = t" :disabled="!selected"
                                            class="flex-1 min-w-[44px] h-[32px] rounded-control font-mono text-[11.5px] font-semibold border transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                                            :style="tf === t ? 'color: var(--on-accent); background: var(--accent); border-color: transparent' : 'color: var(--fg-2); background: var(--bg-elev-2); border-color: var(--border)'"
                                            x-text="t"></button>
                                </template>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- [B] config — collapsible, starts collapsed --}}
                <div class="card card--flat overflow-hidden transition-opacity" :class="selected ? '' : 'opacity-50 pointer-events-none'">
                    <x-ui.card-head icon="sliders" title="Config" :accent="true" collapsible
                                    x-on:click="cfgOpen = !cfgOpen"
                                    ::class="cfgOpen ? 'border-b border-line-soft' : 'rounded-b-surface'">
                        <x-slot:right>
                            <span class="flex items-center gap-2.5">
                                <span x-show="!cfgOpen" class="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]" x-text="selected ? 'ladder parameters' : 'select a token'"></span>
                                <span class="flex transition-transform duration-[280ms] ease-[cubic-bezier(.4,0,.2,1)]" :class="cfgOpen && 'rotate-180'">
                                    <x-feathericon-chevron-down class="w-4 h-4 text-fg-3" stroke-width="1.75"/>
                                </span>
                            </span>
                        </x-slot:right>
                    </x-ui.card-head>
                    {{-- animated collapse: grid 0fr↔1fr slides the body without a fixed height --}}
                    <div class="grid transition-[grid-template-rows] duration-[280ms] ease-[cubic-bezier(.4,0,.2,1)]"
                         :style="cfgOpen ? 'grid-template-rows: 1fr' : 'grid-template-rows: 0fr'">
                        <div class="overflow-hidden min-h-0">
                            <div class="p-4 flex flex-col gap-4">
                                {{-- strategy --}}
                                <div class="flex flex-col gap-3">
                                    <span class="ui-eyebrow">Strategy</span>
                                    <div class="grid grid-cols-2 gap-3">
                                        @php
                                            $btInput = 'w-full h-[34px] px-2.5 bg-surface-2 border border-line rounded-control font-mono text-[12.5px] text-fg-1 tabular-nums outline-none transition-colors duration-fast focus:border-line-focus placeholder:text-fg-faint disabled:opacity-45 disabled:cursor-not-allowed';
                                            $btLabel = 'ui-field-label';
                                        @endphp
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Take-profit %</span><input type="number" step="0.01" x-model="cfg.tp" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Stop-loss %</span><input type="number" step="0.01" x-model="cfg.sl" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Gap long %</span><input type="number" step="0.01" x-model="cfg.gapL" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Gap short %</span><input type="number" step="0.01" x-model="cfg.gapS" :disabled="!selected" class="{{ $btInput }}"/></label>
                                    </div>
                                </div>

                                {{-- fixed envelope --}}
                                <div class="flex flex-col gap-2 pt-3 border-t border-line-soft">
                                    <span class="ui-eyebrow mb-0.5">Fixed envelope</span>
                                    {{-- Leverage deliberately not shown: live leverage is the
                                         account's per-direction setting, not the sim's envelope. --}}
                                    @foreach(['Margin' => '5,000', 'Limit orders' => '4', 'Multipliers' => '[2,2,2,2]'] as $k => $v)
                                        <div class="flex items-center justify-between gap-3 py-[7px] border-b border-line-soft last:border-b-0">
                                            <span class="font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-mute">{{ $k }}</span>
                                            <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-2">{{ $v }}</span>
                                        </div>
                                    @endforeach
                                    <span class="ui-hint mt-1">Sizing is fixed — backtests measure price geometry (does WAP recover to TP?), not capital allocation.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- [C] actions --}}
                <div class="card card--flat p-4 flex flex-col gap-2.5">
                    @php
                        $btnBase = 'appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center justify-center gap-2 whitespace-nowrap transition-colors duration-fast ease-out w-full h-[40px] text-[13px] disabled:opacity-45 disabled:cursor-not-allowed';
                    @endphp
                    {{-- fetch --}}
                    <button type="button" x-on:click="doFetch()" :disabled="!selected || busy"
                            class="{{ $btnBase }} bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                        <template x-if="busy === 'fetch'"><span class="flex items-center gap-2"><span class="w-[14px] h-[14px] rounded-full border-2 border-current border-t-transparent animate-spin"></span>Fetching history…</span></template>
                        <template x-if="busy !== 'fetch'"><span class="flex items-center gap-2"><x-feathericon-download class="w-4 h-4" stroke-width="1.75"/>Fetch candles</span></template>
                    </button>
                    {{-- verify --}}
                    <button type="button" x-on:click="doVerify()" :disabled="!selected || busy"
                            class="{{ $btnBase }} bg-transparent text-fg-3 border-transparent hover:bg-hover hover:text-fg-1">
                        <template x-if="busy === 'verify'"><span class="flex items-center gap-2"><span class="w-[14px] h-[14px] rounded-full border-2 border-current border-t-transparent animate-spin"></span>Auditing…</span></template>
                        <template x-if="busy !== 'verify'"><span class="flex items-center gap-2"><x-feathericon-check class="w-4 h-4" stroke-width="1.75"/>Verify coverage</span></template>
                    </button>
                    {{-- run --}}
                    <button type="button" x-on:click="doRun()" :disabled="!selected || busy"
                            class="{{ $btnBase }} border-transparent text-[color:var(--on-accent)]" style="background: var(--accent)">
                        <template x-if="busy === 'run'"><span class="flex items-center gap-2"><span class="w-[14px] h-[14px] rounded-full border-2 border-current border-t-transparent animate-spin"></span><span x-text="coverageProgress || 'Simulating ladder…'"></span></span></template>
                        <template x-if="busy !== 'run'"><span class="flex items-center gap-2"><x-feathericon-play class="w-4 h-4" stroke-width="1.75"/>Run backtest</span></template>
                    </button>
                    <span class="ui-hint text-center" x-text="coverageProgress || 'Run pulls fresh candles via the fleet, then grades — only on complete, current data.'"></span>
                </div>

                {{-- [G] approval --}}
                <template x-if="selected">
                    <div class="card card--flat overflow-hidden" :style="result ? 'border-color: color-mix(in srgb, var(--accent) 30%, var(--border))' : ''">
                        <x-ui.card-head icon="shield" title="Decision" :accent="true">
                            <x-slot:right>
                                <span class="inline-flex items-center gap-[6px] py-[4px] px-[10px] rounded-chip border font-mono text-[10px] font-bold tracking-[0.06em] uppercase whitespace-nowrap"
                                      :style="`color: ${reviewMeta(status).color}; border-color: color-mix(in srgb, ${reviewMeta(status).color} 36%, transparent); background: color-mix(in srgb, ${reviewMeta(status).color} 11%, transparent)`">
                                    <span class="w-[6px] h-[6px] rounded-chip" :style="`background: ${reviewMeta(status).color}`"></span><span x-text="reviewMeta(status).label"></span>
                                </span>
                            </x-slot:right>
                        </x-ui.card-head>
                        <div class="p-4 flex flex-col gap-2.5">
                            {{-- When the standing decision was taken. Without it a
                                 re-test of an already-decided token gives no clue
                                 whether the call is a day or three months old. --}}
                            <template x-if="status && decidedAtLabel">
                                <div class="flex items-center gap-2 font-mono text-[10.5px] text-fg-mute">
                                    <x-feathericon-clock class="w-[12px] h-[12px] flex-shrink-0" stroke-width="1.75"/>
                                    <span><span x-text="reviewMeta(status).label"></span> <span class="text-fg-2" x-text="decidedAtLabel"></span></span>
                                </div>
                            </template>
                            <template x-if="status && !decidedAtLabel">
                                <div class="flex items-center gap-2 font-mono text-[10.5px] text-fg-mute">
                                    <x-feathericon-clock class="w-[12px] h-[12px] flex-shrink-0" stroke-width="1.75"/>
                                    <span>Decision date not recorded</span>
                                </div>
                            </template>
                            <span x-show="!result" class="ui-hint">Run a backtest before approving — the decision pushes the tested config live.</span>
                            {{-- system proposal — recommended decision derived from the grade --}}
                            <template x-if="result && proposal">
                                <div class="flex items-center gap-2.5 py-2.5 px-3 rounded-control border"
                                     :style="`border-color: color-mix(in srgb, ${proposal.color} 38%, transparent); background: color-mix(in srgb, ${proposal.color} 9%, transparent)`">
                                    <span class="flex-shrink-0 flex" :style="`color: ${proposal.color}`">
                                        <x-feathericon-target class="w-4 h-4" stroke-width="1.75"/>
                                    </span>
                                    <div class="flex flex-col gap-0.5 min-w-0">
                                        <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase" :style="`color: ${proposal.color}`">Proposal · <span x-text="proposal.verb"></span></span>
                                        <span class="font-mono text-[11px] text-fg-2 tabular-nums" x-text="proposalReason"></span>
                                    </div>
                                </div>
                            </template>

                            {{-- smart adjustment — only when the proposal is "adjust" (5–10 bad).
                                 Tries small gap / SL bumps and reports which gets the token safe. --}}
                            <template x-if="result && proposal && proposal.action === 'adjust'">
                                <div class="flex flex-col gap-2">
                                    <button type="button" x-on:click="suggestAdjustment()" :disabled="adjust.loading"
                                            class="appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[34px] rounded-control font-sans text-[12px] font-semibold border transition-colors duration-fast disabled:opacity-50"
                                            style="color: var(--warn); border-color: color-mix(in srgb, var(--warn) 40%, transparent); background: color-mix(in srgb, var(--warn) 8%, transparent)">
                                        <template x-if="adjust.loading"><span class="flex items-center gap-2"><span class="w-[13px] h-[13px] rounded-full border-2 border-current border-t-transparent animate-spin"></span>Testing small bumps…</span></template>
                                        <template x-if="!adjust.loading"><span class="flex items-center gap-2"><x-feathericon-sliders class="w-[14px] h-[14px]" stroke-width="1.75"/><span x-text="adjust.done ? 'Re-test adjustments' : 'Find a safe adjustment'"></span></span></template>
                                    </button>

                                    <template x-if="adjust.done">
                                        <div class="flex flex-col gap-1.5">
                                            <template x-if="adjust.best">
                                                <div class="flex items-start gap-2 py-2 px-2.5 rounded-control border" style="border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent); background: color-mix(in srgb, var(--pnl-up-fg) 8%, transparent)">
                                                    <x-feathericon-check class="w-[14px] h-[14px] mt-px flex-shrink-0" style="color: var(--pnl-up-fg)" stroke-width="2.5"/>
                                                    <div class="flex flex-col gap-1.5 min-w-0 flex-1">
                                                        <span class="text-[12px] text-fg-1 font-semibold leading-snug" x-text="adjustLabel(adjust.best) + ' → ' + adjust.best.stops + ' stops (acceptable)'"></span>
                                                        <button type="button" x-on:click="applyAdjustment(adjust.best)" :disabled="busy" class="self-start appearance-none cursor-pointer inline-flex items-center gap-1.5 font-sans text-[11.5px] font-semibold py-[5px] px-3 rounded-control border-0 disabled:opacity-50" style="color: var(--on-accent); background: var(--pnl-up-fg)"><x-feathericon-play class="w-[12px] h-[12px]" stroke-width="2.5"/>Apply config and backtest again</button>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="!adjust.best">
                                                <div class="flex items-start gap-2 py-2 px-2.5 rounded-control border" style="border-color: color-mix(in srgb, var(--pnl-down-fg) 40%, transparent); background: color-mix(in srgb, var(--pnl-down-fg) 8%, transparent)">
                                                    <x-feathericon-alert-triangle class="w-[14px] h-[14px] mt-px flex-shrink-0" style="color: var(--pnl-down-fg)" stroke-width="1.75"/>
                                                    <span class="text-[12px] text-fg-2 leading-snug">No bump up to +1.5% gets under 5 stops — lean reject or rework the ladder.</span>
                                                </div>
                                            </template>
                                            <div class="flex flex-col gap-0.5 mt-0.5 pt-1.5 border-t border-line-soft">
                                                {{-- Every candidate is one click from a re-test — the leading
                                                     apply button sets that row's config and re-runs the backtest
                                                     (same path as the green best-candidate button), so the
                                                     operator can try ANY bump, not just the system's pick. --}}
                                                <template x-for="c in adjust.candidates" :key="c.lever + c.delta">
                                                    <div class="flex items-center gap-2 py-[3px]">
                                                        <button type="button" x-on:click="applyAdjustment(c)" :disabled="busy"
                                                                title="Apply this config and backtest again"
                                                                class="appearance-none cursor-pointer inline-flex items-center justify-center w-[20px] h-[20px] rounded-control border bg-transparent flex-shrink-0 transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed"
                                                                :style="`color: ${c.acceptable ? 'var(--pnl-up-fg)' : 'var(--fg-mute)'}; border-color: ${c.acceptable ? 'color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : 'var(--border)'}`">
                                                            <x-feathericon-play class="w-[10px] h-[10px]" stroke-width="2.5"/>
                                                        </button>
                                                        <span class="font-mono text-[10.5px] text-fg-2 flex-1 truncate" x-text="adjustLabel(c)"></span>
                                                        <span class="font-mono text-[10.5px] font-semibold tabular-nums flex-shrink-0" :style="`color: ${c.acceptable ? 'var(--pnl-up-fg)' : 'var(--fg-mute)'}`" x-text="c.stops + ' stops'"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            {{-- Approve is disabled on zero-resolved runs: nothing was
                                 tested, so there is no config to push live from here. --}}
                            <span x-show="result && resolvedSims === 0" class="ui-hint">Approve is locked — this run resolved no simulations, so there's nothing tested to push live.</span>
                            {{-- Re-testing a decided token starts from account defaults,
                                 which are not what the token is live with. This puts the
                                 token's own approved conditions back into the form. --}}
                            <template x-if="hasLiveConfiguration">
                                <button type="button" x-on:click="loadLiveConfiguration()" :disabled="tokenLocked"
                                        class="appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[34px] rounded-control font-sans text-[12px] font-semibold border transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed ui-text border-line hover:border-line-strong bg-transparent">
                                    <x-feathericon-rotate-ccw class="w-[14px] h-[14px]" stroke-width="1.75"/>Reload current configuration
                                </button>
                            </template>
                            <div class="flex gap-2">
                                {{-- tokenLocked: deciding mid-fetch/verify/adjust-search would
                                     race the in-flight op and misfire the auto-advance chain. --}}
                                <button type="button" x-on:click="submitDecision(true)" :disabled="!result || status === 'approved' || resolvedSims === 0 || tokenLocked"
                                        class="flex-1 appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] rounded-control font-sans text-[13px] font-bold text-white border-0 transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed" style="background: var(--pnl-up-fg)"
                                        :style="{ boxShadow: proposal && proposal.action === 'approve' ? '0 0 0 2px color-mix(in srgb, var(--pnl-up-fg) 50%, transparent)' : '' }">
                                    <x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/>Approve
                                </button>
                                <button type="button" x-on:click="submitDecision(false)" :disabled="!result || status === 'rejected' || tokenLocked"
                                        class="flex-1 appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] rounded-control font-sans text-[13px] font-bold text-white border-0 transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed" style="background: var(--pnl-down-fg)"
                                        :style="{ boxShadow: proposal && proposal.action === 'reject' ? '0 0 0 2px color-mix(in srgb, var(--pnl-down-fg) 45%, transparent)' : '' }">
                                    <x-feathericon-power class="w-[15px] h-[15px]" stroke-width="2"/>Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- ===================== RIGHT PANEL ===================== --}}
            <div class="flex flex-col gap-4 min-w-0">
                {{-- thin-history alert — auto-fetch couldn't fully cover the
                     requested window; persists until the next run/selection --}}
                <template x-if="coverageWarning">
                    <div class="card card--flat flex items-start gap-2.5 py-3 px-4" style="border-color: color-mix(in srgb, var(--warn) 45%, var(--border)); background: color-mix(in srgb, var(--warn) 7%, transparent)">
                        <x-feathericon-alert-triangle class="w-4 h-4 flex-shrink-0 mt-px" style="color: var(--warn)" stroke-width="1.75"/>
                        <span class="text-[12px] text-fg-2 leading-snug" x-text="coverageWarning"></span>
                    </div>
                </template>
                {{-- [D] coverage strip --}}
                <div x-show="!cov" class="card card--flat flex items-center gap-2.5 py-3 px-4">
                    <x-feathericon-database class="w-[15px] h-[15px] text-fg-3" stroke-width="1.75"/>
                    <span class="text-[12px] text-fg-3">No coverage data — run <span class="font-semibold text-fg-2">Verify</span> or <span class="font-semibold text-fg-2">Fetch</span>.</span>
                </div>
                <template x-if="cov">
                    <div class="card card--flat overflow-hidden">
                        <div class="flex items-center gap-2.5 py-2.5 px-4 border-b border-line-soft" :style="`background: color-mix(in srgb, ${coverageHealthy(cov) ? 'var(--pnl-up-fg)' : 'var(--warn)'} 8%, transparent)`">
                            <span class="w-[8px] h-[8px] rounded-chip flex-shrink-0" :style="`background: ${coverageHealthy(cov) ? 'var(--pnl-up-fg)' : 'var(--warn)'}`"></span>
                            <span class="font-mono text-[11px] font-bold tracking-[0.04em] uppercase" :style="`color: ${coverageHealthy(cov) ? 'var(--pnl-up-fg)' : 'var(--warn)'}`"
                                  x-text="coverageLabel(cov)"></span>
                            <span x-show="!coverageHealthy(cov)" class="font-mono text-[10px] text-fg-mute ml-auto max-[520px]:hidden">Run tops up before grading</span>
                        </div>
                        <div class="grid grid-cols-4 gap-3 py-3 px-4 max-[520px]:grid-cols-2 max-[520px]:gap-y-3">
                            <div class="flex flex-col gap-1 min-w-0">
                                <span class="flex items-center gap-[5px] font-mono text-[9px] tracking-[0.08em] uppercase text-fg-3 whitespace-nowrap">Earliest<x-ui.help-dot topic="cov_earliest"/></span>
                                <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1 whitespace-nowrap" x-text="cov.earliest"></span>
                            </div>
                            <div class="flex flex-col gap-1 min-w-0">
                                <span class="flex items-center gap-[5px] font-mono text-[9px] tracking-[0.08em] uppercase text-fg-3 whitespace-nowrap">Latest<x-ui.help-dot topic="cov_latest"/></span>
                                <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1 whitespace-nowrap" x-text="cov.latest"></span>
                            </div>
                            <div class="flex flex-col gap-1 min-w-0">
                                <span class="flex items-center gap-[5px] font-mono text-[9px] tracking-[0.08em] uppercase text-fg-3 whitespace-nowrap">Candles<x-ui.help-dot topic="cov_candles"/></span>
                                <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1 whitespace-nowrap" x-text="cov.candles.toLocaleString()"></span>
                            </div>
                            <div class="flex flex-col gap-1 min-w-0">
                                <span class="flex items-center gap-[5px] font-mono text-[9px] tracking-[0.08em] uppercase text-fg-3 whitespace-nowrap">Contiguity<x-ui.help-dot topic="cov_contiguity"/></span>
                                <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1 whitespace-nowrap" x-text="cov.contiguity + '%'"></span>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- [E] fetch report --}}
                <template x-if="fetchReport">
                    <div class="card card--flat overflow-hidden">
                        <button type="button" x-on:click="fetchOpen = !fetchOpen" class="w-full flex items-center gap-2.5 py-[13px] px-5 bg-surface-2 border-b border-line-soft cursor-pointer hover:bg-hover transition-colors text-left">
                            <x-feathericon-download class="w-[15px] h-[15px] text-accent" stroke-width="1.75"/>
                            <h4 class="font-sans font-semibold text-[14px] text-fg-1">Fetch report</h4>
                            <x-feathericon-chevron-down class="w-[15px] h-[15px] text-fg-mute ml-auto transition-transform" ::class="fetchOpen && 'rotate-180'" stroke-width="1.75"/>
                        </button>
                        <div x-show="fetchOpen">
                            <div class="flex items-start gap-2.5 py-3 px-5 border-b border-line-soft" style="background: color-mix(in srgb, var(--pnl-up-fg) 7%, transparent)">
                                <x-feathericon-check class="w-[14px] h-[14px] mt-0.5 flex-shrink-0" style="color: var(--pnl-up-fg)" stroke-width="2"/>
                                <span class="text-[12px] text-fg-2 leading-snug" x-text="fetchReport.message"></span>
                            </div>
                            <template x-for="tier in [['Vision', 'database', fetchReport.vision], ['Binance REST', 'shuffle', fetchReport.rest], ['TAAPI', 'zap', fetchReport.taapi]]" :key="tier[0]">
                                <div x-show="tier[2]" class="flex items-center gap-3 py-3 px-5 border-b border-line-soft last:border-b-0">
                                    <span class="w-[30px] h-[30px] rounded-control bg-surface-3 flex items-center justify-center flex-shrink-0">
                                        <x-feathericon-database x-show="tier[1] === 'database'" class="w-[15px] h-[15px] text-fg-2" stroke-width="1.75"/>
                                        <x-feathericon-shuffle x-show="tier[1] === 'shuffle'" class="w-[15px] h-[15px] text-fg-2" stroke-width="1.75"/>
                                        <x-feathericon-zap x-show="tier[1] === 'zap'" class="w-[15px] h-[15px] text-fg-2" stroke-width="1.75"/>
                                    </span>
                                    <div class="flex flex-col min-w-0">
                                        <span class="font-sans text-[12.5px] font-semibold text-fg-1" x-text="tier[0]"></span>
                                        <span class="font-mono text-[11px] text-fg-mute leading-snug" x-text="tier[2] ? tier[2].text : ''"></span>
                                    </div>
                                    <span x-show="tier[2] && tier[2].sub" class="ml-auto font-mono text-[11px] font-semibold tabular-nums text-right" :style="`color: ${tier[2] && tier[2].err ? 'var(--warn)' : 'var(--pnl-up-fg)'}`" x-text="tier[2] ? tier[2].sub : ''"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- empty / running states --}}
                <template x-if="!result && busy !== 'run'">
                    <div class="card card--flat flex flex-col items-center justify-center text-center py-16 px-6">
                        <span class="w-[52px] h-[52px] rounded-control bg-surface-3 flex items-center justify-center mb-4"><x-feathericon-bar-chart-2 class="w-6 h-6 text-fg-mute" stroke-width="1.5"/></span>
                        <h4 class="font-sans font-semibold text-[15px] text-fg-1 mb-1.5" x-text="selected ? 'Run a backtest to see results' : 'Select a token to begin'"></h4>
                        <p class="text-[12.5px] text-fg-mute max-w-[340px] leading-snug" x-show="selected">Fetch history, then <span class="font-semibold text-fg-2">Run backtest</span> — grade, pass rate, and per-trade rows appear here.</p>
                        <p class="text-[12.5px] text-fg-mute max-w-[340px] leading-snug" x-show="!selected">Pick a symbol from the left rail. Its config pre-fills and the actions unlock.</p>
                    </div>
                </template>
                <template x-if="busy === 'run'">
                    <div class="card card--flat flex items-center justify-center gap-3 py-16">
                        <span class="w-[18px] h-[18px] rounded-full border-2 border-t-transparent animate-spin" style="border-color: var(--accent); border-top-color: transparent"></span>
                        <span class="font-mono text-[13px] text-fg-mute">Simulating ladder over history…</span>
                    </div>
                </template>

                {{-- results --}}
                <template x-if="result">
                    <div class="flex flex-col gap-4">
                        {{-- no-sims guard --}}
                        <template x-if="sampleSize === 0">
                            <div class="card card--flat flex items-center gap-2.5 py-3 px-4" style="background: color-mix(in srgb, var(--warn) 8%, transparent); border-color: color-mix(in srgb, var(--warn) 30%, var(--border))">
                                <x-feathericon-alert-triangle class="w-[15px] h-[15px]" style="color: var(--warn)" stroke-width="1.75"/>
                                <span class="text-[12.5px] text-fg-2">No simulations resolved in this window — fetch more history or widen the candle range.</span>
                            </div>
                        </template>

                        <template x-if="sampleSize > 0">
                            <div class="flex flex-col gap-4">
                                {{-- grade hero --}}
                                <div class="card card--flat overflow-hidden flex items-stretch" :style="`border-color: color-mix(in srgb, ${GRADE_COLOR[totals.grade] || 'var(--fg-1)'} 30%, var(--border))`">
                                    <div class="flex items-center justify-center px-6 py-5 flex-shrink-0" :style="`background: color-mix(in srgb, ${GRADE_COLOR[totals.grade] || 'var(--fg-1)'} 12%, transparent); border-right: 1px solid color-mix(in srgb, ${GRADE_COLOR[totals.grade] || 'var(--fg-1)'} 22%, var(--border))`">
                                        <span class="font-mono font-bold text-[56px] leading-none tabular-nums" :style="`color: ${GRADE_COLOR[totals.grade] || 'var(--fg-1)'}`" x-text="totals.grade || '—'"></span>
                                    </div>
                                    <div class="flex flex-col justify-center gap-1.5 px-5 py-4 min-w-0">
                                        <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute inline-flex items-center gap-[5px]">Grade · verdict<x-ui.help-dot topic="grade_verdict"/></span>
                                        <span class="font-sans font-bold text-[17px] text-fg-1 leading-tight" x-text="totals.verdict || 'No verdict'"></span>
                                        <div class="flex items-center gap-4 mt-0.5">
                                            <span class="font-mono text-[11.5px] text-fg-2">Overall <span class="font-bold tabular-nums text-fg-1" x-text="fmtFixed(totals.overall_score)"></span><span class="text-fg-faint">/100</span> <x-ui.help-dot topic="overall_score"/></span>
                                            <span class="font-mono text-[11.5px] text-fg-2">Risk <span class="font-bold tabular-nums" :style="`color: ${(totals.risk_score || 0) > 50 ? 'var(--warn)' : 'var(--fg-1)'}`" x-text="fmtFixed(totals.risk_score)"></span> <x-ui.help-dot topic="risk_score"/></span>
                                        </div>
                                    </div>
                                </div>

                                {{-- scorecards --}}
                                <div class="grid grid-cols-3 gap-3 max-[640px]:grid-cols-2">
                                    @php
                                        $statCard = 'card card--flat px-3.5 py-3 flex flex-col gap-1.5';
                                        $statLabel = 'font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute whitespace-nowrap';
                                        $statVal = 'font-mono text-[20px] font-bold tabular-nums leading-none';
                                        $statSub = 'font-mono text-[9px] tracking-[0.05em] uppercase whitespace-nowrap text-fg-3';
                                    @endphp
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }} inline-flex items-center gap-[5px]">Pass rate<x-ui.help-dot topic="pass_rate"/></span><span class="{{ $statVal }}" style="color: var(--pnl-up-fg)" x-text="passRate.toFixed(1) + '%'"></span><span class="{{ $statSub }}">resolved sims</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }} inline-flex items-center gap-[5px]">Max daily amplitude %<x-ui.help-dot topic="max_daily_amplitude"/></span><span class="{{ $statVal }}" style="color: var(--warn)" x-text="fmtFixed(totals.max_daily_amplitude_pct, 2)"></span><span class="{{ $statSub }}" x-text="totals.max_daily_amplitude_date ? totals.max_daily_amplitude_date + ' UTC' : 'no candle data'"></span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }} inline-flex items-center gap-[5px]">Avg rung depth<x-ui.help-dot topic="avg_rung_depth"/></span><span class="{{ $statVal }} text-fg-1" x-text="fmtFixed(totals.avg_rung_depth)"></span><span class="{{ $statSub }}">of 4 rungs</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }} inline-flex items-center gap-[5px]">Avg → profit<x-ui.help-dot topic="avg_to_profit"/></span><span class="{{ $statVal }} text-fg-1" x-text="totals.avg_candles_to_profit == null ? '—' : totals.avg_candles_to_profit + ' c'"></span><span class="{{ $statSub }}">candles</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }} inline-flex items-center gap-[5px]">p95 → profit<x-ui.help-dot topic="p95_to_profit"/></span><span class="{{ $statVal }} text-fg-1" x-text="totals.p95_candles_to_profit == null ? '—' : totals.p95_candles_to_profit + ' c'"></span><span class="{{ $statSub }}">candles</span></div>
                                    {{-- Thin-sample warn keys on START CANDLES vs the simulator's
                                         threshold (the grade penalty's own unit) — the headline
                                         number stays in sims (each start = LONG + SHORT). --}}
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }} inline-flex items-center gap-[5px]">Sample size<x-ui.help-dot topic="sample_size"/></span><span class="{{ $statVal }} text-fg-1" x-text="sampleSize.toLocaleString()"></span><span class="{{ $statSub }}" :style="`color: ${(totals.candles || 0) < (totals.sample_size_threshold || 180) ? 'var(--warn)' : 'var(--fg-3)'}`" x-text="(totals.candles || 0) < (totals.sample_size_threshold || 180) ? 'thin — ' + fmtNum(totals.candles) + ' starts' : 'sims · ' + fmtNum(totals.candles) + ' starts'"></span></div>
                                </div>

                                {{-- [I] stop-loss coverage (chips strip kept from the old rows
                                     table: status chips carry the failure counts, side chips
                                     filter the direction panels below) --}}
                                <div class="card card--flat overflow-hidden">
                                    <x-ui.card-head icon="shield" title="Stop-loss coverage" :accent="true" hint="SL width that would have absorbed each share of stops" tip="sl_coverage"/>

                                    {{-- filter chips --}}
                                    <div class="flex items-center gap-1.5 flex-wrap py-2.5 px-4 border-b border-line-soft" style="background: color-mix(in srgb, var(--bg-elev-2) 40%, transparent)">
                                        <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-3 mr-1">Status</span>
                                        @php $chip = 'appearance-none cursor-pointer inline-flex items-center gap-1.5 h-[28px] px-2.5 rounded-chip border font-mono text-[10.5px] font-semibold tracking-[0.04em] whitespace-nowrap transition-colors duration-fast'; @endphp
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'all'" :style="statusFilter === 'all' ? 'color: var(--accent); border-color: color-mix(in srgb, var(--accent) 45%, transparent); background: color-mix(in srgb, var(--accent) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">All</button>
                                        {{-- The table lists failures only (wins emit no row by design),
                                             so the status chips cover exactly what can appear: stopped + inconclusive. --}}
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'stopped_out'" :style="statusFilter === 'stopped_out' ? 'color: var(--pnl-down-fg); border-color: color-mix(in srgb, var(--pnl-down-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-down-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'"><x-feathericon-alert-triangle class="w-3 h-3" stroke-width="1.75"/>Stopped · <span x-text="rowCounts().stopped_out || 0"></span></button>
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'inconclusive'" :style="statusFilter === 'inconclusive' ? 'color: var(--fg-mute); border-color: color-mix(in srgb, var(--fg-1) 30%, transparent); background: color-mix(in srgb, var(--fg-1) 8%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Inconclusive · <span x-text="rowCounts().inconclusive || 0"></span></button>
                                        <span class="w-px h-4 bg-line-soft mx-1.5"></span>
                                        <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-3 mr-1">Side</span>
                                        <button type="button" class="{{ $chip }}" x-on:click="dirFilter = 'all'" :style="dirFilter === 'all' ? 'color: var(--accent); border-color: color-mix(in srgb, var(--accent) 45%, transparent); background: color-mix(in srgb, var(--accent) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Both</button>
                                        <button type="button" class="{{ $chip }}" x-on:click="dirFilter = 'LONG'" :style="dirFilter === 'LONG' ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-up-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Long</button>
                                        <button type="button" class="{{ $chip }}" x-on:click="dirFilter = 'SHORT'" :style="dirFilter === 'SHORT' ? 'color: var(--pnl-down-fg); border-color: color-mix(in srgb, var(--pnl-down-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-down-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Short</button>
                                    </div>

                                    {{-- direction panels — filtered by the Side chips --}}
                                    <template x-if="slCoverageView">
                                        <div>
                                            <div class="grid gap-0 max-[640px]:grid-cols-1" :class="slCoverageView.length > 1 ? 'grid-cols-2' : 'grid-cols-1'">
                                                <template x-for="d in slCoverageView" :key="d.dir">
                                                    <div class="p-4 border-r border-line-soft last:border-r-0 max-[640px]:border-r-0 max-[640px]:border-b max-[640px]:last:border-b-0">
                                                        <div class="flex items-center gap-2 mb-2.5">
                                                            <span class="font-mono text-[9.5px] font-bold tracking-[0.05em] py-[2px] px-[7px] rounded-chip" :style="`color: ${d.dir === 'LONG' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'}; background: color-mix(in srgb, ${d.dir === 'LONG' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 13%, transparent)`" x-text="d.dir"></span>
                                                            <span class="font-mono text-[10.5px] text-fg-mute tabular-nums" x-text="d.count + (d.count === 1 ? ' stop' : ' stops')"></span>
                                                            <span class="ml-auto font-mono text-[10px] text-fg-faint tabular-nums whitespace-nowrap" x-text="'Latest SL · ' + relativeAge(d.latest_stop_at)"></span>
                                                        </div>
                                                        <div class="flex flex-col gap-1.5">
                                                            <template x-for="t in SL_TIERS" :key="t[0]">
                                                                <div class="flex items-center gap-3">
                                                                    <span class="font-mono text-[10px] font-bold tracking-[0.05em] uppercase text-fg-mute w-[34px] flex-shrink-0" x-text="t[1]"></span>
                                                                    <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1" x-text="d[t[0]] && d[t[0]].pct != null ? 'SL ' + d[t[0]].pct + '%' : '—'"></span>
                                                                    <span class="font-mono text-[10.5px] tabular-nums text-fg-mute" x-text="d[t[0]] && d[t[0]].delta != null ? (d[t[0]].delta > 0 ? '+' + d[t[0]].delta + '% wider' : 'at current SL') : ''"></span>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="py-2 px-4 border-t border-line-soft">
                                                <span class="ui-hint">Each row: the stop-loss width that would have absorbed that share of this run's stopped trades. A huge "All" value means widening SL can't save this config.</span>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="!slCoverageView" class="py-8 text-center text-[12px] text-fg-mute">No stopped trades for this side — nothing for a stop-loss to absorb.</div>
                                </div>

                                {{-- config echo --}}
                                <div class="flex items-center gap-x-4 gap-y-1 flex-wrap py-2.5 px-4 card card--flat">
                                    <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-3 inline-flex items-center gap-[5px]">Config<x-ui.help-dot topic="config_echo"/></span>
                                    <template x-for="kv in configEcho" :key="kv[0]">
                                        <span class="font-mono text-[10.5px] text-fg-mute"><span class="text-fg-3" x-text="kv[0]"></span> <span class="font-semibold text-fg-2 tabular-nums" x-text="kv[1]"></span></span>
                                    </template>
                                </div>

                                {{-- verdict bar + rung chart --}}
                                <div class="grid grid-cols-2 gap-4 max-[760px]:grid-cols-1">
                                    {{-- verdict breakdown --}}
                                    <div class="card card--flat overflow-hidden">
                                        <x-ui.card-head icon="layers" title="Verdict breakdown" :accent="true" tip="verdict_breakdown">
                                            <x-slot:right><span class="font-mono text-[10.5px] text-fg-mute" x-text="verdictTotal() + ' sims'"></span></x-slot:right>
                                        </x-ui.card-head>
                                        <div class="p-4">
                                            <div class="flex h-[26px] rounded-control overflow-hidden border border-line">
                                                <template x-for="v in verdictBars" :key="v.key">
                                                    <div :title="`${v.label} · ${v.n}`" :style="`width: ${v.n / verdictTotal() * 100}%; background: ${v.striped ? `repeating-linear-gradient(45deg, color-mix(in srgb, ${v.color} 40%, transparent), color-mix(in srgb, ${v.color} 40%, transparent) 5px, transparent 5px, transparent 10px)` : v.color}`"></div>
                                                </template>
                                            </div>
                                            <div class="grid grid-cols-2 gap-x-5 gap-y-2 mt-3.5 max-[420px]:grid-cols-1">
                                                <template x-for="v in verdictBars" :key="v.key">
                                                    <div class="flex items-center gap-2">
                                                        <span class="w-[10px] h-[10px] rounded-r2 flex-shrink-0" :style="`background: ${v.color}; opacity: ${v.striped ? 0.6 : 1}`"></span>
                                                        <span class="font-mono text-[11px] text-fg-2 truncate" x-text="v.label"></span>
                                                        <span class="font-mono text-[11.5px] font-bold tabular-nums ml-auto" :style="`color: ${v.color}`" x-text="v.n"></span>
                                                        <span class="font-mono text-[10px] tabular-nums text-fg-faint w-[38px] text-right" x-text="(v.n / verdictTotal() * 100).toFixed(0) + '%'"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- rung distribution --}}
                                    <div class="card card--flat overflow-hidden">
                                        <x-ui.card-head icon="bar-chart-2" title="Rung distribution" :accent="true" hint="ladder depth reached" tip="rung_distribution"/>
                                        <div class="p-4 flex flex-col gap-2.5">
                                            <template x-for="r in rungBars" :key="r.rung">
                                                <div class="flex items-center gap-3">
                                                    <span class="font-mono text-[10px] font-bold tracking-[0.05em] uppercase text-fg-mute w-[48px] flex-shrink-0" x-text="'Rung ' + r.rung"></span>
                                                    <div class="flex-1 h-[16px] rounded-chip bg-surface-3 overflow-hidden">
                                                        <div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${r.n / rungMax() * 100}%; background: ${rungColor(r.rung)}`"></div>
                                                    </div>
                                                    <span class="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1 w-[36px] text-right" x-text="r.n"></span>
                                                </div>
                                            </template>
                                            <span class="ui-hint mt-0.5">Deeper rungs = more averaging-down — the deepest rung's reach is the key risk signal.</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- [J] AI insights --}}
                                <div class="card card--flat overflow-hidden">
                                    <x-ui.card-head icon="zap" title="AI insights" :accent="true">
                                        <x-slot:right>
                                            <span x-show="ai.text" class="font-mono text-[10px] text-fg-faint" x-text="'via ' + (ai.model || 'model')"></span>
                                            <span x-show="!ai.text" class="font-mono text-[10px] text-fg-3">advisory · applies no changes</span>
                                        </x-slot:right>
                                    </x-ui.card-head>
                                    <div x-show="!ai.text && !ai.loading" class="flex items-center gap-3 p-4 max-[520px]:flex-col max-[520px]:items-stretch">
                                        <span class="text-[12.5px] text-fg-mute flex-1">Ask the model to interpret this run — diagnosis plus three single-variable tests to try next.</span>
                                        <button type="button" x-on:click="doAI()" class="appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] px-4 rounded-control font-sans text-[13px] font-bold border-0 flex-shrink-0 text-[color:var(--on-accent)]" style="background: var(--accent)">
                                            <x-feathericon-zap class="w-[15px] h-[15px]" stroke-width="2"/>Get AI insights
                                        </button>
                                    </div>
                                    <div x-show="ai.loading" class="flex items-center gap-2.5 p-5">
                                        <span class="w-[15px] h-[15px] rounded-full border-2 border-t-transparent animate-spin" style="border-color: var(--accent); border-top-color: transparent"></span>
                                        <span class="font-mono text-[12px] text-fg-mute">Analysing ladder behaviour…</span>
                                    </div>
                                    <div x-show="ai.text" class="p-5 max-w-[760px]" x-html="renderMd(ai.text)"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- ===================== TOAST ===================== --}}
        <template x-teleport="body">
            <template x-if="toast">
                <div :data-theme="contentDark ? 'dark' : 'light'"
                     class="fixed inset-x-0 bottom-[calc(41px+1.5rem)] z-[90] flex justify-center px-4 pointer-events-none max-[820px]:bottom-[calc(64px+1.5rem)]">
                    <div class="w-max max-w-full flex items-center gap-2.5 py-2.5 px-4 rounded-control bg-surface border shadow-3 pointer-events-auto animate-dd-in" :style="`border-color: color-mix(in srgb, ${toast.kind === 'error' ? 'var(--danger)' : toast.kind === 'reject' ? 'var(--pnl-down-fg)' : toast.kind === 'warn' ? 'var(--warn)' : 'var(--pnl-up-fg)'} 45%, var(--border))`">
                        <x-feathericon-alert-triangle x-show="toast.kind === 'error'" class="w-4 h-4 flex-shrink-0" style="color: var(--danger)" stroke-width="1.75"/>
                        <x-feathericon-alert-triangle x-show="toast.kind === 'warn'" class="w-4 h-4 flex-shrink-0" style="color: var(--warn)" stroke-width="1.75"/>
                        <x-feathericon-power x-show="toast.kind === 'reject'" class="w-4 h-4 flex-shrink-0" style="color: var(--pnl-down-fg)" stroke-width="1.75"/>
                        <x-feathericon-check x-show="toast.kind === 'ok'" class="w-4 h-4 flex-shrink-0" style="color: var(--pnl-up-fg)" stroke-width="2"/>
                        <span class="min-w-0 font-sans text-[12.5px] font-semibold leading-snug text-fg-1" x-text="toast.text"></span>
                    </div>
                </div>
            </template>
        </template>
    </div>
</x-app-layout>
