<x-app-layout :activeHighlight="'dashboard'">
    <div x-data="userDashboard()" x-init="init()">

        <x-hub-ui::page-header
            title="Dashboard"
            description="Open positions across the lifecycle."
        />

        {{-- Account selector --}}
        <div x-show="isAdmin || accounts.length > 1" x-cloak class="mb-6 flex items-end gap-4 flex-wrap">
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

        <div x-show="!isAdmin && accounts.length === 1" x-cloak class="mb-6 text-xs ui-text-subtle">
            <span x-text="accounts[0]?.exchange + ' · ' + accounts[0]?.name"></span>
        </div>

        {{-- Empty: no accounts --}}
        <div x-show="accounts.length === 0" x-cloak>
            <x-hub-ui::empty-state
                title="No accounts yet"
                description="Once an account is wired to your user, your open positions will appear here."
            />
        </div>

        {{-- Loading --}}
        <div x-show="loading && accounts.length > 0" x-cloak class="flex items-center justify-center py-20">
            <x-hub-ui::spinner size="lg" />
        </div>

        {{-- No selection yet --}}
        <div x-show="!loading && !selectedAccountId && (isAdmin || accounts.length > 1)" x-cloak>
            <x-hub-ui::empty-state
                title="Pick an account"
                description="Choose an account above to see its open positions."
            />
        </div>

        {{-- Empty: account selected, no open positions --}}
        <div x-show="!loading && selectedAccountId && positions.length === 0" x-cloak>
            <x-hub-ui::empty-state
                title="No open positions"
                description="Nothing is open on this account right now."
            />
        </div>

        {{-- Top metrics strip — BTC reference on the left, account KPIs on
             the right. Account KPIs are stubbed until the balance feed lands. --}}
        <div x-show="!loading && selectedAccountId" x-cloak class="ui-card mb-4 px-4 py-3 flex items-center justify-between gap-4 flex-wrap">

            {{-- BTC reference --}}
            <div class="flex items-center gap-3" x-show="btc">
                <div class="w-9 h-9 rounded-full overflow-hidden flex-shrink-0 flex items-center justify-center" style="background-color: rgb(var(--ui-bg-elevated))">
                    <template x-if="btc?.image">
                        <img :src="btc.image" alt="BTC" class="w-full h-full object-cover" loading="lazy">
                    </template>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle font-semibold">BTC</span>
                        <span class="text-sm font-mono font-bold ui-text ui-tabular" x-text="btc ? '$' + formatPrice(btc.mark) : '—'"></span>
                    </div>
                    <div class="flex items-start gap-1.5 mt-1">
                        <template x-for="dot in (btc?.dots || [])" :key="dot.timeframe">
                            <div class="flex flex-col items-center gap-0.5" :title="dot.timeframe + ' — ' + dot.direction">
                                <span class="w-2.5 h-2.5 rounded-full ring-1" :style="dotColor(dot.direction)"></span>
                                <span class="text-[8px] font-mono ui-tabular ui-text-subtle leading-none" x-text="dot.timeframe"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Divider --}}
            <div class="hidden md:block self-stretch" style="width:1px;background-color:rgb(var(--ui-border))"></div>

            {{-- Black Swan posture --}}
            <div class="flex items-center gap-3" x-show="bscs">
                <div class="w-9 h-9 rounded-full flex-shrink-0 flex items-center justify-center font-mono font-bold text-sm"
                     :style="bscsTileStyle()"
                     x-text="bscs?.score ?? '—'"></div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle font-semibold">Regime</span>
                        <span class="text-sm font-semibold" :style="bscsLabelStyle()" x-text="(bscs?.band ?? 'unknown').toUpperCase()"></span>
                    </div>
                    <div class="text-[10px] ui-text-subtle leading-tight mt-0.5" x-text="bscs?.status ?? ''"></div>
                </div>
            </div>

            {{-- Divider --}}
            <div class="hidden md:block self-stretch" x-show="bscs" style="width:1px;background-color:rgb(var(--ui-border))"></div>

            {{-- Account KPIs --}}
            <div class="flex items-center gap-5 sm:gap-7 flex-wrap">
                <div class="flex flex-col">
                    <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle font-semibold">Balance</span>
                    <span class="text-base font-mono font-semibold ui-text ui-tabular" x-text="metrics?.balance != null ? '$' + formatPrice(metrics.balance) : '—'"></span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle font-semibold">PnL</span>
                    <span class="text-base font-mono font-semibold ui-tabular"
                          :style="metrics?.pnl !== null && metrics?.pnl !== undefined ? (parseFloat(metrics.pnl) < 0 ? 'color: rgb(var(--ui-danger))' : 'color: rgb(var(--ui-success))') : 'color: rgb(var(--ui-text-subtle))'"
                          x-text="metrics?.pnl != null ? (parseFloat(metrics.pnl) >= 0 ? '+' : '') + '$' + formatPrice(metrics.pnl) : '—'"
                    ></span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle font-semibold">Drawdown</span>
                    <span class="text-base font-mono font-semibold ui-tabular"
                          :style="metrics?.drawdown_pct !== null && metrics?.drawdown_pct !== undefined ? 'color: rgb(var(--ui-warning))' : 'color: rgb(var(--ui-text-subtle))'"
                          x-text="metrics?.drawdown_pct !== null && metrics?.drawdown_pct !== undefined ? metrics.drawdown_pct + '%' : '—'"
                    ></span>
                </div>
                <div class="flex flex-col">
                    <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle font-semibold">Margin Ratio</span>
                    <span class="text-base font-mono font-semibold ui-text ui-tabular"
                          x-text="metrics?.margin_ratio !== null && metrics?.margin_ratio !== undefined ? metrics.margin_ratio + '%' : '—'"
                    ></span>
                </div>

                <template x-if="metrics?.is_stub">
                    <span class="text-[10px] uppercase tracking-[0.12em] ui-text-subtle italic">stub data</span>
                </template>
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

                bscsBandColor() {
                    return ({
                        calm:     'rgb(16, 185, 129)',
                        elevated: 'rgb(245, 158, 11)',
                        fragile:  'rgb(249, 115, 22)',
                        critical: 'rgb(239, 68, 68)',
                    })[this.bscs?.band] ?? 'rgb(148, 163, 184)';
                },
                bscsTileStyle() {
                    const c = this.bscsBandColor();
                    return `background-color: ${c}1a; color: ${c}; border: 1px solid ${c}40`;
                },
                bscsLabelStyle() {
                    return `color: ${this.bscsBandColor()}`;
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
