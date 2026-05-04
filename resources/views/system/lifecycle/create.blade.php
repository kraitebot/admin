<x-app-layout :activeSection="'system'" :activeHighlight="'lifecycle'" :flush="false">
    <div class="px-4 sm:px-6 lg:px-12 py-6"
         x-data="lifecycleCreate({
            accounts: @js($accounts),
            tokens: @js($tokens),
            storeUrl: @js(route('system.lifecycle.store')),
            indexUrl: @js(route('system.lifecycle')),
         })">
        <div class="flex items-end justify-between gap-4 flex-wrap mb-6">
            <div>
                <h1 class="text-2xl font-semibold ui-text">New scenario</h1>
                <p class="text-sm ui-text-muted mt-1">
                    Pick a side, an account, and up to 6 tokens with their entry prices. The bot config for each
                    token is frozen into the scenario at creation — later DB changes won't shift this scenario's math.
                </p>
            </div>
            <a href="{{ route('system.lifecycle') }}" wire:navigate class="ui-btn ui-btn-ghost ui-btn-sm">
                Cancel
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left: meta + side + account --}}
            <div class="lg:col-span-1">
                <div class="ui-card p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold ui-text mb-1.5">Scenario name</label>
                        <input type="text"
                               x-model="form.name"
                               placeholder="e.g. Oct 10 cascade — 6 longs"
                               class="ui-input w-full" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold ui-text mb-1.5">Side</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button"
                                    @click="form.side = 'LONG'"
                                    :class="form.side === 'LONG' ? 'ui-btn ui-btn-primary' : 'ui-btn ui-btn-ghost'"
                                    class="ui-btn-sm w-full">
                                LONG
                            </button>
                            <button type="button"
                                    @click="form.side = 'SHORT'"
                                    :class="form.side === 'SHORT' ? 'ui-btn ui-btn-danger' : 'ui-btn ui-btn-ghost'"
                                    class="ui-btn-sm w-full">
                                SHORT
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold ui-text mb-1.5">Account</label>
                        <select x-model.number="form.account_id" class="ui-input w-full">
                            <option :value="null">— pick an account —</option>
                            <template x-for="acc in accounts" :key="acc.id">
                                <option :value="acc.id" x-text="acc.name + ' · ' + acc.margin.toFixed(2) + ' USDT'"></option>
                            </template>
                        </select>
                        <p class="text-[11px] ui-text-subtle mt-1.5" x-show="selectedAccount">
                            Leverage:
                            <span x-text="form.side === 'LONG' ? selectedAccount?.position_leverage_long : selectedAccount?.position_leverage_short"></span>x ·
                            margin per position:
                            <span x-text="(form.side === 'LONG' ? selectedAccount?.margin_percentage_long : selectedAccount?.margin_percentage_short) + '%'"></span>
                        </p>
                    </div>
                </div>
            </div>

            {{-- Right: tokens --}}
            <div class="lg:col-span-2">
                <div class="ui-card p-5">
                    <div class="flex items-end justify-between gap-3 mb-4 flex-wrap">
                        <div>
                            <label class="block text-xs font-semibold ui-text mb-1">Tokens</label>
                            <p class="text-[11px] ui-text-subtle">Pick up to 6. Same side for all.</p>
                        </div>
                        <span class="text-xs ui-text-muted ui-tabular" x-text="form.tokens.length + ' / 6'"></span>
                    </div>

                    {{-- Picked tokens --}}
                    <template x-if="form.tokens.length > 0">
                        <div class="space-y-2 mb-4">
                            <template x-for="(t, idx) in form.tokens" :key="idx">
                                <div class="flex items-center gap-2 ui-bg-elevated rounded-lg px-3 py-2">
                                    <span class="text-xs font-mono ui-text-subtle w-6" x-text="'#' + (idx + 1)"></span>
                                    <span class="text-sm font-semibold ui-text flex-1" x-text="t.token + ' / ' + t.quote"></span>
                                    <input type="number"
                                           step="any"
                                           x-model.number="t.entry_price"
                                           placeholder="entry price"
                                           class="ui-input ui-input-sm w-32" />
                                    <button type="button" @click="removeToken(idx)" class="ui-btn ui-btn-ghost ui-btn-sm">
                                        <span class="ui-text-danger">×</span>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Picker --}}
                    <div x-show="form.tokens.length < 6">
                        <input type="text"
                               x-model="search"
                               placeholder="Search by token (TIA, ENA, BTC…)"
                               class="ui-input ui-input-sm w-full mb-2" />
                        <div class="max-h-64 overflow-y-auto ui-bg-elevated rounded-lg">
                            <template x-for="bucket in filteredBuckets" :key="bucket.quote">
                                <div>
                                    <div class="px-3 py-1.5 text-[10px] uppercase tracking-wider ui-text-subtle ui-bg-card sticky top-0">
                                        <span x-text="bucket.quote"></span>
                                    </div>
                                    <template x-for="tok in bucket.items" :key="tok.id">
                                        <button type="button"
                                                @click="pickToken(tok)"
                                                :disabled="isPicked(tok)"
                                                class="w-full flex items-center justify-between px-3 py-1.5 text-left hover:ui-bg-card transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
                                            <span class="text-xs ui-text" x-text="tok.token"></span>
                                            <span class="text-[10px] ui-tabular ui-text-muted" x-text="'mark ' + tok.mark_price"></span>
                                        </button>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 mt-6">
            <button type="button"
                    @click="submit()"
                    :disabled="!canSubmit() || saving"
                    class="ui-btn ui-btn-primary">
                <span x-show="!saving">Create scenario</span>
                <span x-show="saving">Saving…</span>
            </button>
        </div>
    </div>

    @push('scripts')
    <script>
        window.lifecycleCreate = function (config) {
            return {
                accounts: config.accounts,
                tokens: config.tokens,
                storeUrl: config.storeUrl,
                indexUrl: config.indexUrl,
                form: {
                    name: '',
                    side: 'LONG',
                    account_id: null,
                    tokens: [],
                },
                search: '',
                saving: false,

                get selectedAccount() {
                    return this.accounts.find(a => a.id === this.form.account_id) || null;
                },

                get filteredBuckets() {
                    const q = this.search.trim().toUpperCase();
                    const out = [];
                    for (const [quote, items] of Object.entries(this.tokens)) {
                        const filtered = q
                            ? items.filter(t => t.token.toUpperCase().includes(q))
                            : items;
                        if (filtered.length > 0) {
                            out.push({ quote, items: filtered });
                        }
                    }
                    return out;
                },

                isPicked(tok) {
                    return this.form.tokens.some(t => t.id === tok.id);
                },

                pickToken(tok) {
                    if (this.form.tokens.length >= 6) return;
                    this.form.tokens.push({
                        id: tok.id,
                        token: tok.token,
                        quote: tok.quote,
                        entry_price: tok.mark_price || 0,
                    });
                },

                removeToken(idx) {
                    this.form.tokens.splice(idx, 1);
                },

                canSubmit() {
                    return this.form.name.trim().length > 0
                        && this.form.account_id
                        && this.form.tokens.length > 0
                        && this.form.tokens.every(t => Number(t.entry_price) > 0);
                },

                async submit() {
                    if (!this.canSubmit()) return;
                    this.saving = true;
                    try {
                        const payload = {
                            name: this.form.name,
                            side: this.form.side,
                            account_id: this.form.account_id,
                            tokens: this.form.tokens.map(t => ({
                                exchange_symbol_id: t.id,
                                entry_price: Number(t.entry_price),
                            })),
                        };
                        const res = await window.hubUiFetch(this.storeUrl, {
                            method: 'POST',
                            body: JSON.stringify(payload),
                        });
                        if (res.ok && res.data?.redirect) {
                            window.location.href = res.data.redirect;
                            return;
                        }
                        window.showToast(res.data?.error || 'Could not create scenario', 'error');
                    } catch (e) {
                        window.showToast(e.message || 'Network error', 'error');
                    } finally {
                        this.saving = false;
                    }
                },
            };
        };
    </script>
    @endpush
</x-app-layout>
