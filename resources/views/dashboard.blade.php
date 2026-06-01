<x-app-layout :activeHighlight="'dashboard'">
    <div x-data="userDashboard()" x-init="init()">

        <x-hub-ui::page-header
            title="Dashboard"
            description="Open positions across the lifecycle."
        />

        {{-- Account selector --}}
        <div x-show="isAdmin || accounts.length > 1" x-cloak class="mb-5 flex items-end gap-4 flex-wrap">
            <div class="flex-1 min-w-0 sm:min-w-[280px] max-w-md w-full">
                <label class="block text-[10px] font-semibold uppercase tracking-[0.12em] ui-text-subtle mb-2">Account</label>
                <x-hub-ui::select
                    name="account_id"
                    x-model="selectedAccountId"
                    @change="fetchData()"
                    class="w-full"
                >
                    <option value="">Select account…</option>
                    <template x-for="acc in accounts" :key="acc.id">
                        <option :value="acc.id" x-text="optionLabel(acc)"></option>
                    </template>
                </x-hub-ui::select>
            </div>
        </div>

        {{-- Empty: no accounts --}}
        <div x-show="accounts.length === 0" x-cloak>
            <div class="ui-card p-8">
                <div class="mx-auto flex max-w-xl flex-col items-center gap-4 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                        <x-feathericon-home class="h-7 w-7" />
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold ui-text">No accounts yet</h2>
                        <p class="mt-1 text-sm ui-text-subtle">Create an account before positions can appear here.</p>
                    </div>
                    <a href="{{ route('accounts.edit') }}" wire:navigate class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white transition hover:bg-emerald-700">
                        <x-feathericon-plus class="h-4 w-4" />
                        <span>Open Accounts</span>
                    </a>
                </div>
            </div>
        </div>

        {{-- Loading --}}
        <div x-show="loading && accounts.length > 0" x-cloak class="flex items-center justify-center py-20">
            <x-hub-ui::spinner size="lg" />
        </div>

        {{-- No selection yet --}}
        <div x-show="!loading && accounts.length > 0 && !selectedAccountId && (isAdmin || accounts.length > 1)" x-cloak>
            <div class="ui-card p-8">
                <div class="mx-auto flex max-w-xl flex-col items-center gap-4 text-center">
                    <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-slate-100 ui-text-muted">
                        <x-feathericon-search class="h-7 w-7" />
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold ui-text">Pick an account</h2>
                        <p class="mt-1 text-sm ui-text-subtle">Choose an account to see its current trading state.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Compact account context. Keep it quiet: these are reference
             signals, not the main content of the dashboard. --}}
        <div x-show="!loading && selectedAccountId && positions.length > 0" x-cloak class="mb-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex h-9 items-center gap-2 rounded-lg border ui-border bg-white px-3 shadow-sm">
                <x-feathericon-briefcase class="h-4 w-4 ui-text-muted" />
                <span class="font-semibold ui-text" x-text="selectedAccount()?.exchange"></span>
                <span class="ui-text-subtle" x-text="selectedAccount()?.name"></span>
            </span>

            <span x-show="btc" class="inline-flex h-9 items-center gap-2 rounded-lg border ui-border bg-white px-3 shadow-sm">
                <span class="flex h-5 w-5 items-center justify-center overflow-hidden rounded-full bg-orange-100">
                    <template x-if="btc?.image">
                        <img :src="btc.image" alt="BTC" class="h-full w-full object-cover" loading="lazy">
                    </template>
                </span>
                <span class="ui-text-subtle">BTC</span>
                <span class="font-mono font-semibold ui-text ui-tabular" x-text="btc ? '$' + formatPrice(btc.mark) : '—'"></span>
            </span>

            <span x-show="bscs" class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 shadow-sm" :class="marketCheckClass()">
                <x-feathericon-shield class="h-4 w-4" />
                <span class="font-semibold" x-text="marketCheckLabel()"></span>
            </span>

            <span class="inline-flex h-9 items-center gap-2 rounded-lg border ui-border bg-white px-3 shadow-sm">
                <x-feathericon-activity class="h-4 w-4 ui-text-muted" />
                <span class="ui-text-subtle">Open trades</span>
                <span class="font-mono font-semibold ui-text ui-tabular" x-text="positions.length"></span>
            </span>

            <template x-if="metrics && !metrics.is_stub && metrics.balance !== null && metrics.balance !== undefined">
                <span class="inline-flex h-9 items-center gap-2 rounded-lg border ui-border bg-white px-3 shadow-sm">
                    <x-feathericon-dollar-sign class="h-4 w-4 ui-text-muted" />
                    <span class="ui-text-subtle">Balance</span>
                    <span class="font-mono font-semibold ui-text ui-tabular" x-text="'$' + formatPrice(metrics.balance)"></span>
                </span>
            </template>
        </div>

        {{-- Empty: account selected, no open positions --}}
        <div x-show="!loading && selectedAccountId && positions.length === 0" x-cloak class="mb-5">
            <div class="ui-card overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="p-7 sm:p-8">
                        <div class="max-w-3xl">
                            <div class="mb-6 flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg"
                                    :class="selectedAccount()?.disabled_reason ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'"
                                >
                                    <template x-if="selectedAccount()?.disabled_reason">
                                        <x-feathericon-alert-triangle class="h-6 w-6" />
                                    </template>
                                    <template x-if="!selectedAccount()?.disabled_reason">
                                        <x-feathericon-activity class="h-6 w-6" />
                                    </template>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] ui-text-subtle">Current account</p>
                                    <p class="truncate text-sm font-semibold ui-text" x-text="selectedAccount()?.exchange + ' · ' + selectedAccount()?.name"></p>
                                </div>
                            </div>

                            <h2 class="text-2xl font-semibold leading-tight ui-text" x-text="selectedAccount()?.disabled_reason ? 'Trading is paused for this account' : 'No open trades right now'"></h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 ui-text-subtle" x-text="selectedAccount()?.disabled_reason ? 'This account is set up, but Kraite will not open new trades until the exchange connection is fixed.' : 'Kraite is monitoring the market. When a trade opens, it will appear here with entry, risk, profit target, and progress.'"></p>

                            <div x-show="selectedAccount()?.disabled_reason" x-cloak class="mt-5 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                <x-feathericon-info class="mt-0.5 h-4 w-4 shrink-0" />
                                <p>Allow the Kraite IP addresses in your exchange account, then test the connection again.</p>
                            </div>

                            <div class="mt-6 flex flex-wrap gap-2">
                                <a href="{{ route('accounts.edit') }}" wire:navigate class="inline-flex h-10 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition"
                                   :class="selectedAccount()?.disabled_reason ? 'bg-red-700 text-white hover:bg-red-800' : 'bg-emerald-600 text-white hover:bg-emerald-700'"
                                >
                                    <x-feathericon-settings class="h-4 w-4" />
                                    <span x-text="selectedAccount()?.disabled_reason ? 'Fix connection' : 'Manage account'"></span>
                                </a>
                                <a href="{{ route('accounts.positions') }}" wire:navigate class="inline-flex h-10 items-center justify-center gap-2 rounded-lg border ui-border bg-white px-4 text-sm font-semibold ui-text transition hover:bg-slate-50">
                                    <x-feathericon-bar-chart-2 class="h-4 w-4" />
                                    <span>Positions</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="border-t ui-border bg-slate-50/60 p-6 lg:border-l lg:border-t-0">
                        <div class="space-y-5">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] ui-text-subtle">Snapshot</p>
                                <p class="mt-1 text-sm ui-text-subtle">Nothing is using margin on this account.</p>
                            </div>

                            <div class="space-y-3">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-2 ui-text-subtle">
                                        <x-feathericon-activity class="h-4 w-4" />
                                        <span class="text-sm">Open trades</span>
                                    </div>
                                    <span class="font-mono text-sm font-semibold ui-text">0</span>
                                </div>

                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-2 ui-text-subtle">
                                        <x-feathericon-shield class="h-4 w-4" />
                                        <span class="text-sm">Trading</span>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.12em]"
                                        :class="selectedAccount()?.disabled_reason ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800'"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :class="selectedAccount()?.disabled_reason ? 'bg-red-500' : 'bg-emerald-500'"></span>
                                        <span x-text="selectedAccount()?.disabled_reason ? 'Paused' : 'Ready'"></span>
                                    </span>
                                </div>

                                <div x-show="btc" class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-2 ui-text-subtle">
                                        <span class="flex h-4 w-4 items-center justify-center overflow-hidden rounded-full bg-orange-100">
                                            <template x-if="btc?.image">
                                                <img :src="btc.image" alt="BTC" class="h-full w-full object-cover" loading="lazy">
                                            </template>
                                        </span>
                                        <span class="text-sm">BTC</span>
                                    </div>
                                    <span class="font-mono text-sm font-semibold ui-text ui-tabular" x-text="btc ? '$' + formatPrice(btc.mark) : '—'"></span>
                                </div>

                                <div x-show="bscs" class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-2 ui-text-subtle">
                                        <x-feathericon-shield class="h-4 w-4" />
                                        <span class="text-sm">Market</span>
                                    </div>
                                    <span class="text-sm font-semibold" :class="marketCheckTextClass()" x-text="marketCheckLabel()"></span>
                                </div>
                            </div>

                            <div class="border-t ui-border pt-4">
                                <p class="text-xs leading-5 ui-text-subtle" x-text="selectedAccount()?.disabled_reason ? 'Fix the connection before expecting new trades here.' : 'Leave this page open to watch new trades as they appear.'"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tile grid --}}
        <div x-show="!loading && positions.length > 0" x-cloak class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            <template x-for="position in positions" :key="position.id">
                <div class="ui-card p-2.5 relative overflow-hidden">
                    {{-- Transient-state badge stays in full colour as the
                         only signal cutting through the desaturated tile. --}}
                    <template x-if="position.status !== 'active'">
                        <div class="absolute top-1.5 right-1.5 z-30 flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[9px] font-semibold uppercase tracking-[0.08em]"
                             :style="statusStyle(position.status)"
                        >
                            <span class="w-1.5 h-1.5 rounded-full animate-pulse" :style="`background-color: ${statusDotColor(position.status)}`"></span>
                            <span x-text="statusLabel(position.status)"></span>
                        </div>
                    </template>

                    <div class="flex flex-col gap-2">

                    {{-- Tile header: icon + token meta + direction badge --}}
                    <div class="flex items-center gap-2">
                        {{-- Token icon --}}
                        <div class="w-7 h-7 rounded-full overflow-hidden flex-shrink-0 flex items-center justify-center" style="background-color: rgb(var(--ui-bg-elevated))">
                            <template x-if="position.token_image">
                                <img :src="position.token_image" :alt="position.token" class="w-full h-full object-cover" loading="lazy">
                            </template>
                            <template x-if="!position.token_image">
                                <span class="text-[9px] font-bold ui-text-muted" x-text="(position.token || '?').slice(0, 3)"></span>
                            </template>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-1.5 flex-wrap">
                                <span class="text-[13px] font-bold ui-text leading-none" x-text="position.token"></span>
                                <span class="text-[10px] ui-text-subtle truncate leading-none" x-text="position.token_name"></span>
                            </div>
                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                <span
                                    class="inline-flex items-center gap-0.5 text-[9px] uppercase font-semibold tracking-[0.08em] px-1 py-0.5 rounded"
                                    :style="directionStyle(position)"
                                >
                                    <svg x-show="position.direction === 'LONG'" class="w-2 h-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5M5 12l7-7 7 7" />
                                    </svg>
                                    <svg x-show="position.direction === 'SHORT'" class="w-2 h-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12l7 7 7-7" />
                                    </svg>
                                    <span x-text="position.direction + ' ' + position.leverage + 'x'"></span>
                                </span>

                                <span class="inline-flex items-center gap-0.5 text-[9px] ui-text-subtle">
                                    <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="9" />
                                        <path stroke-linecap="round" d="M12 7v5l3 2" />
                                    </svg>
                                    <span x-text="position.age_human || '—'"></span>
                                </span>
                            </div>
                        </div>

                        {{-- Timeframe direction dots, top-right (hidden on transient
                             states so they don't collide with the status badge). --}}
                        <div x-show="position.status === 'active'" class="flex items-start gap-1 flex-shrink-0 self-start">
                            <template x-for="dot in position.timeframe_dots" :key="dot.timeframe">
                                <div class="flex flex-col items-center gap-0.5" :title="dot.timeframe + ' — ' + dot.direction">
                                    <span
                                        class="w-2 h-2 rounded-full ring-1"
                                        :style="dotColor(dot.direction)"
                                    ></span>
                                    <span class="text-[7px] font-mono ui-tabular ui-text-subtle leading-none" x-text="dot.timeframe"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Lifecycle bar — outer padding gives TP/limit labels
                         vertical breathing room above & below the track;
                         inner mx-3 indents the relative container so the
                         0%/100% markers don't clip against the card border. --}}
                    <div class="pt-4 pb-3">
                        <div class="relative h-8 mx-3">
                            {{-- Track --}}
                            <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 h-1 rounded-full" style="background-color: rgb(var(--ui-border))"></div>

                            {{-- Stress fill — spans current TP marker → current price marker.
                                 Visualises how far price has drifted from TP into the limit ladder. --}}
                            <div
                                class="absolute top-1/2 -translate-y-1/2 h-1 rounded-full transition-all duration-300"
                                :style="`left: ${tickFraction(position, position.profit_price) * 100}%; width: ${Math.max(0, currentPriceFraction(position) - tickFraction(position, position.profit_price)) * 100}%; background-color: ${stressColor(position.alpha_path_pct, position.status)}`"
                            ></div>

                            {{-- Original TP ghost marker (where TP was before any limit filled — only
                                 shown when at least one limit has filled, so the operator can read how far
                                 the WAP-recalculated TP has drifted from where the position opened). --}}
                            <div
                                x-show="position.filled_count > 0"
                                class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 z-[5]"
                                :style="`left: ${tickFraction(position, position.first_profit_price) * 100}%`"
                                :title="'Original TP ' + formatPrice(position.first_profit_price)"
                            >
                                <div class="w-0.5 h-5 rounded-full" style="background-color: rgb(var(--ui-text-subtle))"></div>
                            </div>

                            {{-- Current TP marker --}}
                            <div
                                class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 z-10 flex flex-col items-center"
                                :style="`left: ${tickFraction(position, position.profit_price) * 100}%`"
                                :title="'TP ' + formatPrice(position.profit_price)"
                            >
                                <div class="absolute -top-2.5 text-[8px] font-bold uppercase tracking-wider leading-none" :style="`color: ${tpColor(position)}`">TP</div>
                                <div class="w-0.5 h-5 rounded-full" :style="`background-color: ${tpColor(position)}`"></div>
                            </div>

                            {{-- Unfilled limit ticks --}}
                            <template x-for="limit in unfilledLimits(position)" :key="limit.index">
                                <div
                                    class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 flex flex-col items-center"
                                    :style="`left: ${tickFraction(position, limit.price) * 100}%`"
                                    :title="'Limit ' + limit.index + ' · ' + formatPrice(limit.price)"
                                >
                                    <div class="w-0.5 h-3.5 rounded-full" style="background-color: rgb(var(--ui-text-subtle))"></div>
                                    <div class="absolute -bottom-3.5 text-[9px] font-mono ui-text-subtle ui-tabular leading-none" x-text="limit.index"></div>
                                </div>
                            </template>

                            {{-- Current price marker (filled chevron-style dot) --}}
                            <div
                                class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 z-20"
                                :style="`left: ${currentPriceFraction(position) * 100}%`"
                                :title="'Current ' + formatPrice(position.current_price)"
                            >
                                <div class="w-3 h-3 rounded-full border-2" :style="currentPriceMarkerStyle(position)"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Position size + live PnL --}}
                    <div class="grid grid-cols-2 gap-1.5">
                        <div class="ui-bg-elevated rounded-md py-1 px-1 flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
                                </svg>
                                <span>Size</span>
                            </div>
                            <div class="text-[13px] font-bold font-mono ui-tabular ui-text mt-0.5"
                                 x-text="position.size !== null ? '$' + formatPrice(position.size) : '—'"></div>
                        </div>
                        <div class="ui-bg-elevated rounded-md py-1 px-1 flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                                </svg>
                                <span>PnL</span>
                            </div>
                            <div class="text-sm font-bold font-mono ui-tabular mt-0.5"
                                 :style="pnlColor(position.pnl, position.status)"
                                 x-text="position.pnl !== null ? (parseFloat(position.pnl) >= 0 ? '+' : '') + '$' + formatPrice(position.pnl) : '—'"></div>
                        </div>
                    </div>

                    {{-- Readouts: AlphaPath · AlphaLimit · Filled --}}
                    <div class="grid grid-cols-3 gap-1.5">
                        <div class="ui-bg-elevated rounded-md py-1 px-1 flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" d="M3 12h18M3 6h18M3 18h12" />
                                </svg>
                                <span>Path</span>
                            </div>
                            <div
                                class="text-base font-bold font-mono ui-tabular mt-0.5"
                                :style="`color: ${stressColor(position.alpha_path_pct, position.status)}`"
                                x-text="position.alpha_path_pct + '%'"
                            ></div>
                        </div>
                        <div class="ui-bg-elevated rounded-md py-1 px-1 flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" d="M5 12h14M13 6l6 6-6 6" />
                                </svg>
                                <span>Limit</span>
                            </div>
                            <div class="text-[13px] font-bold font-mono ui-tabular ui-text mt-0.5" x-text="position.alpha_limit_pct + '%'"></div>
                        </div>
                        <div class="ui-bg-elevated rounded-md py-1 px-1 flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" d="M5 12l5 5L20 7" />
                                </svg>
                                <span>Filled</span>
                            </div>
                            <div class="text-[13px] font-bold font-mono ui-tabular ui-text mt-0.5" x-text="position.filled_count + ' / ' + position.total_limits"></div>
                        </div>
                    </div>

                    {{-- Prices grid --}}
                    <div class="grid grid-cols-3 gap-1.5 pt-1.5 border-t ui-border">
                        <div class="flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" d="M12 8v4l3 3" />
                                </svg>
                                <span>Orig TP</span>
                            </div>
                            <div class="text-[11px] font-mono ui-text-muted ui-tabular mt-0.5 leading-none" x-text="formatPrice(position.first_profit_price)"></div>
                        </div>
                        <div class="flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider" :style="`color: ${tpColor(position)}`">
                                <svg class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" d="M12 19V5M5 12l7-7 7 7" />
                                </svg>
                                <span>TP</span>
                            </div>
                            <div class="text-[11px] font-mono ui-tabular mt-0.5 leading-none" :style="`color: ${tpColor(position)}`" x-text="formatPrice(position.profit_price)"></div>
                        </div>
                        <div class="flex flex-col items-center">
                            <div class="flex items-center gap-0.5 text-[9px] uppercase tracking-wider ui-text-subtle">
                                <svg class="w-2.5 h-2.5 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" d="M12 5v14M5 12l7 7 7-7" />
                                </svg>
                                <span>Next</span>
                            </div>
                            <div class="text-xs font-mono ui-text-muted ui-tabular mt-0.5" x-text="formatPrice(position.next_limit_price)"></div>
                        </div>
                    </div>

                    </div>

                </div>
            </template>
        </div>

    </div>


    <script>
        function userDashboard() {
            return {
                accounts: @json($accounts),
                isAdmin: @json($isAdmin),
                selectedAccountId: '',
                loading: false,
                positions: [],
                metrics: null,
                btc: null,
                bscs: null,
                _interval: null,

                marketCheckLabel() {
                    if (!this.bscs) {
                        return 'Market check unavailable';
                    }

                    if (this.bscs.blocked) {
                        return 'New trades paused';
                    }

                    return ({
                        calm: 'Market normal',
                        elevated: 'Market active',
                        fragile: 'Trade size reduced',
                        critical: 'New trades paused',
                    })[this.bscs.band] ?? 'Market check unavailable';
                },

                marketCheckClass() {
                    if (!this.bscs) {
                        return 'border-slate-200 bg-white text-slate-600';
                    }

                    if (this.bscs.blocked || this.bscs.band === 'critical') {
                        return 'border-red-200 bg-red-50 text-red-800';
                    }

                    if (this.bscs.band === 'fragile') {
                        return 'border-orange-200 bg-orange-50 text-orange-800';
                    }

                    if (this.bscs.band === 'elevated') {
                        return 'border-amber-200 bg-amber-50 text-amber-800';
                    }

                    return 'border-emerald-200 bg-emerald-50 text-emerald-800';
                },

                marketCheckTextClass() {
                    if (!this.bscs) {
                        return 'text-slate-600';
                    }

                    if (this.bscs.blocked || this.bscs.band === 'critical') {
                        return 'text-red-700';
                    }

                    if (this.bscs.band === 'fragile') {
                        return 'text-orange-700';
                    }

                    if (this.bscs.band === 'elevated') {
                        return 'text-amber-700';
                    }

                    return 'text-emerald-700';
                },

                init() {
                    // Sysadmin always starts blank — the dashboard is a
                    // cross-user inspection surface for them, so an
                    // auto-restored selection from a prior session would
                    // misrepresent which account they're looking at. They
                    // pick explicitly every visit. For regular users we
                    // still auto-select / restore so the UX stays one-tap.
                    if (this.isAdmin) {
                        this.selectedAccountId = '';
                    } else {
                        const stored = localStorage.getItem(this.storageKey);
                        if (stored && this.accounts.some(a => String(a.id) === stored)) {
                            this.selectedAccountId = stored;
                            this.fetchData();
                        } else if (this.accounts.length === 1) {
                            this.selectedAccountId = String(this.accounts[0].id);
                            this.fetchData();
                        }

                        this.$watch('selectedAccountId', (value) => {
                            if (value) localStorage.setItem(this.storageKey, value);
                            else       localStorage.removeItem(this.storageKey);
                        });
                    }

                    // 10s background poll. Alpine keys the x-for on
                    // position.id, so only the deltas patch into the DOM —
                    // no full grid rerender, no scroll loss, no flicker.
                    this._interval = setInterval(() => {
                        if (this.selectedAccountId) {
                            this.fetchData({ silent: true });
                        }
                    }, 10000);
                },

                get storageKey() {
                    return 'kraite-dashboard-account-' + ({{ auth()->id() }});
                },

                optionLabel(acc) {
                    return this.isAdmin
                        ? acc.owner + ' · ' + acc.name
                        : acc.exchange + ' · ' + acc.name;
                },

                selectedAccount() {
                    return this.accounts.find(acc => String(acc.id) === String(this.selectedAccountId)) || null;
                },

                async fetchData({ silent = false } = {}) {
                    if (!this.selectedAccountId) {
                        this.positions = [];
                        return;
                    }

                    // Show the spinner only on cold loads / account switch —
                    // background polls run silent so the grid doesn't flash.
                    if (!silent) {
                        this.loading = this.positions.length === 0;
                    }

                    const { ok, data } = await hubUiFetch(
                        '{{ route("dashboard.data") }}?account_id=' + this.selectedAccountId,
                        { method: 'GET' }
                    );

                    if (ok) {
                        this.positions = data.positions || [];
                        this.metrics = data.metrics ?? null;
                        this.btc = data.btc ?? null;
                        this.bscs = data.bscs ?? null;
                    }

                    this.loading = false;
                },

                formatPrice(value) {
                    // Server formats prices via api_format_price (per-symbol
                    // tick_size + precision). Pass-through and just guard
                    // null / non-numeric. Values come back as strings already.
                    if (value === null || value === undefined || value === '') return '—';
                    return String(value);
                },

                /**
                 * Map a tick price to its fractional position on the bar.
                 * Direction-aware: LONG has TP above, ladder below; SHORT
                 * inverts. The bar always reads "TP on the left, deepest
                 * limit on the right" regardless of direction.
                 */
                tickFraction(position, price) {
                    const start = parseFloat(position.first_profit_price);
                    const end   = parseFloat(position.last_limit_price);
                    const p     = parseFloat(price);
                    if (!isFinite(start) || !isFinite(end) || !isFinite(p)) return 0;
                    if (start === end) return 0;
                    const f = (start - p) / (start - end);
                    return Math.max(0, Math.min(1, f));
                },

                currentPriceFraction(position) {
                    const raw = this.tickFraction(position, position.current_price);
                    const tp  = this.tickFraction(position, position.profit_price);
                    // Clamp at the current TP marker. Going left-of-TP means
                    // price is on the profit side of TP (LONG: above; SHORT:
                    // below) — visually a "TP already hit" state that can't
                    // be a steady state. Park the dot on the TP marker so
                    // the operator reads "TP imminent" instead.
                    return Math.max(raw, tp);
                },

                unfilledLimits(position) {
                    return (position.limits || []).filter(l => !l.filled);
                },

                isTransient(position) {
                    return position && position.status !== 'active';
                },

                pnlColor(pnl, status) {
                    if (status && status !== 'active') return 'color: rgb(var(--ui-text-muted))';
                    if (pnl === null || pnl === undefined) return 'color: rgb(var(--ui-text-subtle))';
                    const v = parseFloat(pnl) || 0;
                    if (v < 0) return 'color: rgb(var(--ui-danger))';
                    if (v > 0) return 'color: rgb(var(--ui-success))';
                    return 'color: rgb(var(--ui-text-muted))';
                },

                stressColor(pct, status) {
                    if (status && status !== 'active') return 'rgb(var(--ui-text-muted))';
                    const v = parseFloat(pct) || 0;
                    if (v >= 75) return 'rgb(var(--ui-danger))';
                    if (v >= 50) return 'rgb(var(--ui-warning))';
                    return 'rgb(var(--ui-success))';
                },

                directionStyle(position) {
                    if (this.isTransient(position)) {
                        return 'background-color: rgb(var(--ui-bg-elevated)); color: rgb(var(--ui-text-muted))';
                    }
                    return position.direction === 'LONG'
                        ? 'background-color: rgb(var(--ui-success) / 0.15); color: rgb(var(--ui-success))'
                        : 'background-color: rgb(var(--ui-danger) / 0.15); color: rgb(var(--ui-danger))';
                },

                tpColor(position) {
                    return this.isTransient(position)
                        ? 'rgb(var(--ui-text-muted))'
                        : 'rgb(var(--ui-success))';
                },

                currentPriceMarkerStyle(position) {
                    const c = this.isTransient(position)
                        ? 'rgb(var(--ui-text-muted))'
                        : 'rgb(var(--ui-primary))';
                    const ring = this.isTransient(position)
                        ? 'rgb(var(--ui-text-muted) / 0.2)'
                        : 'rgb(var(--ui-primary) / 0.2)';
                    return `background-color: rgb(var(--ui-bg-card)); border-color: ${c}; box-shadow: 0 0 0 2px ${ring};`;
                },

                dotColor(direction) {
                    if (direction === 'up')   return 'background-color: rgb(var(--ui-success)); --tw-ring-color: rgb(var(--ui-success) / 0.35);';
                    if (direction === 'down') return 'background-color: rgb(var(--ui-danger));  --tw-ring-color: rgb(var(--ui-danger) / 0.35);';
                    if (direction === 'flat') return 'background-color: rgb(var(--ui-text-muted)); --tw-ring-color: rgb(var(--ui-text-muted) / 0.35);';
                    return 'background-color: rgb(var(--ui-border-light)); --tw-ring-color: rgb(var(--ui-border-light) / 0.35);';
                },

                statusLabel(status) {
                    return ({
                        new:        'Queued',
                        opening:    'Opening',
                        syncing:    'Syncing',
                        waping:     'Adjusting',
                        closing:    'Closing',
                        cancelling: 'Cancelling',
                    })[status] || status;
                },

                statusStyle(status) {
                    const closing = ['closing', 'cancelling'].includes(status);
                    if (closing) {
                        return 'background-color: rgb(var(--ui-danger) / 0.15); color: rgb(var(--ui-danger))';
                    }
                    return 'background-color: rgb(var(--ui-warning) / 0.15); color: rgb(var(--ui-warning))';
                },

                statusDotColor(status) {
                    return ['closing', 'cancelling'].includes(status)
                        ? 'rgb(var(--ui-danger))'
                        : 'rgb(var(--ui-warning))';
                },
            };
        }
    </script>
</x-app-layout>
