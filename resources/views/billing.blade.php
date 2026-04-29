<x-app-layout :activeHighlight="'billing'">
    <div class="max-w-6xl">

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
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-sm font-semibold ui-text">Top up</h2>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">USDT</span>
                </div>

                <p class="text-xs ui-text-muted mb-3 leading-snug">
                    Any amount. Wallet accumulates until it covers the next monthly renewal.
                    Payment is processed via NOWPayments — you can pay in any supported coin.
                </p>

                <form method="POST" action="{{ route('billing.topup') }}" class="space-y-2">
                    @csrf
                    <x-hub-ui::input
                        name="amount_usdt"
                        type="number"
                        step="0.01"
                        min="1"
                        placeholder="Amount in USDT"
                        required
                    />
                    <x-hub-ui::button type="submit" variant="primary" size="sm" class="w-full">
                        Continue to payment
                    </x-hub-ui::button>
                </form>
                <div class="text-[10px] ui-text-subtle mt-2 leading-snug">
                    You'll be redirected to NOWPayments' hosted invoice page.
                </div>
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

    </div>
</x-app-layout>
