<x-app-layout :activeSection="'accounts'" :activeHighlight="'edit-account'">
    <div x-data="editAccountPage()" x-init="init()" class="max-w-5xl">

        <x-hub-ui::page-header
            title="Accounts"
            description="Manage exchange access, trading availability, and risk settings for each account."
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

        <template x-if="selectedAccountId && form">
            <div class="ui-card p-4 mb-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-[13px] font-semibold ui-text leading-tight">Exchange connection</h2>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                :class="selectedAccount()?.has_credentials ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'"
                                x-text="selectedAccount()?.has_credentials ? 'Credentials saved' : 'Credentials missing'"
                            ></span>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                :class="selectedAccount()?.disabled_reason ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800'"
                                x-text="selectedAccount()?.disabled_reason ? 'Trading disabled' : 'Connection OK'"
                            ></span>
                        </div>
                        <p class="mt-1 text-xs ui-text-subtle">Add read-only futures API keys, allow the listed Kraite IP addresses in your exchange account, then test the connection.</p>
                        <p x-show="selectedAccount()?.disabled_reason" x-cloak class="mt-2 text-xs font-medium text-red-700" x-text="selectedAccount()?.disabled_reason"></p>
                        <p x-show="connectivity.message" x-cloak class="mt-2 text-xs font-medium" :class="connectivity.state === 'okay' ? 'text-emerald-700' : 'text-red-700'" x-text="connectivity.message"></p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-10 items-center justify-center rounded-lg border px-4 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                        :class="connectivity.state === 'testing' ? 'animate-pulse border-emerald-500 bg-emerald-600 text-white shadow-lg shadow-emerald-500/25' : 'border-gray-300 bg-white text-gray-800 hover:bg-gray-50'"
                        :disabled="! credentialsReady() || connectivity.state === 'testing'"
                        @click="testConnectivity()"
                    >
                        <span x-show="connectivity.state !== 'testing'">Test keys</span>
                        <span x-show="connectivity.state === 'testing'" x-cloak>Testing...</span>
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                    <x-hub-ui::input
                        name="connectivity_api_key"
                        label="API key"
                        x-model="credentialForm.api_key"
                        autocomplete="off"
                    />
                    <x-hub-ui::input
                        name="connectivity_api_secret"
                        label="API secret"
                        x-model="credentialForm.api_secret"
                        type="password"
                        autocomplete="off"
                    />
                    <div x-show="selectedAccount()?.requires_passphrase" x-cloak>
                        <x-hub-ui::input
                            name="connectivity_passphrase"
                            label="Passphrase"
                            x-model="credentialForm.passphrase"
                            type="password"
                            autocomplete="off"
                        />
                    </div>
                </div>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs ui-text-subtle" x-text="credentialSaveHint()"></p>
                    <button
                        type="button"
                        class="inline-flex h-9 items-center justify-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="savingCredentials || ! keysCurrentTested()"
                        @click="saveCredentials()"
                    >
                        <span x-show="! savingCredentials">Save API keys</span>
                        <span x-show="savingCredentials" x-cloak>Saving...</span>
                    </button>
                </div>

                <div x-show="connectivity.servers.length > 0" x-cloak class="mt-4 border-t ui-border-light pt-3">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <template x-for="server in connectivity.servers" :key="server.id || server.hostname || server.ip_address">
                            <div class="flex items-center justify-between gap-3 rounded-md border ui-border bg-white px-3 py-2 text-xs">
                                <div class="min-w-0">
                                    <p class="truncate font-medium ui-text" x-text="server.hostname || server.ip_address || 'Server'"></p>
                                    <p class="font-mono ui-text-subtle" x-text="server.ip_address || ''"></p>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                    :class="{
                                        'bg-emerald-100 text-emerald-800': server.status === 'connected',
                                        'bg-red-100 text-red-800': server.status === 'not_connected',
                                        'bg-gray-100 text-gray-700': server.status !== 'connected' && server.status !== 'not_connected',
                                    }"
                                    x-text="server.status === 'connected' ? 'Connected' : (server.status === 'not_connected' ? 'Failed' : 'Testing')"
                                ></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>

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
                                <span>Name this account and choose the currencies Kraite should use for balance and trades.</span>
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
                                <input type="checkbox" name="can_trade" value="1" x-model="form.can_trade" :disabled="! isAdmin && selectedAccount()?.disabled_reason" class="w-4 h-4 rounded border-slate-300 text-emerald-600 disabled:cursor-not-allowed disabled:opacity-50">
                                <span class="text-sm ui-text">Can trade</span>
                            </label>
                            <p class="text-xs ui-text-subtle mt-1">When off, Kraite will not open new trades. Fix connection errors before turning trading back on.</p>
                        </div>

                        <x-hub-ui::select name="portfolio_quote" label="Portfolio quote" x-model="form.portfolio_quote"
                                          hint="Currency used to read this account's balance and results.">
                            <template x-if="loadingQuotes"><option value="">Loading…</option></template>
                            <template x-if="!loadingQuotes && availableQuotes.length === 0">
                                <option value="">— No assets on exchange —</option>
                            </template>
                            <template x-for="q in availableQuotes" :key="q">
                                <option :value="q" x-text="q"></option>
                            </template>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="trading_quote" label="Trading quote" x-model="form.trading_quote"
                                          hint="Currency used on trades, for example USDT in BTC/USDT.">
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
                                <span>Choose the profit target and stop-loss distance Kraite should use for this account.</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-1.5 mb-4 px-2.5 py-2 rounded-md text-[10.5px] leading-snug" style="background-color: rgb(var(--ui-warning) / 0.08); color: rgb(var(--ui-warning))">
                        <x-feathericon-alert-triangle class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        <span><strong>Risk:</strong> A wider stop loss can mean a larger realised loss, especially when leverage is high.</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::select name="profit_percentage" label="Profit %" x-model="form.profit_percentage"
                                          hint="Lower targets exit sooner. Higher targets wait for a larger move.">
                            <option value="0.360">0.360</option>
                            <option value="0.380">0.380</option>
                            <option value="0.400">0.400</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="stop_market_initial_percentage" label="Stop loss %" x-model="form.stop_market_initial_percentage"
                                          hint="Higher values give trades more room, but losses can be larger.">
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
                                <span>Limit how many long and short trades Kraite can keep open at the same time.</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-1.5 mb-4 px-2.5 py-2 rounded-md text-[10.5px] leading-snug" style="background-color: rgb(var(--ui-info) / 0.08); color: rgb(var(--ui-info))">
                        <x-feathericon-info class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        <span>More open trades can use more of your balance at the same time. Market-wide moves can affect several trades together.</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::select name="total_positions_long" label="Long slots" x-model="form.total_positions_long"
                                          hint="Maximum open LONG trades at one time.">
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="total_positions_short" label="Short slots" x-model="form.total_positions_short"
                                          hint="Maximum open SHORT trades at one time.">
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
                                <span>Set how much leverage and balance Kraite can use per trade.</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-1.5 mb-4 px-2.5 py-2 rounded-md text-[10.5px] leading-snug" style="background-color: rgb(var(--ui-danger) / 0.08); color: rgb(var(--ui-danger))">
                        <x-feathericon-alert-triangle class="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        <span><strong>High leverage increases liquidation risk.</strong> Small market moves against the trade can close the position quickly.</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-hub-ui::select name="position_leverage_long" label="Leverage long" x-model="form.position_leverage_long"
                                          hint="Lower leverage gives long trades more room before liquidation.">
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="position_leverage_short" label="Leverage short" x-model="form.position_leverage_short"
                                          hint="Lower leverage gives short trades more room before liquidation.">
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="margin_percentage_long" label="Margin % long" x-model="form.margin_percentage_long"
                                          hint="How much available balance Kraite may use for each new LONG trade.">
                            <option value="4.00">4.00</option>
                            <option value="5.00">5.00</option>
                            <option value="6.00">6.00</option>
                        </x-hub-ui::select>
                        <x-hub-ui::select name="margin_percentage_short" label="Margin % short" x-model="form.margin_percentage_short"
                                          hint="How much available balance Kraite may use for each new SHORT trade.">
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
                savingCredentials: false,
                testedCredentialSignature: '',
                testingCredentialSignature: '',
                testedBlockUuid: null,
                credentialForm: {
                    api_key: '',
                    api_secret: '',
                    passphrase: '',
                },
                connectivity: {
                    state: 'idle',
                    message: '',
                    servers: [],
                    blockUuid: null,
                    pollTimer: null,
                },

                init() {
                    this.$watch('credentialForm.api_key', () => this.markCredentialChanged());
                    this.$watch('credentialForm.api_secret', () => this.markCredentialChanged());
                    this.$watch('credentialForm.passphrase', () => this.markCredentialChanged());

                    if (!this.isAdmin && this.accounts.length === 1) {
                        this.selectedAccountId = String(this.accounts[0].id);
                        this.hydrate();
                    }
                },

                selectedAccount() {
                    return this.accounts.find(x => String(x.id) === String(this.selectedAccountId)) || null;
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
                    // Keep saved values visible even if the exchange balance
                    // for that asset is currently zero.
                    const merged = Array.from(new Set([...savedQuotes.filter(Boolean), ...live]));
                    this.availableQuotes = merged;
                    this.loadingQuotes = false;
                },

                hydrate() {
                    if (!this.selectedAccountId) {
                        this.form = null;
                        this.availableQuotes = [];
                        this.resetConnectivityUi();
                        return;
                    }
                    const a = this.accounts.find(x => String(x.id) === String(this.selectedAccountId));
                    if (!a) { this.form = null; return; }

                    this.resetConnectivityUi();
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
                resetConnectivityUi() {
                    this.stopConnectivityPolling();
                    this.credentialForm = { api_key: '', api_secret: '', passphrase: '' };
                    this.connectivity = {
                        state: 'idle',
                        message: '',
                        servers: [],
                        blockUuid: null,
                        pollTimer: null,
                    };
                    this.savingCredentials = false;
                    this.testedCredentialSignature = '';
                    this.testingCredentialSignature = '';
                    this.testedBlockUuid = null;
                },
                updateAccount(account) {
                    const index = this.accounts.findIndex(x => String(x.id) === String(account.id));

                    if (index !== -1) {
                        this.accounts[index] = account;
                    }
                },
                async saveCredentials() {
                    if (!this.selectedAccountId || this.savingCredentials) { return; }

                    this.savingCredentials = true;
                    this.connectivity.message = '';

                    const { ok, data } = await hubUiFetch('{{ route("accounts.connectivity.credentials") }}', {
                        method: 'PATCH',
                        body: {
                            account_id: this.selectedAccountId,
                            api_key: this.credentialForm.api_key,
                            api_secret: this.credentialForm.api_secret,
                            passphrase: this.credentialForm.passphrase,
                            tested_block_uuid: this.testedBlockUuid,
                        },
                    });

                    this.savingCredentials = false;

                    if (!ok) {
                        this.connectivity.state = 'failed';
                        this.connectivity.message = data?.message || 'Could not save API keys.';
                        return;
                    }

                    this.updateAccount(data.account);
                    this.credentialForm = { api_key: '', api_secret: '', passphrase: '' };
                    this.testedCredentialSignature = '';
                    this.testingCredentialSignature = '';
                    this.testedBlockUuid = null;
                    this.connectivity.state = 'idle';
                    this.connectivity.message = data.message || 'API keys saved. Trading is enabled for this account.';
                },
                async testConnectivity() {
                    if (!this.selectedAccountId || !this.credentialsReady() || this.connectivity.state === 'testing') { return; }

                    this.stopConnectivityPolling();
                    this.testedCredentialSignature = '';
                    this.testedBlockUuid = null;
                    this.testingCredentialSignature = this.credentialSignature();
                    this.connectivity.state = 'testing';
                    this.connectivity.message = 'Testing the exchange connection from Kraite servers...';
                    this.connectivity.servers = [];

                    const { ok, data } = await hubUiFetch('{{ route("accounts.connectivity.test") }}', {
                        body: {
                            account_id: this.selectedAccountId,
                            api_key: this.credentialForm.api_key,
                            api_secret: this.credentialForm.api_secret,
                            passphrase: this.credentialForm.passphrase,
                        },
                    });

                    if (!ok) {
                        this.connectivity.state = 'failed';
                        this.connectivity.message = data?.message || 'The connection test could not start.';
                        return;
                    }

                    this.applyConnectivityPayload(data);

                    if (data.block_uuid && !data.is_complete) {
                        this.pollConnectivityStatus(data.block_uuid);
                    }
                },
                pollConnectivityStatus(blockUuid) {
                    this.connectivity.blockUuid = blockUuid;
                    this.connectivity.pollTimer = setInterval(async () => {
                        const { ok, data } = await hubUiFetch(
                            '{{ route("accounts.connectivity.status", ["blockUuid" => "__BLOCK_UUID__"]) }}'.replace('__BLOCK_UUID__', blockUuid),
                            { method: 'GET' }
                        );

                        if (!ok) {
                            this.stopConnectivityPolling();
                            this.connectivity.state = 'failed';
                            this.connectivity.message = data?.message || 'The connection test could not be checked. Try again in a moment.';
                            return;
                        }

                        this.applyConnectivityPayload(data);

                        if (data.is_complete) {
                            this.stopConnectivityPolling();
                        }
                    }, 2000);
                },
                applyConnectivityPayload(data) {
                    this.connectivity.servers = Array.isArray(data.servers) ? data.servers : [];
                    this.connectivity.blockUuid = data.block_uuid || this.connectivity.blockUuid;

                    if (data.account) {
                        this.updateAccount(data.account);
                    }

                    if (!data.is_complete) {
                        this.connectivity.state = 'testing';
                        this.connectivity.message = 'Testing the exchange connection from Kraite servers...';
                        return;
                    }

                    this.connectivity.state = data.all_connected ? 'okay' : 'failed';
                    this.connectivity.message = data.all_connected
                        ? 'Connection verified. Save these API keys to enable trading for this account.'
                        : 'Some Kraite IP addresses are not allowed in your exchange account. You can save these keys, but trading stays off until you add the IP addresses and test again.';

                    if (data.is_complete) {
                        this.testedCredentialSignature = this.testingCredentialSignature;
                        this.testedBlockUuid = this.connectivity.blockUuid;
                    }
                },
                stopConnectivityPolling() {
                    if (this.connectivity.pollTimer !== null) {
                        clearInterval(this.connectivity.pollTimer);
                        this.connectivity.pollTimer = null;
                    }
                },
                credentialsReady() {
                    return !!this.selectedAccountId
                        && this.credentialForm.api_key.trim() !== ''
                        && this.credentialForm.api_secret.trim() !== ''
                        && (!this.selectedAccount()?.requires_passphrase || this.credentialForm.passphrase.trim() !== '');
                },
                credentialSignature() {
                    return [
                        this.selectedAccountId || '',
                        this.credentialForm.api_key.trim(),
                        this.credentialForm.api_secret.trim(),
                        this.selectedAccount()?.requires_passphrase ? this.credentialForm.passphrase.trim() : '',
                    ].join('|');
                },
                keysPassedCurrentTest() {
                    return this.keysCurrentTested() && this.connectivity.state === 'okay';
                },
                keysCurrentTested() {
                    return this.credentialsReady()
                        && this.testedBlockUuid
                        && this.testedCredentialSignature === this.credentialSignature();
                },
                credentialSaveHint() {
                    if (this.keysPassedCurrentTest()) {
                        return 'Connection passed. You can save these API keys.';
                    }

                    if (this.keysCurrentTested()) {
                        return 'Connection test finished. You can save these keys, but trading stays off until the IP addresses are allowed.';
                    }

                    return 'Test the API keys before saving them to this account.';
                },
                markCredentialChanged() {
                    if (this.keysCurrentTested()) { return; }

                    this.testedBlockUuid = null;
                    this.testedCredentialSignature = '';
                },
            };
        }
    </script>
</x-app-layout>
