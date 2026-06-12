@php
    // ===== PARTIAL WIRING =====
    // The Worker-fleet card is LIVE: it polls system.dashboard.data and renders
    // the real fleet (kraite.fleet.servers ⋈ Redis heartbeat keys), classified
    // online / stale / missing with per-box vitals + supervisor unit states.
    // The KPI row, market-regime, deploy, revenue, venue + incident panels below
    // are still the Claude Design mock — FleetFinancials / exchange wiring is a
    // later phase.
    $regime = 'ELEVATED';
    $regimeScore = 0.63;
    $regimeOrder = ['CALM', 'WATCH', 'ELEVATED', 'CASCADE', 'BLACK SWAN'];
    $regimeColors = [
        'CALM' => 'var(--bsi-calm)',
        'WATCH' => 'var(--bsi-watch)',
        'ELEVATED' => 'var(--bsi-elevated)',
        'CASCADE' => 'var(--bsi-cascade)',
        'BLACK SWAN' => 'var(--bsi-blackswan)',
    ];
    $regimeColor = $regimeColors[$regime] ?? $regimeColors['CALM'];
    $regimeIdx = array_search($regime, $regimeOrder, true);

    $venues = [
        ['ex' => 'Binance',  'mono' => 'B',  'state' => 'operational', 'lat' => 12,   'err' => 0.02, 'accts' => 642, 'spark' => [11, 12, 12, 11, 13, 12, 12]],
        ['ex' => 'Bybit',    'mono' => 'BY', 'state' => 'operational', 'lat' => 31,   'err' => 0.04, 'accts' => 388, 'spark' => [30, 31, 29, 32, 31, 33, 31]],
        ['ex' => 'OKX',      'mono' => 'O',  'state' => 'degraded',    'lat' => 210,  'err' => 1.84, 'accts' => 124, 'spark' => [44, 52, 90, 140, 180, 205, 210]],
        ['ex' => 'Deribit',  'mono' => 'D',  'state' => 'operational', 'lat' => 48,   'err' => 0.01, 'accts' => 96,  'spark' => [47, 48, 49, 48, 47, 48, 48]],
        ['ex' => 'Kraken',   'mono' => 'K',  'state' => 'operational', 'lat' => 22,   'err' => 0.03, 'accts' => 210, 'spark' => [21, 22, 23, 22, 21, 22, 22]],
        ['ex' => 'Coinbase', 'mono' => 'C',  'state' => 'maintenance', 'lat' => null, 'err' => null, 'accts' => 54,  'spark' => [26, 27, 28, 26, 27, 26, 27]],
    ];

    $incidents = [
        ['sev' => 'warn',  'icon' => 'shuffle', 'time' => '2m',  'text' => 'OKX API latency elevated to <span class="font-mono text-warn font-semibold">210ms</span> · 124 accounts affected · auto-throttling engaged'],
        ['sev' => 'warn',  'icon' => 'cpu',     'time' => '18m', 'text' => 'Worker <span class="font-mono text-fg-1 font-semibold">kr-sgp-02</span> CPU saturation <span class="font-mono text-warn font-semibold">94%</span> · load shedding active'],
        ['sev' => 'brand', 'icon' => 'zap',     'time' => '41m', 'text' => 'Deploy <span class="font-mono text-fg-1 font-semibold">v4.2.1</span> shipped to FRA-01, FRA-02, LDN-01 <span class="text-fg-mute">(canary)</span>'],
        ['sev' => 'warn',  'icon' => 'shield',  'time' => '1h',  'text' => 'BSCS regime escalated <span class="font-mono font-semibold">WATCH → ELEVATED</span> at score <span class="font-mono">0.63</span> · platform-wide'],
        ['sev' => 'mute',  'icon' => 'tool',    'time' => '2h',  'text' => 'Worker <span class="font-mono text-fg-1 font-semibold">kr-nyc-02</span> drained for maintenance · 18 bots migrated'],
        ['sev' => 'good',  'icon' => 'users',   'time' => '3h',  'text' => 'Signup spike — <span class="font-mono text-pnlup font-semibold">+64 traders</span> in the last hour'],
        ['sev' => 'mute',  'icon' => 'power',   'time' => '5h',  'text' => 'Kill-switch drill completed · halt → resume in <span class="font-mono text-fg-1 font-semibold">0.8s</span>'],
    ];
    $sevColors = ['warn' => 'var(--warn)', 'good' => 'var(--pnl-up-fg)', 'brand' => 'var(--accent)', 'mute' => 'var(--fg-mute)', 'bad' => 'var(--danger)'];

    $revenue = [
        ['k' => 'MRR', 'v' => '$412.8k', 'd' => 4.2, 'icon' => 'trending-up'],
        ['k' => 'Top-ups today', 'v' => '$84,210', 'sub' => '12 payments', 'icon' => 'plus'],
        ['k' => 'Wallet float held', 'v' => '$1.92M', 'sub' => 'across all wallets', 'icon' => 'credit-card'],
    ];

    // Tiny sparkline path generator (shared shape with x-ui.stat-tile).
    $sparkPath = function (array $data, int $w = 52, int $h = 20): string {
        if (count($data) < 2) {
            return '';
        }
        $min = min($data);
        $max = max($data);
        $range = ($max - $min) ?: 1;
        $pts = [];
        foreach (array_values($data) as $i => $v) {
            $x = ($i / (count($data) - 1)) * $w;
            $y = $h - (($v - $min) / $range) * $h;
            $pts[] = round($x, 2) . ',' . round($y, 2);
        }

        return 'M ' . implode(' L ', $pts);
    };
@endphp

<x-app-layout active="overview" :title="'Kraite — Fleet overview'">
    <script>
        // Live fleet card: polls system.dashboard.data and renders the real
        // fleet roster (kraite.fleet.servers ⋈ Redis heartbeat keys).
        window.systemDash = (dataUrl) => ({
            fleet: [],
            loaded: false,
            loading: false,
            _timer: null,

            init() {
                this.refresh();
                // 15s cadence — the heartbeat writes every ~5min, so a tighter
                // poll only sharpens the "last sync" age + status transitions.
                this._timer = setInterval(() => this.refresh(), 15000);
            },
            destroy() {
                if (this._timer) { clearInterval(this._timer); this._timer = null; }
            },
            async refresh() {
                if (this.loading) return;
                this.loading = true;
                try {
                    const res = await fetch(dataUrl, { headers: { Accept: 'application/json' } });
                    if (res.ok) {
                        const d = await res.json();
                        this.fleet = Array.isArray(d.fleet) ? d.fleet : [];
                        this.loaded = true;
                    }
                } finally {
                    this.loading = false;
                }
            },

            counts() {
                const c = { online: 0, stale: 0, missing: 0 };
                this.fleet.forEach((f) => { c[f.status] = (c[f.status] || 0) + 1; });
                return c;
            },
            statusMeta(status) {
                if (status === 'online') return { label: 'ONLINE', color: 'var(--pnl-up-fg)' };
                if (status === 'stale') return { label: 'STALE', color: 'var(--warn)' };
                return { label: 'MISSING', color: 'var(--danger)' };
            },
            barColor(pct) {
                return pct >= 90 ? 'var(--danger)' : (pct >= 75 ? 'var(--warn)' : 'var(--pnl-up-fg)');
            },
            uptimeHuman(s) {
                if (s === null || s === undefined) return '—';
                if (s < 3600) return Math.floor(s / 60) + 'm';
                if (s < 86400) return Math.floor(s / 3600) + 'h';
                return Math.floor(s / 86400) + 'd';
            },
            ageHuman(s) {
                if (s === null || s === undefined) return '—';
                return s < 60 ? s + 's' : Math.floor(s / 60) + 'm';
            },
            unitList(units) {
                return Object.entries(units || {}).map(([name, state]) => ({ name, state }));
            },
            unitOk(state) {
                return state === 'RUNNING';
            },
        });
    </script>

    {{-- ===================== PAGE HEADER ===================== --}}
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-activity class="w-[13px] h-[13px]" stroke-width="1.75"/>PLATFORM
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Fleet overview</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Live health across every Kraite worker, exchange, and customer account.</div>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
            <span class="inline-flex items-center gap-[7px] py-[6px] px-3 rounded-chip border font-mono text-[11px] font-bold tracking-[0.06em] uppercase whitespace-nowrap"
                  style="color: {{ $regimeColor }}; border-color: color-mix(in srgb, {{ $regimeColor }} 38%, transparent); background: color-mix(in srgb, {{ $regimeColor }} 12%, transparent)">
                <span class="w-2 h-2 rounded-chip" style="background: {{ $regimeColor }}"></span>{{ $regime }}<span class="opacity-70 ml-0.5">{{ number_format($regimeScore, 2) }}</span>
            </span>
            <div class="w-px h-[22px] bg-line"></div>
            <button type="button" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out h-[36px] px-3.5 text-[13px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/>Sync
            </button>
        </div>
    </div>

    {{-- ===================== KPI ROW ===================== --}}
    <div class="grid grid-cols-5 gap-3 mb-6 max-[1100px]:grid-cols-3 max-[680px]:grid-cols-2">
        <x-ui.stat-tile icon="users" label="Active traders" value="1,284" :delta="1.4" sub="24H · +18 SIGNUPS"/>
        <x-ui.stat-tile icon="cpu" label="Worker nodes" value="6 / 8" sub="HEALTHY · 1 DEGRADED">
            <span class="flex items-center gap-1.5"><x-ui.health-dot state="degraded" :pulse="true"/><x-ui.health-dot state="draining"/></span>
        </x-ui.stat-tile>
        <x-ui.stat-tile icon="database" label="Capital under mgmt" value="$84.2M" :delta="2.1" sub="AUM · ALL ACCOUNTS"/>
        <x-ui.stat-tile icon="activity" label="Engine throughput" value="3,420" sub="ORDERS / MIN" :spark="[2800,3010,2950,3180,3240,3120,3360,3420]"/>
        <x-ui.stat-tile icon="layers" label="Open positions" value="9,612" sub="PLATFORM-WIDE"/>
    </div>

    {{-- ===================== FLEET + SIDE COLUMN ===================== --}}
    <div class="grid grid-cols-[1.6fr_1fr] gap-5 mb-5 max-[1024px]:grid-cols-1">
        {{-- worker fleet — LIVE (kraite.fleet.servers ⋈ Redis heartbeat keys) --}}
        <div class="card card--flat overflow-hidden"
             x-data="systemDash(@js(route('system.dashboard.data')))" x-init="init()">
            <x-ui.card-head icon="server" title="Worker fleet" :accent="true">
                <x-slot:right>
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"
                              x-text="loaded ? `${counts().online} online · ${counts().stale} stale · ${counts().missing} missing` : 'loading…'"></span>
                        <button type="button" @click="refresh()" :disabled="loading"
                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-3 hover:bg-hover hover:text-fg-1 h-[30px] px-2.5 text-[12px] disabled:opacity-50">
                            <x-feathericon-refresh-cw class="w-[13px] h-[13px]" stroke-width="1.75" ::class="loading && 'animate-spin'"/>Sync
                        </button>
                    </div>
                </x-slot:right>
            </x-ui.card-head>
            <div class="hidden md:grid grid-cols-[minmax(150px,1.4fr)_104px_1fr_1fr_1fr_64px_minmax(96px,1fr)] items-center gap-4 py-2 px-5 border-b border-line-soft font-mono text-[9px] font-semibold tracking-[0.1em] uppercase text-fg-faint">
                <span>Node</span><span>Status</span><span>CPU</span><span>Memory</span>
                <span class="max-[1024px]:hidden">Disk</span><span class="text-right max-[1024px]:hidden">Uptime</span><span class="max-[1024px]:hidden">Services</span>
            </div>

            {{-- loading / empty --}}
            <div x-show="!loaded" class="py-10 text-center font-mono text-[11px] text-fg-mute">Loading fleet…</div>

            <template x-for="node in fleet" :key="node.hostname">
                <div class="grid grid-cols-[minmax(150px,1.4fr)_104px_1fr_1fr_1fr_64px_minmax(96px,1fr)] items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[1024px]:grid-cols-[minmax(140px,1.5fr)_104px_1fr_1fr] max-[640px]:px-4 transition-colors duration-fast"
                     :style="node.status === 'stale' ? 'background: color-mix(in srgb, var(--warn) 7%, transparent)' : (node.status === 'missing' ? 'background: color-mix(in srgb, var(--danger) 6%, transparent)' : '')">
                    {{-- node: type + hostname + ip --}}
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="font-mono text-[9px] font-bold tracking-[0.06em] uppercase text-fg-mute w-[44px] flex-shrink-0" x-text="node.type ?? '—'"></span>
                        <div class="flex flex-col leading-[1.2] min-w-0">
                            <span class="font-mono text-[12.5px] font-semibold text-fg-1 tracking-[0.01em] whitespace-nowrap inline-flex items-center gap-1.5">
                                <span x-text="node.hostname"></span>
                                <span x-show="node.recently_rebooted" class="font-mono text-[8px] font-bold tracking-[0.06em] uppercase py-px px-1 rounded-chip" style="color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent)">rebooted</span>
                            </span>
                            <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] whitespace-nowrap" x-text="node.ip_address ?? '—'"></span>
                        </div>
                    </div>
                    {{-- status --}}
                    <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase" :style="`color: ${statusMeta(node.status).color}`">
                        <span class="w-[6px] h-[6px] rounded-chip" :class="node.status === 'online' && 'animate-pulse'" :style="`background: ${statusMeta(node.status).color}`"></span>
                        <span x-text="statusMeta(node.status).label"></span>
                    </span>
                    {{-- cpu --}}
                    <div>
                        <template x-if="node.cpu && node.cpu.percent !== null">
                            <div class="flex flex-col gap-1 min-w-[64px]">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">CPU</span>
                                    <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${node.cpu.percent >= 75 ? barColor(node.cpu.percent) : 'var(--fg-2)'}`" x-text="node.cpu.percent + '%'"></span>
                                </div>
                                <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${node.cpu.percent}%; background: ${barColor(node.cpu.percent)}`"></div></div>
                            </div>
                        </template>
                        <span x-show="!node.cpu || node.cpu.percent === null" class="font-mono text-[11px] text-fg-mute">—</span>
                    </div>
                    {{-- memory --}}
                    <div>
                        <template x-if="node.ram && node.ram.percent !== null">
                            <div class="flex flex-col gap-1 min-w-[64px]">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">MEM</span>
                                    <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${node.ram.percent >= 75 ? barColor(node.ram.percent) : 'var(--fg-2)'}`" x-text="node.ram.percent + '%'"></span>
                                </div>
                                <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${node.ram.percent}%; background: ${barColor(node.ram.percent)}`"></div></div>
                            </div>
                        </template>
                        <span x-show="!node.ram || node.ram.percent === null" class="font-mono text-[11px] text-fg-mute">—</span>
                    </div>
                    {{-- disk --}}
                    <div class="max-[1024px]:hidden">
                        <template x-if="node.disk && node.disk.percent !== null">
                            <div class="flex flex-col gap-1 min-w-[64px]">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">DISK</span>
                                    <span class="font-mono text-[11px] font-semibold tabular-nums" :style="`color: ${node.disk.percent >= 75 ? barColor(node.disk.percent) : 'var(--fg-2)'}`" x-text="node.disk.percent + '%'"></span>
                                </div>
                                <div class="h-[4px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip transition-[width] duration-base" :style="`width: ${node.disk.percent}%; background: ${barColor(node.disk.percent)}`"></div></div>
                            </div>
                        </template>
                        <span x-show="!node.disk || node.disk.percent === null" class="font-mono text-[11px] text-fg-mute">—</span>
                    </div>
                    {{-- uptime --}}
                    <div class="flex flex-col items-end leading-tight max-[1024px]:hidden">
                        <span class="font-mono text-[12px] font-semibold tabular-nums text-fg-1" x-text="uptimeHuman(node.uptime_seconds)"></span>
                    </div>
                    {{-- supervisor services — hover a dot for the service name + state --}}
                    <div class="flex items-center gap-[6px] flex-wrap max-[1024px]:hidden">
                        <span x-show="unitList(node.units).length === 0" class="font-mono text-[10px] text-fg-mute">—</span>
                        <template x-for="u in unitList(node.units)" :key="u.name">
                            <span class="relative group inline-flex items-center justify-center w-[13px] h-[13px] cursor-default">
                                <span class="w-[7px] h-[7px] rounded-chip flex-shrink-0 transition-transform duration-fast group-hover:scale-150" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                <div class="absolute bottom-[calc(100%+6px)] left-1/2 -translate-x-1/2 z-20 hidden group-hover:block whitespace-nowrap bg-surface border border-line-strong rounded-control shadow-3 px-2.5 py-1.5 pointer-events-none">
                                    <div class="font-mono text-[10px] font-bold text-fg-1 flex items-center gap-1.5">
                                        <span class="w-[6px] h-[6px] rounded-chip flex-shrink-0" :style="`background: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`"></span>
                                        <span x-text="u.name"></span>
                                    </div>
                                    <div class="font-mono text-[9px] tracking-[0.06em] uppercase mt-0.5" :style="`color: ${unitOk(u.state) ? 'var(--pnl-up-fg)' : 'var(--danger)'}`" x-text="u.state"></div>
                                </div>
                            </span>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        {{-- right column: regime / deploy / revenue --}}
        <div class="flex flex-col gap-5">
            {{-- market regime --}}
            <div class="card card--flat overflow-hidden">
                <x-ui.card-head icon="shield" title="Market regime" :accent="true" hint="BSCS · platform-wide"/>
                <div class="p-5 flex flex-col gap-4">
                    <div class="flex items-end justify-between gap-3">
                        <div class="flex flex-col gap-1.5">
                            <span class="font-sans text-[22px] font-bold tracking-[-0.01em] leading-none" style="color: {{ $regimeColor }}">{{ $regime }}</span>
                            <span class="font-mono text-[10.5px] tracking-[0.04em] text-fg-mute">Exposure auto-reduced</span>
                        </div>
                        <span class="font-mono text-[34px] font-bold tabular-nums leading-none" style="color: {{ $regimeColor }}">{{ number_format($regimeScore, 2) }}</span>
                    </div>
                    <div class="flex flex-col gap-2">
                        <div class="flex items-stretch gap-1 h-[8px]">
                            @foreach($regimeOrder as $i => $r)
                                <div class="flex-1 rounded-chip transition-colors duration-base" style="background: {{ $i <= $regimeIdx ? $regimeColors[$r] : 'var(--bg-elev-3)' }}"></div>
                            @endforeach
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">Calm</span>
                            <span class="font-mono text-[9px] tracking-[0.08em] uppercase text-fg-mute">Black swan</span>
                        </div>
                    </div>
                    <button type="button" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center justify-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-1 border-line-strong hover:bg-hover w-full h-[34px] mt-1 text-[13px]">
                        <x-feathericon-sliders class="w-[14px] h-[14px]" stroke-width="1.75"/>Override regime
                    </button>
                </div>
            </div>

            {{-- deploy --}}
            <div class="card card--flat overflow-hidden">
                <x-ui.card-head icon="zap" title="Deploy" :accent="true" hint="rollout"/>
                <div class="p-5 flex flex-col gap-3.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-mono text-[12.5px] font-semibold text-fg-1">v4.2.1</span>
                        <span class="inline-flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.07em] uppercase" style="color: var(--pnl-up-fg)"><span class="w-[6px] h-[6px] rounded-chip" style="background: var(--pnl-up-fg)"></span>Canary healthy</span>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-[10px] tracking-[0.06em] uppercase text-fg-mute">Rolled out</span>
                            <span class="font-mono text-[11px] font-semibold tabular-nums text-fg-1">6 / 8 nodes</span>
                        </div>
                        <div class="h-[6px] rounded-chip bg-surface-3 overflow-hidden"><div class="h-full rounded-chip bg-accent" style="width: 75%"></div></div>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-[10px] text-fg-mute tracking-[0.02em]">NYC-01 · NYC-02 on v4.2.0</span>
                        <button type="button" class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-3 hover:bg-hover hover:text-fg-1 h-[28px] px-2 text-[11.5px]">Continue<x-feathericon-arrow-right class="w-[13px] h-[13px]" stroke-width="1.75"/></button>
                    </div>
                </div>
            </div>

            {{-- revenue today --}}
            <div class="card card--flat overflow-hidden">
                <x-ui.card-head icon="credit-card" title="Revenue today" :accent="true"/>
                <div class="px-5 py-1.5">
                    @foreach($revenue as $i => $r)
                        <div class="flex items-center justify-between gap-3 py-3 {{ $i < count($revenue) - 1 ? 'border-b border-line-soft' : '' }}">
                            <span class="flex items-center gap-2.5 text-[12.5px] text-fg-3"><x-dynamic-component :component="'feathericon-' . $r['icon']" class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>{{ $r['k'] }}</span>
                            <span class="flex items-center gap-2">
                                @isset($r['sub'])<span class="font-mono text-[10px] text-fg-mute tracking-[0.02em] max-[480px]:hidden">{{ $r['sub'] }}</span>@endisset
                                @isset($r['d'])<span class="font-mono text-[11px] font-semibold tabular-nums inline-flex items-center gap-[2px] py-0.5 px-[7px] rounded-chip text-pnlup bg-pnlup-bg">+{{ number_format($r['d'], 2) }}%</span>@endisset
                                <span class="font-mono text-[14px] font-bold tabular-nums text-fg-1">{{ $r['v'] }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== VENUES + INCIDENTS ===================== --}}
    <div class="grid grid-cols-[1.6fr_1fr] gap-5 max-[1024px]:grid-cols-1">
        {{-- exchange connectivity --}}
        <div class="card card--flat overflow-hidden">
            <x-ui.card-head icon="shuffle" title="Exchange connectivity" :accent="true">
                <x-slot:right>
                    <span class="font-mono text-[10.5px] text-fg-mute tabular-nums">6 venues · 1 degraded</span>
                </x-slot:right>
            </x-ui.card-head>
            @foreach($venues as $v)
                @php $up = in_array($v['state'], ['operational', 'healthy'], true); @endphp
                <div class="grid grid-cols-[minmax(130px,1.4fr)_120px_120px_72px_70px] items-center gap-4 py-3 px-5 border-b border-line-soft last:border-b-0 max-[820px]:grid-cols-[minmax(120px,1.4fr)_110px_1fr] max-[640px]:px-4 transition-colors"
                     @if($v['state'] === 'degraded') style="background: color-mix(in srgb, var(--warn) 7%, transparent)" @endif>
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="w-[28px] h-[28px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[11px] flex items-center justify-center flex-shrink-0">{{ $v['mono'] }}</span>
                        <span class="font-sans text-[13px] font-semibold text-fg-1 whitespace-nowrap">{{ $v['ex'] }}</span>
                    </div>
                    <x-ui.health-chip :state="$v['state']"/>
                    <div class="flex items-center gap-2.5 max-[820px]:hidden">
                        <div class="w-[52px] h-[20px] flex-shrink-0 opacity-80">
                            <svg viewBox="0 0 52 20" preserveAspectRatio="none" class="w-full h-full"><path d="{{ $sparkPath($v['spark']) }}" fill="none" stroke="{{ $up ? 'var(--pnl-up-fg)' : 'var(--warn)' }}" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>
                        </div>
                        <span class="font-mono text-[11.5px] font-semibold tabular-nums" style="color: {{ $v['lat'] === null ? 'var(--fg-mute)' : ($v['lat'] >= 100 ? 'var(--warn)' : 'var(--fg-1)') }}">{{ $v['lat'] === null ? '—' : $v['lat'] . 'ms' }}</span>
                    </div>
                    <div class="flex flex-col items-end leading-tight max-[820px]:hidden">
                        <span class="font-mono text-[11.5px] font-semibold tabular-nums" style="color: {{ $v['err'] === null ? 'var(--fg-mute)' : ($v['err'] >= 1 ? 'var(--warn)' : 'var(--fg-2)') }}">{{ $v['err'] === null ? '—' : number_format($v['err'], 2) . '%' }}</span>
                        <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">errors</span>
                    </div>
                    <div class="flex flex-col items-end leading-tight">
                        <span class="font-mono text-[11.5px] font-semibold tabular-nums text-fg-1">{{ $v['accts'] }}</span>
                        <span class="font-mono text-[9px] tracking-[0.06em] uppercase text-fg-mute">accts</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- incidents & events --}}
        <div class="card card--flat overflow-hidden">
            <x-ui.card-head icon="activity" title="Incidents & events" :accent="true">
                <x-slot:right>
                    <button type="button" class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out bg-transparent text-fg-3 hover:bg-hover hover:text-fg-1 h-[28px] px-2 text-[11.5px]">View all<x-feathericon-arrow-right class="w-[13px] h-[13px]" stroke-width="1.75"/></button>
                </x-slot:right>
            </x-ui.card-head>
            <div class="px-5 py-1">
                @foreach($incidents as $i => $ev)
                    @php $c = $sevColors[$ev['sev']] ?? $sevColors['mute']; @endphp
                    <div class="flex items-start gap-3 py-3 {{ $i < count($incidents) - 1 ? 'border-b border-line-soft' : '' }}">
                        <span class="w-[26px] h-[26px] rounded-control flex items-center justify-center flex-shrink-0 mt-px" style="background: color-mix(in srgb, {{ $c }} 14%, transparent); color: {{ $c }}">
                            <x-dynamic-component :component="'feathericon-' . $ev['icon']" class="w-3.5 h-3.5" stroke-width="1.75"/>
                        </span>
                        <span class="flex-1 min-w-0 text-[12.5px] text-fg-2 leading-snug">{!! $ev['text'] !!}</span>
                        <span class="font-mono text-[10.5px] text-fg-mute tabular-nums flex-shrink-0 mt-0.5">{{ $ev['time'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
