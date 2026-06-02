@php
    // ============================================================
    // MOCK DATA — first design-fidelity port. Wire to backend later.
    // ============================================================
    $regime = 'ELEVATED';
    $regimeScores = ['CALM' => 0.31, 'WATCH' => 0.42, 'ELEVATED' => 0.63, 'CASCADE' => 0.82, 'BLACK SWAN' => 0.94];
    $score = $regimeScores[$regime] ?? 0.42;
    $suspended = in_array($regime, ['ELEVATED', 'CASCADE', 'BLACK SWAN'], true);
    $untilStr = gmdate('M j, H:i', time() + 24 * 3600) . ' UTC';

    $regimes = [
        'CALM'        => ['color' => 'var(--bsi-calm)'],
        'WATCH'       => ['color' => 'var(--bsi-watch)'],
        'ELEVATED'    => ['color' => 'var(--bsi-cascade)'],
        'CASCADE'     => ['color' => 'var(--bsi-cascade)'],
        'BLACK SWAN'  => ['color' => 'var(--bsi-blackswan)'],
    ];
    $r = $regimes[$regime] ?? $regimes['CALM'];

    $kpis = [
        ['key' => 'pv',  'label' => 'Portfolio value', 'icon' => 'credit-card', 'value' => '$284,910.42', 'delta' => 1.84,  'spark' => [262,264,261,268,270,267,272,275,273,279,278,281,280,285], 'up' => true],
        ['key' => 'pnl', 'label' => "P&L — today",     'icon' => 'zap',         'value' => '+$5,142.18',  'delta' => 1.84,  'spark' => [0,0.4,0.2,0.9,0.7,1.3,1.1,1.0,1.6,1.4,1.9,1.7,2.1,1.84], 'up' => true],
        ['key' => 'p30', 'label' => 'P&L — 30 day',    'icon' => 'arrow-up-right', 'value' => '+$23,418.06', 'delta' => 8.96, 'spark' => [0,2,1.5,3,4,3.6,5,4.7,6,5.8,7,6.6,8,8.96], 'up' => true],
        ['key' => 'op',  'label' => 'Open positions',  'icon' => 'layers',      'value' => '10', 'delta' => null, 'spark' => null, 'up' => true],
    ];

    $positions = [
        ['sym' => 'BTC',  'name' => 'Bitcoin',   'cmcId' => 1,     'color' => '#f7931a', 'osc' => ['up','up','down','up'],    'side' => 'long',  'lev' => '3×', 'eta' => '2h from now',  'path' => 4.3, 'limit' => 16.7, 'filled' => '1 / 4', 'fillN' => 1, 'trackTp' => 26, 'trackPx' => 35, 'open' => '67,420.00', 'tp' => '70,250.00', 'next' => '66,310.00'],
        ['sym' => 'ETH',  'name' => 'Ethereum',  'cmcId' => 1027,  'color' => '#627eea', 'osc' => ['idle','idle','idle','idle'], 'status' => 'opening', 'side' => 'long',  'lev' => '3×', 'eta' => '1h from now',  'path' => 3.4, 'limit' => 13.1, 'filled' => '0 / 4', 'fillN' => 0, 'trackTp' => 5,  'trackPx' => 13, 'open' => '3,512.00',  'tp' => '3,624.00',  'next' => '3,448.00'],
        ['sym' => 'SOL',  'name' => 'Solana',    'cmcId' => 5426,  'color' => '#9945ff', 'osc' => ['down','up','up','up'],    'status' => 'waped',   'side' => 'short', 'lev' => '2×', 'eta' => '40m from now', 'path' => 2.2, 'limit' => 8.6,  'filled' => '2 / 4', 'fillN' => 2, 'trackTp' => 44, 'trackPx' => 54, 'open' => '168.40',    'tp' => '162.10',    'next' => '171.20'],
        ['sym' => 'ARB',  'name' => 'Arbitrum',  'cmcId' => 11841, 'color' => '#28a0f0', 'osc' => ['down','down','up','down'], 'side' => 'long',  'lev' => '4×', 'eta' => '3h from now',  'path' => 1.9, 'limit' => 7.2,  'filled' => '0 / 4', 'fillN' => 0, 'trackTp' => 5,  'trackPx' => 20, 'open' => '0.8920',    'tp' => '0.9180',    'next' => '0.8710'],
        ['sym' => 'AVAX', 'name' => 'Avalanche', 'cmcId' => 5805,  'color' => '#e84142', 'osc' => ['up','down','down','up'],  'side' => 'short', 'lev' => '2×', 'eta' => '1h from now',  'path' => 1.5, 'limit' => 6.0,  'filled' => '1 / 4', 'fillN' => 1, 'trackTp' => 26, 'trackPx' => 37, 'open' => '38.20',     'tp' => '36.40',     'next' => '39.05'],
        ['sym' => 'DOGE', 'name' => 'Dogecoin',  'cmcId' => 74,    'color' => '#c2a633', 'osc' => ['up','up','down','up'],    'side' => 'long',  'lev' => '3×', 'eta' => '5h from now',  'path' => 1.2, 'limit' => 4.8,  'filled' => '0 / 4', 'fillN' => 0, 'trackTp' => 5,  'trackPx' => 16, 'open' => '0.16200',   'tp' => '0.16850',   'next' => '0.15900'],
        ['sym' => 'LINK', 'name' => 'Chainlink', 'cmcId' => 1975,  'color' => '#2a5ada', 'osc' => ['up','down','up','up'],    'side' => 'long',  'lev' => '3×', 'eta' => '2h from now',  'path' => 2.7, 'limit' => 9.4,  'filled' => '1 / 4', 'fillN' => 1, 'trackTp' => 26, 'trackPx' => 33, 'open' => '18.92',     'tp' => '20.10',     'next' => '18.20'],
        ['sym' => 'OP',   'name' => 'Optimism',  'cmcId' => 11840, 'color' => '#ff0420', 'osc' => ['down','up','up','down'],  'side' => 'long',  'lev' => '4×', 'eta' => '4h from now',  'path' => 1.6, 'limit' => 6.4,  'filled' => '0 / 4', 'fillN' => 0, 'trackTp' => 5,  'trackPx' => 19, 'open' => '2.480',     'tp' => '2.610',     'next' => '2.395'],
        ['sym' => 'XRP',  'name' => 'XRP',       'cmcId' => 52,    'color' => '#23292f', 'osc' => ['down','down','up','down'], 'side' => 'short', 'lev' => '2×', 'eta' => '1h from now',  'path' => 2.0, 'limit' => 7.8,  'filled' => '2 / 4', 'fillN' => 2, 'trackTp' => 44, 'trackPx' => 53, 'open' => '0.5420',    'tp' => '0.5180',    'next' => '0.5560'],
        ['sym' => 'INJ',  'name' => 'Injective', 'cmcId' => 7226,  'color' => '#00a3ff', 'osc' => ['up','down','down','up'],  'side' => 'short', 'lev' => '2×', 'eta' => '3h from now',  'path' => 1.1, 'limit' => 5.2,  'filled' => '0 / 4', 'fillN' => 0, 'trackTp' => 5,  'trackPx' => 15, 'open' => '24.80',     'tp' => '23.40',     'next' => '25.50'],
    ];

    // Pagination preparation. PER = 6 positions per page. Chunked per filter
    // so each filter (ALL/LONG/SHORT) gets its own page set; switching filter
    // resets Alpine's page state back to 0.
    $per = 6;
    $longs  = array_values(array_filter($positions, fn ($p) => $p['side'] === 'long'));
    $shorts = array_values(array_filter($positions, fn ($p) => $p['side'] === 'short'));
    $chunksAll   = array_chunk($positions, $per);
    $chunksLong  = array_chunk($longs,  $per);
    $chunksShort = array_chunk($shorts, $per);
    $countsByFilter = ['ALL' => count($chunksAll), 'LONG' => count($chunksLong), 'SHORT' => count($chunksShort)];

    $servers = [
        ['id' => 'kr-fra-01', 'region' => 'fra', 'state' => 'ok',   'latency' => '11ms'],
        ['id' => 'kr-fra-02', 'region' => 'fra', 'state' => 'ok',   'latency' => '12ms'],
        ['id' => 'kr-ldn-01', 'region' => 'ldn', 'state' => 'ok',   'latency' => '19ms'],
        ['id' => 'kr-nyc-01', 'region' => 'nyc', 'state' => 'ok',   'latency' => '38ms'],
        ['id' => 'kr-sgp-01', 'region' => 'sgp', 'state' => 'down', 'latency' => '—'],
        ['id' => 'kr-sgp-02', 'region' => 'sgp', 'state' => 'ok',   'latency' => '54ms'],
    ];
    $downServers = array_filter($servers, fn($s) => $s['state'] === 'down');
    $okServers = array_filter($servers, fn($s) => $s['state'] === 'ok');
    $downCount = count($downServers);

    $activityCats = [
        ['id' => 'all',     'label' => 'All activity'],
        ['id' => 'trading', 'label' => 'Trading'],
        ['id' => 'risk',    'label' => 'Risk & regime'],
        ['id' => 'funding', 'label' => 'Funding'],
        ['id' => 'system',  'label' => 'System & alerts'],
    ];

    // activity items rendered as inline HTML so spans + classes preserve faithfully
    $activity = [
        ['kind' => 'OPEN',    'cat' => 'trading', 'dot' => 'var(--pnl-up-fg)', 'time' => '2m',  'html' => 'Opened <span class="align-middle inline-flex items-center gap-[5px] font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-px px-[5px] bg-pnlup-bg text-pnlup before:content-[\'\'] before:w-1.5 before:h-1.5 before:rounded-chip before:bg-current before:opacity-90">LONG</span> <span class="font-mono font-semibold text-fg-1">BTC-PERP</span> <span class="font-mono tabular-nums text-fg-1">0.850</span> @ <span class="font-mono tabular-nums text-fg-1">67,420.00</span>'],
        ['kind' => 'REDUCE',  'cat' => 'trading', 'dot' => 'var(--fg-mute)',   'time' => '14m', 'html' => 'Reduced <span class="font-mono font-semibold text-fg-1">SOL-PERP</span> short by <span class="font-mono tabular-nums text-fg-1">40.0</span> @ <span class="font-mono tabular-nums text-fg-1">162.10</span> · realized <span class="font-mono tabular-nums text-pnlup">+$252.00</span>'],
        ['kind' => 'REGIME',  'cat' => 'risk',    'dot' => 'var(--bsi-watch)', 'time' => '38m', 'html' => 'BSCS regime escalated <span class="font-mono tabular-nums text-fg-1">CALM → WATCH</span> at score <span class="font-mono tabular-nums text-fg-1">0.42</span>'],
        ['kind' => 'ALERT',   'cat' => 'system',  'dot' => 'var(--danger)',    'time' => '52m', 'html' => 'Exchange account <span class="font-mono font-semibold text-fg-1">OKX</span> (arb) lost connectivity — bot management paused'],
        ['kind' => 'CLOSE',   'cat' => 'trading', 'dot' => 'var(--pnl-up-fg)', 'time' => '1h',  'html' => 'Closed <span class="font-mono font-semibold text-fg-1">LINK-PERP</span> long <span class="font-mono tabular-nums text-pnlup">+$312.40</span> @ <span class="font-mono tabular-nums text-fg-1">18.92</span>'],
        ['kind' => 'SIZING',  'cat' => 'risk',    'dot' => 'var(--bsi-watch)', 'time' => '1h',  'html' => 'Position sizing tightened — max notional <span class="font-mono tabular-nums text-fg-1">4.0× → 3.0×</span>'],
        ['kind' => 'FUNDING', 'cat' => 'funding', 'dot' => 'var(--fg-mute)',   'time' => '2h',  'html' => 'Funding collected <span class="font-mono tabular-nums text-pnlup">+$84.20</span> across <span class="font-mono tabular-nums text-fg-1">4</span> positions'],
        ['kind' => 'LOGIN',   'cat' => 'system',  'dot' => 'var(--fg-mute)',   'time' => '2h',  'html' => 'New sign-in from <span class="font-mono tabular-nums text-fg-1">Frankfurt, DE</span> · session <span class="font-mono tabular-nums text-fg-1">a1f9…</span>'],
        ['kind' => 'FUNDING', 'cat' => 'funding', 'dot' => 'var(--fg-mute)',   'time' => '3h',  'html' => 'Funding paid <span class="font-mono tabular-nums text-pnldown">-$31.50</span> on <span class="font-mono font-semibold text-fg-1">SOL-PERP</span> short'],
        ['kind' => 'CLOSE',   'cat' => 'trading', 'dot' => 'var(--pnl-down-fg)','time' => '3h', 'html' => 'Closed <span class="font-mono font-semibold text-fg-1">APT-PERP</span> short <span class="font-mono tabular-nums text-pnldown">-$96.10</span> @ <span class="font-mono tabular-nums text-fg-1">9.14</span> · stop hit'],
        ['kind' => 'OPEN',    'cat' => 'trading', 'dot' => 'var(--pnl-up-fg)', 'time' => '4h',  'html' => 'Opened <span class="align-middle inline-flex items-center gap-[5px] font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-px px-[5px] bg-pnldown-bg text-pnldown before:content-[\'\'] before:w-1.5 before:h-1.5 before:rounded-chip before:bg-current before:opacity-90">SHORT</span> <span class="font-mono font-semibold text-fg-1">AVAX-PERP</span> <span class="font-mono tabular-nums text-fg-1">95.0</span> @ <span class="font-mono tabular-nums text-fg-1">38.20</span>'],
    ];

    $downAccount = ['ex' => 'OKX', 'tag' => 'arb', 'note' => 'last seen 4m ago'];

    // Sparkline SVG path helper
    $sparkPath = function (array $data, int $w = 84, int $h = 28) {
        $min = min($data); $max = max($data); $rng = ($max - $min) ?: 1;
        $n = count($data);
        $pts = [];
        foreach ($data as $i => $v) {
            $x = ($i / ($n - 1)) * $w;
            $y = $h - 3 - (($v - $min) / $rng) * ($h - 6);
            $pts[] = [$x, $y];
        }
        $line = '';
        foreach ($pts as $i => $p) {
            $line .= ($i ? 'L' : 'M') . number_format($p[0], 1) . ' ' . number_format($p[1], 1) . ' ';
        }
        $area = trim($line) . " L{$w} {$h} L0 {$h} Z";
        return ['line' => trim($line), 'area' => $area];
    };
@endphp

<x-app-layout active="dashboard" :title="'Kraite — Dashboard'" :showBanner="true" :downAccount="$downAccount">

    {{-- ===================== PAGE HEADER ===================== --}}
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-home class="w-[13px] h-[13px]" stroke-width="1.75"/>OVERVIEW
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Dashboard</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">
                Engine running autonomously · <span class="font-mono tabular-nums text-fg-2">10</span> open positions · last sync <span class="font-mono tabular-nums text-fg-2">3s</span> ago
            </div>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
            <div class="flex items-center gap-2.5 whitespace-nowrap">
                <img class="w-[26px] h-[26px] rounded-full flex-shrink-0" src="https://s2.coinmarketcap.com/static/img/coins/64x64/1.png" alt="BTC"/>
                <div class="flex flex-col leading-[1.15]">
                    <span class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase text-fg-mute">Bitcoin · USDT</span>
                    <span class="font-mono text-[15px] font-semibold text-fg-1 tabular-nums tracking-[-0.01em]">68,910.50</span>
                </div>
                <div class="flex gap-[3px] items-center">
                    @foreach(['up','up','down','up'] as $d)
                        <i class="block w-1.5 h-1.5 rounded-chip {{ $d === 'up' ? 'bg-pnlup' : 'bg-pnldown' }}"></i>
                    @endforeach
                </div>
            </div>
            <div class="w-px h-[22px] bg-line"></div>
            {{-- Regime pill --}}
            <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                  style="background: color-mix(in srgb, {{ $r['color'] }} 12%, transparent); border-color: color-mix(in srgb, {{ $r['color'] }} 38%, transparent); color: {{ $r['color'] }};">
                <span class="w-2 h-2 rounded-chip" style="background: {{ $r['color'] }};"></span>
                {{ $regime }}<span class="opacity-70 ml-0.5">{{ number_format($score, 2) }}</span>
            </span>
            <div class="w-px h-[22px] bg-line"></div>
            <button type="button" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/>Sync
            </button>
            <button type="button" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] border-transparent bg-accent text-accent-on hover:bg-accent-hover">
                <x-feathericon-activity class="w-[15px] h-[15px]" stroke-width="1.75"/>View projections
            </button>
        </div>
    </div>

    {{-- ===================== SUSPENDED BANNER (regime-conditional) ===================== --}}
    @if($suspended)
        <div class="flex items-center gap-3 py-[13px] px-4 mb-5 rounded-control bg-pnldown-bg text-pnldown border" style="border-color: color-mix(in srgb, var(--danger) 45%, transparent);">
            <span class="flex flex-shrink-0 animate-pulse-soft"><x-feathericon-alert-triangle class="w-[18px] h-[18px]" stroke-width="1.75"/></span>
            <span class="text-[13px] leading-[1.45] flex-1 min-w-0">
                <strong class="text-pnldown-strong font-bold">New position openings suspended for 24h</strong> — Black Swan regime is
                <span class="font-mono text-pnldown-strong font-semibold">{{ $regime }} {{ number_format($score, 2) }}</span>.
                Resumes <span class="font-mono text-pnldown-strong font-semibold">{{ $untilStr }}</span> if the regime clears. Existing positions are still managed.
            </span>
            <a href="#" class="flex-shrink-0 text-[12px] font-semibold no-underline text-pnldown whitespace-nowrap hover:text-pnldown-strong">View risk policy →</a>
        </div>
    @endif

    {{-- ===================== KPI TILES ===================== --}}
    <div class="grid grid-cols-4 gap-5 mb-6 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-2 max-[640px]:gap-3 max-[420px]:grid-cols-1">
        @foreach($kpis as $k)
            <div class="tile kpi-invert overflow-hidden bg-surface border border-line rounded-control py-[13px] px-[15px] flex flex-col gap-[9px] relative transition-colors duration-fast ease-out hover:border-line-strong">
                <div class="font-mono text-[10px] font-medium tracking-[0.11em] uppercase text-fg-3 flex items-center gap-[7px]">
                    <x-dynamic-component :component="'feathericon-' . $k['icon']" class="w-[12px] h-[12px]" stroke-width="1.75"/>{{ $k['label'] }}
                </div>
                @if($k['key'] === 'op')
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="font-mono font-semibold text-[24px] tracking-[-0.025em] text-fg-1 tabular-nums leading-none">{{ $k['value'] }}</span>
                        <div class="ml-auto flex flex-col gap-1 w-24 min-w-0 flex-shrink">
                            <div class="flex h-1.5 rounded-chip overflow-hidden gap-0.5">
                                <span class="rounded-chip" style="flex: 6; background: var(--pnl-up-fg);"></span>
                                <span class="rounded-chip" style="flex: 4; background: var(--pnl-down-fg);"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="font-mono text-[10px] font-semibold text-pnlup">6L</span>
                                <span class="font-mono text-[10px] font-semibold text-pnldown">4S</span>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="font-mono font-semibold text-[24px] tracking-[-0.025em] text-fg-1 tabular-nums leading-none">{{ $k['value'] }}</span>
                        @if($k['delta'] !== null)
                            @php $up = $k['delta'] >= 0; @endphp
                            <span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip {{ $up ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg' }}">
                                @if($up)
                                    <x-feathericon-arrow-up class="w-[11px] h-[11px]" stroke-width="2"/>+{{ number_format($k['delta'], 2) }}%
                                @else
                                    <x-feathericon-arrow-down class="w-[11px] h-[11px]" stroke-width="2"/>{{ number_format($k['delta'], 2) }}%
                                @endif
                            </span>
                        @endif
                        @if($k['spark'])
                            @php $sp = $sparkPath($k['spark'], 84, 28); $col = $k['up'] ? 'var(--pnl-up-fg)' : 'var(--pnl-down-fg)'; $gid = 'sp_' . $k['key']; @endphp
                            <div class="ml-auto w-[84px] min-w-0 flex-shrink">
                                <svg class="block w-full" viewBox="0 0 84 28" preserveAspectRatio="none" width="84" height="28">
                                    <defs><linearGradient id="{{ $gid }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="{{ $col }}" stop-opacity="0.18"/>
                                        <stop offset="100%" stop-color="{{ $col }}" stop-opacity="0"/>
                                    </linearGradient></defs>
                                    <path d="{{ $sp['area'] }}" fill="url(#{{ $gid }})"/>
                                    <path d="{{ $sp['line'] }}" fill="none" stroke="{{ $col }}" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                                </svg>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ===================== POSITIONS SECTION ===================== --}}
    <section class="mb-6"
             x-data="{
                filter: 'ALL',
                page: 0,
                prevPage: 0,
                per: {{ $per }},
                counts: @js($countsByFilter),
                totals: @js(['ALL' => count($positions), 'LONG' => count($longs), 'SHORT' => count($shorts)]),
                segHl: null,
                dotThumb: null,
                _settleTimer: null,
                setFilter(f) { this.filter = f; this.prevPage = 0; this.page = 0; this.$nextTick(() => { this.measureSeg(); this.animateDot(); }); },
                pageCount() { return Math.max(1, this.counts[this.filter] || 1); },
                safePage() { return Math.min(this.page, this.pageCount() - 1); },
                rangeLabel() {
                    const total = this.totals[this.filter] || 0;
                    if (total <= this.per) return total + ' positions managed across the lifecycle · no manual orders';
                    const from = this.safePage() * this.per + 1;
                    const to = Math.min(from + this.per - 1, total);
                    return total + ' positions · showing ' + from + '–' + to + ' · max ' + this.per + ' per page';
                },
                measureSeg() {
                    const el = this.$refs.seg?.querySelector('[data-seg-active]');
                    if (!el) { this.segHl = null; return; }
                    this.segHl = { left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth, height: el.offsetHeight };
                },
                animateDot() {
                    const wrap = this.$el.querySelector('[data-dots-active]');
                    if (!wrap) { this.dotThumb = null; return; }
                    const dots = wrap.querySelectorAll('.pcar__dot');
                    const cur = dots[this.safePage()];
                    const prev = dots[this.prevPage] || cur;
                    if (!cur) { this.dotThumb = null; return; }
                    if (this._settleTimer) { clearTimeout(this._settleTimer); this._settleTimer = null; }
                    if (prev !== cur) {
                        const lo = Math.min(prev.offsetLeft, cur.offsetLeft);
                        const hi = Math.max(prev.offsetLeft + prev.offsetWidth, cur.offsetLeft + cur.offsetWidth);
                        this.dotThumb = { left: lo, width: hi - lo };
                        this._settleTimer = setTimeout(() => {
                            this.dotThumb = { left: cur.offsetLeft, width: cur.offsetWidth };
                        }, 190);
                    } else {
                        this.dotThumb = { left: cur.offsetLeft, width: cur.offsetWidth };
                    }
                    this.prevPage = this.safePage();
                },
             }"
             x-init="
                $nextTick(() => { measureSeg(); animateDot(); });
                $watch('page', () => $nextTick(() => animateDot()));
                $watch('filter', () => $nextTick(() => animateDot()));
                window.addEventListener('resize', () => { measureSeg(); animateDot(); });
                if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { measureSeg(); animateDot(); });
             ">
        <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
            <div>
                <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                    <x-feathericon-layers class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Open positions
                </div>
                <div class="text-[12.5px] text-fg-3 mt-1 whitespace-nowrap" x-text="rangeLabel()"></div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
                {{-- Segmented control with sliding green pill --}}
                <div x-ref="seg" class="relative inline-flex items-center h-[34px] bg-surface-3 border border-line rounded-control px-[3px] gap-0.5">
                    <span aria-hidden="true"
                          x-show="segHl"
                          x-cloak
                          :style="segHl ? `left:${segHl.left}px;top:${segHl.top}px;width:${segHl.width}px;height:${segHl.height}px` : ''"
                          class="absolute z-0 bg-accent rounded-[7px] shadow-1 pointer-events-none transition-all duration-[420ms] ease-[cubic-bezier(0.16,1,0.3,1)]"></span>
                    @foreach(['ALL','LONG','SHORT'] as $opt)
                        <button type="button" @click="setFilter('{{ $opt }}')"
                                :data-seg-active="filter === '{{ $opt }}' ? '' : null"
                                :class="filter === '{{ $opt }}' ? 'text-accent-on' : 'text-fg-3 hover:text-fg-1'"
                                class="appearance-none bg-transparent border-0 rounded-[7px] h-[26px] inline-flex items-center px-3 font-mono text-[11px] font-semibold tracking-[0.04em] cursor-pointer relative z-[1] transition-colors duration-fast ease-out">{{ $opt }}</button>
                    @endforeach
                </div>
                {{-- Account dropdown (static for now) --}}
                <button type="button" class="inline-flex items-center gap-[9px] h-[34px] border border-line rounded-control bg-surface px-3 cursor-pointer text-[12.5px] text-fg-2 max-w-[280px] transition-colors duration-fast ease-out hover:border-line-strong max-[640px]:max-w-none max-[640px]:flex-1">
                    <span class="w-[7px] h-[7px] rounded-chip bg-green-500 flex-shrink-0"></span>
                    <span class="whitespace-nowrap overflow-hidden text-ellipsis">Karine Esnault · Binance</span>
                    <x-feathericon-chevron-down class="w-[14px] h-[14px] text-fg-mute" stroke-width="1.75"/>
                </button>
            </div>
        </div>

        @php
            // Render one sliding-page carousel per filter so the dot count
            // adapts to filter. Visibility is toggled by Alpine (x-show).
            $tracks = ['ALL' => $chunksAll, 'LONG' => $chunksLong, 'SHORT' => $chunksShort];
        @endphp
        @foreach($tracks as $filterKey => $chunks)
            <div x-show="filter === '{{ $filterKey }}'" x-cloak
                 x-data="{
                    chunkCount: {{ count($chunks) }},
                    drag: { active: false, startX: 0, base: 0, w: 0, moved: 0, pointerId: null },
                    onDown(e) {
                        if (this.chunkCount <= 1 || e.button === 1 || e.button === 2) return;
                        const v = this.$refs.view, t = this.$refs.track;
                        if (!v || !t) return;
                        const w = v.offsetWidth;
                        this.drag = { active: true, startX: e.clientX, base: -this.safePage() * w, w, moved: 0, pointerId: e.pointerId };
                        t.style.transition = 'none';
                        v.setPointerCapture?.(e.pointerId);
                    },
                    onMove(e) {
                        if (!this.drag.active) return;
                        const dx = e.clientX - this.drag.startX;
                        this.drag.moved = dx;
                        let pos = this.drag.base + dx;
                        const min = -(this.chunkCount - 1) * this.drag.w;
                        if (pos > 0) pos = pos * 0.35;
                        if (pos < min) pos = min + (pos - min) * 0.35;
                        this.$refs.track.style.transform = 'translateX(' + pos + 'px)';
                    },
                    onUp(e) {
                        if (!this.drag.active) return;
                        const moved = this.drag.moved, w = this.drag.w;
                        this.drag.active = false;
                        this.$refs.view?.releasePointerCapture?.(this.drag.pointerId);
                        let next = this.safePage();
                        if (moved < -w * 0.18) next = Math.min(this.chunkCount - 1, this.safePage() + 1);
                        else if (moved > w * 0.18) next = Math.max(0, this.safePage() - 1);
                        this.$refs.track.style.transition = '';
                        this.$refs.track.style.transform = '';
                        this.page = next;
                    },
                 }">
                <div x-ref="view"
                     class="overflow-hidden cursor-grab touch-pan-y active:cursor-grabbing"
                     @pointerdown="onDown($event)"
                     @pointermove="onMove($event)"
                     @pointerup="onUp($event)"
                     @pointercancel="onUp($event)">
                    <div x-ref="track"
                         class="flex transition-transform duration-[380ms] ease-out select-none"
                         :style="`transform: translateX(-${safePage() * 100}%)`">
                        @foreach($chunks as $chunk)
                            <div class="flex-[0_0_100%] min-w-0 [&_img]:pointer-events-none [&_img]:select-none">
                                <div class="grid grid-cols-3 gap-5 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-1">
                                    @foreach($chunk as $p)
                                        @include('partials.position-tile', ['p' => $p])
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @if(count($chunks) > 1)
                    <div :data-dots-active="filter === '{{ $filterKey }}' ? '' : null"
                         class="relative flex justify-center items-center gap-[7px] mt-5">
                        @foreach($chunks as $i => $chunk)
                            <button type="button" @click="page = {{ $i }}"
                                    :data-dot-active="safePage() === {{ $i }} ? '' : null"
                                    class="pcar__dot appearance-none cursor-pointer p-0 border-0 w-[7px] h-[7px] rounded-chip bg-line-strong transition-colors duration-fast ease-out hover:bg-fg-mute"
                                    aria-label="Page {{ $i + 1 }}"></button>
                        @endforeach
                        <span aria-hidden="true"
                              x-show="filter === '{{ $filterKey }}' && dotThumb"
                              x-cloak
                              :style="dotThumb ? `left:${dotThumb.left}px;width:${dotThumb.width}px` : ''"
                              class="absolute top-1/2 h-[7px] w-[7px] -translate-y-1/2 rounded-chip bg-accent z-[2] pointer-events-none transition-[left,width] duration-base ease-out"></span>
                    </div>
                @endif
            </div>
        @endforeach

    </section>

    {{-- ===================== BOTTOM GRID: ACTIVITY + CONNECTIVITY + BSCS ===================== --}}
    <div class="grid grid-cols-3 gap-5 items-start max-[1080px]:grid-cols-1">

        {{-- Activity feed (2 cols) --}}
        <div class="flex flex-col gap-5 min-w-0 col-span-2 max-[1080px]:col-auto">
            <div class="card" x-data="{ cat: 'all', showAll: false }">
                <div class="flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft">
                    <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                        <x-feathericon-cpu class="w-[16px] h-[16px] text-fg-3" stroke-width="1.75"/>Recent bot activity
                    </div>
                    <div class="flex items-center gap-1.5" x-data="{ open: false }">
                        <button @click="open = !open" type="button" class="inline-flex items-center gap-[7px] h-[34px] bg-surface rounded-control px-[11px] font-sans text-[12.5px] font-medium whitespace-nowrap border border-line text-fg-2 transition-colors duration-fast ease-out hover:border-line-strong hover:text-fg-1 cursor-pointer">
                            <x-feathericon-filter class="w-[14px] h-[14px] text-fg-3" stroke-width="1.75"/>
                            <span x-text="({{ collect($activityCats)->mapWithKeys(fn($c) => [$c['id'] => $c['label']])->toJson() }})[cat]"></span>
                            <x-feathericon-chevron-down class="w-[13px] h-[13px] opacity-60" stroke-width="1.75"/>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-cloak x-transition.opacity
                             class="absolute top-[calc(100%+6px)] right-5 z-[60] min-w-[184px] bg-surface border border-line rounded-control shadow-2 p-[5px] flex flex-col gap-px animate-dd-in">
                            @foreach($activityCats as $c)
                                <button @click="cat = '{{ $c['id'] }}'; showAll = false; open = false" type="button"
                                        :class="cat === '{{ $c['id'] }}' ? 'text-fg-1 font-semibold' : 'text-fg-2'"
                                        class="appearance-none cursor-pointer text-left flex items-center justify-between gap-3 bg-transparent border-0 rounded-[7px] py-[7px] px-[9px] font-sans text-[12.5px] transition-colors duration-fast ease-out hover:bg-hover hover:text-fg-1">
                                    <span>{{ $c['label'] }}</span>
                                    <span x-show="cat === '{{ $c['id'] }}'"><x-feathericon-check class="w-[14px] h-[14px] text-accent" stroke-width="2"/></span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="flex flex-col">
                    @foreach($activity as $a)
                        <div x-show="cat === 'all' || cat === '{{ $a['cat'] }}'"
                             class="flex items-center gap-2.5 py-[9px] px-5 border-b border-line-soft min-w-0 last:border-b-0">
                            <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0" style="background: {{ $a['dot'] }};"></span>
                            <span class="font-mono text-[9px] font-semibold tracking-[0.07em] uppercase text-fg-mute w-14 flex-shrink-0">{{ $a['kind'] }}</span>
                            <span class="flex-1 min-w-0 text-[12.5px] text-fg-2 whitespace-nowrap overflow-hidden text-ellipsis">{!! $a['html'] !!}</span>
                            <span class="font-mono text-[10.5px] text-fg-mute whitespace-nowrap flex-shrink-0">{{ $a['time'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                    <span class="font-mono tabular-nums text-[11px] text-fg-mute">UPDATED 12s AGO · {{ count($activity) }} EVENTS</span>
                    <a href="#" class="text-[12px] font-sans font-semibold no-underline text-accent inline-flex items-center gap-[5px] hover:text-accent-hover">Audit log →</a>
                </div>
            </div>
        </div>

        {{-- Right column: Connectivity + BSCS mini --}}
        <div class="flex flex-col gap-5 min-w-0">

            {{-- Connectivity card --}}
            <div class="card {{ $downCount > 0 ? 'card--alert' : '' }}">
                <div class="flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft">
                    <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                        <x-feathericon-server class="w-[16px] h-[16px] text-fg-3" stroke-width="1.75"/>Server connectivity
                    </div>
                    @if($downCount > 0)
                        <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                              style="background: color-mix(in srgb, var(--danger) 12%, transparent); border-color: color-mix(in srgb, var(--danger) 38%, transparent); color: var(--pnl-down-fg);">
                            <span class="w-2 h-2 rounded-chip animate-pulse-soft" style="background: var(--danger);"></span>{{ $downCount }} DOWN
                        </span>
                    @else
                        <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                              style="background: color-mix(in srgb, var(--accent) 12%, transparent); border-color: color-mix(in srgb, var(--accent) 38%, transparent); color: var(--pnl-up-fg);">
                            <span class="w-2 h-2 rounded-chip" style="background: var(--accent);"></span>ALL LINKED
                        </span>
                    @endif
                </div>

                <div>
                    @php $listShown = $downCount > 0 ? array_filter($servers, fn($s) => $s['state'] !== 'ok') : $servers; @endphp
                    @foreach($listShown as $s)
                        @php $st = $s['state']; $dotClass = $st === 'ok' ? 'bg-green-500' : ($st === 'warn' ? 'bg-warn' : ($st === 'down' ? 'bg-danger animate-pulse-soft' : 'bg-fg-faint')); @endphp
                        <div class="srv flex items-center gap-[11px] py-[9px] px-5 border-b border-line-soft transition-colors duration-fast ease-out hover:bg-hover last:border-b-0 {{ $st === 'down' ? 'is-down' : '' }}">
                            <span class="w-[9px] h-[9px] rounded-chip flex-shrink-0 {{ $dotClass }}"></span>
                            <span class="font-mono text-[12.5px] font-semibold text-fg-1 flex-1">{{ $s['id'] }}</span>
                            <span class="font-mono text-[9.5px] text-fg-mute uppercase tracking-[0.08em]">{{ $s['region'] }}</span>
                            <span class="font-mono text-[11.5px] tabular-nums flex-shrink-0 {{ $st === 'down' ? 'text-pnldown font-semibold' : 'text-fg-3' }}">{{ $st === 'ok' ? $s['latency'] : 'DOWN' }}</span>
                        </div>
                    @endforeach
                    @if($downCount > 0)
                        <div class="flex items-center gap-[9px] py-[9px] px-5 font-mono text-[11px] text-fg-mute tracking-[0.02em]">
                            <span class="w-[9px] h-[9px] rounded-chip flex-shrink-0 bg-green-500"></span>{{ count($okServers) }} other servers linked · egress nominal
                        </div>
                    @endif
                </div>

                <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                    <span class="font-mono tabular-nums text-[11px] text-fg-mute inline-flex items-center gap-[7px]">
                        <span class="w-1.5 h-1.5 rounded-chip bg-green-500"></span>HEARTBEAT 5s
                    </span>
                    <a href="#" class="appearance-none bg-transparent border-0 cursor-pointer font-mono text-[11px] tracking-[0.04em] text-fg-mute no-underline inline-flex items-center transition-colors duration-fast ease-out hover:text-fg-1">whitelist →</a>
                </div>
            </div>

            {{-- BSCS mini card --}}
            @php
                $clamp = fn($v) => max(0.05, min(0.98, $v));
                $comps = [
                    ['label' => 'BTC realized vol',    'v' => $clamp($score * 1.00)],
                    ['label' => 'Cross-asset corr.',   'v' => $clamp($score * 1.38)],
                    ['label' => 'Funding dispersion',  'v' => $clamp($score * 0.74)],
                    ['label' => 'Liquidity depth',     'v' => $clamp($score * 1.12)],
                ];
                $barColor = fn($v) => $v < 0.5 ? 'var(--accent)' : ($v < 0.75 ? 'var(--bsi-watch)' : 'var(--bsi-cascade)');
                $newPosLabel = $suspended ? 'NEW POS. SUSPENDED' : ($score < 0.5 ? 'NEW POS. ALLOWED' : 'NEW POS. REDUCED');
            @endphp
            <div class="card">
                <div class="flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft">
                    <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                        <x-feathericon-shield class="w-[16px] h-[16px] text-fg-3" stroke-width="1.75"/>Black Swan Composite
                    </div>
                    <span class="inline-flex items-center gap-[7px] py-[5px] px-[13px] rounded-chip border font-mono text-[11px] font-semibold tracking-[0.1em] uppercase whitespace-nowrap"
                          style="background: color-mix(in srgb, {{ $r['color'] }} 12%, transparent); border-color: color-mix(in srgb, {{ $r['color'] }} 38%, transparent); color: {{ $r['color'] }};">
                        <span class="w-2 h-2 rounded-chip" style="background: {{ $r['color'] }};"></span>{{ $regime }}
                    </span>
                </div>
                <div class="flex flex-col gap-[13px] pt-4 px-5 pb-[18px]">
                    <div class="flex items-baseline gap-[9px] flex-wrap">
                        <span class="font-mono text-[32px] font-semibold leading-none tracking-[-0.03em]" style="color: {{ $r['color'] }};">{{ number_format($score, 2) }}</span>
                        <span class="font-mono text-[11.5px] text-fg-mute whitespace-nowrap">/ 1.00 · <span style="color: {{ $r['color'] }}; font-weight: 600;">{{ $regime }}</span></span>
                        <span class="font-mono text-[9.5px] tracking-[0.06em] text-fg-mute ml-auto self-center">{{ $newPosLabel }}</span>
                    </div>
                    <div>
                        <div class="h-[7px] rounded-chip relative" style="background: linear-gradient(90deg, var(--bsi-calm) 0%, var(--bsi-watch) 32%, var(--bsi-elevated) 55%, var(--bsi-cascade) 80%, var(--bsi-blackswan) 100%);">
                            <span class="absolute top-1/2 w-[3px] h-[15px] bg-fg-1 border-2 border-surface rounded-chip -translate-x-1/2 -translate-y-1/2 transition-[left] duration-slow ease-snap" style="left: {{ $score * 100 }}%; box-shadow: 0 0 0 1px rgba(0,0,0,0.25);"></span>
                        </div>
                        <div class="flex justify-between mt-1.5">
                            @foreach(['CALM','WATCH','ELEV','CASC','SWAN'] as $lbl)
                                <span class="font-mono text-[9px] text-fg-mute tracking-[0.04em]">{{ $lbl }}</span>
                            @endforeach
                        </div>
                    </div>
                    @if($suspended)
                        <div class="flex items-start gap-[9px] py-[11px] px-[13px] rounded-control bg-pnldown-bg text-pnldown text-[12px] leading-[1.45] border" style="border-color: color-mix(in srgb, var(--danger) 38%, transparent);">
                            <x-feathericon-alert-triangle class="w-[15px] h-[15px] flex-shrink-0 mt-0.5" stroke-width="1.75"/>
                            <span>New position openings <strong class="font-bold">suspended for 24h</strong> — until <span class="font-mono text-pnldown-strong">{{ $untilStr }}</span>. Existing positions are still managed.</span>
                        </div>
                    @endif
                    <div class="flex flex-col gap-[11px] pt-[3px]">
                        @foreach($comps as $c)
                            <div class="grid grid-cols-[1fr_78px_36px] items-center gap-[11px]">
                                <span class="text-[12px] text-fg-3">{{ $c['label'] }}</span>
                                <div class="h-1 rounded-chip bg-surface-3 overflow-hidden">
                                    <div class="h-full rounded-chip" style="width: {{ $c['v'] * 100 }}%; background: {{ $barColor($c['v']) }};"></div>
                                </div>
                                <span class="font-mono text-[11px] text-fg-2 text-right tabular-nums">{{ number_format($c['v'], 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="py-3 px-5 border-t border-line-soft flex items-center justify-between gap-3">
                    <span class="font-mono tabular-nums text-[11px] text-fg-mute">UPDATED 38s AGO</span>
                    <a href="#" class="text-[12px] font-sans font-semibold no-underline text-accent inline-flex items-center gap-[5px] hover:text-accent-hover">View details →</a>
                </div>
            </div>
        </div>
    </div>

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
