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
    ];

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
    <section class="mb-6" x-data="{ filter: 'ALL' }">
        <div class="flex items-end justify-between gap-4 mb-4 max-[640px]:flex-col max-[640px]:items-start">
            <div>
                <div class="font-sans font-semibold text-[16px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap">
                    <x-feathericon-layers class="w-[17px] h-[17px] text-fg-3" stroke-width="1.75"/>Open positions
                </div>
                <div class="text-[12.5px] text-fg-3 mt-1 whitespace-nowrap">{{ count($positions) }} positions managed across the lifecycle · no manual orders</div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0 max-[640px]:w-full max-[640px]:flex-wrap max-[640px]:gap-y-2.5">
                {{-- Segmented control --}}
                <div class="relative inline-flex items-center h-[34px] bg-surface-3 border border-line rounded-control px-[3px] gap-0.5">
                    @foreach(['ALL','LONG','SHORT'] as $opt)
                        <button type="button" @click="filter = '{{ $opt }}'"
                                :class="filter === '{{ $opt }}' ? 'bg-accent text-accent-on shadow-1' : 'text-fg-3 hover:text-fg-1'"
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

        <div class="grid grid-cols-3 gap-5 max-[1080px]:grid-cols-2 max-[640px]:grid-cols-1">
            @foreach($positions as $p)
                @php
                    $long = $p['side'] === 'long';
                    $status = $p['status'] ?? null;
                    $waped = $status === 'waped';
                    $rib = $long
                        ? ['bg' => '#0e3f2a', 'border' => 'color-mix(in srgb, var(--pnl-up-fg) 30%, transparent)', 'sym' => 'text-[#eafff5]', 'mute' => 'text-[#8fd9b4]', 'chip' => 'text-[#aef0cf]']
                        : ['bg' => '#3f1212', 'border' => 'color-mix(in srgb, var(--pnl-down-fg) 32%, transparent)', 'sym' => 'text-[#ffecec]', 'mute' => 'text-[#e0a3a3]', 'chip' => 'text-[#ffc9c9]'];
                    $ladder = [26, 44, 62, 80];
                    $gainL = min($p['trackPx'], $p['trackTp']);
                    $gainW = abs($p['trackTp'] - $p['trackPx']);
                    $mc = $long ? 'text-accent' : 'text-pnldown';
                @endphp
                <div class="ptile bg-surface border-2 rounded-surface overflow-hidden transition-colors duration-fast ease-out {{ $long ? 'ptile--long' : 'ptile--short' }} {{ $waped ? 'ptile--waped' : '' }}">
                    <div class="{{ $status === 'opening' ? 'grayscale opacity-[0.62]' : '' }} pt-4 px-[18px] pb-[14px]">
                        {{-- Ribbon header --}}
                        <div class="flex items-start gap-[11px] -mt-4 -mx-[18px] mb-4 px-[18px] pt-4 pb-3.5 border-b"
                             style="background: {{ $rib['bg'] }}; border-color: {{ $rib['border'] }};">
                            <div class="w-[30px] h-[30px] rounded-full flex items-center justify-center font-mono font-bold text-[12px] text-white flex-shrink-0 overflow-hidden">
                                <img src="https://s2.coinmarketcap.com/static/img/coins/64x64/{{ $p['cmcId'] }}.png" alt="{{ $p['sym'] }}" class="block w-full h-full object-cover"/>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-[7px]">
                                    <span class="font-sans font-bold text-[14px] tracking-[-0.01em] flex-shrink-0 {{ $rib['sym'] }}">{{ $p['sym'] }}</span>
                                    <span class="text-[12px] whitespace-nowrap overflow-hidden text-ellipsis min-w-0 {{ $rib['mute'] }}">{{ $p['name'] }}</span>
                                    @if($status === 'opening')
                                        <span class="ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap bg-surface-3 text-fg-3">
                                            <span class="w-1.5 h-1.5 rounded-chip bg-fg-3 animate-pulse-soft"></span>Opening
                                        </span>
                                    @endif
                                    @if($waped)
                                        <span class="ml-auto flex-shrink-0 inline-flex items-center gap-[5px] font-mono text-[9px] font-bold tracking-[0.08em] uppercase py-0.5 px-2 rounded-chip whitespace-nowrap text-warn" style="background: color-mix(in srgb, var(--warn) 16%, transparent);">
                                            <x-feathericon-layers class="w-[10px] h-[10px]" stroke-width="2"/>WAP'd
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-[9px] mt-1.5">
                                    <span class="inline-flex items-center gap-1 font-mono text-[10.5px] font-bold tracking-[0.07em] uppercase rounded-chip py-1 px-2.5 bg-white/10 {{ $rib['chip'] }}">
                                        @if($long)
                                            <x-feathericon-arrow-up class="w-[11px] h-[11px]" stroke-width="2"/>
                                        @else
                                            <x-feathericon-arrow-down class="w-[11px] h-[11px]" stroke-width="2"/>
                                        @endif
                                        {{ $p['side'] }} {{ $p['lev'] }}
                                    </span>
                                    <span class="font-mono text-[11px] inline-flex items-center gap-[5px] {{ $rib['mute'] }}">
                                        <x-feathericon-clock class="w-[12px] h-[12px]" stroke-width="1.75"/>{{ $p['eta'] }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex gap-1 items-center flex-shrink-0 pt-[3px]">
                                @foreach($p['osc'] as $d)
                                    <i class="block w-1.5 h-1.5 rounded-chip {{ $d === 'up' ? 'bg-pnlup' : ($d === 'down' ? 'bg-pnldown' : 'bg-line-strong') }}"></i>
                                @endforeach
                            </div>
                        </div>

                        {{-- Lifecycle track --}}
                        <div class="relative mt-[22px] mx-0.5 mb-4 h-[30px]"
                             style="--mc: {{ $long ? 'var(--accent)' : 'var(--pnl-down-fg)' }}; --mc-soft: {{ $long ? 'var(--accent-soft)' : 'color-mix(in srgb, var(--pnl-down-fg) 22%, transparent)' }};">
                            <div class="absolute left-0 right-0 top-[21px] h-px" style="background: color-mix(in srgb, var(--mc) 45%, transparent);"></div>
                            <div class="absolute top-[19px] h-1 rounded-chip" style="left: {{ $gainL }}%; width: {{ $gainW }}%; background: var(--mc);"></div>
                            @foreach($ladder as $i => $pos)
                                @if($i >= $p['fillN'])
                                    <span class="absolute top-[15px] -translate-x-1/2 font-mono text-[10px] bg-surface px-1 leading-[1.4]" style="left: {{ $pos }}%; color: var(--mc);">{{ $i + 1 }}</span>
                                @endif
                            @endforeach
                            <div class="absolute top-0 -translate-x-1/2 flex flex-col items-center z-[2]" style="left: {{ $p['trackTp'] }}%;">
                                <span class="font-mono text-[9px] font-bold tracking-[0.06em]" style="color: var(--mc);">TP</span>
                                <span class="w-[11px] h-[11px] rotate-45 mt-[3px]" style="background: var(--mc); border-radius: 50% 50% 50% 0; box-shadow: 0 0 0 3px var(--mc-soft);"></span>
                            </div>
                            <div class="absolute top-0 -translate-x-1/2 flex flex-col items-center z-[2]" style="left: {{ $p['trackPx'] }}%;">
                                <span class="font-mono text-[9px] font-bold tracking-[0.06em] text-fg-2">PX</span>
                                <span class="w-[10px] h-[10px] rounded-full bg-fg-1 mt-[3px]" style="box-shadow: 0 0 0 3px color-mix(in srgb, var(--fg-1) 20%, transparent);"></span>
                            </div>
                        </div>

                        {{-- Metrics row 1: Path / Limit / Filled --}}
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center gap-[5px] mb-[5px]">
                                    <x-feathericon-flag class="w-[11px] h-[11px]" stroke-width="1.75"/>Path
                                </div>
                                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[14px] {{ $mc }}">{{ number_format($p['path'], 1) }}%</div>
                            </div>
                            <div class="text-center">
                                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-center gap-[5px] mb-[5px]">
                                    <x-feathericon-arrow-right class="w-[11px] h-[11px]" stroke-width="1.75"/>Limit
                                </div>
                                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[14px] text-fg-1">{{ number_format($p['limit'], 1) }}%</div>
                            </div>
                            <div class="text-right">
                                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-end gap-[5px] mb-[5px]">
                                    <x-feathericon-check class="w-[11px] h-[11px]" stroke-width="1.75"/>Filled
                                </div>
                                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[14px] text-fg-1">{{ $p['filled'] }}</div>
                            </div>
                        </div>

                        <div class="h-px bg-line-soft my-[13px]"></div>

                        {{-- Metrics row 2: Open / TP / Next --}}
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center gap-[5px] mb-[5px]">
                                    <span class="inline-block w-[6px] h-[6px] rounded-full bg-current"></span>Open
                                </div>
                                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[13px] text-fg-1">{{ $p['open'] }}</div>
                            </div>
                            <div class="text-center">
                                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-center gap-[5px] mb-[5px]">
                                    <x-feathericon-arrow-up class="w-[11px] h-[11px]" stroke-width="1.75"/>TP
                                </div>
                                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[13px] {{ $mc }}">{{ $p['tp'] }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-mono text-[9.5px] font-medium tracking-[0.08em] uppercase {{ $mc }} flex items-center justify-end gap-[5px] mb-[5px]">
                                    <x-feathericon-arrow-down class="w-[11px] h-[11px]" stroke-width="1.75"/>Next
                                </div>
                                <div class="font-mono font-semibold tabular-nums tracking-[-0.01em] text-[13px] text-fg-1">{{ $p['next'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
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
