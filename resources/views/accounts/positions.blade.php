@php
    // ============================================================
    // MOCK DATA — design-fidelity port. Wire to backend later.
    // The first-run gate below is REAL: an account that has never
    // opened a single position gets the first-run empty state
    // instead of the mock tables.
    // ============================================================
    $accountIds = auth()->user()->is_admin
        ? null
        : Kraite\Core\Models\Account::where('user_id', auth()->id())->pluck('id');
    $noPositions = ! Kraite\Core\Models\Position::query()
        ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds))
        ->exists();

    $regime = 'ELEVATED';
    $score = 0.63;

    $regimes = [
        'CALM'        => ['color' => 'var(--bsi-calm)'],
        'WATCH'       => ['color' => 'var(--bsi-watch)'],
        'ELEVATED'    => ['color' => 'var(--bsi-cascade)'],
        'CASCADE'     => ['color' => 'var(--bsi-cascade)'],
        'BLACK SWAN'  => ['color' => 'var(--bsi-blackswan)'],
    ];
    $r = $regimes[$regime] ?? $regimes['CALM'];

    // ---- open positions ----
    $positions = [
        ['sym' => 'BTC',  'market' => 'BTC-PERP',  'name' => 'Bitcoin',   'cmcId' => 1,     'side' => 'long',  'lev' => '3×', 'filled' => '1 / 4', 'open' => '67,420.00', 'tp' => '70,250.00', 'pnl' => 1266.93,  'mark' => '68,910.50', 'size' => '0.850',  'notional' => 57307, 'margin' => 19102, 'liq' => '45,910.00', 'roe' => 6.63,  'ageH' => 28],
        ['sym' => 'ETH',  'market' => 'ETH-PERP',  'name' => 'Ethereum',  'cmcId' => 1027,  'status' => 'opening', 'side' => 'long',  'lev' => '3×', 'filled' => '0 / 4', 'open' => '3,512.00',  'tp' => '3,624.00',  'pnl' => 448.88,   'mark' => '3,548.17',  'size' => '12.40',  'notional' => 43549, 'margin' => 14516, 'liq' => '2,389.50',  'roe' => 3.09,  'ageH' => 0.2],
        ['sym' => 'SOL',  'market' => 'SOL-PERP',  'name' => 'Solana',    'cmcId' => 5426,  'status' => 'waped',   'side' => 'short', 'lev' => '2×', 'filled' => '2 / 4', 'open' => '168.40',    'tp' => '162.10',    'pnl' => 1386.00,  'mark' => '162.10',    'size' => '308',    'notional' => 51867, 'margin' => 25933, 'liq' => '244.10',    'roe' => 5.34,  'ageH' => 54],
        ['sym' => 'ARB',  'market' => 'ARB-PERP',  'name' => 'Arbitrum',  'cmcId' => 11841, 'side' => 'long',  'lev' => '4×', 'filled' => '0 / 4', 'open' => '0.8920',    'tp' => '0.9180',    'pnl' => -113.40,  'mark' => '0.8710',    'size' => '5,400',  'notional' => 4817,  'margin' => 1204,  'liq' => '0.6920',    'roe' => -9.42, 'ageH' => 8],
        ['sym' => 'AVAX', 'market' => 'AVAX-PERP', 'name' => 'Avalanche', 'cmcId' => 5805,  'side' => 'short', 'lev' => '2×', 'filled' => '1 / 4', 'open' => '38.20',     'tp' => '36.40',     'pnl' => -80.75,   'mark' => '39.05',     'size' => '95',     'notional' => 3629,  'margin' => 1814,  'liq' => '55.10',     'roe' => -4.45, 'ageH' => 14],
        ['sym' => 'DOGE', 'market' => 'DOGE-PERP', 'name' => 'Dogecoin',  'cmcId' => 74,    'side' => 'long',  'lev' => '3×', 'filled' => '0 / 4', 'open' => '0.16200',   'tp' => '0.16850',   'pnl' => 140.00,   'mark' => '0.16550',   'size' => '43,000', 'notional' => 6966,  'margin' => 2322,  'liq' => '0.11000',   'roe' => 6.03,  'ageH' => 22],
        ['sym' => 'LINK', 'market' => 'LINK-PERP', 'name' => 'Chainlink', 'cmcId' => 1975,  'side' => 'long',  'lev' => '3×', 'filled' => '1 / 4', 'open' => '18.92',     'tp' => '20.10',     'pnl' => 318.50,   'mark' => '19.25',     'size' => '970',    'notional' => 18352, 'margin' => 6117,  'liq' => '12.85',     'roe' => 5.21,  'ageH' => 26],
        ['sym' => 'OP',   'market' => 'OP-PERP',   'name' => 'Optimism',  'cmcId' => 11840, 'side' => 'long',  'lev' => '4×', 'filled' => '0 / 4', 'open' => '2.480',     'tp' => '2.610',     'pnl' => -54.20,   'mark' => '2.451',     'size' => '1,850',  'notional' => 4588,  'margin' => 1147,  'liq' => '1.9250',    'roe' => -4.73, 'ageH' => 6],
        ['sym' => 'XRP',  'market' => 'XRP-PERP',  'name' => 'XRP',       'cmcId' => 52,    'side' => 'short', 'lev' => '2×', 'filled' => '2 / 4', 'open' => '0.5420',    'tp' => '0.5180',    'pnl' => 412.00,   'mark' => '0.5278',    'size' => '13,800', 'notional' => 7480,  'margin' => 3740,  'liq' => '0.7850',    'roe' => 11.02, 'ageH' => 16],
        ['sym' => 'INJ',  'market' => 'INJ-PERP',  'name' => 'Injective', 'cmcId' => 7226,  'side' => 'short', 'lev' => '2×', 'filled' => '0 / 4', 'open' => '24.80',     'tp' => '23.40',     'pnl' => -38.90,   'mark' => '25.05',     'size' => '310',    'notional' => 7688,  'margin' => 3844,  'liq' => '36.10',     'roe' => -1.01, 'ageH' => 10],
    ];

    // ---- closed / historical positions (realized) ----
    // reason: 'tp' (target hit) · 'stop' (stop-loss) · 'manual' · 'regime' (closed by Black-Swan halt)
    $closed = [
        ['sym' => 'LINK', 'name' => 'Chainlink', 'cmcId' => 1975,  'side' => 'long',  'lev' => '3×', 'entry' => '17.80',     'exit' => '18.92',     'size' => '970',    'pnl' => 312.40,   'roe' => 6.0,  'durH' => 28, 'closedAgo' => '1h',    'reason' => 'tp'],
        ['sym' => 'APT',  'name' => 'Aptos',     'cmcId' => 21794, 'side' => 'short', 'lev' => '2×', 'entry' => '8.900',     'exit' => '9.140',     'size' => '1,200',  'pnl' => -96.10,   'roe' => -2.7, 'durH' => 3,  'closedAgo' => '3h',    'reason' => 'stop'],
        ['sym' => 'BTC',  'name' => 'Bitcoin',   'cmcId' => 1,     'side' => 'long',  'lev' => '3×', 'entry' => '64,200.00', 'exit' => '66,980.00', 'size' => '0.620',  'pnl' => 1722.60,  'roe' => 8.1,  'durH' => 41, 'closedAgo' => '6h',    'reason' => 'tp'],
        ['sym' => 'SUI',  'name' => 'Sui',       'cmcId' => 20947, 'side' => 'long',  'lev' => '3×', 'entry' => '1.840',     'exit' => '1.762',     'size' => '3,400',  'pnl' => -265.20,  'roe' => -7.2, 'durH' => 11, 'closedAgo' => '9h',    'reason' => 'stop'],
        ['sym' => 'SOL',  'name' => 'Solana',    'cmcId' => 5426,  'side' => 'short', 'lev' => '2×', 'entry' => '178.20',    'exit' => '171.40',    'size' => '290',    'pnl' => 1972.00,  'roe' => 7.6,  'durH' => 33, 'closedAgo' => '12h',   'reason' => 'tp'],
        ['sym' => 'DOGE', 'name' => 'Dogecoin',  'cmcId' => 74,    'side' => 'long',  'lev' => '3×', 'entry' => '0.15800',   'exit' => '0.16240',   'size' => '38,000', 'pnl' => 167.20,   'roe' => 5.4,  'durH' => 19, 'closedAgo' => '14h',   'reason' => 'manual'],
        ['sym' => 'ETH',  'name' => 'Ethereum',  'cmcId' => 1027,  'side' => 'long',  'lev' => '3×', 'entry' => '3,640.00',  'exit' => '3,512.00',  'size' => '9.80',   'pnl' => -1254.40, 'roe' => -8.8, 'durH' => 7,  'closedAgo' => '18h',   'reason' => 'regime'],
        ['sym' => 'AVAX', 'name' => 'Avalanche', 'cmcId' => 5805,  'side' => 'short', 'lev' => '2×', 'entry' => '41.20',     'exit' => '38.60',     'size' => '120',    'pnl' => 312.00,   'roe' => 5.1,  'durH' => 22, 'closedAgo' => '1d',    'reason' => 'tp'],
        ['sym' => 'TIA',  'name' => 'Celestia',  'cmcId' => 22861, 'side' => 'short', 'lev' => '2×', 'entry' => '9.800',     'exit' => '10.120',    'size' => '2,100',  'pnl' => -134.40,  'roe' => -3.6, 'durH' => 5,  'closedAgo' => '1d 4h', 'reason' => 'stop'],
        ['sym' => 'XRP',  'name' => 'XRP',       'cmcId' => 52,    'side' => 'long',  'lev' => '3×', 'entry' => '0.5180',    'exit' => '0.5420',    'size' => '12,000', 'pnl' => 288.00,   'roe' => 6.7,  'durH' => 26, 'closedAgo' => '1d 8h', 'reason' => 'tp'],
    ];

    // ---- formatters ----
    $num = fn (string|int|float $s): float => (float) str_replace(',', '', (string) $s);
    $usd0 = fn (float|int $n): string => '$' . number_format(round($n));
    $usdSigned = fn (float $n): string => ($n >= 0 ? '+$' : '−$') . number_format(abs($n), 2);
    $pctSigned = fn (float $n): string => ($n >= 0 ? '+' : '−') . number_format(abs($n), 2) . '%';
    $fmtAge = function (float $h): string {
        if ($h < 1) {
            return round($h * 60) . 'm';
        }
        if ($h < 24) {
            return round($h) . 'h';
        }
        $d = floor($h / 24);
        $rem = round(fmod($h, 24));
        return $d . 'd' . ($rem ? ' ' . $rem . 'h' : '');
    };
    $fmtTime = fn (int $ts): string => gmdate('M j, H:i', $ts) . ' UTC';
    $decimalsOf = function (string $s): int {
        $parts = explode('.', $s);
        return isset($parts[1]) ? strlen($parts[1]) : 0;
    };
    $fmtPrice = fn (float $n, int $dec): string => number_format($n, $dec);
    $parseAgo = function (string $s): float {
        $h = 0.0;
        if (preg_match('/(\d+)\s*d/', $s, $m)) {
            $h += (int) $m[1] * 24;
        }
        if (preg_match('/(\d+)\s*h/', $s, $m)) {
            $h += (int) $m[1];
        }
        if (preg_match('/(\d+)\s*m/', $s, $m)) {
            $h += (int) $m[1] / 60;
        }
        return $h ?: 1.0;
    };

    // close-reason metadata
    $reasonMeta = [
        'tp'     => ['label' => 'TP HIT', 'color' => 'var(--pnl-up-fg)'],
        'stop'   => ['label' => 'STOP',   'color' => 'var(--pnl-down-fg)'],
        'manual' => ['label' => 'MANUAL', 'color' => 'var(--fg-mute)'],
        'regime' => ['label' => 'REGIME', 'color' => 'var(--bsi-blackswan)'],
    ];

    // ---- per-position record (derived deterministically from the row) ----
    // In production these fields resolve from exchange_symbol_id / account_id
    // and the order log; here they're synthesised from the position so the
    // panel reads real. NOW is frozen so records stay deterministic.
    $NOW = strtotime('2026-06-02T14:30:00Z');
    $buildDetail = function (array $p) use ($num, $decimalsOf, $fmtPrice, $fmtAge, $parseAgo, $NOW): array {
        $closed = array_key_exists('exit', $p);
        $seed = 0;
        foreach (str_split($p['sym']) as $c) {
            $seed = (($seed * 31) + ord($c)) & 0xFFFFFFFF;
        }
        $rng = function () use (&$seed): float {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            return $seed / 0x7FFFFFFF;
        };
        $long = $p['side'] === 'long';
        $openStr = $closed ? $p['entry'] : $p['open'];
        $dec = $decimalsOf($openStr);
        $openN = $num($openStr);
        $qtyDec = $decimalsOf($p['size']);
        $qtyN = $num($p['size']);
        $fmtQty = fn (float $frac): string => number_format($qtyN * $frac, $qtyDec);

        $durH = $closed ? $p['durH'] : $p['ageH'];
        $closedAt = $closed ? $NOW - (int) round($parseAgo($p['closedAgo']) * 3600) : null;
        $openedAt = ($closed ? $closedAt : $NOW) - (int) round($durH * 3600);

        $levN = ((int) $p['lev']) ?: 2;
        $notional = $closed ? (int) round($openN * $qtyN) : $p['notional'];
        $margin = $closed ? (int) round($notional / $levN) : $p['margin'];

        $tpPct = $closed ? (4 + $rng() * 3) : abs(($num($p['tp']) - $openN) / $openN) * 100;
        $slPct = 6 + (int) round($rng() * 5); // frozen at open
        $tf = ['5m', '15m', '1h', '4h'][(int) floor($rng() * 4)];
        $firstProfit = $closed ? $fmtPrice($openN * (1 + ($long ? 1 : -1) * $tpPct / 100), $dec) : $p['tp'];

        $total = $closed ? 4 : (((int) explode('/', $p['filled'])[1]) ?: 4);
        $filledN = $closed ? (1 + (int) floor($rng() * 3)) : (int) $p['filled'];

        // orders — entry market, limit ladder (avg-down), then the close (open:
        // live profit target; closed: the realized close + cancelled ladder)
        $entrySide = $long ? 'BUY' : 'SELL';
        $closeSide = $long ? 'SELL' : 'BUY';
        $orders = [];
        $orders[] = ['type' => 'MARKET', 'side' => $entrySide, 'status' => 'FILLED', 'qty' => $fmtQty(0.40), 'price' => $fmtPrice($openN, $dec), 'opened' => $openedAt, 'filled' => $openedAt];
        // demo: BTC's entry order is OUT OF SYNC with the exchange — the
        // exchange reports a slightly different fill qty/price and a later
        // fill timestamp than Kraite's DB. Drives the reconcile sub-row.
        if (! $closed && $p['sym'] === 'BTC') {
            $orders[0]['sync'] = [
                'type' => 'MARKET',
                'side' => $entrySide,
                'status' => 'FILLED',
                'qty' => number_format($qtyN * 0.40 + 0.002, $qtyDec),
                'price' => $fmtPrice($openN - 1.5, $dec),
                'opened' => $openedAt,
                'filled' => $openedAt + 60,
            ];
        }
        for ($i = 0; $i < $total; $i++) {
            $done = $i < $filledN;
            $px = $openN * (1 + ($long ? -1 : 1) * 0.012 * ($i + 1));
            $t = $openedAt + ($i + 1) * 22 * 60;
            $orders[] = ['type' => 'LIMIT', 'side' => $entrySide, 'status' => $done ? 'FILLED' : ($closed ? 'CANCELLED' : 'NEW'), 'qty' => $fmtQty(0.15), 'price' => $fmtPrice($px, $dec), 'opened' => $openedAt, 'filled' => $done ? $t : null];
        }
        if ($closed) {
            $tpHit = $p['reason'] === 'tp';
            $orders[] = ['type' => $tpHit ? 'PROFIT' : 'MARKET', 'side' => $closeSide, 'status' => 'FILLED', 'qty' => $fmtQty(1.0), 'price' => $p['exit'], 'opened' => $tpHit ? $openedAt : $closedAt, 'filled' => $closedAt];
        } else {
            if (($p['status'] ?? null) === 'waped') {
                $orders[] = ['type' => 'CANCEL-MARKET', 'side' => $closeSide, 'status' => 'CANCELLED', 'qty' => $fmtQty(0.15), 'price' => $fmtPrice($openN * (1 + ($long ? -1 : 1) * 0.05), $dec), 'opened' => $openedAt + 90 * 60, 'filled' => null];
            }
            $orders[] = ['type' => 'PROFIT', 'side' => $closeSide, 'status' => 'NEW', 'qty' => $fmtQty(1.0), 'price' => $p['tp'], 'opened' => $openedAt, 'filled' => null];
        }

        return [
            'closed' => $closed,
            'reason' => $p['reason'] ?? null,
            'exch' => ['Binance Futures', 'Bybit', 'OKX'][$seed % 3],
            'account' => ['Kraite-Main', 'Kraite-Alpha', 'Hedge-01', 'Scout-02'][($seed >> 3) % 4],
            'openedAt' => $openedAt,
            'closedAt' => $closedAt,
            'duration' => $fmtAge($durH),
            'leverage' => $p['lev'],
            'margin' => $margin,
            'qty' => $p['size'],
            'total' => $total,
            'openPrice' => $openStr,
            'markPrice' => $closed ? $p['exit'] : $p['mark'],
            'pnl' => $p['pnl'],
            'tpPct' => $tpPct,
            'slPct' => $slPct,
            'firstProfit' => $firstProfit,
            'tf' => $tf,
            'orders' => $orders,
        ];
    };

    // ---- aggregate summary strip ----
    $longCount = count(array_filter($positions, fn ($p) => $p['side'] === 'long'));
    $shortCount = count($positions) - $longCount;
    $exposure = array_sum(array_column($positions, 'notional'));
    $marginUsed = array_sum(array_column($positions, 'margin'));
    $unrealized = array_sum(array_column($positions, 'pnl'));
    $aggRoe = ($unrealized / $marginUsed) * 100;
    $aggCells = [
        ['label' => 'Open positions', 'value' => (string) count($positions), 'sub' => $longCount . 'L · ' . $shortCount . 'S', 'tone' => null],
        ['label' => 'Total exposure', 'value' => $usd0($exposure), 'sub' => 'NOTIONAL', 'tone' => null],
        ['label' => 'Margin used', 'value' => $usd0($marginUsed), 'sub' => round(($marginUsed / $exposure) * 100) . '% OF NOTIONAL', 'tone' => null],
        ['label' => 'Unrealized P&L', 'value' => $usdSigned($unrealized), 'sub' => $pctSigned($aggRoe) . ' ROE', 'tone' => $unrealized >= 0 ? 'up' : 'down'],
        ['label' => 'Capacity', 'value' => count($positions) . ' / 12', 'sub' => 'MAX 6 / DIR', 'tone' => null],
    ];

    // SSR order matches the Alpine defaults (open: notional desc, closed: pnl
    // desc) so first paint and first update() agree.
    usort($positions, fn ($a, $b) => $b['notional'] <=> $a['notional']);
    usort($closed, fn ($a, $b) => $b['pnl'] <=> $a['pnl']);
    $closedPer = 6;

    $downAccount = ['ex' => 'OKX', 'tag' => 'arb', 'note' => 'last seen 4m ago'];

    // shared row cell class strings (color applied per cell to avoid conflicts)
    $tdNum = 'py-[12px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right';
    $tdNumClosed = 'py-[11px] px-3 border-b border-line-soft whitespace-nowrap tabular-nums font-mono text-[12.5px] text-right';
@endphp

<x-app-layout active="positions" :title="'Kraite — Positions'" :showBanner="true" :downAccount="$downAccount">

    <script>
        // Shared controller for the sortable / filterable / pageable position
        // tables. Rows are server-rendered <tbody data-row> blocks (main row +
        // expandable detail row); sorting reorders them in the DOM, filtering
        // and pagination toggle their visibility.
        window.posTable = (cfg) => ({
            filter: 'ALL',
            sortKey: cfg.sortKey,
            sortDir: 'desc',
            per: cfg.per || 0,
            page: 0,
            pageCount: 1,
            count: 0,
            open: null,
            init() { this.update(); },
            setFilter(f) { this.filter = f; this.page = 0; this.open = null; this.update(); },
            setSort(key) {
                this.sortDir = this.sortKey === key ? (this.sortDir === 'asc' ? 'desc' : 'asc') : 'desc';
                this.sortKey = key;
                this.update();
            },
            setPage(p) { this.page = p; this.open = null; this.update(); },
            toggle(id) { this.open = this.open === id ? null : id; },
            update() {
                const table = this.$refs.table;
                const rows = Array.from(table.querySelectorAll('tbody[data-row]'));
                const val = (r) => {
                    const raw = r.getAttribute('data-s-' + this.sortKey) ?? '';
                    const n = parseFloat(raw);
                    return isNaN(n) ? raw : n;
                };
                rows.sort((a, b) => {
                    const va = val(a), vb = val(b);
                    const c = (typeof va === 'string' || typeof vb === 'string')
                        ? String(va).localeCompare(String(vb))
                        : va - vb;
                    return this.sortDir === 'asc' ? c : -c;
                });
                rows.forEach((r) => table.appendChild(r));
                const visible = rows.filter((r) => this.filter === 'ALL' || r.dataset.side === this.filter);
                this.count = visible.length;
                if (this.per) {
                    this.pageCount = Math.max(1, Math.ceil(visible.length / this.per));
                    if (this.page > this.pageCount - 1) this.page = this.pageCount - 1;
                }
                const from = this.per ? this.page * this.per : 0;
                const to = this.per ? from + this.per : visible.length;
                rows.forEach((r) => { r.style.display = 'none'; });
                visible.forEach((r, i) => { if (i >= from && i < to) r.style.display = ''; });
            },
        });
    </script>

    {{-- ===================== PAGE HEADER ===================== --}}
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-layers class="w-[13px] h-[13px]" stroke-width="1.75"/>PORTFOLIO
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Positions</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">{{ $noPositions ? 'Full lifecycle — no positions opened or closed yet on this account.' : 'Full lifecycle — open positions, realized history, and per-market detail.' }}</div>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
            {{-- Regime pill --}}
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
        {{-- first-run empty state: this account has never traded --}}
        <div class="flex flex-col items-center justify-center text-center py-[110px] px-5 border border-dashed border-line rounded-surface bg-surface">
            <div class="w-14 h-14 rounded-control border border-line flex items-center justify-center text-fg-mute mb-5">
                <x-feathericon-layers class="w-[26px] h-[26px]" stroke-width="1.75"/>
            </div>
            <h4 class="font-sans font-semibold text-[22px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-2">No positions yet</h4>
            <p class="text-[14px] text-fg-3 max-w-[460px] leading-[1.5]">This account hasn't opened or closed a single position. The moment the engine takes its first trade, open positions and realized history will populate here.</p>
            <span class="mt-5 inline-flex items-center gap-[7px] font-mono text-[10.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">
                <span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>Engine running · scanning for entries
            </span>
        </div>
    @else
    {{-- ===================== AGGREGATE SUMMARY STRIP ===================== --}}
    <div class="card flex items-stretch mb-7 max-[900px]:flex-wrap">
        @foreach($aggCells as $i => $c)
            <div class="flex-1 min-w-[150px] py-[15px] px-5 flex flex-col gap-1.5 {{ $i ? 'border-l border-line-soft max-[900px]:border-l-0' : '' }}{{ $i >= 3 ? ' max-[900px]:border-t max-[900px]:border-line-soft' : '' }}">
                <span class="font-mono text-[9.5px] font-medium tracking-[0.1em] uppercase text-fg-mute">{{ $c['label'] }}</span>
                <span class="font-mono text-[22px] font-semibold leading-none tabular-nums tracking-[-0.02em] {{ $c['tone'] === 'up' ? 'text-pnlup' : ($c['tone'] === 'down' ? 'text-pnldown' : 'text-fg-1') }}">{{ $c['value'] }}</span>
                <span class="font-mono text-[9.5px] tracking-[0.06em] text-fg-mute">{{ $c['sub'] }}</span>
            </div>
        @endforeach
    </div>

    {{-- ===================== OPEN POSITIONS ===================== --}}
    <section class="mb-8" x-data="posTable({ sortKey: 'notional' })">
        <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
            <div>
                <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px]">
                    <x-feathericon-layers class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Open positions
                </div>
                <div class="text-[12.5px] text-fg-3 mt-1" x-text="count + ' managed across the lifecycle · click any row for detail'">{{ count($positions) }} managed across the lifecycle · click any row for detail</div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
                @include('partials.segmented', ['options' => ['ALL', 'LONG', 'SHORT']])
                <button type="button" class="inline-flex items-center gap-[9px] h-[34px] border border-line rounded-control bg-surface px-3 cursor-pointer text-[12.5px] text-fg-2 max-w-[280px] transition-colors duration-fast ease-out hover:border-line-strong max-[640px]:max-w-none max-[640px]:flex-1">
                    <span class="w-[7px] h-[7px] rounded-chip bg-green-500 flex-shrink-0"></span>
                    <span class="whitespace-nowrap overflow-hidden text-ellipsis">All accounts</span>
                    <x-feathericon-chevron-down class="w-[14px] h-[14px] text-fg-mute" stroke-width="1.75"/>
                </button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[920px]" x-ref="table">
                    <thead>
                        <tr>
                            @include('partials.sort-th', ['id' => 'sym',      'label' => 'Market',   'w' => '200px'])
                            @include('partials.sort-th', ['id' => 'side',     'label' => 'Side',     'w' => null])
                            @include('partials.sort-th', ['id' => 'entry',    'label' => 'Entry',    'w' => null])
                            @include('partials.sort-th', ['id' => 'mark',     'label' => 'Mark',     'w' => null])
                            @include('partials.sort-th', ['id' => 'liq',      'label' => 'Liq.',     'w' => null])
                            @include('partials.sort-th', ['id' => 'notional', 'label' => 'Notional', 'w' => null])
                            @include('partials.sort-th', ['id' => 'margin',   'label' => 'Margin',   'w' => null])
                            @include('partials.sort-th', ['id' => 'pnl',      'label' => 'P&L',      'w' => null])
                            @include('partials.sort-th', ['id' => 'roe',      'label' => 'ROE',      'w' => null])
                            @include('partials.sort-th', ['id' => 'age',      'label' => 'Age',      'w' => null])
                            <th class="w-9 bg-accent"></th>
                        </tr>
                    </thead>
                    @foreach($positions as $p)
                        @php $d = $buildDetail($p); @endphp
                        <tbody data-row
                               data-side="{{ strtoupper($p['side']) }}"
                               data-s-sym="{{ $p['sym'] }}"
                               data-s-side="{{ $p['side'] === 'long' ? 1 : 0 }}"
                               data-s-entry="{{ $num($p['open']) }}"
                               data-s-mark="{{ $num($p['mark']) }}"
                               data-s-liq="{{ $num($p['liq']) }}"
                               data-s-notional="{{ $p['notional'] }}"
                               data-s-margin="{{ $p['margin'] }}"
                               data-s-pnl="{{ $p['pnl'] }}"
                               data-s-roe="{{ $p['roe'] }}"
                               data-s-age="{{ $p['ageH'] }}">
                            <tr @click="toggle('{{ $p['sym'] }}')"
                                :class="open === '{{ $p['sym'] }}' ? 'bg-hover' : ''"
                                class="cursor-pointer transition-colors duration-fast ease-out hover:bg-hover">
                                <td class="py-[12px] pl-5 pr-3 border-b border-line-soft">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <span class="w-[26px] h-[26px] rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden">
                                            <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/{{ $p['cmcId'] }}.png" alt="{{ $p['sym'] }}" class="block w-full h-full object-cover"/>
                                        </span>
                                        <div class="flex flex-col leading-[1.2] min-w-0">
                                            <span class="font-sans font-bold text-[13px] text-fg-1 tracking-[-0.01em] whitespace-nowrap">{{ $p['market'] }}</span>
                                            <span class="text-[11px] text-fg-mute whitespace-nowrap overflow-hidden text-ellipsis">{{ $p['name'] }}</span>
                                        </div>
                                        @if(($p['status'] ?? null) === 'opening')
                                            <span class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase text-fg-3 bg-surface-3 rounded-chip py-0.5 px-1.5">OPENING</span>
                                        @endif
                                        @if(($p['status'] ?? null) === 'waped')
                                            <span class="font-mono text-[8.5px] font-bold tracking-[0.08em] uppercase text-warn rounded-chip py-0.5 px-1.5" style="background: color-mix(in srgb, var(--warn) 16%, transparent);">WAP'D</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-[12px] px-3 border-b border-line-soft text-left">@include('partials.side-tag', ['side' => $p['side'], 'lev' => $p['lev']])</td>
                                <td class="{{ $tdNum }} text-fg-1">{{ $p['open'] }}</td>
                                <td class="{{ $tdNum }} text-fg-1">{{ $p['mark'] }}</td>
                                <td class="{{ $tdNum }} text-warn">{{ $p['liq'] }}</td>
                                <td class="{{ $tdNum }} text-fg-1">{{ $usd0($p['notional']) }}</td>
                                <td class="{{ $tdNum }} text-fg-3">{{ $usd0($p['margin']) }}</td>
                                <td class="{{ $tdNum }} font-semibold {{ $p['pnl'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $usdSigned($p['pnl']) }}</td>
                                <td class="{{ $tdNum }} font-semibold {{ $p['roe'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $pctSigned($p['roe']) }}</td>
                                <td class="{{ $tdNum }} text-fg-3">{{ $fmtAge($p['ageH']) }}</td>
                                <td class="py-[12px] pr-5 pl-1 border-b border-line-soft text-right">
                                    <span class="inline-flex text-fg-mute transition-transform duration-fast ease-out"
                                          :style="open === '{{ $p['sym'] }}' ? 'transform: rotate(180deg)' : ''">
                                        <x-feathericon-chevron-down class="w-4 h-4" stroke-width="1.75"/>
                                    </span>
                                </td>
                            </tr>
                            <tr :aria-hidden="open !== '{{ $p['sym'] }}'">
                                <td colspan="11" class="p-0 border-0">
                                    <div x-show="open === '{{ $p['sym'] }}'" x-collapse.duration.360ms x-cloak>
                                        @include('partials.position-detail', ['p' => $p, 'd' => $d])
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
            <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                <span class="font-mono tabular-nums text-[11px] text-fg-mute" x-text="count + ' OPEN · MAX 6 PER DIRECTION'">{{ count($positions) }} OPEN · MAX 6 PER DIRECTION</span>
                <span class="font-mono text-[11px] text-fg-mute tracking-[0.04em] inline-flex items-center gap-[7px]"><span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>LIVE · SYNC 3s</span>
            </div>
        </div>
    </section>

    {{-- Section separator between open and history --}}
    <div class="flex items-center gap-4 my-7" role="separator" aria-label="History">
        <span class="h-px flex-1 bg-line"></span>
        <span class="font-mono text-[10px] font-medium tracking-[0.14em] uppercase text-fg-mute flex items-center gap-[7px] whitespace-nowrap">
            <x-feathericon-clock class="w-[13px] h-[13px]" stroke-width="1.75"/>History
        </span>
        <span class="h-px flex-1 bg-line"></span>
    </div>

    {{-- ===================== CLOSED POSITIONS ===================== --}}
    <section x-data="posTable({ sortKey: 'pnl', per: {{ $closedPer }} })">
        <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
            <div>
                <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px]">
                    <x-feathericon-clock class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Closed positions
                </div>
                <div class="text-[12.5px] text-fg-3 mt-1">Realized over the last 48 hours · click any row for detail</div>
            </div>
            @include('partials.segmented', ['options' => ['ALL', 'LONG', 'SHORT']])
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse min-w-[860px]" x-ref="table">
                    <thead>
                        <tr>
                            @include('partials.sort-th', ['id' => 'sym',    'label' => 'Market',       'w' => '200px'])
                            @include('partials.sort-th', ['id' => 'side',   'label' => 'Side',         'w' => null])
                            @include('partials.sort-th', ['id' => 'entry',  'label' => 'Entry',        'w' => null])
                            @include('partials.sort-th', ['id' => 'exit',   'label' => 'Exit',         'w' => null])
                            @include('partials.sort-th', ['id' => 'dur',    'label' => 'Held',         'w' => null])
                            @include('partials.sort-th', ['id' => 'pnl',    'label' => 'Realized P&L', 'w' => null])
                            @include('partials.sort-th', ['id' => 'roe',    'label' => 'ROE',          'w' => null])
                            @include('partials.sort-th', ['id' => 'reason', 'label' => 'Closed',       'w' => null])
                            <th class="w-9 bg-accent"></th>
                        </tr>
                    </thead>
                    @foreach($closed as $i => $p)
                        @php
                            $d = $buildDetail($p);
                            $rm = $reasonMeta[$p['reason']] ?? $reasonMeta['manual'];
                        @endphp
                        <tbody data-row
                               data-side="{{ strtoupper($p['side']) }}"
                               data-s-sym="{{ $p['sym'] }}"
                               data-s-side="{{ $p['side'] === 'long' ? 1 : 0 }}"
                               data-s-entry="{{ $num($p['entry']) }}"
                               data-s-exit="{{ $num($p['exit']) }}"
                               data-s-pnl="{{ $p['pnl'] }}"
                               data-s-roe="{{ $p['roe'] }}"
                               data-s-dur="{{ $p['durH'] }}"
                               data-s-reason="{{ $p['reason'] }}"
                               @if($i >= $closedPer) style="display: none;" @endif>
                            <tr @click="toggle('{{ $p['sym'] }}')"
                                :class="open === '{{ $p['sym'] }}' ? 'bg-hover' : ''"
                                class="cursor-pointer transition-colors duration-fast ease-out hover:bg-hover">
                                <td class="py-[11px] pl-5 pr-3 border-b border-line-soft">
                                    <div class="flex items-center gap-2.5">
                                        <span class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden">
                                            <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/{{ $p['cmcId'] }}.png" alt="{{ $p['sym'] }}" class="block w-full h-full object-cover"/>
                                        </span>
                                        <div class="flex flex-col leading-[1.2]">
                                            <span class="font-sans font-bold text-[12.5px] text-fg-1 tracking-[-0.01em] whitespace-nowrap">{{ $p['sym'] }}-PERP</span>
                                            <span class="text-[10.5px] text-fg-mute">closed {{ $p['closedAgo'] }} ago</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-[11px] px-3 border-b border-line-soft text-left">@include('partials.side-tag', ['side' => $p['side'], 'lev' => $p['lev']])</td>
                                <td class="{{ $tdNumClosed }} text-fg-3">{{ $p['entry'] }}</td>
                                <td class="{{ $tdNumClosed }} text-fg-1">{{ $p['exit'] }}</td>
                                <td class="{{ $tdNumClosed }} text-fg-3">{{ $fmtAge($p['durH']) }}</td>
                                <td class="{{ $tdNumClosed }} font-semibold {{ $p['pnl'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $usdSigned($p['pnl']) }}</td>
                                <td class="{{ $tdNumClosed }} font-semibold {{ $p['roe'] >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ $pctSigned($p['roe']) }}</td>
                                <td class="py-[11px] px-3 border-b border-line-soft text-left">
                                    <span class="inline-flex items-center gap-[6px] font-mono text-[9.5px] font-bold tracking-[0.08em] uppercase rounded-chip py-[3px] px-2 whitespace-nowrap"
                                          style="color: {{ $rm['color'] }}; background: color-mix(in srgb, {{ $rm['color'] }} 13%, transparent);">
                                        <span class="w-1.5 h-1.5 rounded-chip" style="background: {{ $rm['color'] }};"></span>{{ $rm['label'] }}
                                    </span>
                                </td>
                                <td class="py-[11px] pr-5 pl-1 border-b border-line-soft text-right">
                                    <span class="inline-flex text-fg-mute transition-transform duration-fast ease-out"
                                          :style="open === '{{ $p['sym'] }}' ? 'transform: rotate(180deg)' : ''">
                                        <x-feathericon-chevron-down class="w-4 h-4" stroke-width="1.75"/>
                                    </span>
                                </td>
                            </tr>
                            <tr :aria-hidden="open !== '{{ $p['sym'] }}'">
                                <td colspan="9" class="p-0 border-0">
                                    <div x-show="open === '{{ $p['sym'] }}'" x-collapse.duration.360ms x-cloak>
                                        @include('partials.position-detail', ['p' => $p, 'd' => $d])
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
            <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                <span class="font-mono tabular-nums text-[11px] text-fg-mute" x-text="count + ' CLOSED · 48H WINDOW'">{{ count($closed) }} CLOSED · 48H WINDOW</span>
                {{-- Numbered pager --}}
                <div class="flex items-center gap-1.5" x-show="pageCount > 1">
                    <button type="button" :disabled="page === 0" @click="setPage(Math.max(0, page - 1))" aria-label="Previous page"
                            class="appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2">
                        <x-feathericon-chevron-left class="w-3.5 h-3.5" stroke-width="1.75"/>
                    </button>
                    <template x-for="i in pageCount" :key="i">
                        <button type="button" @click="setPage(i - 1)" :aria-current="page === i - 1" :aria-label="'Page ' + i"
                                :class="page === i - 1 ? 'border-accent bg-accent text-accent-on' : 'border-line bg-surface text-fg-3 hover:text-fg-1 hover:border-line-strong'"
                                class="appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control font-mono text-[11px] font-semibold tabular-nums border transition-colors duration-fast ease-out"
                                x-text="i"></button>
                    </template>
                    <button type="button" :disabled="page === pageCount - 1" @click="setPage(Math.min(pageCount - 1, page + 1))" aria-label="Next page"
                            class="appearance-none cursor-pointer w-[26px] h-[26px] inline-flex items-center justify-center rounded-control border border-line bg-surface text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 disabled:opacity-35 disabled:cursor-not-allowed disabled:hover:border-line disabled:hover:text-fg-2">
                        <x-feathericon-chevron-right class="w-3.5 h-3.5" stroke-width="1.75"/>
                    </button>
                </div>
                <a href="#" class="text-[12px] font-sans font-semibold no-underline text-accent inline-flex items-center gap-[5px] hover:text-accent-hover">Full history <x-feathericon-arrow-right class="w-[13px] h-[13px]" stroke-width="1.75"/></a>
            </div>
        </div>
    </section>
    @endif

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
