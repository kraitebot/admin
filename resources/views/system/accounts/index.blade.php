<x-app-layout :activeSection="'system'" :activeHighlight="'accounts'">
    <div x-data="accountsPage()" class="max-w-6xl">
        <x-hub-ui::page-header
            title="Accounts"
            description="Database ⇄ Exchange reconciliation. Expand a row to see field-level drift."
        />

        {{-- Selector row --}}
        <div class="mb-6 flex items-end gap-4 flex-wrap">
            <div class="flex-1 min-w-0 sm:min-w-[280px] max-w-md w-full">
                <label class="block text-[10px] font-semibold uppercase tracking-[0.12em] ui-text-subtle mb-2">Account</label>
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
            <div x-show="selectedAccountId && !loading" x-cloak class="flex items-center gap-3">
                <span class="text-[11px] ui-text-subtle font-mono">
                    ID <span class="ui-text-muted" x-text="selectedAccountId"></span>
                </span>
                <button
                    type="button"
                    @click="fetchAccountData()"
                    :disabled="loading"
                    class="ui-btn ui-btn-secondary ui-btn-sm"
                    title="Refresh"
                >
                    <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M21.015 4.356v4.992" />
                    </svg>
                    <span>Refresh</span>
                </button>
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

        <div x-show="accountData && !loading" x-cloak class="space-y-6">

            {{-- Hero: identity + metrics strip --}}
            <div class="ui-card overflow-hidden">
                <div class="flex flex-col lg:flex-row lg:items-stretch">
                    {{-- Identity zone --}}
                    <div class="flex-1 p-5 flex items-start sm:items-center gap-4 flex-wrap min-w-0">
                        <div
                            class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center text-lg font-bold tracking-tight"
                            :style="exchangeStyle(selectedAccount?.exchange) + '; color: #000;'"
                            x-text="(selectedAccount?.name || '').charAt(0).toUpperCase()"
                        ></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-base font-semibold ui-text tracking-tight" x-text="selectedAccount?.name || '—'"></h2>
                                <span
                                    class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1.5 py-0.5 rounded ui-bg-elevated ui-text-muted"
                                    x-text="selectedAccount?.exchange"
                                ></span>
                            </div>
                            <p class="text-[11px] ui-text-subtle font-mono mt-1">
                                owned by <span class="ui-text-muted" x-text="selectedAccount?.user"></span>
                            </p>
                        </div>

                        {{-- Status pills — stacked right --}}
                        <div class="flex items-center gap-2 flex-wrap">
                            <template x-if="selectedAccount?.can_trade"><x-hub-ui::badge type="success" size="sm" :dot="true">Active</x-hub-ui::badge></template>
                            <template x-if="!selectedAccount?.can_trade"><x-hub-ui::badge type="danger" size="sm" :dot="true">Inactive</x-hub-ui::badge></template>
                            <template x-if="driftCount === 0"><x-hub-ui::badge type="success" size="sm" :dot="true">In sync</x-hub-ui::badge></template>
                            <template x-if="driftCount > 0 && driftCount <= 2">
                                <x-hub-ui::badge type="warning" size="sm" :dot="true"><span x-text="driftCount + ' drift'"></span></x-hub-ui::badge>
                            </template>
                            <template x-if="driftCount > 2">
                                <x-hub-ui::badge type="danger" size="sm" :dot="true"><span x-text="driftCount + ' drift'"></span></x-hub-ui::badge>
                            </template>
                        </div>
                    </div>

                    {{-- Metrics strip --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 border-t lg:border-t-0 lg:border-l ui-border">
                        <div class="px-5 py-4 border-l ui-border first:border-l-0">
                            <div class="text-[9px] font-semibold uppercase tracking-[0.12em] ui-text-subtle">DB Pos</div>
                            <div class="text-xl font-bold ui-text font-mono ui-tabular mt-1 leading-none" x-text="dbPositionCount"></div>
                        </div>
                        <div class="px-5 py-4 border-l ui-border">
                            <div class="text-[9px] font-semibold uppercase tracking-[0.12em] ui-text-subtle">Ex Pos</div>
                            <div class="text-xl font-bold ui-text font-mono ui-tabular mt-1 leading-none" x-text="exchangePositionCount"></div>
                        </div>
                        <div class="px-5 py-4 border-l ui-border">
                            <div class="text-[9px] font-semibold uppercase tracking-[0.12em] ui-text-subtle">Orders</div>
                            <div class="text-xl font-bold ui-text font-mono ui-tabular mt-1 leading-none" x-text="totalOrderCount"></div>
                        </div>
                        <div class="px-5 py-4 border-l ui-border">
                            <div class="text-[9px] font-semibold uppercase tracking-[0.12em] ui-text-subtle">Drift</div>
                            <div
                                class="text-xl font-bold font-mono ui-tabular mt-1 leading-none"
                                :class="driftCount === 0 ? 'ui-text-success' : (driftCount <= 2 ? 'ui-text-warning' : 'ui-text-danger')"
                                x-text="driftCount"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section header: Positions --}}
            <div class="flex items-center gap-3 pt-2 flex-wrap">
                <div class="flex items-baseline gap-2">
                    <h3 class="text-[11px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Positions</h3>
                    <span class="text-[11px] ui-text-subtle ui-tabular font-mono" x-text="'· ' + visiblePairs.length + ' / ' + pairs.length"></span>
                </div>
                <span class="flex-1"></span>
                <x-hub-ui::switch
                    x-model="onlyDrifts"
                    onColor="warning"
                    size="sm"
                    label="Only drifts"
                    labelPosition="right"
                />
            </div>

            {{-- Empty state --}}
            <template x-if="pairs.length === 0">
                <div class="ui-card px-5 py-4 flex items-center gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-lg ui-bg-elevated flex items-center justify-center">
                        <x-feathericon-check-circle class="w-4 h-4" style="color: rgb(var(--ui-success))" />
                    </div>
                    <div>
                        <div class="text-sm font-medium ui-text">No open positions</div>
                        <div class="text-[11px] ui-text-subtle mt-0.5 font-mono">Nothing on DB or exchange — account is clean.</div>
                    </div>
                </div>
            </template>

            {{-- Filter empty state --}}
            <template x-if="pairs.length > 0 && visiblePairs.length === 0">
                <div class="ui-card px-5 py-4 flex items-center gap-3">
                    <div class="flex-shrink-0 w-8 h-8 rounded-lg ui-bg-elevated flex items-center justify-center">
                        <x-feathericon-check-circle class="w-4 h-4" style="color: rgb(var(--ui-success))" />
                    </div>
                    <div>
                        <div class="text-sm font-medium ui-text">All positions in sync</div>
                        <div class="text-[11px] ui-text-subtle mt-0.5 font-mono">Toggle off <span class="ui-text-muted">Only drifts</span> to see the full list.</div>
                    </div>
                </div>
            </template>

            {{-- Pairs list --}}
            <div class="space-y-2">
                <template x-for="pair in visiblePairs" :key="pair.symbol + '|' + pair.direction">
                    <div class="ui-card overflow-hidden relative">
                        {{-- Left rail --}}
                        <span class="absolute top-0 left-0 bottom-0 w-[3px] pointer-events-none" :style="'background-color: ' + railColor(pair.status)"></span>

                        <button
                            type="button"
                            @click="togglePair(pair)"
                            class="w-full text-left pl-5 pr-4 py-3 flex items-center gap-3 hover:ui-bg-elevated transition-colors"
                        >
                            <svg
                                class="w-3 h-3 ui-text-subtle transition-transform flex-shrink-0"
                                :class="isPairOpen(pair) ? 'rotate-90' : ''"
                                fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>

                            <span class="font-mono text-sm font-semibold ui-text" x-text="pair.symbol"></span>

                            <span
                                class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1.5 py-0.5 rounded"
                                :class="pair.direction === 'LONG' ? 'ui-text-success' : 'ui-text-danger'"
                                :style="'background-color: rgb(var(' + (pair.direction === 'LONG' ? '--ui-success' : '--ui-danger') + ') / 0.1)'"
                                x-text="pair.direction"
                            ></span>

                            <span class="flex-1"></span>

                            <span class="text-[11px] ui-text-subtle font-mono ui-tabular hidden sm:inline">
                                <span x-text="pair.order_counts.total"></span> orders
                                <template x-if="pair.order_counts.total > 0">
                                    <span>
                                        · <span :class="pair.order_counts.synced === pair.order_counts.total ? 'ui-text-success' : 'ui-text-muted'" x-text="pair.order_counts.synced"></span> synced
                                    </span>
                                </template>
                            </span>

                            <template x-if="pair.status === 'synced'"><x-hub-ui::badge type="success" size="sm" :dot="true">Synced</x-hub-ui::badge></template>
                            <template x-if="pair.status === 'drift'"><x-hub-ui::badge type="warning" size="sm" :dot="true">Drift</x-hub-ui::badge></template>
                            <template x-if="pair.status === 'db_only'"><x-hub-ui::badge type="danger" size="sm" :dot="true">DB only</x-hub-ui::badge></template>
                            <template x-if="pair.status === 'exchange_only'"><x-hub-ui::badge type="warning" size="sm" :dot="true">Exchange only</x-hub-ui::badge></template>
                        </button>

                        {{-- Expanded --}}
                        <div x-show="isPairOpen(pair)" x-collapse>
                            <div class="border-t ui-border">
                                {{-- Position split --}}
                                <div class="grid grid-cols-1 md:grid-cols-2">
                                    {{-- DB column --}}
                                    <div class="p-5 md:border-r ui-border">
                                        <div class="flex items-center gap-2 mb-3">
                                            <x-feathericon-database class="w-3.5 h-3.5 ui-text-muted" />
                                            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Database</span>
                                        </div>
                                        <template x-if="pair.db">
                                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-3">
                                                @foreach (['quantity' => 'Qty', 'entry_price' => 'Entry', 'leverage' => 'Lev', 'margin' => 'Margin', 'margin_mode' => 'Mode'] as $field => $label)
                                                    <div>
                                                        <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">{{ $label }}</div>
                                                        <div
                                                            class="font-mono ui-tabular text-sm"
                                                            :class="isPositionFieldDrift(pair, '{{ $field }}') ? 'ui-text-danger font-semibold' : 'ui-text'"
                                                            x-text="(pair.db['{{ $field }}'] || '—') + ({{ $field === 'leverage' ? "'×'" : "''" }})"
                                                        ></div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </template>
                                        <template x-if="!pair.db">
                                            <div class="text-[11px] ui-text-subtle italic font-mono">Not in database</div>
                                        </template>
                                    </div>

                                    {{-- Exchange column --}}
                                    <div class="p-5 border-t md:border-t-0 ui-border">
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="flex items-center gap-2">
                                                <x-feathericon-globe class="w-3.5 h-3.5 ui-text-muted" />
                                                <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Exchange</span>
                                            </div>
                                            <template x-if="pair.exchange && pair.exchange.unrealized_pnl !== null && pair.exchange.unrealized_pnl !== undefined">
                                                <span
                                                    class="text-[11px] font-mono ui-tabular"
                                                    :class="parseFloat(pair.exchange.unrealized_pnl) >= 0 ? 'ui-text-success' : 'ui-text-danger'"
                                                    x-text="(parseFloat(pair.exchange.unrealized_pnl) >= 0 ? '+' : '') + parseFloat(pair.exchange.unrealized_pnl).toFixed(4) + ' pnl'"
                                                ></span>
                                            </template>
                                        </div>
                                        <template x-if="pair.exchange">
                                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-3">
                                                @foreach (['quantity' => 'Qty', 'entry_price' => 'Entry', 'leverage' => 'Lev', 'margin' => 'Margin', 'margin_mode' => 'Mode'] as $field => $label)
                                                    <div>
                                                        <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">{{ $label }}</div>
                                                        <div
                                                            class="font-mono ui-tabular text-sm"
                                                            :class="isPositionFieldDrift(pair, '{{ $field }}') ? 'ui-text-danger font-semibold' : (pair.exchange['{{ $field }}'] === null ? 'ui-text-subtle' : 'ui-text')"
                                                            x-text="pair.exchange['{{ $field }}'] === null || pair.exchange['{{ $field }}'] === '' ? '—' : (pair.exchange['{{ $field }}'] + ({{ $field === 'leverage' ? "'×'" : "''" }}))"
                                                        ></div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </template>
                                        <template x-if="!pair.exchange">
                                            <div class="text-[11px] ui-text-subtle italic font-mono">Not on exchange</div>
                                        </template>
                                    </div>
                                </div>

                                {{-- Orders compact comparator --}}
                                <template x-if="visibleOrders(pair).length > 0">
                                    <div class="border-t ui-border">
                                        <div class="flex items-center gap-2 px-5 py-2.5 ui-bg-elevated">
                                            <x-feathericon-list class="w-3.5 h-3.5 ui-text-muted" />
                                            <span class="text-[10px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Orders</span>
                                            <span class="text-[10px] ui-text-subtle font-mono ui-tabular" x-text="'· ' + visibleOrders(pair).length + (onlyDrifts && pair.orders.length !== visibleOrders(pair).length ? ' / ' + pair.orders.length : '')"></span>
                                        </div>
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-[11px] font-mono">
                                                <thead>
                                                    <tr class="ui-text-subtle text-[9px] uppercase tracking-[0.12em]">
                                                        <th class="text-left px-5 py-2 font-semibold" style="width: 140px">Status</th>
                                                        <th class="text-left px-3 py-2 font-semibold">Type / Side</th>
                                                        <th class="text-right px-3 py-2 font-semibold border-l ui-border" style="width: 35%">DB</th>
                                                        <th class="text-right px-5 py-2 font-semibold border-l ui-border" style="width: 35%">Exchange</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="(order, idx) in visibleOrders(pair)" :key="idx">
                                                        <tr class="border-t ui-border">
                                                            <td class="px-5 py-2.5">
                                                                <template x-if="order.status === 'synced'"><x-hub-ui::badge type="success" size="sm" :dot="true">Synced</x-hub-ui::badge></template>
                                                                <template x-if="order.status === 'drift'"><x-hub-ui::badge type="warning" size="sm" :dot="true">Drift</x-hub-ui::badge></template>
                                                                <template x-if="order.status === 'db_only'"><x-hub-ui::badge type="danger" size="sm" :dot="true">DB only</x-hub-ui::badge></template>
                                                                <template x-if="order.status === 'exchange_only'"><x-hub-ui::badge type="warning" size="sm" :dot="true">Ex only</x-hub-ui::badge></template>
                                                            </td>
                                                            <td class="px-3 py-2.5">
                                                                <div class="flex items-center gap-1.5">
                                                                    <span class="ui-text" :class="isOrderFieldDrift(order, 'type') ? 'ui-text-danger font-semibold' : ''" x-text="(order.db?.type || order.exchange?.type || '—')"></span>
                                                                    <span
                                                                        class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1 py-0.5 rounded"
                                                                        :class="(order.db?.side || order.exchange?.side) === 'BUY' ? 'ui-text-success' : 'ui-text-danger'"
                                                                        :style="'background-color: rgb(var(' + ((order.db?.side || order.exchange?.side) === 'BUY' ? '--ui-success' : '--ui-danger') + ') / 0.1)'"
                                                                        x-text="order.db?.side || order.exchange?.side || '—'"
                                                                    ></span>
                                                                </div>
                                                            </td>
                                                            <td class="px-3 py-2.5 border-l ui-border text-right ui-tabular">
                                                                <template x-if="order.db">
                                                                    <div class="space-y-0.5">
                                                                        <div>
                                                                            <span :class="isOrderFieldDrift(order, 'quantity') ? 'ui-text-danger font-semibold' : 'ui-text'" x-text="order.db.quantity"></span>
                                                                            <span class="ui-text-subtle">&nbsp;@&nbsp;</span>
                                                                            <span :class="isOrderFieldDrift(order, 'price') ? 'ui-text-danger font-semibold' : 'ui-text'" x-text="order.db.price"></span>
                                                                        </div>
                                                                        <div class="text-[10px]" :class="isOrderFieldDrift(order, 'status') ? 'ui-text-danger font-semibold' : 'ui-text-subtle'" x-text="order.db.status"></div>
                                                                    </div>
                                                                </template>
                                                                <template x-if="!order.db">
                                                                    <span class="ui-text-subtle italic">—</span>
                                                                </template>
                                                            </td>
                                                            <td class="px-5 py-2.5 border-l ui-border text-right ui-tabular">
                                                                <template x-if="order.exchange">
                                                                    <div class="space-y-0.5">
                                                                        <div>
                                                                            <span :class="isOrderFieldDrift(order, 'quantity') ? 'ui-text-danger font-semibold' : 'ui-text'" x-text="order.exchange.quantity"></span>
                                                                            <span class="ui-text-subtle">&nbsp;@&nbsp;</span>
                                                                            <span :class="isOrderFieldDrift(order, 'price') ? 'ui-text-danger font-semibold' : 'ui-text'" x-text="order.exchange.price"></span>
                                                                        </div>
                                                                        <div class="text-[10px]" :class="isOrderFieldDrift(order, 'status') ? 'ui-text-danger font-semibold' : 'ui-text-subtle'" x-text="order.exchange.status"></div>
                                                                    </div>
                                                                </template>
                                                                <template x-if="!order.exchange">
                                                                    <span class="ui-text-subtle italic">—</span>
                                                                </template>
                                                            </td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- All positions (history) --}}
            <div class="space-y-2 pt-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <x-feathericon-archive class="w-4 h-4 ui-text-muted" />
                    <h3 class="text-[11px] font-semibold uppercase tracking-[0.18em] ui-text-muted">All positions</h3>
                    <span class="text-[11px] ui-text-subtle font-mono" x-text="'· ' + historyTotal"></span>
                    <span class="flex-1"></span>
                    <span x-show="loadingHistory" class="text-[11px] ui-text-subtle flex items-center gap-2">
                        <x-hub-ui::spinner size="xs" /> loading
                    </span>
                </div>

                <template x-if="historyPositions.length === 0 && !loadingHistory">
                    <div class="ui-card px-5 py-4">
                        <div class="text-[11px] ui-text-subtle font-mono">No positions recorded for this account.</div>
                    </div>
                </template>

                <template x-if="historyPositions.length > 0">
                    <div class="ui-card overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-[11px] font-mono">
                                <thead>
                                    <tr class="ui-text-subtle text-[9px] uppercase tracking-[0.12em] ui-bg-elevated">
                                        <th class="text-left px-4 py-2.5 font-semibold" style="width: 36px"></th>
                                        <th class="text-left px-3 py-2.5 font-semibold">Symbol</th>
                                        <th class="text-left px-3 py-2.5 font-semibold">Dir</th>
                                        <th class="text-left px-3 py-2.5 font-semibold">Status</th>
                                        <th class="text-right px-3 py-2.5 font-semibold">Qty</th>
                                        <th class="text-right px-3 py-2.5 font-semibold">Entry</th>
                                        <th class="text-right px-3 py-2.5 font-semibold">Exit</th>
                                        <th class="text-right px-3 py-2.5 font-semibold">Lev</th>
                                        <th class="text-right px-3 py-2.5 font-semibold">PnL</th>
                                        <th class="text-right px-3 py-2.5 font-semibold">Orders</th>
                                        <th class="text-left px-3 py-2.5 font-semibold">Opened</th>
                                        <th class="text-left px-4 py-2.5 font-semibold">Closed</th>
                                    </tr>
                                </thead>
                                <template x-for="pos in historyPositions" :key="pos.id">
                                    <tbody>
                                        <tr class="border-t ui-border hover:ui-bg-elevated cursor-pointer" @click="toggleHistory(pos)">
                                                <td class="px-4 py-2.5">
                                                    <svg
                                                        class="w-3 h-3 ui-text-subtle transition-transform"
                                                        :class="isHistoryOpen(pos) ? 'rotate-90' : ''"
                                                        fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"
                                                    >
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                                    </svg>
                                                </td>
                                                <td class="px-3 py-2.5 font-semibold ui-text" x-text="pos.symbol"></td>
                                                <td class="px-3 py-2.5">
                                                    <span
                                                        class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1.5 py-0.5 rounded"
                                                        :class="pos.direction === 'LONG' ? 'ui-text-success' : 'ui-text-danger'"
                                                        :style="'background-color: rgb(var(' + (pos.direction === 'LONG' ? '--ui-success' : '--ui-danger') + ') / 0.1)'"
                                                        x-text="pos.direction"
                                                    ></span>
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    <span
                                                        class="text-[10px] uppercase tracking-[0.1em] font-semibold"
                                                        :class="historyStatusClass(pos.status)"
                                                        x-text="pos.status"
                                                    ></span>
                                                </td>
                                                <td class="px-3 py-2.5 text-right ui-text ui-tabular" x-text="pos.quantity"></td>
                                                <td class="px-3 py-2.5 text-right ui-text ui-tabular" x-text="pos.opening_price || '—'"></td>
                                                <td class="px-3 py-2.5 text-right ui-text-muted ui-tabular" x-text="pos.closing_price || '—'"></td>
                                                <td class="px-3 py-2.5 text-right ui-text-muted ui-tabular" x-text="pos.leverage + '×'"></td>
                                                <td class="px-3 py-2.5 text-right ui-tabular">
                                                    <template x-if="pos.pnl !== null && pos.pnl !== undefined">
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <span :class="parseFloat(pos.pnl) >= 0 ? 'ui-text-success' : 'ui-text-danger'" x-text="(parseFloat(pos.pnl) >= 0 ? '+' : '') + pos.pnl"></span>
                                                            <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle" x-text="pos.pnl_kind === 'realized' ? 'R' : 'u'"></span>
                                                        </span>
                                                    </template>
                                                    <template x-if="pos.pnl === null || pos.pnl === undefined">
                                                        <span class="ui-text-subtle">—</span>
                                                    </template>
                                                </td>
                                                <td class="px-3 py-2.5 text-right ui-text-muted ui-tabular" x-text="pos.order_count"></td>
                                                <td class="px-3 py-2.5 ui-text-subtle" x-text="pos.created_at || '—'"></td>
                                                <td class="px-4 py-2.5 ui-text-subtle" x-text="pos.closed_at || '—'"></td>
                                            </tr>
                                            <template x-if="isHistoryOpen(pos)">
                                                <tr class="border-t ui-border ui-bg-elevated">
                                                    <td></td>
                                                    <td colspan="11" class="px-3 py-3">
                                                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-3">
                                                            <div>
                                                                <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">Margin</div>
                                                                <div class="ui-text ui-tabular text-xs" x-text="pos.margin"></div>
                                                            </div>
                                                            <div>
                                                                <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">Leverage</div>
                                                                <div class="ui-text ui-tabular text-xs" x-text="pos.leverage + '×'"></div>
                                                            </div>
                                                            <div>
                                                                <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">Qty</div>
                                                                <div class="ui-text ui-tabular text-xs" x-text="pos.quantity"></div>
                                                            </div>
                                                            <div>
                                                                <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">Entry</div>
                                                                <div class="ui-text ui-tabular text-xs" x-text="pos.opening_price || '—'"></div>
                                                            </div>
                                                            <div>
                                                                <div class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle mb-1">Exit</div>
                                                                <div class="ui-text ui-tabular text-xs" x-text="pos.closing_price || '—'"></div>
                                                            </div>
                                                        </div>

                                                        <template x-if="pos.orders.length > 0">
                                                            <div class="rounded border ui-border overflow-x-auto">
                                                                <table class="w-full text-[10px] font-mono">
                                                                    <thead>
                                                                        <tr class="ui-text-subtle text-[9px] uppercase tracking-[0.12em]">
                                                                            <th class="text-left px-3 py-1.5 font-semibold">Type</th>
                                                                            <th class="text-left px-3 py-1.5 font-semibold">Side</th>
                                                                            <th class="text-left px-3 py-1.5 font-semibold">Status</th>
                                                                            <th class="text-right px-3 py-1.5 font-semibold">Qty</th>
                                                                            <th class="text-right px-3 py-1.5 font-semibold">Price</th>
                                                                            <th class="text-left px-3 py-1.5 font-semibold">Client ID</th>
                                                                            <th class="text-left px-3 py-1.5 font-semibold">Exchange ID</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <template x-for="o in pos.orders" :key="o.id">
                                                                            <tr class="border-t ui-border">
                                                                                <td class="px-3 py-1.5 ui-text-muted" x-text="o.type"></td>
                                                                                <td class="px-3 py-1.5">
                                                                                    <span
                                                                                        class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1 py-0.5 rounded"
                                                                                        :class="o.side === 'BUY' ? 'ui-text-success' : 'ui-text-danger'"
                                                                                        :style="'background-color: rgb(var(' + (o.side === 'BUY' ? '--ui-success' : '--ui-danger') + ') / 0.1)'"
                                                                                        x-text="o.side"
                                                                                    ></span>
                                                                                </td>
                                                                                <td class="px-3 py-1.5 ui-text-subtle" x-text="o.status"></td>
                                                                                <td class="px-3 py-1.5 text-right ui-text ui-tabular" x-text="o.quantity"></td>
                                                                                <td class="px-3 py-1.5 text-right ui-text ui-tabular" x-text="o.price"></td>
                                                                                <td class="px-3 py-1.5 ui-text-subtle truncate" :title="o.client_order_id" x-text="o.client_order_id || '—'"></td>
                                                                                <td class="px-3 py-1.5 ui-text-subtle" x-text="o.exchange_order_id || '—'"></td>
                                                                            </tr>
                                                                        </template>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </template>
                                                        <template x-if="pos.orders.length === 0">
                                                            <div class="text-[11px] ui-text-subtle italic font-mono">No orders recorded for this position.</div>
                                                        </template>
                                                    </td>
                                                </tr>
                                            </template>
                                    </tbody>
                                </template>
                            </table>
                        </div>

                        {{-- Pager --}}
                        <div class="px-4 py-3 border-t ui-border">
                            <x-hub-ui::pager
                                page="historyPage"
                                lastPage="historyLastPage"
                                perPage="historyPerPage"
                                total="historyTotal"
                                visiblePages="historyVisiblePages"
                                goTo="goHistoryToPage"
                            />
                        </div>
                    </div>
                </template>
            </div>

            {{-- Orphan orders --}}
            <template x-if="orphanOrders.length > 0">
                <div class="space-y-2 pt-2">
                    <div class="flex items-baseline gap-2">
                        <x-feathericon-alert-triangle class="w-4 h-4" style="color: rgb(var(--ui-warning))" />
                        <h3 class="text-[11px] font-semibold uppercase tracking-[0.18em] ui-text-muted">Orphan orders</h3>
                        <span class="text-[11px] ui-text-subtle font-mono" x-text="'· ' + orphanOrders.length"></span>
                        <span class="text-[11px] ui-text-subtle ml-auto hidden sm:inline">exchange orders with no DB parent</span>
                    </div>

                    <div class="ui-card overflow-hidden relative">
                        <span class="absolute top-0 left-0 bottom-0 w-[3px] pointer-events-none" style="background-color: rgb(var(--ui-warning))"></span>
                        <div class="divide-y ui-border">
                            <template x-for="(order, idx) in orphanOrders" :key="idx">
                                <div class="pl-5 pr-4 py-3 flex items-center gap-3 flex-wrap">
                                    <span class="font-mono text-sm font-semibold ui-text" x-text="order.symbol || '—'"></span>
                                    <span
                                        class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1.5 py-0.5 rounded ui-bg-elevated ui-text-muted"
                                        x-text="order.type"
                                    ></span>
                                    <span
                                        class="text-[9px] font-semibold uppercase tracking-[0.12em] px-1.5 py-0.5 rounded"
                                        :class="order.side === 'BUY' ? 'ui-text-success' : 'ui-text-danger'"
                                        :style="'background-color: rgb(var(' + (order.side === 'BUY' ? '--ui-success' : '--ui-danger') + ') / 0.1)'"
                                        x-text="order.side"
                                    ></span>

                                    <span class="flex-1"></span>

                                    <span class="font-mono text-xs ui-tabular ui-text-muted">
                                        <span x-text="order.quantity"></span>
                                        <span class="ui-text-subtle">&nbsp;@&nbsp;</span>
                                        <span x-text="order.price"></span>
                                    </span>
                                    <span class="text-[10px] font-mono uppercase tracking-[0.12em] ui-text-subtle" x-text="order.status"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>

    </div>

    <script>
        function accountsPage() {
            return {
                selectedAccountId: '',
                loading: false,
                accountData: null,
                apiError: null,
                openPairs: {},
                onlyDrifts: false,
                _loadedAccountId: null,

                // History (paginated)
                historyPositions: [],
                historyPage: 1,
                historyLastPage: 1,
                historyPerPage: 25,
                historyTotal: 0,
                loadingHistory: false,
                expandedHistory: {},

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

                get pairs()         { return this.accountData?.pairs || []; },
                get orphanOrders()  { return this.accountData?.orphan_orders || []; },

                get visiblePairs() {
                    if (!this.onlyDrifts) return this.pairs;
                    return this.pairs.filter(p => p.status !== 'synced');
                },

                visibleOrders(pair) {
                    if (!this.onlyDrifts) return pair.orders;
                    return (pair.orders || []).filter(o => o.status !== 'synced');
                },

                get dbPositionCount()       { return this.pairs.filter(p => p.db).length; },
                get exchangePositionCount() { return this.pairs.filter(p => p.exchange).length; },
                get totalOrderCount()       { return this.pairs.reduce((s, p) => s + p.order_counts.total, 0) + this.orphanOrders.length; },
                get driftCount() {
                    return this.pairs.filter(p => p.status !== 'synced').length + this.orphanOrders.length;
                },

                exchangeStyle(exchange) {
                    const key = (exchange || '').toLowerCase();
                    const color = this.exchangeColors[key] || 'rgb(var(--ui-primary))';
                    return 'background-color: ' + color;
                },

                pairKey(pair) { return pair.symbol + '|' + pair.direction; },
                togglePair(pair) { const k = this.pairKey(pair); this.openPairs[k] = !this.openPairs[k]; },
                isPairOpen(pair) { return !!this.openPairs[this.pairKey(pair)]; },

                railColor(status) {
                    if (status === 'synced') return 'rgb(var(--ui-success))';
                    if (status === 'drift') return 'rgb(var(--ui-warning))';
                    if (status === 'db_only') return 'rgb(var(--ui-danger))';
                    if (status === 'exchange_only') return 'rgb(var(--ui-warning))';
                    return 'rgb(var(--ui-text-subtle))';
                },

                isPositionFieldDrift(pair, field) { return (pair.position_drift_fields || []).includes(field); },
                isOrderFieldDrift(order, field)   { return (order.drift_fields || []).includes(field); },

                toggleHistory(pos) { this.expandedHistory[pos.id] = !this.expandedHistory[pos.id]; },
                isHistoryOpen(pos) { return !!this.expandedHistory[pos.id]; },

                historyStatusClass(status) {
                    const s = (status || '').toLowerCase();
                    if (s === 'active') return 'ui-text-success';
                    if (s === 'closed') return 'ui-text-muted';
                    if (s === 'failed' || s === 'cancelled') return 'ui-text-danger';
                    if (s === 'opening' || s === 'closing' || s === 'waping' || s === 'syncing' || s === 'cancelling') return 'ui-text-warning';
                    return 'ui-text-subtle';
                },

                get historyVisiblePages() {
                    const total = this.historyLastPage;
                    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
                    const pages = [1];
                    const cur = this.historyPage;
                    if (cur > 3) pages.push('...');
                    for (let i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) pages.push(i);
                    if (cur < total - 2) pages.push('...');
                    pages.push(total);
                    return pages;
                },

                async goHistoryToPage(p) {
                    if (p === '...' || p < 1 || p > this.historyLastPage || p === this.historyPage) return;
                    this.historyPage = p;
                    await this.fetchHistory();
                },

                async fetchHistory() {
                    if (!this.selectedAccountId) { this.historyPositions = []; return; }
                    this.loadingHistory = true;
                    const url = '{{ route("system.accounts.history") }}'
                        + '?account_id=' + this.selectedAccountId
                        + '&page=' + this.historyPage
                        + '&per_page=' + this.historyPerPage;
                    const { ok, data } = await hubUiFetch(url, { method: 'GET' });
                    if (ok) {
                        this.historyPositions = data.positions;
                        this.historyPage = data.page;
                        this.historyLastPage = data.last_page;
                        this.historyPerPage = data.per_page;
                        this.historyTotal = data.total;
                    }
                    this.loadingHistory = false;
                },

                async fetchAccountData() {
                    if (!this.selectedAccountId) {
                        this.accountData = null;
                        this.historyPositions = [];
                        this.historyPage = 1;
                        this.historyTotal = 0;
                        this.historyLastPage = 1;
                        this.openPairs = {};
                        this.expandedHistory = {};
                        this._loadedAccountId = null;
                        return;
                    }

                    // Reset expansion + pagination state only when we're
                    // loading a different account. On a refresh of the same
                    // account, preserve what the operator had open.
                    const accountChanged = this._loadedAccountId !== this.selectedAccountId;
                    if (accountChanged) {
                        this.openPairs = {};
                        this.expandedHistory = {};
                        this.historyPage = 1;
                    }

                    this.loading = true;
                    this.apiError = null;
                    const { ok, data } = await hubUiFetch(
                        '{{ route("system.accounts.data") }}?account_id=' + this.selectedAccountId,
                        { method: 'GET' }
                    );
                    if (ok) {
                        this.accountData = data;
                        if (data.api_error) this.apiError = data.api_error;
                    } else {
                        this.apiError = data.message || 'Failed to fetch account data';
                    }
                    this.loading = false;
                    this._loadedAccountId = this.selectedAccountId;

                    this.fetchHistory();
                },
            };
        }
    </script>
</x-app-layout>
