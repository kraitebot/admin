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
            'toggle' => route('system.backtesting.toggle-approval'),
            'ai' => route('system.backtesting.ai-insights'),
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
            filters: { top100: false, approved: false, notConcluded: false },

            // ---- async results ----
            cov: null,
            fetchReport: null,
            fetchOpen: true,
            busy: null,                          // 'fetch' | 'verify' | 'run'
            result: null,                        // raw server result { rows, totals, regimes, meta }
            pair: null,
            rowsTruncated: false,
            maxRowsCap: 500,
            reviews: {},                         // id -> status override
            ai: { loading: false, text: null, model: null },
            coverageWarning: null,               // thin-history alert after auto-fetch

            // ---- rows table filters ----
            statusFilter: 'all',
            dirFilter: 'all',

            // ---- overlays ----
            confirm: null,                       // 'approve' | 'reject'
            toast: null,
            _toastTimer: null,

            // ================= static meta =================
            STATUS_META: {
                tp_market_only: { label: 'TP off market', short: 'TP market', color: 'var(--pnl-up-fg)' },
                reboundable:    { label: 'Reboundable',    short: 'Rebound',   color: '#15b8a6' },
                stopped_out:    { label: 'Stopped out',    short: 'Stopped',   color: 'var(--pnl-down-fg)' },
                inconclusive:   { label: 'Inconclusive',   short: 'Inconcl.',  color: 'var(--fg-mute)', striped: true },
                skipped:        { label: 'Skipped',        short: 'Skipped',   color: 'var(--fg-faint)' },
            },
            REVIEW_META: {
                approved: { label: 'Approved',     color: 'var(--pnl-up-fg)' },
                rejected: { label: 'Rejected',     color: 'var(--pnl-down-fg)' },
                null:     { label: 'Not reviewed', color: 'var(--fg-mute)' },
            },
            GRADE_COLOR: { A: 'var(--pnl-up-fg)', B: '#15b8a6', C: 'var(--warn)', D: '#ff8a3d', F: 'var(--danger)' },

            // ================= derived =================
            get selected() { return this.symbols.find((s) => s.id === this.selId) || null; },
            get status() {
                if (!this.selected) return null;
                return this.reviews[this.selected.id] !== undefined ? this.reviews[this.selected.id] : this.selected.status;
            },
            reviewMeta(status) { return this.REVIEW_META[status == null ? 'null' : status]; },

            quoteOrder(q) { return q === 'USDT' ? 0 : q === 'USDC' ? 1 : 2; },
            // Stable per-token hue → each coin gets its own avatar without inventing brand palettes.
            tokenHue(sym) { let h = 0; for (let i = 0; i < sym.length; i++) { h = (h * 31 + sym.charCodeAt(i)) >>> 0; } return Math.round(((h * 0.6180339887) % 1) * 360); },
            get filteredSymbols() {
                const q = this.query.toLowerCase();
                return this.symbols.filter((s) => {
                    // Token-universe filters (the checkboxes below the selector).
                    if (this.filters.top100 && s.rank > 100) return false;
                    // The two status filters combine as a union: when either is on,
                    // the token must match one of them. AND'd with Top 100 above.
                    if (this.filters.approved || this.filters.notConcluded) {
                        const ok = (this.filters.approved && s.status === 'approved')
                            || (this.filters.notConcluded && s.status == null);
                        if (! ok) return false;
                    }
                    // Search query.
                    return (s.token + ' ' + s.quote).toLowerCase().includes(q);
                });
            },
            // Live universe counts — always over the FULL symbol set, so each
            // checkbox shows its total reach regardless of the others' state.
            get countTop100() { return this.symbols.filter((s) => s.rank <= 100).length; },
            get countApproved() { return this.symbols.filter((s) => s.status === 'approved').length; },
            get countNotConcluded() { return this.symbols.filter((s) => s.status == null).length; },
            get quoteGroups() {
                const quotes = [...new Set(this.filteredSymbols.map((s) => s.quote))]
                    .sort((a, b) => this.quoteOrder(a) - this.quoteOrder(b) || a.localeCompare(b));
                return quotes.map((q) => ({ quote: q, items: this.filteredSymbols.filter((s) => s.quote === q) }));
            },

            // ================= selection =================
            selectToken(s) {
                this.selId = s.id;
                this.selOpen = false;
                this.query = '';
                this.cfg = {
                    since: '', candles_back: '',
                    tp: this.defaults.tp_percent, sl: this.defaults.sl_percent,
                    gapL: s.gapL, gapS: s.gapS,
                    limit_hit: '', max_rows: '500',
                    taapi: true, max_months: '',
                };
                this.cov = null; this.fetchReport = null; this.result = null;
                this.ai = { loading: false, text: null, model: null };
                this.coverageWarning = null;
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
                };
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

            // How does stored coverage fall short of the requested run window?
            // Returns a human message, or null when the window is fully covered.
            // The run windows on candles_back (most-recent N); the Since field
            // drives history depth. So "covered" means: candles exist, at least
            // candles_back of them when a count is set, and history reaches back
            // to Since when a date is set. Reads the mapped `cov` snapshot.
            coverageShortfall() {
                const c = this.cov;
                const tf = this.tf;
                const pair = this.selected ? `${this.selected.token}/${this.selected.quote}` : 'this symbol';
                const present = c ? (c.candles || 0) : 0;

                if (present === 0) {
                    return `No ${tf} candles for ${pair} — the exchange returned no history, so there's nothing to simulate.`;
                }

                const candlesBack = this.cfg.candles_back ? Number(this.cfg.candles_back) : null;
                if (candlesBack && present < candlesBack) {
                    return `Thin history — only ${present.toLocaleString()} ${tf} candles for ${pair}, fewer than the ${candlesBack.toLocaleString()} requested. Ran on all available.`;
                }

                if (this.cfg.since && c && c.earliest && c.earliest !== '—'
                    && new Date(c.earliest) > new Date(this.cfg.since + 'T23:59:59')) {
                    return `Thin history — ${pair} ${tf} only goes back to ${c.earliest}, not the requested ${this.cfg.since}. Ran on what exists.`;
                }

                return null;
            },

            // Pre-run gate: audit coverage, and if the requested window isn't
            // covered, fetch history first so Run never silently simulates on a
            // gap. After the fetch, if history is STILL thin (the token just
            // doesn't have that much real history), alert the admin — banner +
            // toast — but proceed; the run uses whatever exists. Returns false
            // only when a required fetch itself failed (its toast already shown).
            async ensureCoverage() {
                this.busy = 'verify';
                const { ok, data } = await hubUiFetch(this.routes.verify, { body: {
                    exchange_symbol_id: this.selected.id,
                    timeframe: this.tf,
                } });
                this.busy = null;
                if (!ok) { this.flashToast(data.error || 'Coverage check failed', 'error'); return false; }

                this.cov = this.mapCov(data.coverage);
                if (! this.coverageShortfall()) return true;

                // Window short (or no data at all) → fetch fills it.
                const fetched = await this.doFetch();
                if (! fetched) return false;

                // Re-assess against the freshly-fetched coverage. Still short →
                // thin real history; warn loudly and keep going.
                const shortfall = this.coverageShortfall();
                if (shortfall) {
                    this.coverageWarning = shortfall;
                    this.flashToast('Thin history — ran on what exists', 'warn');
                }

                return true;
            },

            async doRun() {
                if (!this.selected || this.busy) return;
                this.result = null;
                this.ai = { loading: false, text: null, model: null };
                this.coverageWarning = null;

                // Auto-fetch: guarantee the candles this run's window needs are
                // present before simulating. Audits coverage, fetches first if the
                // stored window doesn't reach the requested Since / Candles-back.
                if (! await this.ensureCoverage()) return;

                this.busy = 'run';
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
                this.pair = data.pair;
                this.rowsTruncated = !!data.rows_truncated;
                this.maxRowsCap = this.cfg.max_rows ? Number(this.cfg.max_rows) : 500;
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

            // ================= approval =================
            askConfirm(kind) { this.confirm = kind; },
            async onConfirm() {
                const approve = this.confirm === 'approve';
                this.confirm = null;
                const { ok, data } = await hubUiFetch(this.routes.toggle, { body: {
                    exchange_symbol_id: this.selected.id,
                    approve: approve,
                    gap_long_percent: approve && this.cfg.gapL ? Number(this.cfg.gapL) : null,
                    gap_short_percent: approve && this.cfg.gapS ? Number(this.cfg.gapS) : null,
                    tp_percent: approve && this.cfg.tp ? Number(this.cfg.tp) : null,
                    sl_percent: approve && this.cfg.sl ? Number(this.cfg.sl) : null,
                } });
                if (!ok) { this.flashToast(data.error || 'Could not save decision', 'error'); return; }
                this.reviews[this.selected.id] = data.backtesting_review_status;
                this.flashToast(approve ? 'Approved — config live' : 'Rejected — no config pushed', approve ? 'ok' : 'reject');
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
            // System's own decision proposal, derived from the simulator grade:
            // A/B → recommend approve, D/F → recommend reject, C → review (manual
            // call). Drives the proposal banner + the suggested-button emphasis.
            get proposal() {
                if (! this.result) return null;
                const g = this.totals.grade;
                if (g === 'A' || g === 'B') return { action: 'approve', verb: 'Recommend approve', color: 'var(--pnl-up-fg)' };
                if (g === 'D' || g === 'F') return { action: 'reject', verb: 'Recommend reject', color: 'var(--pnl-down-fg)' };
                return { action: 'review', verb: 'Borderline — review manually', color: 'var(--warn)' };
            },
            get proposalReason() {
                if (! this.result) return '';
                const t = this.totals;
                const parts = [];
                if (t.grade) parts.push('Grade ' + t.grade);
                if (t.overall_score != null) parts.push(this.fmtFixed(t.overall_score) + '/100');
                parts.push(this.passRate.toFixed(1) + '% pass');
                return parts.join(' · ');
            },
            get verdictBars() {
                const t = this.totals;
                return [
                    { key: 'tp_market_only', label: 'TP off market leg', n: t.tp_market_only || 0, color: 'var(--pnl-up-fg)' },
                    { key: 'reboundable',    label: 'Reboundable (WAP)', n: t.reboundable || 0,    color: '#15b8a6' },
                    { key: 'stopped_out',    label: 'Stopped out',       n: t.stops || 0,          color: 'var(--pnl-down-fg)' },
                    { key: 'inconclusive',   label: 'Inconclusive',      n: t.inconclusive || 0,   color: 'var(--fg-mute)', striped: true },
                ];
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
            get regimeBars() {
                const regimes = this.result ? (this.result.regimes || []) : [];
                return regimes.map((r) => ({ from: r.from, to: r.to, pass: (r.pass_rate || 0) / 100, stops: r.stops || 0 }));
            },
            worstPass() { const b = this.regimeBars; return b.length ? Math.min(...b.map((r) => r.pass)) : 0; },
            regimeColor(p) { return p >= 0.8 ? 'var(--pnl-up-fg)' : p >= 0.6 ? 'var(--warn)' : 'var(--pnl-down-fg)'; },

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

            // ---- rows table ----
            rowCounts() {
                return (this.result.rows || []).reduce((a, r) => { a[r.status] = (a[r.status] || 0) + 1; return a; }, {});
            },
            get viewRows() {
                return (this.result.rows || []).filter((r) =>
                    (this.statusFilter === 'all' || r.status === this.statusFilter) &&
                    (this.dirFilter === 'all' || r.direction === this.dirFilter));
            },
            maeColor(m) { m = Number(m); return m >= 12 ? 'var(--pnl-down-fg)' : m >= 7 ? 'var(--warn)' : 'var(--fg-2)'; },
            rungCellColor(r) { return r >= 4 ? 'var(--pnl-down-fg)' : r === 3 ? 'var(--warn)' : 'var(--fg-2)'; },
            fmtNum(v) { return v == null ? '—' : Number(v).toLocaleString(); },
            fmtFixed(v, d = 1) { return v == null ? '—' : Number(v).toFixed(d); },

            // ---- markdown (AI insights) ----
            renderMd(src) {
                if (!src) return '';
                const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const inl = (t) => esc(t)
                    .replace(/`([^`]+)`/g, '<code class="font-mono text-[12px] px-1 py-[1px] rounded-r2 bg-surface-3 text-accent">$1</code>')
                    .replace(/\*\*([^*]+)\*\*/g, '<strong class="font-bold text-fg-1">$1</strong>')
                    .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em class="italic text-fg-2">$2</em>')
                    .replace(/_([^_]+)_/g, '<em class="italic text-fg-mute">$1</em>');
                const lines = src.split('\n'); const out = []; let list = null;
                const flush = () => { if (list) { out.push('<ul class="flex flex-col gap-1.5 my-1.5 pl-1">' + list.join('') + '</ul>'); list = null; } };
                lines.forEach((ln) => {
                    if (/^###\s+/.test(ln)) { flush(); out.push('<h5 class="font-mono text-[10.5px] font-bold tracking-[0.1em] uppercase text-fg-mute mt-4 mb-1">' + inl(ln.replace(/^###\s+/, '')) + '</h5>'); }
                    else if (/^##\s+/.test(ln)) { flush(); out.push('<h4 class="font-sans font-bold text-[15px] text-fg-1 mt-4 first:mt-0 mb-1.5 pb-1.5 border-b border-line-soft">' + inl(ln.replace(/^##\s+/, '')) + '</h4>'); }
                    else if (/^\d+\.\s+/.test(ln)) { const m = ln.match(/^(\d+)\.\s+(.*)$/); list = list || []; list.push('<li class="flex gap-2.5 text-[13px] text-fg-2 leading-relaxed"><span class="font-mono text-[11px] font-bold text-accent flex-shrink-0 mt-[2px]">' + m[1] + '</span><span>' + inl(m[2]) + '</span></li>'); }
                    else if (/^-\s+/.test(ln)) { list = list || []; list.push('<li class="flex gap-2.5 text-[13px] text-fg-2 leading-relaxed"><span class="text-accent flex-shrink-0">·</span><span>' + inl(ln.replace(/^-\s+/, '')) + '</span></li>'); }
                    else if (ln.trim() === '') { flush(); }
                    else { flush(); out.push('<p class="text-[13px] text-fg-2 leading-relaxed my-1.5">' + inl(ln) + '</p>'); }
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
                            <button type="button" x-on:click="selOpen = !selOpen"
                                    class="w-full flex items-center gap-2.5 h-[40px] px-3 bg-surface-2 border border-line rounded-control cursor-pointer hover:border-line-strong transition-colors duration-fast text-left">
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
                            ] as $f)
                                <label class="flex items-center gap-2 cursor-pointer select-none group">
                                    <span class="relative flex items-center justify-center w-[16px] h-[16px] rounded-[4px] border transition-colors duration-fast flex-shrink-0"
                                          :style="filters.{{ $f['key'] }} ? 'background: var(--accent); border-color: var(--accent)' : 'background: var(--bg-elev-2); border-color: var(--border-strong)'">
                                        <input type="checkbox" x-model="filters.{{ $f['key'] }}" class="sr-only"/>
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
                                {{-- window --}}
                                <div class="flex flex-col gap-3">
                                    <span class="font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-3">Window</span>
                                    <div class="grid grid-cols-2 gap-3">
                                        <label class="flex flex-col gap-[6px]">
                                            <span class="font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-3">Since</span>
                                            <input type="date" x-model="cfg.since" :disabled="!selected" class="w-full h-[34px] px-2.5 bg-surface-2 border border-line rounded-control font-mono text-[12.5px] text-fg-1 tabular-nums outline-none transition-colors duration-fast focus:border-line-focus placeholder:text-fg-faint disabled:opacity-45 disabled:cursor-not-allowed"/>
                                        </label>
                                        <label class="flex flex-col gap-[6px]">
                                            <span class="font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-3">Candles back</span>
                                            <input type="number" x-model="cfg.candles_back" placeholder="all" :disabled="!selected" class="w-full h-[34px] px-2.5 bg-surface-2 border border-line rounded-control font-mono text-[12.5px] text-fg-1 tabular-nums outline-none transition-colors duration-fast focus:border-line-focus placeholder:text-fg-faint disabled:opacity-45 disabled:cursor-not-allowed"/>
                                        </label>
                                    </div>
                                    <span class="font-mono text-[10px] text-fg-3 tracking-[0.01em] leading-snug">Leave both empty to walk all history. Date drives the fetch depth; candle count windows the run.</span>
                                </div>

                                {{-- strategy --}}
                                <div class="flex flex-col gap-3 pt-3 border-t border-line-soft">
                                    <span class="font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-3">Strategy</span>
                                    <div class="grid grid-cols-2 gap-3">
                                        @php
                                            $btInput = 'w-full h-[34px] px-2.5 bg-surface-2 border border-line rounded-control font-mono text-[12.5px] text-fg-1 tabular-nums outline-none transition-colors duration-fast focus:border-line-focus placeholder:text-fg-faint disabled:opacity-45 disabled:cursor-not-allowed';
                                            $btLabel = 'font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-3';
                                        @endphp
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Take-profit %</span><input type="number" step="0.01" x-model="cfg.tp" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Stop-loss %</span><input type="number" step="0.01" x-model="cfg.sl" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Gap long %</span><input type="number" step="0.01" x-model="cfg.gapL" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Gap short %</span><input type="number" step="0.01" x-model="cfg.gapS" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Limit hit ≥</span><input type="number" x-model="cfg.limit_hit" placeholder="any" :disabled="!selected" class="{{ $btInput }}"/></label>
                                        <label class="flex flex-col gap-[6px]"><span class="{{ $btLabel }}">Max rows</span><input type="number" x-model="cfg.max_rows" :disabled="!selected" class="{{ $btInput }}"/></label>
                                    </div>
                                </div>

                                {{-- fixed envelope --}}
                                <div class="flex flex-col gap-2 pt-3 border-t border-line-soft">
                                    <span class="font-mono text-[9px] font-bold tracking-[0.12em] uppercase text-fg-3 mb-0.5">Fixed envelope</span>
                                    @foreach(['Margin' => '5,000', 'Leverage' => '20×', 'Limit orders' => '4', 'Multipliers' => '[2,2,2,2]'] as $k => $v)
                                        <div class="flex items-center justify-between gap-3 py-[7px] border-b border-line-soft last:border-b-0">
                                            <span class="font-mono text-[10px] font-semibold tracking-[0.08em] uppercase text-fg-mute">{{ $k }}</span>
                                            <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-2">{{ $v }}</span>
                                        </div>
                                    @endforeach
                                    <span class="font-mono text-[10px] text-fg-3 tracking-[0.01em] leading-snug mt-1">Sizing is fixed — backtests measure price geometry (does WAP recover to TP?), not capital allocation.</span>
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
                        <template x-if="busy === 'run'"><span class="flex items-center gap-2"><span class="w-[14px] h-[14px] rounded-full border-2 border-current border-t-transparent animate-spin"></span>Simulating ladder…</span></template>
                        <template x-if="busy !== 'run'"><span class="flex items-center gap-2"><x-feathericon-play class="w-4 h-4" stroke-width="1.75"/>Run backtest</span></template>
                    </button>
                    <span class="font-mono text-[10px] text-fg-faint tracking-[0.01em] leading-snug text-center">Fetch and Run can take a few seconds.</span>
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
                            <span x-show="!result" class="font-mono text-[10px] text-fg-faint tracking-[0.01em] leading-snug">Run a backtest before approving — the decision pushes the tested config live.</span>
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
                            <div class="flex gap-2">
                                <button type="button" x-on:click="askConfirm('approve')" :disabled="!result || status === 'approved'"
                                        class="flex-1 appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] rounded-control font-sans text-[13px] font-bold text-white border-0 transition-colors duration-fast disabled:opacity-40 disabled:cursor-not-allowed" style="background: var(--pnl-up-fg)"
                                        :style="{ boxShadow: proposal && proposal.action === 'approve' ? '0 0 0 2px color-mix(in srgb, var(--pnl-up-fg) 50%, transparent)' : '' }">
                                    <x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/>Approve
                                </button>
                                <button type="button" x-on:click="askConfirm('reject')" :disabled="!result || status === 'rejected'"
                                        class="flex-1 appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[38px] rounded-control font-sans text-[13px] font-bold border transition-colors duration-fast hover:bg-hover disabled:opacity-40 disabled:cursor-not-allowed" style="color: var(--pnl-down-fg); border-color: color-mix(in srgb, var(--pnl-down-fg) 40%, transparent)"
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
                    <x-feathericon-database class="w-[15px] h-[15px] text-fg-faint" stroke-width="1.75"/>
                    <span class="text-[12px] text-fg-mute">No coverage data — run <span class="font-semibold text-fg-2">Verify</span> or <span class="font-semibold text-fg-2">Fetch</span>.</span>
                </div>
                <template x-if="cov">
                    <div class="card card--flat overflow-hidden">
                        <div class="flex items-center gap-2.5 py-2.5 px-4 border-b border-line-soft" :style="`background: color-mix(in srgb, ${cov.holes === 0 ? 'var(--pnl-up-fg)' : 'var(--warn)'} 8%, transparent)`">
                            <span class="w-[8px] h-[8px] rounded-chip flex-shrink-0" :style="`background: ${cov.holes === 0 ? 'var(--pnl-up-fg)' : 'var(--warn)'}`"></span>
                            <span class="font-mono text-[11px] font-bold tracking-[0.04em] uppercase" :style="`color: ${cov.holes === 0 ? 'var(--pnl-up-fg)' : 'var(--warn)'}`"
                                  x-text="cov.holes === 0 ? 'Complete coverage' : `${cov.holes} gap${cov.holes > 1 ? 's' : ''} · ${cov.contiguity}% contiguity`"></span>
                            <span x-show="cov.holes > 0" class="font-mono text-[10px] text-fg-mute ml-auto max-[520px]:hidden">Fetch can backfill the gaps</span>
                        </div>
                        <div class="grid grid-cols-4 gap-3 py-3 px-4 max-[520px]:grid-cols-2 max-[520px]:gap-y-3">
                            <template x-for="c in [['Earliest', cov.earliest], ['Latest', cov.latest], ['Candles', cov.candles.toLocaleString()], ['Contiguity', cov.contiguity + '%']]" :key="c[0]">
                                <div class="flex flex-col gap-1 min-w-0">
                                    <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-faint whitespace-nowrap" x-text="c[0]"></span>
                                    <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1 whitespace-nowrap" x-text="c[1]"></span>
                                </div>
                            </template>
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
                        <p class="text-[12.5px] text-fg-mute max-w-[340px] leading-snug" x-show="selected">Fetch history, then <span class="font-semibold text-fg-2">Run backtest</span> — grade, pass rate, regime stability, and per-trade rows appear here.</p>
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
                                        <span class="font-mono text-[10px] font-semibold tracking-[0.1em] uppercase text-fg-mute">Grade · verdict</span>
                                        <span class="font-sans font-bold text-[17px] text-fg-1 leading-tight" x-text="totals.verdict || 'No verdict'"></span>
                                        <div class="flex items-center gap-4 mt-0.5">
                                            <span class="font-mono text-[11.5px] text-fg-2">Overall <span class="font-bold tabular-nums text-fg-1" x-text="fmtFixed(totals.overall_score)"></span><span class="text-fg-faint">/100</span></span>
                                            <span class="font-mono text-[11.5px] text-fg-2">Risk <span class="font-bold tabular-nums" :style="`color: ${(totals.risk_score || 0) > 50 ? 'var(--warn)' : 'var(--fg-1)'}`" x-text="fmtFixed(totals.risk_score)"></span></span>
                                        </div>
                                    </div>
                                </div>

                                {{-- truncated notice --}}
                                <div x-show="rowsTruncated" class="card card--flat flex items-center gap-2.5 py-2.5 px-4" style="background: color-mix(in srgb, var(--info) 7%, transparent); border-color: color-mix(in srgb, var(--info) 28%, var(--border))">
                                    <x-feathericon-alert-circle class="w-[14px] h-[14px]" style="color: var(--info)" stroke-width="1.75"/>
                                    <span class="text-[12px] text-fg-2">Showing first <span x-text="maxRowsCap"></span> rows of a larger set.</span>
                                </div>

                                {{-- scorecards --}}
                                <div class="grid grid-cols-3 gap-3 max-[640px]:grid-cols-2">
                                    @php
                                        $statCard = 'card card--flat px-3.5 py-3 flex flex-col gap-1.5';
                                        $statLabel = 'font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute whitespace-nowrap';
                                        $statVal = 'font-mono text-[20px] font-bold tabular-nums leading-none';
                                        $statSub = 'font-mono text-[9px] tracking-[0.05em] uppercase whitespace-nowrap';
                                    @endphp
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }}">Pass rate</span><span class="{{ $statVal }}" style="color: var(--pnl-up-fg)" x-text="passRate.toFixed(1) + '%'"></span><span class="{{ $statSub }} text-fg-faint">resolved sims</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }}">Max MAE %</span><span class="{{ $statVal }}" style="color: var(--pnl-down-fg)" x-text="fmtFixed(totals.max_mae_pct)"></span><span class="{{ $statSub }}" style="color: var(--warn)">liq-risk proxy</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }}">Avg rung depth</span><span class="{{ $statVal }} text-fg-1" x-text="fmtFixed(totals.avg_rung_depth)"></span><span class="{{ $statSub }} text-fg-faint">of 4 rungs</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }}">Avg → profit</span><span class="{{ $statVal }} text-fg-1" x-text="totals.avg_candles_to_profit == null ? '—' : totals.avg_candles_to_profit + ' c'"></span><span class="{{ $statSub }} text-fg-faint">candles</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }}">p95 → profit</span><span class="{{ $statVal }} text-fg-1" x-text="totals.p95_candles_to_profit == null ? '—' : totals.p95_candles_to_profit + ' c'"></span><span class="{{ $statSub }} text-fg-faint">candles</span></div>
                                    <div class="{{ $statCard }}"><span class="{{ $statLabel }}">Sample size</span><span class="{{ $statVal }} text-fg-1" x-text="sampleSize.toLocaleString()"></span><span class="{{ $statSub }}" :style="`color: ${sampleSize < (totals.sample_size_threshold || 180) ? 'var(--warn)' : 'var(--fg-faint)'}`" x-text="sampleSize < (totals.sample_size_threshold || 180) ? 'below threshold' : 'sims'"></span></div>
                                </div>

                                {{-- verdict bar + rung chart --}}
                                <div class="grid grid-cols-2 gap-4 max-[760px]:grid-cols-1">
                                    {{-- verdict breakdown --}}
                                    <div class="card card--flat overflow-hidden">
                                        <x-ui.card-head icon="layers" title="Verdict breakdown" :accent="true">
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
                                        <x-ui.card-head icon="bar-chart-2" title="Rung distribution" :accent="true" hint="ladder depth reached"/>
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
                                            <span class="font-mono text-[10px] text-fg-faint mt-0.5">Deeper rungs = more averaging-down — the deepest rung's reach is the key risk signal.</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- config echo --}}
                                <div class="flex items-center gap-x-4 gap-y-1 flex-wrap py-2.5 px-4 card card--flat">
                                    <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-faint">Config</span>
                                    <template x-for="kv in configEcho" :key="kv[0]">
                                        <span class="font-mono text-[10.5px] text-fg-mute"><span class="text-fg-faint" x-text="kv[0]"></span> <span class="font-semibold text-fg-2 tabular-nums" x-text="kv[1]"></span></span>
                                    </template>
                                </div>

                                {{-- [H] regime stability --}}
                                <template x-if="regimeBars.length">
                                    <div class="card card--flat overflow-hidden">
                                        <x-ui.card-head icon="activity" title="Regime stability" :accent="true">
                                            <x-slot:right><span class="font-mono text-[10.5px] text-fg-mute" x-text="`worst ${(worstPass() * 100).toFixed(0)}% pass`"></span></x-slot:right>
                                        </x-ui.card-head>
                                        <div class="p-4">
                                            <div class="flex items-end gap-1.5 h-[96px]">
                                                <template x-for="(r, i) in regimeBars" :key="i">
                                                    <div class="flex-1 flex flex-col items-center justify-end h-full group relative">
                                                        <div class="w-full rounded-t-[3px] transition-all duration-base relative" :style="`height: ${r.pass * 100}%; min-height: 4px; background: ${regimeColor(r.pass)}; opacity: ${r.pass === worstPass() ? 1 : 0.8}; box-shadow: ${r.pass === worstPass() ? `0 0 0 2px color-mix(in srgb, ${regimeColor(r.pass)} 55%, transparent)` : 'none'}`"></div>
                                                        <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-20 hidden group-hover:block whitespace-nowrap bg-surface border border-line-strong rounded-control shadow-3 px-2.5 py-1.5 pointer-events-none">
                                                            <div class="font-mono text-[10px] font-bold text-fg-1" x-text="`${r.from} – ${r.to}`"></div>
                                                            <div class="font-mono text-[10px]" :style="`color: ${regimeColor(r.pass)}`" x-text="`${(r.pass * 100).toFixed(0)}% pass · ${r.stops} stops`"></div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-line-soft">
                                                <span class="font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-faint" x-text="regimeBars[0].from"></span>
                                                <span class="font-mono text-[10px] text-fg-mute max-[520px]:hidden">Each bar = a time bucket · height = pass rate · worst highlighted</span>
                                                <span class="font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-faint" x-text="regimeBars[regimeBars.length - 1].to"></span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- [I] rows table --}}
                                <div class="card card--flat overflow-hidden">
                                    <x-ui.card-head icon="database" title="Per-simulation rows" :accent="true">
                                        <x-slot:right><span class="font-mono text-[10.5px] text-fg-mute tabular-nums" x-text="`${viewRows.length} of ${(result.rows || []).length}`"></span></x-slot:right>
                                    </x-ui.card-head>

                                    {{-- filter chips --}}
                                    <div class="flex items-center gap-1.5 flex-wrap py-2.5 px-4 border-b border-line-soft" style="background: color-mix(in srgb, var(--bg-elev-2) 40%, transparent)">
                                        <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-faint mr-1">Status</span>
                                        @php $chip = 'appearance-none cursor-pointer inline-flex items-center gap-1.5 h-[28px] px-2.5 rounded-chip border font-mono text-[10.5px] font-semibold tracking-[0.04em] whitespace-nowrap transition-colors duration-fast'; @endphp
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'all'" :style="statusFilter === 'all' ? 'color: var(--accent); border-color: color-mix(in srgb, var(--accent) 45%, transparent); background: color-mix(in srgb, var(--accent) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">All</button>
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'stopped_out'" :style="statusFilter === 'stopped_out' ? 'color: var(--pnl-down-fg); border-color: color-mix(in srgb, var(--pnl-down-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-down-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'"><x-feathericon-alert-triangle class="w-3 h-3" stroke-width="1.75"/>Stopped · <span x-text="rowCounts().stopped_out || 0"></span></button>
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'reboundable'" :style="statusFilter === 'reboundable' ? 'color: #15b8a6; border-color: color-mix(in srgb, #15b8a6 45%, transparent); background: color-mix(in srgb, #15b8a6 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Rebound · <span x-text="rowCounts().reboundable || 0"></span></button>
                                        <button type="button" class="{{ $chip }}" x-on:click="statusFilter = 'tp_market_only'" :style="statusFilter === 'tp_market_only' ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-up-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">TP market · <span x-text="rowCounts().tp_market_only || 0"></span></button>
                                        <span class="w-px h-4 bg-line-soft mx-1.5"></span>
                                        <span class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase text-fg-faint mr-1">Side</span>
                                        <button type="button" class="{{ $chip }}" x-on:click="dirFilter = 'all'" :style="dirFilter === 'all' ? 'color: var(--accent); border-color: color-mix(in srgb, var(--accent) 45%, transparent); background: color-mix(in srgb, var(--accent) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Both</button>
                                        <button type="button" class="{{ $chip }}" x-on:click="dirFilter = 'LONG'" :style="dirFilter === 'LONG' ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-up-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Long</button>
                                        <button type="button" class="{{ $chip }}" x-on:click="dirFilter = 'SHORT'" :style="dirFilter === 'SHORT' ? 'color: var(--pnl-down-fg); border-color: color-mix(in srgb, var(--pnl-down-fg) 45%, transparent); background: color-mix(in srgb, var(--pnl-down-fg) 13%, transparent)' : 'color: var(--fg-mute); border-color: var(--border); background: transparent'">Short</button>
                                    </div>

                                    {{-- header --}}
                                    <div class="hidden lg:grid grid-cols-[64px_136px_1fr_56px_136px_1fr_64px_112px] gap-2 py-2 px-4 border-b border-line-soft bg-surface-2 font-mono text-[9px] font-semibold tracking-[0.08em] uppercase text-fg-faint">
                                        <span>Side</span><span>Start candle</span><span>Entry ref</span><span>Rung</span><span>Last touch</span><span>TP price</span><span>MAE %</span><span>Status</span>
                                    </div>

                                    {{-- rows --}}
                                    <div class="max-h-[420px] overflow-y-auto">
                                        <template x-for="(r, i) in viewRows" :key="i">
                                            <div class="grid grid-cols-[64px_136px_1fr_56px_136px_1fr_64px_112px] gap-2 items-center py-2.5 px-4 border-b border-line-soft last:border-b-0 max-lg:grid-cols-2 max-lg:gap-y-1.5">
                                                <span class="flex"><span class="font-mono text-[9.5px] font-bold tracking-[0.05em] py-[2px] px-[7px] rounded-chip" :style="`color: ${r.direction === 'LONG' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'}; background: color-mix(in srgb, ${r.direction === 'LONG' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 13%, transparent)`" x-text="r.direction"></span></span>
                                                <span class="font-mono text-[11px] tabular-nums text-fg-2" x-text="r.start_candle"></span>
                                                <span class="font-mono text-[11.5px] tabular-nums text-fg-1" x-text="r.entry_ref_price"></span>
                                                <span class="font-mono text-[11.5px] font-semibold tabular-nums" :style="`color: ${rungCellColor(r.last_rung)}`" x-text="r.last_rung"></span>
                                                <span class="font-mono text-[11px] tabular-nums text-fg-mute" x-text="r.last_touch_candle || '—'"></span>
                                                <span class="font-mono text-[11.5px] tabular-nums text-fg-2" x-text="r.tp_price"></span>
                                                <span class="font-mono text-[11.5px] font-semibold tabular-nums" :style="`color: ${maeColor(r.mae_pct)}`" x-text="fmtFixed(r.mae_pct)"></span>
                                                <span class="flex"><span class="inline-flex items-center gap-1.5 font-mono text-[9.5px] font-bold tracking-[0.04em] uppercase" :style="`color: ${(STATUS_META[r.status] || STATUS_META.skipped).color}`"><span class="w-[6px] h-[6px] rounded-r2" :style="`background: ${(STATUS_META[r.status] || STATUS_META.skipped).color}; opacity: ${(STATUS_META[r.status] || {}).striped ? 0.6 : 1}`"></span><span x-text="(STATUS_META[r.status] || STATUS_META.skipped).short"></span></span></span>
                                            </div>
                                        </template>
                                        <div x-show="!viewRows.length" class="py-8 text-center text-[12px] text-fg-mute">No rows match this filter.</div>
                                    </div>
                                </div>

                                {{-- [J] AI insights --}}
                                <div class="card card--flat overflow-hidden">
                                    <x-ui.card-head icon="zap" title="AI insights" :accent="true">
                                        <x-slot:right>
                                            <span x-show="ai.text" class="font-mono text-[10px] text-fg-faint" x-text="'via ' + (ai.model || 'model')"></span>
                                            <span x-show="!ai.text" class="font-mono text-[10px] text-fg-faint">advisory · applies no changes</span>
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
                                    <div x-show="ai.text" class="p-5" x-html="renderMd(ai.text)"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- ===================== CONFIRM MODAL ===================== --}}
        <template x-if="confirm">
            <div class="fixed inset-0 z-[80] flex items-center justify-center p-4 animate-dd-in" style="background: rgba(0,0,0,0.55)" x-on:mousedown="confirm = null">
                <div class="w-[420px] max-w-full bg-surface border rounded-control shadow-3 p-5" :style="`border-color: color-mix(in srgb, ${confirm === 'approve' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 40%, var(--border))`" x-on:mousedown.stop>
                    <div class="flex items-center gap-2.5 mb-2.5">
                        <span class="w-[32px] h-[32px] rounded-control flex items-center justify-center flex-shrink-0" :style="`background: color-mix(in srgb, ${confirm === 'approve' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'} 15%, transparent); color: ${confirm === 'approve' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'}`">
                            <x-feathericon-check x-show="confirm === 'approve'" class="w-[17px] h-[17px]" stroke-width="2"/>
                            <x-feathericon-alert-triangle x-show="confirm === 'reject'" class="w-[17px] h-[17px]" stroke-width="2"/>
                        </span>
                        <h4 class="font-sans font-bold text-[15px] text-fg-1"><span x-text="confirm === 'approve' ? 'Approve' : 'Reject'"></span> <span x-text="selected ? selected.token + ' ' + selected.quote : ''"></span>?</h4>
                    </div>
                    <p class="text-[12.5px] text-fg-3 leading-snug mb-4" x-show="confirm === 'approve'">Enables <span class="font-semibold text-fg-1" x-text="selected ? selected.token + '/' + selected.quote : ''"></span> for live trading and pushes the tested gap / TP / SL config to the engine — and to sibling exchanges.</p>
                    <p class="text-[12.5px] text-fg-3 leading-snug mb-4" x-show="confirm === 'reject'">Flags <span class="font-semibold text-fg-1" x-text="selected ? selected.token + '/' + selected.quote : ''"></span> as rejected. No config is pushed; the live engine is untouched.</p>
                    <div class="flex items-center gap-2 justify-end">
                        <button type="button" x-on:click="confirm = null" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-2 h-[36px] px-4 text-[13px] bg-transparent text-fg-1 border-line-strong hover:bg-hover transition-colors duration-fast">Cancel</button>
                        <button type="button" x-on:click="onConfirm()" class="appearance-none cursor-pointer inline-flex items-center justify-center gap-2 h-[36px] px-4 rounded-control font-sans text-[13px] font-bold text-white border-0 transition-colors duration-fast" :style="`background: ${confirm === 'approve' ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'}`">
                            <x-feathericon-check x-show="confirm === 'approve'" class="w-[15px] h-[15px]" stroke-width="2"/>
                            <x-feathericon-power x-show="confirm === 'reject'" class="w-[15px] h-[15px]" stroke-width="2"/>
                            <span x-text="confirm === 'approve' ? 'Approve & push' : 'Reject'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        {{-- ===================== TOAST ===================== --}}
        <template x-if="toast">
            <div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[90] flex items-center gap-2.5 py-2.5 px-4 rounded-control bg-surface border shadow-3 animate-dd-in" :style="`border-color: color-mix(in srgb, ${toast.kind === 'error' ? 'var(--danger)' : toast.kind === 'reject' ? 'var(--pnl-down-fg)' : toast.kind === 'warn' ? 'var(--warn)' : 'var(--pnl-up-fg)'} 45%, var(--border))`">
                <x-feathericon-alert-triangle x-show="toast.kind === 'error'" class="w-4 h-4" style="color: var(--danger)" stroke-width="1.75"/>
                <x-feathericon-alert-triangle x-show="toast.kind === 'warn'" class="w-4 h-4" style="color: var(--warn)" stroke-width="1.75"/>
                <x-feathericon-power x-show="toast.kind === 'reject'" class="w-4 h-4" style="color: var(--pnl-down-fg)" stroke-width="1.75"/>
                <x-feathericon-check x-show="toast.kind === 'ok'" class="w-4 h-4" style="color: var(--pnl-up-fg)" stroke-width="2"/>
                <span class="font-sans text-[12.5px] font-semibold text-fg-1" x-text="toast.text"></span>
            </div>
        </template>
    </div>
</x-app-layout>
