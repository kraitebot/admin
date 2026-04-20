<x-app-layout>
    <div x-data="accountsPage()" class="max-w-6xl">
        <x-hub-ui::page-header
            title="Accounts"
            description="Trading account overview — compare database state against live exchange data to catch drift."
        />

        {{-- Account Selector --}}
        <div class="mb-8 flex items-end gap-4 flex-wrap">
            <div class="flex-1 min-w-[280px] max-w-md">
                <label class="block text-[10px] font-semibold uppercase tracking-wider ui-text-subtle mb-2">Account</label>
                <div class="relative">
                    <select
                        x-model="selectedAccountId"
                        @change="fetchAccountData()"
                        class="w-full px-4 py-2.5 text-sm rounded-lg border ui-input appearance-none cursor-pointer font-medium"
                    >
                        <option value="">— Select an account —</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account['id'] }}">
                                {{ $account['name'] }} · {{ $account['exchange'] }} · {{ $account['user'] }}
                            </option>
                        @endforeach
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                        <x-feathericon-chevron-down class="w-4 h-4 ui-text-subtle" />
                    </div>
                </div>
            </div>
            <div x-show="selectedAccountId && !loading" x-cloak class="text-[11px] ui-text-subtle font-mono">
                ID <span class="ui-text-muted" x-text="selectedAccountId"></span>
            </div>
        </div>

        {{-- Loading --}}
        <div x-show="loading" class="flex items-center justify-center py-20">
            <x-hub-ui::spinner size="lg" />
        </div>

        {{-- Error --}}
        <div x-show="apiError && !loading" x-cloak class="mb-6">
            <x-hub-ui::alert type="error" dismissible>
                <span class="font-medium">API error</span>
                <div class="text-sm mt-1 font-mono" x-text="apiError"></div>
            </x-hub-ui::alert>
        </div>

        {{-- Content --}}
        <div x-show="accountData && !loading" x-cloak class="space-y-6">

            {{-- Hero summary card --}}
            <div class="ui-card overflow-hidden">
                <div class="p-6">
                    <div class="flex items-start gap-5 flex-wrap">
                        {{-- Avatar block --}}
                        <div
                            class="flex-shrink-0 w-14 h-14 rounded-xl flex items-center justify-center text-xl font-bold"
                            :style="exchangeStyle(selectedAccount?.exchange) + '; color: #000;'"
                            x-text="(selectedAccount?.name || '').charAt(0).toUpperCase()"
                        ></div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-3 flex-wrap">
                                <h2 class="text-xl font-semibold tracking-tight ui-text" x-text="selectedAccount?.name || '—'"></h2>
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wider"
                                    style="background-color: rgb(var(--ui-bg-elevated)); color: rgb(var(--ui-text-muted))"
                                    x-text="selectedAccount?.exchange"
                                ></span>
                            </div>
                            <p class="text-xs ui-text-subtle mt-1">
                                owned by <span class="ui-text-muted font-mono" x-text="selectedAccount?.user"></span>
                            </p>
                        </div>

                        {{-- Sync status --}}
                        <div class="flex items-center gap-3 pl-5 border-l ui-border">
                            <div class="text-right">
                                <div class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Sync status</div>
                                <div class="text-xs font-medium mt-0.5" :style="'color: ' + syncStatusColor">
                                    <span x-text="syncStatusLabel"></span>
                                </div>
                            </div>
                            <div
                                class="w-3 h-3 rounded-full"
                                :style="'background-color: ' + syncStatusColor + '; box-shadow: 0 0 0 4px ' + syncStatusColor + '25;'"
                            ></div>
                        </div>
                    </div>

                    {{-- Metrics row --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-5 border-t ui-border">
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">DB Positions</div>
                            <div class="text-2xl font-bold ui-text ui-tabular mt-1">
                                <x-hub-ui::number value="dbPositions.length" />
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Exchange Positions</div>
                            <div class="text-2xl font-bold ui-text ui-tabular mt-1">
                                <x-hub-ui::number value="exchangePositions.length" />
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Open Orders</div>
                            <div class="text-2xl font-bold ui-text ui-tabular mt-1">
                                <x-hub-ui::number value="exchangeOrders.length" />
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] font-semibold uppercase tracking-wider ui-text-subtle">Discrepancies</div>
                            <div
                                class="text-2xl font-bold ui-tabular mt-1"
                                :style="'color: ' + syncStatusColor"
                            >
                                <x-hub-ui::number value="discrepancies.length" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Discrepancies summary --}}
            <template x-if="discrepancies.length > 0">
                <x-hub-ui::alert type="warning">
                    <div class="font-semibold mb-2 text-sm">
                        <span x-text="discrepancies.length"></span>
                        <span x-text="discrepancies.length === 1 ? 'discrepancy' : 'discrepancies'"></span>
                        <span>detected</span>
                    </div>
                    <ul class="text-xs space-y-1 font-mono">
                        <template x-for="d in discrepancies" :key="d.symbol + d.issue">
                            <li class="flex items-center gap-2">
                                <span
                                    class="inline-flex items-center px-1.5 py-0 rounded text-[9px] font-semibold uppercase tracking-wider"
                                    :class="d.issue === 'db_only' ? 'ui-badge-danger' : 'ui-badge-warning'"
                                    x-text="d.issue === 'db_only' ? 'db only' : 'exchange only'"
                                ></span>
                                <span class="font-semibold" x-text="d.symbol"></span>
                                <span class="ui-text-muted" x-text="'· ' + d.message"></span>
                            </li>
                        </template>
                    </ul>
                </x-hub-ui::alert>
            </template>

            {{-- Positions comparison --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- DB Positions --}}
                <div class="ui-card overflow-hidden">
                    <div class="px-4 py-3 border-b ui-border flex items-center gap-2 ui-bg-elevated">
                        <x-feathericon-database class="w-4 h-4 ui-text-muted" />
                        <span class="text-sm font-semibold ui-text">Database</span>
                        <span class="text-[11px] ui-text-subtle">·</span>
                        <span class="text-[11px] ui-text-subtle ui-tabular" x-text="dbPositions.length + ' active'"></span>
                    </div>
                    <div class="divide-y ui-border">
                        <template x-if="dbPositions.length === 0">
                            <div class="px-6 py-10 text-center">
                                <p class="text-xs ui-text-subtle">No active positions in database</p>
                            </div>
                        </template>
                        <template x-for="pos in dbPositions" :key="pos.id">
                            <div class="px-4 py-3 transition-colors hover:ui-bg-elevated" :style="discrepancyHighlight(pos.symbol, 'db')">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-sm font-semibold ui-text" x-text="pos.symbol"></span>
                                        <span
                                            class="inline-flex items-center px-1.5 py-0 rounded text-[9px] font-semibold uppercase tracking-wider"
                                            :class="pos.direction === 'LONG' ? 'ui-badge-success' : 'ui-badge-danger'"
                                            x-text="pos.direction"
                                        ></span>
                                    </div>
                                    <span x-show="pos.orders.length > 0" class="text-[10px] ui-text-subtle font-mono ui-tabular" x-text="pos.orders.length + ' orders'"></span>
                                </div>
                                <div class="grid grid-cols-3 gap-2 text-[11px]">
                                    <div>
                                        <div class="ui-text-subtle text-[10px] uppercase tracking-wider">Qty</div>
                                        <div class="ui-text font-mono ui-tabular" x-text="pos.quantity"></div>
                                    </div>
                                    <div>
                                        <div class="ui-text-subtle text-[10px] uppercase tracking-wider">Entry</div>
                                        <div class="ui-text font-mono ui-tabular" x-text="pos.entry_price"></div>
                                    </div>
                                    <div>
                                        <div class="ui-text-subtle text-[10px] uppercase tracking-wider">Leverage</div>
                                        <div class="ui-text font-mono ui-tabular" x-text="pos.leverage + '×'"></div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Exchange Positions --}}
                <div class="ui-card overflow-hidden">
                    <div class="px-4 py-3 border-b ui-border flex items-center gap-2 ui-bg-elevated">
                        <x-feathericon-globe class="w-4 h-4 ui-text-muted" />
                        <span class="text-sm font-semibold ui-text">Exchange</span>
                        <span class="text-[11px] ui-text-subtle">·</span>
                        <span class="text-[11px] ui-text-subtle ui-tabular" x-text="exchangePositions.length + ' open'"></span>
                    </div>
                    <div class="divide-y ui-border">
                        <template x-if="exchangePositions.length === 0">
                            <div class="px-6 py-10 text-center">
                                <p class="text-xs ui-text-subtle">No open positions on exchange</p>
                            </div>
                        </template>
                        <template x-for="(pos, idx) in exchangePositions" :key="idx">
                            <div class="px-4 py-3 transition-colors hover:ui-bg-elevated" :style="discrepancyHighlight(pos.symbol, 'exchange')">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-sm font-semibold ui-text" x-text="pos.symbol"></span>
                                        <span
                                            class="inline-flex items-center px-1.5 py-0 rounded text-[9px] font-semibold uppercase tracking-wider"
                                            :class="pos.direction === 'LONG' ? 'ui-badge-success' : 'ui-badge-danger'"
                                            x-text="pos.direction"
                                        ></span>
                                    </div>
                                    <span
                                        class="text-[11px] font-mono ui-tabular"
                                        :class="parseFloat(pos.unrealized_pnl) >= 0 ? 'ui-text-success' : 'ui-text-danger'"
                                        x-text="(parseFloat(pos.unrealized_pnl) >= 0 ? '+' : '') + pos.unrealized_pnl"
                                    ></span>
                                </div>
                                <div class="grid grid-cols-3 gap-2 text-[11px]">
                                    <div>
                                        <div class="ui-text-subtle text-[10px] uppercase tracking-wider">Qty</div>
                                        <div class="ui-text font-mono ui-tabular" x-text="pos.quantity"></div>
                                    </div>
                                    <div>
                                        <div class="ui-text-subtle text-[10px] uppercase tracking-wider">Entry</div>
                                        <div class="ui-text font-mono ui-tabular" x-text="pos.entry_price"></div>
                                    </div>
                                    <div>
                                        <div class="ui-text-subtle text-[10px] uppercase tracking-wider">Unrealized</div>
                                        <div
                                            class="font-mono ui-tabular"
                                            :class="parseFloat(pos.unrealized_pnl) >= 0 ? 'ui-text-success' : 'ui-text-danger'"
                                            x-text="pos.unrealized_pnl"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Exchange Orders --}}
            <div class="ui-card overflow-hidden">
                <div class="px-4 py-3 border-b ui-border flex items-center gap-2 ui-bg-elevated">
                    <x-feathericon-list class="w-4 h-4 ui-text-muted" />
                    <span class="text-sm font-semibold ui-text">Open Orders</span>
                    <span class="text-[11px] ui-text-subtle">·</span>
                    <span class="text-[11px] ui-text-subtle ui-tabular" x-text="exchangeOrders.length + ' live'"></span>
                </div>
                <div x-show="exchangeOrders.length === 0" class="px-6 py-10 text-center">
                    <p class="text-xs ui-text-subtle">No open orders on exchange</p>
                </div>
                <div x-show="exchangeOrders.length > 0" class="overflow-x-auto">
                    <table class="w-full ui-table ui-data-table ui-data-table--sm">
                        <thead class="ui-bg-elevated">
                            <tr>
                                <th>Symbol</th>
                                <th>Type</th>
                                <th>Side</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(order, idx) in exchangeOrders" :key="idx">
                                <tr>
                                    <td class="font-mono font-semibold" x-text="order.symbol"></td>
                                    <td class="ui-text-muted" x-text="order.type"></td>
                                    <td>
                                        <span
                                            class="inline-flex items-center px-1.5 py-0 rounded text-[9px] font-semibold uppercase tracking-wider"
                                            :class="order.side === 'BUY' ? 'ui-badge-success' : 'ui-badge-danger'"
                                            x-text="order.side"
                                        ></span>
                                    </td>
                                    <td class="text-right font-mono ui-tabular" x-text="order.quantity"></td>
                                    <td class="text-right font-mono ui-tabular" x-text="order.price"></td>
                                    <td class="ui-text-subtle text-[11px]" x-text="order.status"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Empty State --}}
        <div x-show="!selectedAccountId && !loading">
            <x-hub-ui::empty-state
                title="Pick an account to inspect"
                description="Select an account above to compare its database state against live exchange data."
            >
                <x-slot:icon>
                    <x-feathericon-users class="w-full h-full" />
                </x-slot:icon>
            </x-hub-ui::empty-state>
        </div>
    </div>

    <script>
        function accountsPage() {
            return {
                selectedAccountId: '',
                loading: false,
                accountData: null,
                apiError: null,

                accounts: @json($accounts),

                exchangeColors: {
                    binance: '#F0B90B',
                    bybit:   '#F7A600',
                    kucoin:  '#23AF91',
                    bitget:  '#00B8D9',
                    kraken:  '#5741D9',
                },

                get selectedAccount() {
                    if (!this.selectedAccountId) return null;
                    return this.accounts.find(a => String(a.id) === String(this.selectedAccountId)) || null;
                },

                get dbPositions()       { return this.accountData?.db?.positions || []; },
                get exchangePositions() { return this.accountData?.exchange?.positions || []; },
                get exchangeOrders()    { return this.accountData?.exchange?.orders || []; },
                get discrepancies()     { return this.accountData?.discrepancies || []; },

                get syncStatusColor() {
                    const n = this.discrepancies.length;
                    if (n === 0) return 'rgb(var(--ui-success))';
                    if (n <= 2)  return 'rgb(var(--ui-warning))';
                    return 'rgb(var(--ui-danger))';
                },

                get syncStatusLabel() {
                    const n = this.discrepancies.length;
                    if (n === 0) return 'In sync';
                    if (n <= 2)  return 'Minor drift';
                    return 'Significant drift';
                },

                exchangeStyle(exchange) {
                    const key = (exchange || '').toLowerCase();
                    const color = this.exchangeColors[key] || 'rgb(var(--ui-primary))';
                    return 'background-color: ' + color;
                },

                discrepancyHighlight(symbol, source) {
                    const disc = this.discrepancies.find(d => d.symbol === symbol && d.type === 'position');
                    if (!disc) return '';
                    if (disc.issue === 'db_only' && source === 'db') return 'background-color: rgb(var(--ui-danger) / 0.08)';
                    if (disc.issue === 'exchange_only' && source === 'exchange') return 'background-color: rgb(var(--ui-warning) / 0.08)';
                    return '';
                },

                async fetchAccountData() {
                    if (!this.selectedAccountId) {
                        this.accountData = null;
                        return;
                    }

                    this.loading = true;
                    this.apiError = null;

                    const { ok, data } = await hubUiFetch(
                        '{{ route("accounts.data") }}?account_id=' + this.selectedAccountId,
                        { method: 'GET' }
                    );

                    if (ok) {
                        this.accountData = data;
                        if (data.api_error) {
                            this.apiError = data.api_error;
                        }
                    } else {
                        this.apiError = data.message || 'Failed to fetch account data';
                    }

                    this.loading = false;
                },
            };
        }
    </script>
</x-app-layout>
