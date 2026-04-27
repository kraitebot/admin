<x-app-layout :activeSection="'accounts'" :activeHighlight="'edit-account'">
    <div x-data="editAccountPage()" x-init="init()" class="max-w-5xl">

        <x-hub-ui::page-header
            title="Edit Account"
            description="Tune profit / stop / leverage / slot sizing per account. Changes apply immediately."
        />

        @if (session('status'))
            <x-hub-ui::alert type="success" class="mb-4">{{ session('status') }}</x-hub-ui::alert>
        @endif

        @if ($errors->any())
            <x-hub-ui::alert type="error" class="mb-4">
                <ul class="list-disc list-inside text-sm">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </x-hub-ui::alert>
        @endif

        {{-- Account picker — hidden when the user owns exactly one
             account (auto-selected). Sysadmin always sees it. --}}
        <div x-show="isAdmin || accounts.length > 1" x-cloak class="mb-6 flex items-end gap-4 flex-wrap">
            <div class="flex-1 min-w-0 sm:min-w-[280px] max-w-md w-full">
                <label class="block text-[10px] font-semibold uppercase tracking-[0.12em] ui-text-subtle mb-2">Account</label>
                <div class="relative">
                    <select
                        x-model="selectedAccountId"
                        @change="hydrate()"
                        class="w-full px-4 py-2.5 text-sm rounded-lg border ui-input appearance-none cursor-pointer font-medium"
                    >
                        <option value="">— Select an account —</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account['id'] }}">
                                {{ $account['name'] }} · {{ $account['exchange'] }} · {{ $account['owner'] }}
                            </option>
                        @endforeach
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                        <x-feathericon-chevron-down class="w-4 h-4 ui-text-subtle" />
                    </div>
                </div>
            </div>
            <span x-show="selectedAccountId" x-cloak class="text-[11px] ui-text-subtle font-mono">
                ID <span class="ui-text-muted" x-text="selectedAccountId"></span>
            </span>
        </div>

        {{-- Form --}}
        <template x-if="selectedAccountId && form">
            <form method="POST" action="{{ route('accounts.update') }}" class="space-y-6">
                @csrf
                @method('PATCH')
                <input type="hidden" name="account_id" :value="selectedAccountId">

                {{-- Identity --}}
                <div class="ui-card p-4">
                    <div class="flex items-start gap-2 mb-4 pb-3 border-b ui-border-light">
                        <x-feathericon-tag class="w-4 h-4 ui-text-muted mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <h2 class="text-[13px] font-semibold ui-text leading-tight">Identity</h2>
                            <p class="text-[11px] ui-text-info leading-snug mt-0.5 flex items-start gap-1 opacity-90">
                                <x-feathericon-info class="w-3 h-3 mt-0.5 flex-shrink-0" />
                                <span>Display label + the quote currencies that drive trading-pair selection. Only assets you hold show up.</span>
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::input
                            name="name"
                            label="Name"
                            x-model="form.name"
                            hint="Free-text label only — doesn't affect trading."
                            required
                        />

                        <div class="self-start">
                            <label class="flex items-center gap-2 cursor-pointer pt-7">
                                <input type="checkbox" name="can_trade" value="1" x-model="form.can_trade" class="w-4 h-4 rounded border-slate-300 text-emerald-600">
                                <span class="text-sm ui-text">Can trade</span>
                            </label>
                            <p class="text-xs ui-text-subtle mt-1">Off → ingestion stops opening new positions. Existing positions keep running their close logic.</p>
                        </div>

                        <x-hub-ui::select name="portfolio_quote" label="Portfolio quote" x-model="form.portfolio_quote"
                                          hint="Currency the account's wallet is denominated in. Margin and PnL accounting use this asset.">
                            <template x-if="loadingQuotes"><option value="">Loading…</option></template>
                            <template x-if="!loadingQuotes && availableQuotes.length === 0">
                                <option value="">— No assets on exchange —</option>
                            </template>
                            <template x-for="q in availableQuotes" :key="q">
                                <option :value="q" x-text="q"></option>
                            </template>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="trading_quote" label="Trading quote" x-model="form.trading_quote"
                                          hint="Quote half of every trading pair the engine opens (e.g. BTC/USDT). Doesn't have to match the portfolio quote.">
                            <template x-if="loadingQuotes"><option value="">Loading…</option></template>
                            <template x-if="!loadingQuotes && availableQuotes.length === 0">
                                <option value="">— No assets on exchange —</option>
                            </template>
                            <template x-for="q in availableQuotes" :key="q">
                                <option :value="q" x-text="q"></option>
                            </template>
                        </x-hub-ui::select>
                    </div>
                </div>

                {{-- Trading --}}
                <div class="ui-card p-4">
                    <div class="flex items-start gap-2 mb-4 pb-3 border-b ui-border-light">
                        <x-feathericon-trending-up class="w-4 h-4 ui-text-muted mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <h2 class="text-[13px] font-semibold ui-text leading-tight">Trading</h2>
                            <p class="text-[11px] ui-text-info leading-snug mt-0.5 flex items-start gap-1 opacity-90">
                                <x-feathericon-info class="w-3 h-3 mt-0.5 flex-shrink-0" />
                                <span>Profit + stop-loss thresholds. SL only fires once the deepest ladder rung has been touched.</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-1.5 mb-4 px-2.5 py-2 rounded-md text-[10.5px] leading-snug" style="background-color: rgb(var(--ui-warning) / 0.08); color: rgb(var(--ui-warning))">
                        <x-feathericon-alert-triangle class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        <span><strong>Risk:</strong> realised loss per stop ≈ <span class="font-mono">SL% × cumulative-qty × leverage × margin</span>. Loosening SL while leverage is high amplifies every stop.</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::select name="profit_percentage" label="Profit %" x-model="form.profit_percentage"
                                          hint="Lower = quicker exits, higher throughput. Higher = wider profit zone but slower compounding.">
                            <option value="0.360">0.360</option>
                            <option value="0.380">0.380</option>
                            <option value="0.400">0.400</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="stop_market_initial_percentage" label="Stop loss %" x-model="form.stop_market_initial_percentage"
                                          hint="Realised loss ≈ SL% × cumulative-qty × leverage × margin. Tighter SL only safe when rung-N reach rate is already low.">
                            <option value="2.50">2.50</option>
                            <option value="5.00">5.00</option>
                            <option value="7.50">7.50</option>
                        </x-hub-ui::select>
                    </div>
                </div>

                {{-- Slots --}}
                <div class="ui-card p-4">
                    <div class="flex items-start gap-2 mb-4 pb-3 border-b ui-border-light">
                        <x-feathericon-layers class="w-4 h-4 ui-text-muted mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <h2 class="text-[13px] font-semibold ui-text leading-tight">Position slots</h2>
                            <p class="text-[11px] ui-text-info leading-snug mt-0.5 flex items-start gap-1 opacity-90">
                                <x-feathericon-info class="w-3 h-3 mt-0.5 flex-shrink-0" />
                                <span>Concurrent positions per side. All slots share the same wallet pool.</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-1.5 mb-4 px-2.5 py-2 rounded-md text-[10.5px] leading-snug" style="background-color: rgb(var(--ui-info) / 0.08); color: rgb(var(--ui-info))">
                        <x-feathericon-info class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        <span>Max committed margin ≈ <span class="font-mono">(longs + shorts) × margin %</span>. Correlated drawdowns hit every slot at once.</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::select name="total_positions_long" label="Long slots" x-model="form.total_positions_long"
                                          hint="Maximum concurrent LONG positions. Each consumes one slot until it closes.">
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="total_positions_short" label="Short slots" x-model="form.total_positions_short"
                                          hint="Maximum concurrent SHORT positions. Same wallet pool as longs.">
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </x-hub-ui::select>
                    </div>
                </div>

                {{-- Leverage + margin --}}
                <div class="ui-card p-4">
                    <div class="flex items-start gap-2 mb-4 pb-3 border-b ui-border-light">
                        <x-feathericon-sliders class="w-4 h-4 ui-text-muted mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <h2 class="text-[13px] font-semibold ui-text leading-tight">Leverage &amp; margin</h2>
                            <p class="text-[11px] ui-text-info leading-snug mt-0.5 flex items-start gap-1 opacity-90">
                                <x-feathericon-info class="w-3 h-3 mt-0.5 flex-shrink-0" />
                                <span>Risk envelope per position. Liquidation distance ≈ <span class="font-mono">1 / leverage</span>.</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-1.5 mb-4 px-2.5 py-2 rounded-md text-[10.5px] leading-snug" style="background-color: rgb(var(--ui-danger) / 0.08); color: rgb(var(--ui-danger))">
                        <x-feathericon-alert-triangle class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        <span><strong>High leverage = fast liquidation.</strong> 20× wipes at ≈ 5% adverse move, 15× at ≈ 6.7%, 10× at ≈ 10%.</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::select name="position_leverage_long" label="Leverage long" x-model="form.position_leverage_long"
                                          hint="20× → ≈ 5% adverse move = liquidation. 10× → ≈ 10%. Lower = safer per position, slower compounding.">
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="position_leverage_short" label="Leverage short" x-model="form.position_leverage_short"
                                          hint="Same math as long. Shorts have unbounded upside risk if held through a squeeze.">
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="margin_percentage_long" label="Margin % long" x-model="form.margin_percentage_long"
                                          hint="% of free wallet committed per new LONG. With N slots, max committed ≈ N × this value.">
                            <option value="4.00">4.00</option>
                            <option value="5.00">5.00</option>
                            <option value="6.00">6.00</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="margin_percentage_short" label="Margin % short" x-model="form.margin_percentage_short"
                                          hint="% of free wallet committed per new SHORT.">
                            <option value="4.00">4.00</option>
                            <option value="5.00">5.00</option>
                            <option value="6.00">6.00</option>
                        </x-hub-ui::select>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="button" @click="hydrate()" class="ui-btn ui-btn-secondary ui-btn-md">Reset</button>
                    <x-hub-ui::button type="submit" variant="primary" size="md">Save changes</x-hub-ui::button>
                </div>
            </form>
        </template>

    </div>

    <script>
        function editAccountPage() {
            return {
                accounts: @json($accounts),
                isAdmin: @json($isAdmin),
                selectedAccountId: '',
                form: null,
                availableQuotes: [],
                loadingQuotes: false,

                init() {
                    if (!this.isAdmin && this.accounts.length === 1) {
                        this.selectedAccountId = String(this.accounts[0].id);
                        this.hydrate();
                    }
                },

                async fetchQuotes(savedQuotes = []) {
                    if (!this.selectedAccountId) { this.availableQuotes = []; return; }
                    this.loadingQuotes = true;
                    const { ok, data } = await hubUiFetch(
                        '{{ route("accounts.quotes") }}?account_id=' + this.selectedAccountId,
                        { method: 'GET' }
                    );

                    const live = ok && Array.isArray(data?.assets) ? data.assets : [];

                    // Always include the saved values from the DB so the
                    // dropdown can show them even if the operator no longer
                    // holds that asset on the exchange. Missing-from-live
                    // is a real edge case (e.g. balance went to zero on
                    // an asset the position is still configured against)
                    // and the operator needs to see what the form is
                    // currently set to before changing it.
                    const merged = Array.from(new Set([...savedQuotes.filter(Boolean), ...live]));
                    this.availableQuotes = merged;
                    this.loadingQuotes = false;
                },

                hydrate() {
                    if (!this.selectedAccountId) {
                        this.form = null;
                        this.availableQuotes = [];
                        return;
                    }
                    const a = this.accounts.find(x => String(x.id) === String(this.selectedAccountId));
                    if (!a) { this.form = null; return; }

                    this.fetchQuotes([a.portfolio_quote, a.trading_quote]);

                    // Clone the editable subset so the form mutates an
                    // isolated buffer — Reset rehydrates from the source.
                    // Coerce decimals to the canonical display string so
                    // the matching <option> wins on initial render (e.g.
                    // "5.00" instead of "5" or "5.0").
                    this.form = {
                        name: a.name,
                        portfolio_quote: a.portfolio_quote || 'USDT',
                        trading_quote: a.trading_quote || 'USDT',
                        can_trade: !!a.can_trade,
                        profit_percentage: parseFloat(a.profit_percentage).toFixed(3),
                        stop_market_initial_percentage: parseFloat(a.stop_market_initial_percentage).toFixed(2),
                        total_positions_long: String(a.total_positions_long),
                        total_positions_short: String(a.total_positions_short),
                        position_leverage_long: String(a.position_leverage_long),
                        position_leverage_short: String(a.position_leverage_short),
                        margin_percentage_long: parseFloat(a.margin_percentage_long).toFixed(2),
                        margin_percentage_short: parseFloat(a.margin_percentage_short).toFixed(2),
                    };
                },
            };
        }
    </script>
</x-app-layout>
