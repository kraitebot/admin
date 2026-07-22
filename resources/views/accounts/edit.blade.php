@php
    $cards = collect($accounts)->map(function (array $a) {
        $pq = $a['portfolio_quote'] ?: 'USDT';
        $tq = $a['trading_quote'] ?: 'USDT';

        return [
            'key' => 'acct-' . $a['id'],
            'id' => $a['id'],
            'ex' => $a['exchange'],
            'tag' => $a['name'],
            'mono' => mb_strtoupper(mb_substr($a['exchange'] ?: '?', 0, 1)),
            'owner' => $a['owner'],
            'note' => $a['disabled_reason'] ?: ($a['is_active'] ? 'Active' : 'Inactive'),
            'equity' => '—',
            'hasCredentials' => (bool) ($a['has_credentials'] ?? false),
            'isActive' => (bool) ($a['is_active'] ?? false),
            'subscriptionActive' => (bool) ($a['subscription_active'] ?? true),
            'openPositionsCount' => (int) ($a['open_positions_count'] ?? 0),
            'needsPass' => (bool) ($a['requires_passphrase'] ?? false),
            'connectivityHealth' => $a['connectivity_health'] ?? [
                'kind' => 'unconfigured',
                'label' => 'Not connected',
                'blocked_servers' => 0,
                'total_servers' => 0,
            ],
            'quotes' => array_values(array_unique(array_filter([
                $pq,
                $tq,
                ...($a['exchange_canonical'] === 'bitget' ? ['USDT', 'USDC'] : []),
            ]))),
            'cfg' => [
                'cfgName' => $a['name'],
                'canTrade' => (bool) ($a['can_trade'] ?? false),
                'pq' => $pq,
                'tq' => $tq,
                'pt' => number_format((float) ($a['profit_percentage'] ?? 0), 3, '.', ''),
                'sl' => number_format((float) ($a['stop_market_initial_percentage'] ?? 0), 2, '.', ''),
                'sL' => (string) (int) ($a['total_positions_long'] ?? 1),
                'sS' => (string) (int) ($a['total_positions_short'] ?? 1),
                'lL' => (string) (int) ($a['position_leverage_long'] ?? 0),
                'lS' => (string) (int) ($a['position_leverage_short'] ?? 0),
                'mL' => number_format((float) ($a['margin_percentage_long'] ?? 0), 2, '.', ''),
                'mS' => number_format((float) ($a['margin_percentage_short'] ?? 0), 2, '.', ''),
            ],
        ];
    })->values()->all();

    // Option lists = design defaults ∪ every real value in use, so a stored
    // value is always selectable even if outside the default range.
    $union = function (array $defaults, array $values) {
        return collect($defaults)->merge($values)->unique()
            ->sortBy(fn ($v) => (float) $v)->values()->all();
    };
    $opts = [
        'pt'     => $union(['0.360', '0.380', '0.400'], collect($cards)->pluck('cfg.pt')->all()),
        'sl'     => $union(['2.50', '5.00', '7.50'], collect($cards)->pluck('cfg.sl')->all()),
        'slots'  => $union(['1', '4', '5', '6'], collect($cards)->flatMap(fn ($c) => [$c['cfg']['sL'], $c['cfg']['sS']])->all()),
        'lev'    => $union(['10', '15', '20'], collect($cards)->flatMap(fn ($c) => [$c['cfg']['lL'], $c['cfg']['lS']])->all()),
        'margin' => $union(['4.00', '5.00', '6.00'], collect($cards)->flatMap(fn ($c) => [$c['cfg']['mL'], $c['cfg']['mS']])->all()),
    ];
@endphp

<x-app-layout active="accounts" :title="'Kraite — Accounts'">

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
            <a href="{{ route('accounts.edit') }}" class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover no-underline">
                <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/>Refresh
            </a>
        </div>
    </div>

    <div x-data="{ openIdx: 0 }">
        <div class="flex items-center justify-between gap-3 mb-4">
            <span class="font-mono text-[10.5px] font-semibold tracking-[0.12em] uppercase text-fg-mute">Your exchange accounts · {{ count($cards) }}</span>
            <span class="font-mono text-[10.5px] text-fg-faint tracking-[0.04em] max-[640px]:hidden">Expand an account to configure it</span>
        </div>

        @if(count($cards) === 0)
            <div class="card">
                <div class="flex flex-col items-center justify-center text-center py-[64px] px-5">
                    <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><x-feathericon-link class="w-6 h-6" stroke-width="1.75"/></div>
                    <h4 class="font-sans font-semibold text-[17px] text-fg-1 mb-1.5">No exchange accounts yet</h4>
                    <p class="text-[13px] text-fg-3 max-w-[420px]">Connect an exchange account to let the engine trade on it.</p>
                </div>
            </div>
        @endif

        <div class="flex flex-col gap-3">
            @foreach($cards as $i => $a)
                @php
                    $key = $a['key'];
                    $cardInit = [
                        'accountId' => $a['id'],
                        'hasCredentials' => $a['hasCredentials'],
                        'isActive' => $a['isActive'],
                        'subscriptionActive' => $a['subscriptionActive'],
                        'openPositionsCount' => $a['openPositionsCount'],
                        'needsPass' => $a['needsPass'],
                        'connectivityHealth' => $a['connectivityHealth'],
                        'cfg' => $a['cfg'],
                        'servers' => $connectivityServers,
                        'urls' => [
                            'start' => route('accounts.connectivity.test'),
                            'status' => route('accounts.connectivity.status', '__UUID__'),
                            'save' => route('accounts.connectivity.credentials'),
                            'update' => route('accounts.update'),
                            'disableTrading' => route('accounts.trading.disable'),
                        ],
                    ];
                @endphp
                <div class="card card--flat overflow-hidden"
                     x-data="acctCard(@js($cardInit))"
                     @if($a['hasCredentials'] && (! $a['cfg']['canTrade'] || ! $a['subscriptionActive'])) style="border-color: color-mix(in srgb, var(--warn) 32%, var(--border));" @endif>

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
                                <span x-text="tradingActive() ? 'Trading' : 'Not trading'"></span>
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
                                        <span class="w-[7px] h-[7px] rounded-chip"
                                              :class="connectivityStatus().pulse ? 'animate-pulse-soft' : ''"
                                              :style="`background: ${connectivityStatus().c}`"
                                              :title="connectivityStatus().t"></span>
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
                                                <x-form.toggle model="cfg.canTrade" checkedExpr="cfg.canTrade" clickExpr="requestCanTradeToggle()" disabledExpr="!canChangeTrading()"/>
                                                <span class="font-mono text-[12px] font-semibold tracking-[0.03em]"
                                                      :style="`color: ${cfg.canTrade ? 'var(--pnl-up-fg)' : 'var(--fg-mute)'}`"
                                                      x-text="cfg.canTrade ? 'CAN TRADE' : 'NOT TRADING'"></span>
                                                <span x-show="!subscriptionActive" class="ml-auto font-mono text-[9.5px] tracking-[0.06em] uppercase text-warn">Subscription inactive</span>
                                                <span x-show="subscriptionActive && !connectionUsable()" class="ml-auto font-mono text-[9.5px] tracking-[0.06em] uppercase text-fg-faint">needs connection</span>
                                            </div>
                                            <div class="text-[11.5px] leading-[1.45] text-fg-mute mt-1.5" x-text="tradingHelper()"></div>
                                        </x-form.field>
                                        <x-form.field label="Portfolio quote">
                                            <x-form.select model="cfg.pq" :options="$a['quotes']" disabledExpr="quotesLocked()"/>
                                            <div class="text-[11.5px] leading-[1.45] text-fg-mute mt-1.5" x-text="quoteHelper('Currency the portfolio is valued in.')"></div>
                                        </x-form.field>
                                        <x-form.field label="Trading quote">
                                            <x-form.select model="cfg.tq" :options="$a['quotes']" disabledExpr="quotesLocked()"/>
                                            <div class="text-[11.5px] leading-[1.45] text-fg-mute mt-1.5" x-text="quoteHelper('Quote currency for new positions.')"></div>
                                        </x-form.field>
                                    </x-form.group>

                                    <x-form.group title="Trading" icon="activity" hint="per position">
                                        <x-form.field label="Profit target" for="{{ $key }}-pt" help="Closes a position after the price moves this percentage in your favor.">
                                            <x-form.select model="cfg.pt" :options="$opts['pt']"/>
                                        </x-form.field>
                                        <x-form.field label="Stop-loss" for="{{ $key }}-sl" help="Closes a position after the price moves this percentage against you, limiting the loss.">
                                            <x-form.select model="cfg.sl" :options="$opts['sl']"/>
                                        </x-form.field>
                                    </x-form.group>

                                    <x-form.group title="Position slots" icon="layers" hint="max concurrent">
                                        <x-form.field label="Long slots" dir="long" help="Maximum long positions that can be open at the same time.">
                                            <x-form.select model="cfg.sL" :options="$opts['slots']" dir="long"/>
                                        </x-form.field>
                                        <x-form.field label="Short slots" dir="short" help="Maximum short positions that can be open at the same time.">
                                            <x-form.select model="cfg.sS" :options="$opts['slots']" dir="short"/>
                                        </x-form.field>
                                    </x-form.group>

                                    <x-form.group title="Leverage & margin" icon="database">
                                        <x-form.field label="Leverage — long" dir="long" help="Multiplies the size of each long position. A higher number means larger gains and losses.">
                                            <x-form.select model="cfg.lL" :options="$opts['lev']" dir="long"/>
                                        </x-form.field>
                                        <x-form.field label="Leverage — short" dir="short" help="Multiplies the size of each short position. A higher number means larger gains and losses.">
                                            <x-form.select model="cfg.lS" :options="$opts['lev']" dir="short"/>
                                        </x-form.field>
                                        <x-form.field label="Margin % — long" dir="long" help="Uses up to this percentage of the trading balance as margin for each new long position.">
                                            <x-form.select model="cfg.mL" :options="$opts['margin']" dir="long"/>
                                        </x-form.field>
                                        <x-form.field label="Margin % — short" dir="short" help="Uses up to this percentage of the trading balance as margin for each new short position.">
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
                                    <div x-show="cfgError" x-cloak class="border-t border-line-soft px-6 py-3 max-[640px]:px-4">
                                        <div class="rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[12px] text-danger" x-text="cfgError"></div>
                                    </div>
                                </div>

                                {{-- ================= CONNECTIVITY ================= --}}
                                <div x-show="tab === 'connectivity'" x-cloak>
                                    {{-- failed-test / stored-disabled banner --}}
                                    <div x-show="phase === 'fail' || tradingDisabled()" x-cloak
                                         class="m-6 mb-0 rounded-control border px-4 py-3.5 flex items-start gap-3 max-[640px]:mx-4"
                                        style="border-color: color-mix(in srgb, var(--warn) 42%, transparent); background: color-mix(in srgb, var(--warn) 11%, transparent);">
                                        <span class="flex-shrink-0 mt-px text-warn"><x-feathericon-alert-triangle class="w-[17px] h-[17px]" stroke-width="1.75"/></span>
                                        <div class="flex-1 min-w-0">
                                            <div x-show="phase === 'fail'">
                                                <div class="font-sans font-semibold text-[13px] text-fg-1 leading-tight">Connectivity test failed</div>
                                                <div class="text-[12px] text-fg-3 mt-1 leading-snug">At least one Kraite server could not use these {{ $a['ex'] }} credentials. Applying this result keeps trading disabled.</div>
                                            </div>
                                            <div x-show="phase !== 'fail'">
                                                <div class="font-sans font-semibold text-[13px] text-fg-1 leading-tight">Trading is disabled on this account</div>
                                                <div class="text-[12px] text-fg-3 mt-1 leading-snug">Run a successful test from every eligible Kraite server before enabling trading.</div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- API credentials --}}
                                    <x-form.group title="API credentials" icon="key" :hint="$a['hasCredentials'] ? 'saved securely · leave blank to re-test' : 'required before first test'">
                                        <x-form.field label="API key" for="{{ $key }}-apikey">
                                            <x-form.input model="creds.key" id="{{ $key }}-apikey" mono :placeholder="$a['hasCredentials'] ? 'Paste only to replace saved key' : 'Paste API key'" disabledExpr="testing() || connSaving" changed="credChanged()"/>
                                        </x-form.field>
                                        <x-form.field label="API secret" for="{{ $key }}-apisecret">
                                            <x-form.input model="creds.secret" id="{{ $key }}-apisecret" mono secret :placeholder="$a['hasCredentials'] ? 'Paste only to replace saved secret' : 'Paste API secret'" disabledExpr="testing() || connSaving" changed="credChanged()"/>
                                        </x-form.field>
                                        @if($a['needsPass'])
                                            <x-form.field label="API passphrase" for="{{ $key }}-apipass" help="Required by this exchange.">
                                                <x-form.input model="creds.pass" id="{{ $key }}-apipass" mono secret :placeholder="$a['hasCredentials'] ? 'Paste only to replace saved passphrase' : 'Paste passphrase'" disabledExpr="testing() || connSaving" changed="credChanged()"/>
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
                                                    @click="navigator.clipboard?.writeText(@js(collect($connectivityServers)->pluck('ip_address')->implode("\n"))); copiedAll = true; setTimeout(() => copiedAll = false, 1400)"
                                                    @disabled(count($connectivityServers) === 0)
                                                    :style="copiedAll ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                                    class="appearance-none cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[30px] px-3">
                                                <span x-show="!copiedAll"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                                                <span x-show="copiedAll" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                                                <span x-text="copiedAll ? 'Copied' : 'Copy all'"></span>
                                            </button>
                                        </div>
                                        <div class="py-5 px-6 max-[640px]:px-4">
                                            <p class="text-[12px] text-fg-3 mb-3 leading-snug max-w-[480px]">Add every address below to your {{ $a['ex'] }} API key's IP restriction. <span class="text-fg-2">Missing IPs are the #1 reason a test fails.</span></p>
                                            @if(count($connectivityServers) === 0)
                                                <div class="rounded-control border border-warn/40 bg-warn/10 px-4 py-3 text-[12px] text-warn">No eligible API servers are configured. Connectivity testing is unavailable.</div>
                                            @else
                                                <div class="grid grid-cols-2 gap-2 max-[700px]:grid-cols-1">
                                                @foreach($connectivityServers as $server)
                                                    <div class="flex items-center gap-3 py-2 px-3 rounded-control border border-line-soft bg-surface-2">
                                                        <span class="font-mono text-[12.5px] font-semibold text-fg-1 tabular-nums tracking-[0.02em]">{{ $server['ip_address'] }}</span>
                                                        <span class="font-mono text-[10px] tracking-[0.07em] uppercase text-fg-mute">{{ $server['hostname'] }}</span>
                                                        <button type="button"
                                                                @click="navigator.clipboard?.writeText(@js($server['ip_address'])); copied = {{ $server['id'] }}; setTimeout(() => copied = null, 1400)"
                                                                :style="copied === {{ $server['id'] }} ? 'color: var(--pnl-up-fg); border-color: color-mix(in srgb, var(--pnl-up-fg) 40%, transparent)' : ''"
                                                                class="ml-auto appearance-none cursor-pointer inline-flex items-center gap-1.5 rounded-[7px] border border-line bg-surface-3 text-fg-2 font-mono text-[10.5px] font-semibold tracking-[0.04em] transition-colors duration-fast hover:border-line-strong hover:text-fg-1 h-[26px] px-2.5">
                                                            <span x-show="copied !== {{ $server['id'] }}"><x-feathericon-copy class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                                                            <span x-show="copied === {{ $server['id'] }}" x-cloak><x-feathericon-check class="w-[13px] h-[13px]" stroke-width="2"/></span>
                                                            <span x-text="copied === {{ $server['id'] }} ? 'Copied' : 'Copy'"></span>
                                                        </button>
                                                    </div>
                                                @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- per-server connectivity results --}}
                                    <div x-show="testing() || tested()" x-cloak class="border-b border-line-soft">
                                        <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                                            <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                                                <x-feathericon-server class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Connectivity from Kraite servers
                                            </h4>
                                            <span class="font-mono text-[10.5px] text-fg-mute tabular-nums"><span x-text="connectedCount()"></span>/<span x-text="rows.length"></span> connected</span>
                                        </div>
                                        <div class="py-5 px-6 max-[640px]:px-4">
                                            <div class="rounded-control border border-line-soft overflow-hidden bg-surface-2">
                                                <template x-for="server in rows" :key="server.id">
                                                    <div class="flex items-center gap-3 py-2.5 px-3.5 border-b border-line-soft last:border-b-0 max-[700px]:flex-wrap">
                                                        <span class="w-[18px] flex items-center justify-center flex-shrink-0">
                                                            <template x-if="server.status === 'testing'">
                                                                <span class="w-[13px] h-[13px] rounded-full border-2 border-line-strong border-t-info animate-spin"></span>
                                                            </template>
                                                            <template x-if="server.status === 'connected'">
                                                                <span class="text-pnlup"><x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/></span>
                                                            </template>
                                                            <template x-if="server.status === 'not_connected'">
                                                                <span class="text-danger"><x-feathericon-wifi-off class="w-3.5 h-3.5" stroke-width="1.75"/></span>
                                                            </template>
                                                            <template x-if="server.status === 'idle'">
                                                                <span class="w-[7px] h-[7px] rounded-chip bg-fg-faint"></span>
                                                            </template>
                                                        </span>
                                                        <span class="font-mono text-[12px] font-semibold text-fg-1 tracking-[0.02em]" x-text="server.hostname"></span>
                                                        <span class="font-mono text-[11px] text-fg-faint tabular-nums ml-auto max-[700px]:ml-0 max-[700px]:order-4 max-[700px]:w-full max-[700px]:pl-[30px]" x-text="server.ip_address"></span>
                                                        <span class="font-mono text-[10px] font-bold tracking-[0.09em] uppercase w-[88px] text-right max-[700px]:ml-auto"
                                                              :style="`color: ${resultColor(server.status)}`"
                                                              x-text="resultLabel(server.status)"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>

                                    <div x-show="connError" x-cloak class="border-b border-line-soft px-6 py-3 max-[640px]:px-4">
                                        <div class="rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[12px] text-danger" x-text="connError"></div>
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
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/><span x-text="testButtonLabel()"></span></span>
                                            </template>
                                        </button>
                                        <button type="button" @click="saveConn()" :disabled="!canSave()"
                                                :style="connDone ? 'background: var(--pnl-up-fg); color: #04140d' : ''"
                                                :class="!canSave() ? 'opacity-40 cursor-not-allowed hover:bg-accent' : ''"
                                                class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[40px] px-4 text-[12px] bg-accent text-accent-on hover:bg-accent-hover">
                                            <template x-if="connSaving">
                                                <span class="inline-flex items-center gap-[7px]"><span class="w-3.5 h-3.5 rounded-full border-2 border-[rgba(4,20,13,.35)] border-t-[#04140d] animate-spin"></span>Applying…</span>
                                            </template>
                                            <template x-if="connDone">
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-check class="w-4 h-4" stroke-width="2"/>Saved</span>
                                            </template>
                                            <template x-if="!connDone && !connSaving">
                                                <span class="inline-flex items-center gap-[7px]"><x-feathericon-shield class="w-[15px] h-[15px]" stroke-width="1.75"/><span x-text="saveButtonLabel()"></span></span>
                                            </template>
                                        </button>
                                        <span x-show="!tested() && !testing() && !credentialsDirty()" x-cloak class="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[560px]:text-center" x-text="rows.length === 0 ? 'No eligible servers configured' : (hasCredentials ? 'Uses the saved credentials without exposing them' : 'Enter API credentials to run the first test')"></span>
                                        <span x-show="!tested() && !testing() && credentialsDirty()" x-cloak class="font-mono text-[10.5px] tracking-[0.04em] text-warn max-[560px]:text-center">Replacement credentials must be tested before saving</span>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div x-show="stopTradingOpen" x-cloak x-transition.opacity.duration.200ms
                         class="fixed inset-0 z-[80] flex items-center justify-center p-4"
                         style="background: rgba(0,0,0,0.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);"
                         @mousedown="stopTradingOpen = false" @keydown.escape.window="stopTradingOpen = false">
                        <div class="w-[480px] max-w-full bg-surface border border-line-strong rounded-control shadow-3 overflow-hidden" @mousedown.stop>
                            <div class="flex items-center gap-2.5 py-3 px-5 bg-surface-2 border-b border-line-soft">
                                <span class="w-[28px] h-[28px] rounded-control flex items-center justify-center flex-shrink-0 text-warn bg-warn/10">
                                    <x-feathericon-alert-triangle class="w-4 h-4" stroke-width="1.75"/>
                                </span>
                                <h4 class="font-sans font-bold text-[15px] text-fg-1">Open positions keep running</h4>
                                <button type="button" @click="stopTradingOpen = false" class="appearance-none bg-transparent border-0 p-0 ml-auto w-[28px] h-[28px] rounded-control inline-flex items-center justify-center text-fg-mute hover:text-fg-1 hover:bg-hover transition-colors duration-fast cursor-pointer">
                                    <x-feathericon-x class="w-4 h-4" stroke-width="2"/>
                                </button>
                            </div>
                            <div class="p-5 flex flex-col gap-4">
                                <p class="text-[12.5px] text-fg-2 leading-normal">This account has <span class="font-mono font-semibold text-fg-1" x-text="openPositionsCount"></span> open <span x-text="openPositionsCount === 1 ? 'position' : 'positions'"></span>. After stopping, the bot will continue managing <span x-text="openPositionsCount === 1 ? 'it' : 'them'"></span> until closed, but it will not open new positions.</p>
                                <div class="flex items-center gap-2.5 flex-wrap">
                                    <button type="button" @click="confirmStopTrading()" class="appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] h-[40px] px-4 text-[12px] bg-accent text-accent-on hover:bg-accent-hover">Stop opening new positions</button>
                                    <button type="button" @click="stopTradingOpen = false" class="appearance-none font-sans font-semibold rounded-control border border-line-strong cursor-pointer inline-flex items-center h-[40px] px-4 text-[12px] bg-transparent text-fg-1 hover:bg-hover">Cancel</button>
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
