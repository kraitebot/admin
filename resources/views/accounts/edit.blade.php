@php
    // ============================================================
    // MOCK DATA — design-fidelity port. Wire to AccountController later
    // (edit payload + quotes/test/save endpoints already exist).
    // ============================================================
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

    $downAccount = ['ex' => 'OKX', 'tag' => 'arb', 'note' => 'last seen 4m ago'];

    // Kraite egress IPs the user must allowlist (paired to the server fleet).
    $ips = [
        ['id' => 'kr-fra-01', 'region' => 'Frankfurt', 'ip' => '51.158.10.21'],
        ['id' => 'kr-fra-02', 'region' => 'Frankfurt', 'ip' => '51.158.10.22'],
        ['id' => 'kr-ldn-01', 'region' => 'London',    'ip' => '178.62.40.13'],
        ['id' => 'kr-nyc-01', 'region' => 'New York',  'ip' => '159.65.20.44'],
        ['id' => 'kr-sgp-01', 'region' => 'Singapore', 'ip' => '128.199.80.5'],
        ['id' => 'kr-sgp-02', 'region' => 'Singapore', 'ip' => '128.199.80.6'],
    ];

    // constrained config option lists (verbatim, backend-validated)
    $opts = [
        'pt'     => ['0.360', '0.380', '0.400'],
        'sl'     => ['2.50', '5.00', '7.50'],
        'slots'  => ['4', '5', '6'],
        'lev'    => ['10', '15', '20'],
        'margin' => ['4.00', '5.00', '6.00'],
    ];

    // exchange accounts — identity, connection phase, config defaults.
    // OKX ships in the trading-disabled flavor (SGP IPs blocked) so the
    // failure surface is visible in the mock.
    $accounts = [
        [
            'key' => 'binance-main', 'ex' => 'Binance', 'tag' => 'main', 'mono' => 'B',
            'owner' => 'Frankfurt desk', 'note' => 'Futures · cross', 'equity' => '$184,210.08',
            'needsPass' => false, 'quotes' => ['USDT', 'USDC', 'BNB'],
            'phase' => 'ok',
            'cfg' => ['cfgName' => 'Primary book', 'canTrade' => true, 'pq' => 'USDT', 'tq' => 'USDT', 'pt' => '0.380', 'sl' => '5.00', 'sL' => '5', 'sS' => '5', 'lL' => '15', 'lS' => '15', 'mL' => '5.00', 'mS' => '5.00'],
        ],
        [
            'key' => 'bybit-hedge', 'ex' => 'Bybit', 'tag' => 'hedge', 'mono' => 'BY',
            'owner' => 'Frankfurt desk', 'note' => 'Perp · isolated', 'equity' => '$62,840.12',
            'needsPass' => false, 'quotes' => ['USDT', 'USDC'],
            'phase' => 'ok',
            'cfg' => ['cfgName' => 'Hedge sleeve', 'canTrade' => true, 'pq' => 'USDT', 'tq' => 'USDC', 'pt' => '0.360', 'sl' => '5.00', 'sL' => '4', 'sS' => '6', 'lL' => '10', 'lS' => '15', 'mL' => '4.00', 'mS' => '5.00'],
        ],
        [
            'key' => 'okx-arb', 'ex' => 'OKX', 'tag' => 'arb', 'mono' => 'O',
            'owner' => 'Singapore desk', 'note' => 'Last seen 4m ago', 'equity' => '$24,980.55',
            'needsPass' => true, 'quotes' => ['USDT'],
            'phase' => 'fail',
            'cfg' => ['cfgName' => 'Arb engine', 'canTrade' => false, 'pq' => 'USDT', 'tq' => 'USDT', 'pt' => '0.400', 'sl' => '7.50', 'sL' => '6', 'sS' => '6', 'lL' => '20', 'lS' => '20', 'mL' => '6.00', 'mS' => '6.00'],
        ],
        [
            'key' => 'deribit-options', 'ex' => 'Deribit', 'tag' => 'options', 'mono' => 'D',
            'owner' => 'Frankfurt desk', 'note' => 'Options · portfolio', 'equity' => '$12,879.67',
            'needsPass' => false, 'quotes' => ['BTC', 'ETH', 'USDC'],
            'phase' => 'ok',
            'cfg' => ['cfgName' => 'Options book', 'canTrade' => true, 'pq' => 'BTC', 'tq' => 'USDC', 'pt' => '0.380', 'sl' => '2.50', 'sL' => '5', 'sS' => '4', 'lL' => '15', 'lS' => '10', 'mL' => '5.00', 'mS' => '4.00'],
        ],
    ];

    // IPs that fail the connectivity test on the trading-disabled account
    $failIds = ['kr-sgp-01', 'kr-sgp-02'];
@endphp

<x-app-layout active="accounts" :title="'Kraite — Accounts'" :showBanner="true" :downAccount="$downAccount">

    <script>
        // Per-account card controller — credential phase machine + the live
        // progressive connectivity test. Phases: 'empty' (first-run, config
        // locked) · 'idle' (creds edited, re-test required) · 'testing' ·
        // 'ok' (connection usable) · 'fail' (keys saved, trading disabled).
        window.acctCard = (init) => ({
            tab: init.phase === 'fail' ? 'connectivity' : 'general',
            phase: init.phase,
            creds: { ...init.creds },
            results: { ...init.results },
            cfg: { ...init.cfg },
            cfgSaved: 'idle',
            connDone: false,
            failIds: init.failIds,
            servers: init.servers,
            _timers: [],

            tested() { return this.phase === 'ok' || this.phase === 'fail'; },
            testing() { return this.phase === 'testing'; },
            canTest() {
                return !this.testing()
                    && this.creds.key.trim() !== ''
                    && this.creds.secret.trim() !== ''
                    && (!init.needsPass || this.creds.pass.trim() !== '');
            },
            canSave() { return this.tested() && !this.testing(); },
            configLocked() { return this.phase === 'empty'; },
            connectionUsable() { return this.phase === 'ok'; },
            tradingDisabled() { return this.phase === 'fail'; },
            tradingActive() { return this.connectionUsable() && this.cfg.canTrade; },
            okCount() { return Object.values(this.results).filter(v => v === 'ok').length; },
            credChanged() { if (this.phase === 'ok' || this.phase === 'empty') this.phase = 'idle'; },

            status() {
                if (this.testing()) return { kind: 'testing', c: 'var(--info)', t: 'Testing…', pulse: false };
                if (this.phase === 'ok') return { kind: 'ok', c: 'var(--pnl-up-fg)', t: 'Connection OK', pulse: false };
                if (this.phase === 'fail') return { kind: 'disabled', c: 'var(--warn)', t: 'Trading disabled', pulse: true };
                return { kind: 'none', c: 'var(--fg-mute)', t: 'Not connected', pulse: false };
            },
            resultColor(st) {
                return st === 'ok' ? 'var(--pnl-up-fg)' : st === 'fail' ? 'var(--danger)' : st === 'testing' ? 'var(--info)' : 'var(--fg-faint)';
            },

            // live progressive test — servers resolve one-by-one; a previously
            // failed account keeps failing on its blocked IPs (mock behavior)
            runTest() {
                const forceFail = init.phase === 'fail';
                this._timers.forEach(clearTimeout); this._timers = [];
                this.phase = 'testing';
                this.servers.forEach(s => { this.results[s.id] = 'pending'; });
                this.servers.forEach((s, i) => {
                    this._timers.push(setTimeout(() => { this.results[s.id] = 'testing'; }, 220 + i * 540));
                    this._timers.push(setTimeout(() => {
                        this.results[s.id] = (forceFail && this.failIds.includes(s.id)) ? 'fail' : 'ok';
                    }, 220 + i * 540 + 460));
                });
                this._timers.push(setTimeout(() => { this.phase = forceFail ? 'fail' : 'ok'; }, 220 + this.servers.length * 540 + 480));
            },

            saveConn() {
                if (!this.canSave()) return;
                this.connDone = true;
                this._timers.push(setTimeout(() => { this.connDone = false; }, 1900));
            },
            saveCfg() {
                if (this.configLocked() || this.cfgSaved !== 'idle') return;
                this.cfgSaved = 'saving';
                this._timers.push(setTimeout(() => { this.cfgSaved = 'done'; }, 520));
                this._timers.push(setTimeout(() => { this.cfgSaved = 'idle'; }, 2200));
            },
        });
    </script>

    {{-- ===================== PAGE HEADER ===================== --}}
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-link class="w-[13px] h-[13px]" stroke-width="1.75"/>EXCHANGES
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Accounts</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Connect and configure the exchange accounts the bot trades on.</div>
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

    <div x-data="{ openIdx: 0 }">
        <div class="flex items-center justify-between gap-3 mb-4">
            <span class="font-mono text-[10.5px] font-semibold tracking-[0.12em] uppercase text-fg-mute">Your exchange accounts · {{ count($accounts) }}</span>
            <span class="font-mono text-[10.5px] text-fg-faint tracking-[0.04em] max-[640px]:hidden">Expand an account to configure it</span>
        </div>

        <div class="flex flex-col gap-3">
            @foreach($accounts as $i => $a)
                @php
                    $key = $a['key'];
                    $cardInit = [
                        'phase' => $a['phase'],
                        'needsPass' => $a['needsPass'],
                        'creds' => [
                            'key' => 'kx_live_8f3a…d21',
                            'secret' => '••••••••••••••••',
                            'pass' => $a['needsPass'] ? '••••••' : '',
                        ],
                        'results' => collect($ips)->mapWithKeys(fn ($s) => [
                            $s['id'] => $a['phase'] === 'fail' && in_array($s['id'], $failIds, true) ? 'fail' : 'ok',
                        ])->all(),
                        'cfg' => $a['cfg'],
                        'failIds' => $failIds,
                        'servers' => collect($ips)->map(fn ($s) => ['id' => $s['id']])->all(),
                    ];
                @endphp
                <div class="card card--flat overflow-hidden"
                     x-data="acctCard(@js($cardInit))"
                     @if($a['phase'] === 'fail') style="border-color: color-mix(in srgb, var(--warn) 32%, var(--border));" @endif>

                    {{-- ---------- collapsed header ---------- --}}
                    <button type="button" @click="openIdx = openIdx === {{ $i }} ? -1 : {{ $i }}"
                            class="w-full flex items-center gap-3.5 py-4 px-6 text-left bg-transparent border-0 max-[640px]:px-4 transition-colors duration-fast ease-out cursor-pointer hover:bg-hover">
                        <span class="flex-shrink-0 text-fg-mute transition-transform duration-[220ms] ease-[cubic-bezier(0.16,1,0.3,1)]" :class="openIdx === {{ $i }} ? 'rotate-180' : ''">
                            <x-feathericon-chevron-down class="w-[18px] h-[18px]" stroke-width="1.75"/>
                        </span>
                        <span class="w-[36px] h-[36px] rounded-full bg-surface-3 text-fg-1 font-mono font-bold text-[14px] flex items-center justify-center flex-shrink-0">{{ $a['mono'] }}</span>
                        <div class="flex flex-col leading-[1.2] min-w-0">
                            <span class="text-[14.5px] font-semibold text-fg-1 whitespace-nowrap">{{ $a['ex'] }} <span class="text-fg-mute font-normal">· {{ $a['tag'] }}</span></span>
                            <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.02em] whitespace-nowrap">{{ $a['owner'] }} · {{ $a['note'] }}</span>
                        </div>
                        <div class="ml-auto flex items-center gap-4 flex-shrink-0 max-[640px]:gap-2.5">
                            {{-- trading pill — only when the connection is usable --}}
                            <span x-show="connectionUsable()" x-cloak
                                  class="hidden sm:inline-flex items-center gap-1.5 font-mono text-[9.5px] font-bold tracking-[0.09em] uppercase"
                                  :style="`color: ${tradingActive() ? 'var(--pnl-up-fg)' : 'var(--fg-mute)'}`">
                                <span class="w-[6px] h-[6px] rounded-chip" :style="`background: ${tradingActive() ? 'var(--pnl-up-fg)' : 'var(--border-strong)'}`"></span>
                                <span x-text="tradingActive() ? 'Trading' : 'Paused'"></span>
                            </span>
                            <span class="font-mono text-[13px] font-semibold text-fg-1 tabular-nums max-[480px]:hidden">{{ $a['equity'] }}</span>
                            {{-- status chip --}}
                            <span class="inline-flex items-center gap-2 py-[6px] px-3 rounded-chip border font-mono text-[11px] font-semibold tracking-[0.06em] whitespace-nowrap"
                                  :style="`color: ${status().c}; border-color: color-mix(in srgb, ${status().c} 38%, transparent); background: color-mix(in srgb, ${status().c} 12%, transparent)`">
                                <span class="w-2 h-2 rounded-chip" :class="status().pulse ? 'animate-pulse-soft' : ''" :style="`background: ${status().c}`"></span>
                                <span x-text="status().t"></span>
                            </span>
                        </div>
                    </button>

                    {{-- ---------- expandable body ---------- --}}
                    <div class="grid transition-[grid-template-rows] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]"
                         :style="`grid-template-rows: ${openIdx === {{ $i }} ? '1fr' : '0fr'}`">
                        <div class="min-h-0 overflow-hidden">
                            <div class="border-t border-line-soft">

                                {{-- sub-tab bar --}}
                                <div class="flex items-center gap-7 px-6 border-b border-line-soft max-[640px]:px-4 max-[640px]:gap-5">
                                    <button type="button" @click="tab = 'general'"
                                            class="relative inline-flex items-center gap-2 py-3.5 bg-transparent border-0 font-mono text-[12px] font-semibold tracking-[0.04em] transition-colors duration-fast ease-out cursor-pointer"
                                            :style="`color: ${tab === 'general' ? 'var(--fg-1)' : 'var(--fg-mute)'}`">
                                        General information
                                        <span x-show="tab === 'general'" class="absolute left-0 right-0 -bottom-px h-[2px] rounded-t bg-accent"></span>
                                    </button>
                                    <button type="button" @click="tab = 'connectivity'"
                                            class="relative inline-flex items-center gap-2 py-3.5 bg-transparent border-0 font-mono text-[12px] font-semibold tracking-[0.04em] transition-colors duration-fast ease-out cursor-pointer"
                                            :style="`color: ${tab === 'connectivity' ? 'var(--fg-1)' : 'var(--fg-mute)'}`">
                                        Connectivity
                                        <span class="w-[7px] h-[7px] rounded-chip" :style="`background: ${status().kind === 'ok' ? 'var(--pnl-up-fg)' : status().kind === 'disabled' ? 'var(--warn)' : status().kind === 'testing' ? 'var(--info)' : 'var(--fg-faint)'}`"></span>
                                        <span x-show="tab === 'connectivity'" class="absolute left-0 right-0 -bottom-px h-[2px] rounded-t bg-accent"></span>
                                    </button>
                                </div>

                                {{-- ================= GENERAL INFORMATION ================= --}}
                                <div x-show="tab === 'general'" :class="configLocked() ? 'opacity-40 pointer-events-none select-none' : ''">
                                    <template x-if="configLocked()">
                                        <div class="flex items-center gap-2.5 py-3 px-6 border-b border-line-soft max-[640px]:px-4">
                                            <x-feathericon-key class="w-3.5 h-3.5 text-fg-mute" stroke-width="1.75"/>
                                            <span class="text-[12.5px] text-fg-3">Connect this account first — configuration unlocks after a successful connection.</span>
                                        </div>
                                    </template>

                                    <x-form.group title="Identity" icon="user">
                                        <x-form.field label="Account name" for="{{ $key }}-name" help="Label only — has no effect on trading.">
                                            <x-form.input model="cfg.cfgName" id="{{ $key }}-name" placeholder="Account name"/>
                                        </x-form.field>
                                        <x-form.field label="Trading enabled">
                                            <div class="h-[42px] flex items-center gap-3 px-3.5 rounded-control border border-line bg-input">
                                                <x-form.toggle model="cfg.canTrade" disabledExpr="!connectionUsable()"/>
                                                <span class="font-mono text-[12px] font-semibold tracking-[0.03em]"
                                                      :style="`color: ${cfg.canTrade ? 'var(--pnl-up-fg)' : 'var(--fg-mute)'}`"
                                                      x-text="cfg.canTrade ? 'CAN TRADE' : 'PAUSED'"></span>
                                                <span x-show="!connectionUsable()" class="ml-auto font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-faint">needs connection</span>
                                            </div>
                                            <div class="text-[11.5px] leading-[1.45] text-fg-mute mt-1.5" x-text="cfg.canTrade ? 'Bot may open and manage positions on this account.' : 'Master off — bot will not trade this account.'"></div>
                                        </x-form.field>
                                        <x-form.field label="Portfolio quote" help="Currency the portfolio is valued in.">
                                            <x-form.select model="cfg.pq" :options="$a['quotes']"/>
                                        </x-form.field>
                                        <x-form.field label="Trading quote" help="Quote currency for new positions.">
                                            <x-form.select model="cfg.tq" :options="$a['quotes']"/>
                                        </x-form.field>
                                    </x-form.group>

                                    <x-form.group title="Trading" icon="activity" hint="per position">
                                        <x-form.field label="Profit target" for="{{ $key }}-pt">
                                            <x-form.select model="cfg.pt" :options="$opts['pt']"/>
                                        </x-form.field>
                                        <x-form.field label="Stop-loss" for="{{ $key }}-sl">
                                            <x-form.select model="cfg.sl" :options="$opts['sl']"/>
                                        </x-form.field>
                                    </x-form.group>

                                    <x-form.group title="Position slots" icon="layers" hint="max concurrent">
                                        <x-form.field label="Long slots" dir="long">
                                            <x-form.select model="cfg.sL" :options="$opts['slots']" dir="long"/>
                                        </x-form.field>
                                        <x-form.field label="Short slots" dir="short">
                                            <x-form.select model="cfg.sS" :options="$opts['slots']" dir="short"/>
                                        </x-form.field>
                                    </x-form.group>

                                    <x-form.group title="Leverage &amp; margin" icon="database">
                                        <x-form.field label="Leverage — long" dir="long">
                                            <x-form.select model="cfg.lL" :options="$opts['lev']" dir="long"/>
                                        </x-form.field>
                                        <x-form.field label="Leverage — short" dir="short">
                                            <x-form.select model="cfg.lS" :options="$opts['lev']" dir="short"/>
                                        </x-form.field>
                                        <x-form.field label="Margin % — long" dir="long">
                                            <x-form.select model="cfg.mL" :options="$opts['margin']" dir="long"/>
                                        </x-form.field>
                                        <x-form.field label="Margin % — short" dir="short">
                                            <x-form.select model="cfg.mS" :options="$opts['margin']" dir="short"/>
                                        </x-form.field>
                                    </x-form.group>

                                    <div class="flex items-center gap-3 py-4 px-6 max-[640px]:px-4 max-[560px]:flex-col max-[560px]:items-stretch">
                                        <button type="button" @click="saveCfg()" :disabled="configLocked() || cfgSaved !== 'idle'"
                                                :style="cfgSaved === 'done' ? 'background: var(--pnl-up-fg); color: #04140d' : ''"
                                                :class="configLocked() ? 'opacity-40 cursor-not-allowed hover:bg-accent' : ''"
                                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[40px] px-5 min-w-[188px] justify-center text-[12px] bg-accent text-accent-on hover:bg-accent-hover">
                                            <template x-if="cfgSaved === 'saving'">
                                                <span class="inline-flex items-center gap-[7px]"><span class="w-[15px] h-[15px] rounded-full border-2 border-[rgba(4,20,13,.35)] border-t-[#04140d] animate-spin"></span>Saving…</span>
                                            </template>
                                            <template x-if="cfgSaved === 'done'">
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-check class="w-4 h-4" stroke-width="2"/>Configuration saved</span>
                                            </template>
                                            <template x-if="cfgSaved === 'idle'">
                                                <span>Save configuration</span>
                                            </template>
                                        </button>
                                        <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[560px]:text-center">Applies to new positions opened after saving</span>
                                    </div>
                                </div>

                                {{-- ================= CONNECTIVITY ================= --}}
                                <div x-show="tab === 'connectivity'" x-cloak>
                                    {{-- trading-disabled banner --}}
                                    <div x-show="tradingDisabled()" x-cloak
                                         class="m-6 mb-0 rounded-control border px-4 py-3.5 flex items-start gap-3 max-[640px]:mx-4"
                                         style="border-color: color-mix(in srgb, var(--warn) 42%, transparent); background: color-mix(in srgb, var(--warn) 11%, transparent);">
                                        <span class="flex-shrink-0 mt-px text-warn"><x-feathericon-alert-triangle class="w-[17px] h-[17px]" stroke-width="1.75"/></span>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-sans font-semibold text-[13px] text-fg-1 leading-tight">Trading is disabled on this account</div>
                                            <div class="text-[12px] text-fg-3 mt-1 leading-snug">Some Kraite IP addresses are not allowlisted in your {{ $a['ex'] }} account. Keys are saved, but the bot will not open or manage positions here until the test passes from every server.</div>
                                        </div>
                                    </div>

                                    {{-- API credentials --}}
                                    <x-form.group title="API credentials" icon="key">
                                        <x-form.field label="API key" for="{{ $key }}-apikey">
                                            <x-form.input model="creds.key" id="{{ $key }}-apikey" mono placeholder="Paste API key" disabledExpr="testing()" changed="credChanged()"/>
                                        </x-form.field>
                                        <x-form.field label="API secret" for="{{ $key }}-apisecret">
                                            <x-form.input model="creds.secret" id="{{ $key }}-apisecret" mono secret placeholder="Paste API secret" disabledExpr="testing()" changed="credChanged()"/>
                                        </x-form.field>
                                        @if($a['needsPass'])
                                            <x-form.field label="API passphrase" for="{{ $key }}-apipass" help="Required by this exchange.">
                                                <x-form.input model="creds.pass" id="{{ $key }}-apipass" mono secret placeholder="Paste passphrase" disabledExpr="testing()" changed="credChanged()"/>
                                            </x-form.field>
                                        @endif
                                    </x-form.group>

                                    {{-- IP allowlist --}}
                                    <div class="border-b border-line-soft" x-data="{ copiedAll: false, copied: null }">
                                        <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                                            <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                                                <x-feathericon-shield class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Allowlist Kraite's IP addresses
                                            </h4>
                                            <button type="button"
                                                    @click="navigator.clipboard?.writeText(@js(collect($ips)->pluck('ip')->implode("\n"))); copiedAll = true; setTimeout(() => copiedAll = false, 1400)"
                                                    :style="copiedAll ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                                    class="appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[30px] px-3">
                                                <span x-show="!copiedAll"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                                                <span x-show="copiedAll" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                                                <span x-text="copiedAll ? 'Copied' : 'Copy all'"></span>
                                            </button>
                                        </div>
                                        <div class="py-5 px-6 max-[640px]:px-4">
                                            <p class="text-[12px] text-fg-3 mb-3 leading-snug max-w-[480px]">Add every address below to your {{ $a['ex'] }} API key's IP restriction. <span class="text-fg-2">Missing IPs are the #1 reason a test fails.</span></p>
                                            <div class="grid grid-cols-2 gap-2 max-[700px]:grid-cols-1">
                                                @foreach($ips as $ip)
                                                    <div class="flex items-center gap-3 py-2 px-3 rounded-control border border-line-soft bg-surface-2">
                                                        <span class="font-mono text-[12.5px] font-semibold text-fg-1 tabular-nums tracking-[0.02em]">{{ $ip['ip'] }}</span>
                                                        <span class="font-mono text-[10px] tracking-[0.07em] uppercase text-fg-mute">{{ $ip['region'] }}</span>
                                                        <button type="button"
                                                                @click="navigator.clipboard?.writeText('{{ $ip['ip'] }}'); copied = '{{ $ip['id'] }}'; setTimeout(() => copied = null, 1400)"
                                                                :style="copied === '{{ $ip['id'] }}' ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                                                class="ml-auto appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[26px] px-2.5">
                                                            <span x-show="copied !== '{{ $ip['id'] }}'"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                                                            <span x-show="copied === '{{ $ip['id'] }}'" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                                                            <span x-text="copied === '{{ $ip['id'] }}' ? 'Copied' : 'Copy'"></span>
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    {{-- per-server connectivity results --}}
                                    <div x-show="testing() || tested()" x-cloak class="border-b border-line-soft">
                                        <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                                            <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                                                <x-feathericon-server class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Connectivity from Kraite servers
                                            </h4>
                                            <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"><span x-text="okCount()"></span>/{{ count($ips) }} connected</span>
                                        </div>
                                        <div class="py-5 px-6 max-[640px]:px-4">
                                            <div class="rounded-control border border-line-soft overflow-hidden bg-surface-2">
                                                @foreach($ips as $s)
                                                    <div class="flex items-center gap-3 py-2.5 px-3.5 border-b border-line-soft last:border-b-0">
                                                        <span class="w-[18px] flex items-center justify-center flex-shrink-0">
                                                            <template x-if="results['{{ $s['id'] }}'] === 'testing'">
                                                                <span class="w-[13px] h-[13px] rounded-full border-2 border-line-strong border-t-info animate-spin"></span>
                                                            </template>
                                                            <template x-if="results['{{ $s['id'] }}'] === 'ok'">
                                                                <span class="text-pnlup"><x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/></span>
                                                            </template>
                                                            <template x-if="results['{{ $s['id'] }}'] === 'fail'">
                                                                <span class="text-danger"><x-feathericon-wifi-off class="w-3.5 h-3.5" stroke-width="1.75"/></span>
                                                            </template>
                                                            <template x-if="results['{{ $s['id'] }}'] === 'pending'">
                                                                <span class="w-[7px] h-[7px] rounded-chip bg-fg-faint"></span>
                                                            </template>
                                                        </span>
                                                        <span class="font-mono text-[12px] font-semibold text-fg-1 tracking-[0.02em]">{{ $s['id'] }}</span>
                                                        <span class="font-mono text-[10.5px] tracking-[0.08em] uppercase text-fg-mute">{{ $s['region'] }}</span>
                                                        <span class="font-mono text-[11px] text-fg-faint tabular-nums ml-auto">{{ $s['ip'] }}</span>
                                                        <span class="font-mono text-[10px] font-bold tracking-[0.09em] uppercase w-[78px] text-right"
                                                              :style="`color: ${resultColor(results['{{ $s['id'] }}'])}`"
                                                              x-text="results['{{ $s['id'] }}'] === 'ok' ? 'Connected' : results['{{ $s['id'] }}'] === 'fail' ? 'Blocked' : results['{{ $s['id'] }}'] === 'testing' ? 'Testing' : 'Queued'"></span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    {{-- actions --}}
                                    <div class="flex items-center gap-3 py-4 px-6 max-[640px]:px-4 max-[560px]:flex-col max-[560px]:items-stretch">
                                        <button type="button" @click="runTest()" :disabled="!canTest()"
                                                :class="!canTest() ? 'opacity-40 cursor-not-allowed hover:bg-transparent' : ''"
                                                class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[40px] px-4 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                                            <template x-if="testing()">
                                                <span class="inline-flex items-center gap-[7px]"><span class="w-3.5 h-3.5 rounded-full border-2 border-line-strong border-t-fg-1 animate-spin"></span>Testing…</span>
                                            </template>
                                            <template x-if="!testing()">
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/><span x-text="tested() ? 'Re-test connection' : 'Test connection'"></span></span>
                                            </template>
                                        </button>
                                        <button type="button" @click="saveConn()" :disabled="!canSave()"
                                                :style="connDone ? 'background: var(--pnl-up-fg); color: #04140d' : ''"
                                                :class="!canSave() ? 'opacity-40 cursor-not-allowed hover:bg-accent' : ''"
                                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[40px] px-4 text-[12px] bg-accent text-accent-on hover:bg-accent-hover">
                                            <template x-if="connDone">
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-check class="w-4 h-4" stroke-width="2"/>Saved</span>
                                            </template>
                                            <template x-if="!connDone">
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-shield class="w-[15px] h-[15px]" stroke-width="1.75"/><span x-text="tradingDisabled() ? 'Save keys (trading stays off)' : 'Save & enable trading'"></span></span>
                                            </template>
                                        </button>
                                        <span x-show="!tested() && !testing()" x-cloak class="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[560px]:text-center">Save unlocks after a successful test</span>
                                        <span x-show="phase === 'idle'" x-cloak class="font-mono text-[10.5px] tracking-[0.04em] text-warn max-[560px]:text-center">Credentials changed — re-test required</span>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
