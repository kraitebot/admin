<x-app-layout :activeHighlight="'billing'">
    <div class="max-w-6xl">

        <x-hub-ui::page-header
            title="Billing"
            description="Your wallet, plan, and payment history."
        />

        @if (session('status'))
            <div class="mb-4">
                <x-hub-ui::alert type="success">{{ session('status') }}</x-hub-ui::alert>
            </div>
        @endif

        @if ($user->trial_started_at === null)
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Start your free trial</h2>
                    <span class="text-[11px] ui-text-subtle uppercase tracking-wider">{{ $tier?->trial_days ?? 7 }} days, free</span>
                </div>
                <p class="text-sm ui-text-muted mb-3">
                    The trial activates the moment you click below. After {{ $tier?->trial_days ?? 7 }} days,
                    your wallet starts being debited at
                    {{ number_format((float) ($tier?->daily_rate_usdt ?? 0), 4) }} USDT/day.
                </p>
                <form method="POST" action="{{ route('billing.start-trading') }}">
                    @csrf
                    <x-hub-ui::button type="submit" variant="primary" size="sm">Start trading</x-hub-ui::button>
                </form>
            </div>
        @elseif ($trialActive)
            @php
                $end = $user->trial_started_at?->copy()->addDays((int) ($tier?->trial_days ?? 0));
                $hoursLeft = $end ? max(0, (int) round(now()->diffInMinutes($end, false) / 60)) : 0;
            @endphp
            <div class="mb-4">
                <x-hub-ui::alert type="info">
                    Trial active — ~{{ $hoursLeft }}h remaining. After that, your wallet starts being
                    debited at {{ number_format((float) ($tier?->daily_rate_usdt ?? 0), 4) }} USDT/day.
                </x-hub-ui::alert>
            </div>
        @elseif ($inClosingMode)
            <div class="mb-4">
                <x-hub-ui::alert type="error">
                    <strong>Closing-positions mode.</strong> Your wallet can't cover today's debit.
                    Existing trades continue normally; new positions are paused. Top up to resume opens.
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
                <div class="text-xs ui-text-muted mt-3">
                    @if ($trialActive)
                        Trial active — wallet untouched.
                    @elseif ($runwayDays === null)
                        No daily rate.
                    @else
                        ~{{ $runwayDays }} days runway @ {{ number_format((float) ($tier?->daily_rate_usdt ?? 0), 4) }}/day
                    @endif
                </div>
            </div>

            {{-- Plan --}}
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
                        {{ number_format((float) $tier->daily_rate_usdt, 4) }} USDT/day
                    </div>
                    <div class="grid grid-cols-2 gap-2 mt-3">
                        <div class="ui-bg-elevated rounded-lg p-2">
                            <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Accounts</div>
                            <div class="text-xs font-mono ui-text">{{ $tier->max_accounts ?? '∞' }}</div>
                        </div>
                        <div class="ui-bg-elevated rounded-lg p-2">
                            <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Cap (USDT)</div>
                            <div class="text-xs font-mono ui-text">
                                {{ $tier->max_balance ? number_format((float) $tier->max_balance, 0) : '∞' }}
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('billing.subscription') }}" class="mt-3 flex items-end gap-2">
                        @csrf
                        <div class="flex-1">
                            <x-hub-ui::select name="subscription_id" :value="$user->subscription_id">
                                @foreach ($subscriptions as $sub)
                                    <option value="{{ $sub->id }}" @selected($user->subscription_id === $sub->id)>
                                        {{ $sub->name }} · {{ number_format((float) $sub->daily_rate_usdt, 2) }}/d
                                    </option>
                                @endforeach
                            </x-hub-ui::select>
                        </div>
                        <x-hub-ui::button type="submit" variant="secondary" size="sm">Switch</x-hub-ui::button>
                    </form>
                @else
                    <div class="ui-text-subtle text-xs">No plan assigned.</div>
                @endif
            </div>

            {{-- Top up --}}
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-sm font-semibold ui-text">Top up</h2>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">+ bonus credit</span>
                </div>

                <ul class="text-[11px] ui-text-muted space-y-1 mb-3 font-mono">
                    <li>50–99 USDT → +5%</li>
                    <li>100–499 USDT → +10%</li>
                    <li>500+ USDT → +15%</li>
                </ul>

                <x-hub-ui::button variant="primary" size="sm" disabled>
                    Top up (coming soon)
                </x-hub-ui::button>
                <div class="text-[10px] ui-text-subtle mt-2 leading-snug">
                    Payment gateway integration ships next. Until then, contact admin for manual credits.
                </div>
            </div>

        </div>

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
