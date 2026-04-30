<x-app-layout :activeHighlight="'billing'">
    <div
        class="max-w-6xl"
        x-data="walletPoller({
            endpoint: @js(route('billing.wallet-status')),
            initialBalance: {{ (float) $user->wallet_balance_usdt }},
        })"
        x-init="start()"
    >

        <x-hub-ui::page-header
            title="Billing"
            description="Your subscription, wallet, and renewal."
        />

        @if (session('status'))
            <div class="mb-4">
                <x-hub-ui::alert type="success">{{ session('status') }}</x-hub-ui::alert>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4">
                <x-hub-ui::alert type="error">{{ session('error') }}</x-hub-ui::alert>
            </div>
        @endif

        {{-- State banners --}}
        @if ($user->subscription_id === null)
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Pick a plan to begin</h2>
                </div>
                <p class="text-sm ui-text-muted">
                    Choose a subscription tier in the "Current plan" card below, then click
                    "Start trading" to begin your free trial.
                </p>
            </div>
        @elseif ($user->trial_started_at === null)
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Start your free trial</h2>
                    <span class="text-[11px] ui-text-subtle uppercase tracking-wider">{{ $tier?->trial_days ?? 7 }} days, free</span>
                </div>
                <p class="text-sm ui-text-muted mb-3">
                    The trial activates the moment you click below. After {{ $tier?->trial_days ?? 7 }} days,
                    your first renewal at {{ number_format($monthlyRate, 2) }} USDT will fire — top up
                    before then to keep the bot running.
                </p>
                <form method="POST" action="{{ route('billing.start-trading') }}">
                    @csrf
                    <x-hub-ui::button type="submit" variant="primary" size="sm">Start trading</x-hub-ui::button>
                </form>
            </div>
        @elseif ($trialActive)
            @php
                $trialEnd = $user->trial_started_at?->copy()->addDays($user->effectiveTrialDays());
                $hoursLeft = $trialEnd ? max(0, (int) round(now()->diffInMinutes($trialEnd, false) / 60)) : 0;
            @endphp
            <div class="mb-4">
                <x-hub-ui::alert type="info">
                    <strong>Trial active</strong> — ~{{ $hoursLeft }}h remaining. Your first renewal at
                    {{ number_format($monthlyRate, 2) }} USDT will fire when the trial ends.
                </x-hub-ui::alert>
            </div>
        @elseif ($isPaused)
            <div class="mb-4">
                <x-hub-ui::alert type="warning">
                    <strong>Subscription paused.</strong> Existing positions continue normally; new opens are blocked.
                    Resume anytime — the renewal date pushes forward by your pause duration.
                </x-hub-ui::alert>
            </div>
        @elseif ($inClosingMode)
            <div class="mb-4">
                <x-hub-ui::alert type="error">
                    <strong>Read-only mode.</strong> Renewal failed — wallet is short
                    {{ number_format($shortfall, 2) }} USDT. Existing trades continue normally;
                    new positions are blocked. Top up to retry the renewal immediately.
                </x-hub-ui::alert>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">

            {{-- Wallet --}}
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-sm font-semibold ui-text">Wallet balance</h2>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">USDT</span>
                </div>
                <div class="text-3xl font-bold ui-text font-mono ui-tabular leading-none">
                    {{ number_format((float) $user->wallet_balance_usdt, 4) }}
                </div>
                <div class="mt-3">
                    @if ($trialActive)
                        <span class="text-xs ui-text-muted">Trial active — wallet untouched.</span>
                    @elseif ($monthlyRate <= 0)
                        <span class="text-xs ui-text-muted">No monthly rate.</span>
                    @elseif ($rateCovered)
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                              style="background: rgb(var(--ui-success)); color: white">
                            Renewal covered
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                              style="background: rgb(var(--ui-danger)); color: white">
                            Need {{ number_format($shortfall, 2) }} USDT more
                        </span>
                    @endif
                </div>
            </div>

            {{-- Plan + renewal --}}
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-sm font-semibold ui-text">Current plan</h2>
                    @if ($tier)
                        <span class="text-[10px] font-mono ui-text-subtle">{{ $tier->canonical }}</span>
                    @endif
                </div>

                @if ($tier)
                    <div class="text-xl font-semibold ui-text leading-none">{{ $tier->name }}</div>
                    <div class="text-xs ui-text-subtle font-mono mt-1">
                        {{ number_format($monthlyRate, 2) }} USDT/month
                    </div>

                    <div class="grid grid-cols-2 gap-2 mt-3">
                        <div class="ui-bg-elevated rounded-lg p-2">
                            <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Renews</div>
                            <div class="text-xs font-mono ui-text">
                                {{ $renewsAt ? $renewsAt->toDateString() : '—' }}
                            </div>
                        </div>
                        <div class="ui-bg-elevated rounded-lg p-2">
                            <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Accounts</div>
                            <div class="text-xs font-mono ui-text">{{ $tier->max_accounts ?? '∞' }}</div>
                        </div>
                    </div>
                @else
                    <div class="text-xl font-semibold ui-text leading-none">No plan</div>
                    <div class="text-xs ui-text-subtle mt-1">Pick one to begin.</div>
                @endif

                @php
                    $cappedSubIds = $subscriptions
                        ->filter(fn ($s) => ! $s->hasUnlimitedAccounts() && (int) $s->max_accounts === 1)
                        ->pluck('id')
                        ->all();
                    $selectableSubs = $user->subscription_id !== null
                        ? $subscriptions->reject(fn ($s) => $s->id === $user->subscription_id)
                        : $subscriptions;
                    $defaultSelected = (int) ($selectableSubs->first()?->id ?? 0);
                @endphp

                @if ($selectableSubs->isNotEmpty())
                    <form method="POST" action="{{ route('billing.subscription') }}" class="mt-3 space-y-2"
                          x-data="{
                              selected: {{ $defaultSelected }},
                              cappedIds: @js($cappedSubIds),
                              accountCount: {{ $accounts->count() }},
                              get isCapped() { return this.cappedIds.includes(parseInt(this.selected)); },
                              get needsAccountPick() { return this.isCapped && this.accountCount > 1; },
                              get showSoloAccount() { return this.isCapped && this.accountCount === 1; }
                          }">
                        @csrf
                        <x-hub-ui::select name="subscription_id" x-model="selected">
                            @foreach ($selectableSubs as $sub)
                                <option value="{{ $sub->id }}">
                                    {{ $sub->name }} · {{ number_format((float) $sub->monthly_rate_usdt, 2) }}/mo
                                </option>
                            @endforeach
                        </x-hub-ui::select>

                        <div x-show="needsAccountPick" x-cloak>
                            <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">
                                Active account on capped tier
                            </label>
                            <x-hub-ui::select name="active_account_id">
                                <option value="">— pick one —</option>
                                @foreach ($accounts as $acc)
                                    <option value="{{ $acc->id }}" @selected($user->active_account_id === $acc->id)>
                                        {{ $acc->name }}
                                    </option>
                                @endforeach
                            </x-hub-ui::select>
                        </div>

                        @if ($accounts->count() === 1)
                            <div x-show="showSoloAccount" x-cloak>
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider mb-1">
                                    Active account
                                </div>
                                <div class="ui-bg-elevated rounded-lg px-3 py-2 text-xs font-mono ui-text">
                                    {{ $accounts->first()->name }}
                                </div>
                            </div>
                        @endif

                        <x-hub-ui::button type="submit" variant="secondary" size="sm">
                            {{ $tier ? 'Switch plan' : 'Select plan' }}
                        </x-hub-ui::button>
                    </form>
                @endif
            </div>

            {{-- Top up --}}
            <div
                class="ui-card p-4 sm:p-5"
                x-data="topUpCard({
                    endpoint: @js(route('billing.min-amount')),
                    coins: @js($topUpCoins->map(fn ($c) => ['canonical' => $c->canonical, 'display_name' => $c->display_name])->all()),
                    initial: @js($topUpCoins->first()?->canonical ?? ''),
                })"
                x-init="refresh()"
            >
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-sm font-semibold ui-text">Top up</h2>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">USDT</span>
                </div>

                <p class="text-xs ui-text-muted mb-3 leading-snug">
                    Pick a coin, hit the button, and you'll be taken to a hosted payment page locked to that coin.
                    Wallet is credited in USDT after confirmation.
                </p>

                @if ($topUpCoins->isEmpty())
                    <div class="text-xs ui-text-subtle">No top-up coins are configured. Contact admin.</div>
                @else
                    <form method="POST" action="{{ route('billing.topup') }}" target="_blank" class="space-y-3">
                        @csrf

                        <div>
                            <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Pay with</label>
                            <x-hub-ui::select name="pay_currency" x-model="selected" @change="refresh()">
                                @foreach ($topUpCoins as $coin)
                                    <option value="{{ $coin->canonical }}">{{ $coin->display_name }}</option>
                                @endforeach
                            </x-hub-ui::select>
                        </div>

                        <div>
                            <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Amount (USDT)</label>
                            <x-hub-ui::input
                                name="amount_usdt"
                                type="number"
                                step="0.01"
                                min="0"
                                x-model.number="amount"
                                x-bind:placeholder="formatUsdt(state.effective_min_usdt)"
                            />
                            <div class="text-[11px] mt-1 leading-snug" x-show="state.effective_min_usdt > 0">
                                <template x-if="!validAmount() && amount > 0">
                                    <span style="color: rgb(var(--ui-danger))">
                                        Below minimum
                                        <span class="font-mono ui-tabular" x-text="formatUsdt(state.effective_min_usdt)"></span>.
                                    </span>
                                </template>
                                <template x-if="validAmount() || amount <= 0">
                                    <span class="ui-text-muted">
                                        Minimum:
                                        <span class="font-mono ui-tabular ui-text" x-text="formatUsdt(state.effective_min_usdt)"></span>
                                        <template x-if="state.gateway_min_usdt > state.rule_min_usdt">
                                            <span class="ui-text-subtle">(gateway floor)</span>
                                        </template>
                                        <template x-if="state.gateway_min_usdt <= state.rule_min_usdt && state.rule_min_usdt > 0">
                                            <span class="ui-text-subtle">
                                                (coverage rule —
                                                <button
                                                    type="button"
                                                    class="underline ui-text-primary hover:opacity-80"
                                                    @click.prevent="$dispatch('open-modal', 'coverage-rule')"
                                                >why?</button>)
                                            </span>
                                        </template>
                                    </span>
                                </template>
                            </div>
                        </div>

                        <x-hub-ui::button
                            type="submit"
                            variant="primary"
                            size="sm"
                            class="w-full"
                            x-bind:disabled="loading || !validAmount()"
                        >
                            <span x-text="loading ? 'Loading…' : buttonLabel()"></span>
                        </x-hub-ui::button>
                    </form>
                @endif

                <div class="text-[10px] ui-text-subtle mt-2 leading-snug">
                    Opens NOWPayments hosted invoice in a new tab.
                </div>

                <script>
                    function topUpCard(config) {
                        return {
                            selected: config.initial,
                            loading: false,
                            amount: 0,
                            state: {
                                effective_min_usdt: 0,
                                rule_min_usdt: 0,
                                gateway_min_usdt: 0,
                            },
                            async refresh() {
                                if (!this.selected) return;
                                this.loading = true;
                                const previousMin = parseFloat(this.state.effective_min_usdt || 0);
                                try {
                                    const res = await fetch(config.endpoint + '?coin=' + encodeURIComponent(this.selected), {
                                        headers: { 'Accept': 'application/json' },
                                        credentials: 'same-origin',
                                    });
                                    if (!res.ok) throw new Error('lookup failed');
                                    this.state = await res.json();
                                    // Auto-bump the input when the user hadn't
                                    // typed something above the prior min, OR
                                    // when their typed value is now below the
                                    // new coin's floor.
                                    const newMin = parseFloat(this.state.effective_min_usdt || 0);
                                    if (this.amount <= 0 || this.amount === previousMin || this.amount < newMin) {
                                        this.amount = newMin;
                                    }
                                } catch (e) {
                                    console.error('[topup] min-amount lookup failed', e);
                                    this.state = {
                                        effective_min_usdt: 0,
                                        rule_min_usdt: 0,
                                        gateway_min_usdt: 0,
                                    };
                                } finally {
                                    this.loading = false;
                                }
                            },
                            validAmount() {
                                const min = parseFloat(this.state.effective_min_usdt || 0);
                                return min > 0 && parseFloat(this.amount || 0) >= min;
                            },
                            buttonLabel() {
                                if (!this.validAmount()) return 'Top up';
                                return 'Top up ' + this.formatUsdt(this.amount);
                            },
                            formatUsdt(v) {
                                return parseFloat(v || 0).toFixed(2) + ' USDT';
                            },
                        };
                    }
                </script>
            </div>

        </div>

        {{-- Pause / Resume --}}
        @if ($user->trial_started_at !== null && ! $trialActive)
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Subscription state</h2>
                    <span class="text-[11px] ui-text-subtle uppercase tracking-wider">
                        {{ $isPaused ? 'paused' : 'active' }}
                    </span>
                </div>

                @if ($isPaused)
                    <p class="text-xs ui-text-muted mb-3 leading-snug">
                        Paused since {{ $user->subscription_paused_at?->diffForHumans() }}.
                        Resume to push the renewal anchor forward by the pause duration and re-enable new opens.
                    </p>
                    <form method="POST" action="{{ route('billing.resume') }}">
                        @csrf
                        <x-hub-ui::button type="submit" variant="primary" size="sm">Resume subscription</x-hub-ui::button>
                    </form>
                @else
                    <p class="text-xs ui-text-muted mb-3 leading-snug">
                        Pause to stop renewals indefinitely. Existing positions continue trading; new opens block until you resume.
                    </p>
                    <form method="POST" action="{{ route('billing.pause') }}"
                          onsubmit="return confirm('Pause subscription? New positions will be blocked until you resume.');">
                        @csrf
                        <x-hub-ui::button type="submit" variant="ghost" size="sm">Pause subscription</x-hub-ui::button>
                    </form>
                @endif
            </div>
        @endif

        {{-- Transaction history --}}
        <div class="ui-card p-4 sm:p-5">
            <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                <h2 class="text-sm font-semibold ui-text">Transaction history</h2>
                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">last {{ $transactions->count() }}</span>
            </div>

            <x-hub-ui::data-table>
                <x-slot:head>
                    <tr>
                        <th class="text-left">When</th>
                        <th class="text-left">Type</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Balance after</th>
                        <th class="text-left">Description</th>
                    </tr>
                </x-slot:head>

                @forelse ($transactions as $tx)
                    <tr>
                        <td class="font-mono ui-text-subtle">{{ $tx->created_at?->toDateTimeString() }}</td>
                        <td><span class="text-xs font-mono ui-text-muted">{{ $tx->type }}</span></td>
                        <td class="font-mono ui-tabular text-right" style="color: rgb(var(--{{ $tx->isCredit() ? 'ui-success' : 'ui-danger' }}))">
                            {{ ($tx->isCredit() ? '+' : '') . number_format((float) $tx->amount_usdt, 4) }}
                        </td>
                        <td class="font-mono ui-tabular text-right">
                            {{ number_format((float) $tx->balance_after, 4) }}
                        </td>
                        <td class="ui-text-muted">{{ $tx->description }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-hub-ui::empty-state title="No activity yet" description="Your wallet has no ledger entries." />
                        </td>
                    </tr>
                @endforelse
            </x-hub-ui::data-table>
        </div>

        {{-- Terms of Service --}}
        <div class="ui-card p-4 sm:p-5 mt-6" x-data="{ open: false }">
            <button
                type="button"
                class="w-full flex items-center justify-between text-left"
                @click="open = ! open"
            >
                <div>
                    <h2 class="text-sm font-semibold ui-text">Billing terms</h2>
                    <p class="text-[11px] ui-text-subtle leading-snug mt-0.5">
                        How subscriptions, top-ups, fees, and refunds work. By using Kraite you accept the terms below.
                    </p>
                </div>
                <svg class="w-4 h-4 ui-text-muted transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div class="text-xs ui-text-muted leading-relaxed mt-4 space-y-3" x-show="open" x-cloak>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">1. Subscription model</div>
                    <p>
                        Kraite is a monthly subscription. Each plan ("Starter", "Unlimited", or any future tier) carries a fixed monthly price in USDT.
                        At each renewal date, the monthly fee is debited from your wallet balance. There is no daily debit, no prorated charge against you mid-month — just one charge per renewal.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">2. Free trial</div>
                    <p>
                        New users get a free trial when they activate their first plan. During the trial, no debits happen — the bot trades for free.
                        At the end of the trial, the first renewal fires automatically. If your wallet doesn't cover the renewal at that moment, your account enters <strong>read-only mode</strong> (existing positions continue to TP/SL; no new positions open).
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">3. Wallet top-ups</div>
                    <p>
                        You fund your subscription by topping up a USDT wallet held by Kraite. Top-ups are processed by <a href="https://nowpayments.io" target="_blank" rel="noopener" class="ui-text-primary underline">NOWPayments</a> (a third-party crypto payment gateway). You may pay in any of the coins offered in the dropdown.
                        Once a payment is confirmed on-chain, the equivalent USDT is credited to your Kraite wallet automatically.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">4. Fees</div>
                    <p>
                        <strong>You pay the gateway processing fee</strong> (currently 0.5% per top-up, charged by NOWPayments). The amount displayed at checkout already includes this fee — what NOWPayments shows you is the gross.
                        You also pay the network/gas fee for the chain you choose (e.g. ~$1 for TRC20, much higher for ERC20). Network fees are quoted by your wallet, not by Kraite.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">5. Cross-chain conversion</div>
                    <p>
                        Your wallet balance is denominated in USDT. If you pay in a different coin or different chain, NOWPayments converts to USDT at their internal rate, which can be 0.1%–0.3% off the global market rate.
                        For the cleanest 1:1 settlement, pay with the same chain Kraite settles in (USDT-TRC20). Any other coin will land in your wallet as the post-conversion USDT amount, not the amount you typed.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">6. Wallet credit is non-refundable</div>
                    <p>
                        Once funds are credited to your Kraite wallet, they <strong>cannot be withdrawn</strong> back to your crypto address. They can only be consumed by subscription renewals.
                        If you stop using the service, any leftover balance stays in the wallet — you can resume anytime and use it. Kraite does not process cash refunds, KYC withdrawals, or fiat conversions.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">7. Renewal failures (read-only mode)</div>
                    <p>
                        If your wallet balance is below the monthly rate when renewal fires, the renewal fails and your accounts switch to read-only:
                    </p>
                    <ul class="list-disc pl-5 mt-1 space-y-0.5">
                        <li>Existing positions continue trading their full lifecycle (WAP, stop-loss, take-profit, sync).</li>
                        <li>No new positions open until the wallet covers the next renewal.</li>
                        <li>Top up at any time — the moment your balance covers the monthly rate, the bot automatically retries the renewal and unblocks new opens.</li>
                    </ul>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">8. Pause &amp; resume</div>
                    <p>
                        You can pause your subscription at any time. While paused, no debits happen and no new positions open (existing ones still trade out).
                        When you resume, the renewal date is pushed forward by exactly the number of days you were paused — you don't lose any of the time you've already paid for.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">9. Plan changes</div>
                    <p>
                        You can switch plans at any time. Mid-cycle plan changes are <strong>prorated</strong>: the unused days of your current plan are credited back to your wallet, and the full new plan rate is charged from your wallet. The new renewal date is set 30 days from the change.
                        If your wallet can't cover the new plan after the prorate, the change is rejected — top up first.
                    </p>
                    <p class="mt-1">
                        Downgrading from a multi-account plan to a single-account plan requires you to designate which account stays active. Other accounts go read-only until you upgrade again.
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">10. Notifications</div>
                    <p>
                        We send notifications for: top-up confirmation, low balance (7 days before renewal if your wallet is short), trial ending (2 days before, if you haven't topped up), and renewal failure.
                        Channels are based on your account preferences (email, Pushover).
                    </p>
                </div>

                <div>
                    <div class="text-xs font-semibold ui-text mb-1">11. Account responsibility</div>
                    <p>
                        You are responsible for the security of your Kraite account, your exchange API keys, and your payment wallet. Kraite stores exchange credentials encrypted, but cannot recover lost access if you lose your login or 2FA.
                        Trading carries financial risk. Kraite makes no guarantees about returns; past performance of the bot does not predict future results.
                    </p>
                </div>

                <div class="text-[10px] ui-text-subtle pt-3 border-t ui-border">
                    These terms may evolve as the service grows. Material changes will be communicated via email before they take effect.
                </div>

            </div>
        </div>

    </div>

    <script>
        // Polls /billing/wallet-status every 5s. When the wallet balance
        // changes from what we rendered with, the page reloads so all
        // dependent UI (state banners, plan card, coverage badge,
        // top-up minimum) reflects the new state.
        function walletPoller(config) {
            return {
                initial: parseFloat(config.initialBalance),
                async start() {
                    setInterval(async () => {
                        try {
                            const res = await fetch(config.endpoint, {
                                headers: { 'Accept': 'application/json' },
                                credentials: 'same-origin',
                            });
                            if (!res.ok) return;
                            const data = await res.json();
                            const current = parseFloat(data.wallet_balance_usdt || 0);
                            if (Math.abs(current - this.initial) > 0.0001) {
                                location.reload();
                            }
                        } catch (e) {
                            // Silent — just retry next tick.
                        }
                    }, 5000);
                },
            };
        }
    </script>

    {{-- Coverage rule explainer --}}
    <x-hub-ui::modal name="coverage-rule" maxWidth="lg">
        <div class="p-5 sm:p-6">
            <div class="flex items-start justify-between mb-4 gap-3">
                <h3 class="text-base font-semibold ui-text">Why this minimum?</h3>
                <button type="button" class="ui-text-subtle hover:ui-text" @click="$dispatch('close-modal', 'coverage-rule')">×</button>
            </div>

            <div class="space-y-3 text-sm ui-text-muted leading-relaxed">
                <p>
                    The bot needs your wallet to always cover at least <strong class="ui-text">one full subscription month</strong>.
                    Otherwise the next renewal would fail and your accounts would drop into read-only mode (existing
                    positions still trade out, but no new ones open).
                </p>

                <p class="ui-text">When your wallet is short:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Minimum top-up = <em>monthly rate − current wallet</em> (just the shortfall).</li>
                    <li>You can chip in tiny amounts as long as they cover that gap.</li>
                </ul>

                <p class="ui-text">When your wallet already covers the next month:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>A flat floor (set by admin) applies, to discourage micro top-ups.</li>
                    <li>The floor only kicks in once you're already safe, never when you're under-funded.</li>
                </ul>

                <p>
                    You'll never be asked for more than the minimum required to keep your subscription running —
                    just enough to fund at least one more month.
                </p>
            </div>

            <div class="mt-5 flex justify-end">
                <x-hub-ui::button variant="secondary" size="sm" @click="$dispatch('close-modal', 'coverage-rule')">Got it</x-hub-ui::button>
            </div>
        </div>
    </x-hub-ui::modal>
</x-app-layout>
