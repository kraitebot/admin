<x-app-layout :activeSection="'system'" :activeHighlight="'system-users'">
    <div class="max-w-6xl">

        <x-hub-ui::page-header
            title="Billing"
            description="Pick a user to inspect subscription state, connected accounts, wallet balance, and apply override actions."
        />

        @include('system.billing._tabs', ['active' => 'users'])

        @if (session('status'))
            <div class="mb-4">
                <x-hub-ui::alert type="success">{{ session('status') }}</x-hub-ui::alert>
            </div>
        @endif

        {{-- User picker — same shape as the account picker on /accounts/positions
             and /projections. Selecting a user navigates to /system/users/{id}. --}}
        <div class="mb-6 flex items-end gap-4 flex-wrap">
            <div class="flex-1 min-w-0 sm:min-w-[280px] max-w-md w-full">
                <label class="block text-[10px] font-semibold uppercase tracking-[0.12em] ui-text-subtle mb-2">User</label>
                <div class="relative">
                    <select
                        onchange="if (this.value) window.location = this.value;"
                        class="w-full px-4 py-2.5 text-sm rounded-lg border ui-input appearance-none cursor-pointer font-medium"
                    >
                        <option value="">— Select a user —</option>
                        @foreach ($users as $u)
                            <option value="{{ route('system.users', $u) }}" @selected($selected && $selected->id === $u->id)>
                                {{ $u->email }} · {{ $u->name }}{{ $u->subscription ? ' · '.$u->subscription->name : '' }}
                            </option>
                        @endforeach
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                        <x-feathericon-chevron-down class="w-4 h-4 ui-text-subtle" />
                    </div>
                </div>
            </div>

            @if ($selected)
                <div class="flex items-center gap-3">
                    <span class="text-[11px] ui-text-subtle font-mono">
                        ID <span class="ui-text-muted">{{ $selected->id }}</span>
                    </span>
                </div>
            @endif
        </div>

        @unless ($selected)
            <x-hub-ui::empty-state
                title="Pick a user"
                description="Choose a user from the dropdown above to see their billing state."
            />
        @else

            {{-- Identity strip --}}
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="text-base font-semibold ui-text">{{ $selected->name }}</h2>
                        <div class="text-xs ui-text-muted font-mono mt-0.5">{{ $selected->email }}</div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        @if ($selected->is_admin)
                            <x-hub-ui::badge type="primary">admin</x-hub-ui::badge>
                        @endif
                        @if ($selected->is_active)
                            <x-hub-ui::badge type="success">active</x-hub-ui::badge>
                        @else
                            <x-hub-ui::badge type="warning">inactive</x-hub-ui::badge>
                        @endif
                        @if ($selected->can_trade)
                            <x-hub-ui::badge type="success">can trade</x-hub-ui::badge>
                        @else
                            <x-hub-ui::badge type="default">no trade</x-hub-ui::badge>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">

                {{-- Wallet --}}
                <div class="ui-card p-4 sm:p-5">
                    <div class="flex items-baseline justify-between mb-3">
                        <h2 class="text-sm font-semibold ui-text">Wallet</h2>
                        <span class="text-[10px] ui-text-subtle uppercase tracking-wider">USDT</span>
                    </div>
                    <div class="text-3xl font-bold ui-text font-mono ui-tabular leading-none">
                        {{ number_format((float) $selected->wallet_balance_usdt, 4) }}
                    </div>
                    @php
                        $monthly = (float) ($selected->subscription?->monthly_rate_usdt ?? 0);
                        $covered = $selected->subscriptionCoversNextRenewal();
                        $shortfall = $selected->renewalShortfallUsdt();
                    @endphp
                    <div class="text-xs ui-text-muted mt-3">
                        @if ($monthly <= 0)
                            No monthly rate set.
                        @elseif ($covered)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                                  style="background: rgb(var(--ui-success)); color: white">
                                Renewal covered
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold"
                                  style="background: rgb(var(--ui-danger)); color: white">
                                Short {{ number_format($shortfall, 2) }} USDT
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Subscription --}}
                <div class="ui-card p-4 sm:p-5">
                    <div class="flex items-baseline justify-between mb-3">
                        <h2 class="text-sm font-semibold ui-text">Subscription</h2>
                        @if ($selected->subscription)
                            <span class="text-[10px] font-mono ui-text-subtle">{{ $selected->subscription->canonical }}</span>
                        @endif
                    </div>

                    @if ($selected->subscription)
                        <div class="text-xl font-semibold ui-text leading-none">{{ $selected->subscription->name }}</div>
                        <div class="text-xs ui-text-subtle font-mono mt-1">
                            {{ number_format((float) $selected->subscription->monthly_rate_usdt, 2) }} USDT/month
                        </div>

                        <div class="grid grid-cols-2 gap-2 mt-3">
                            <div class="ui-bg-elevated rounded-lg p-2">
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Renews</div>
                                <div class="text-xs font-mono ui-text">
                                    {{ $selected->subscription_renews_at ? $selected->subscription_renews_at->toDateString() : '—' }}
                                </div>
                            </div>
                            <div class="ui-bg-elevated rounded-lg p-2">
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Paused</div>
                                <div class="text-xs font-mono ui-text">
                                    {{ $selected->subscription_paused_at ? $selected->subscription_paused_at->diffForHumans() : '—' }}
                                </div>
                            </div>
                            <div class="ui-bg-elevated rounded-lg p-2">
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Trial days</div>
                                <div class="text-xs font-mono ui-text">{{ $selected->subscription->trial_days }}</div>
                            </div>
                            <div class="ui-bg-elevated rounded-lg p-2">
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Accounts</div>
                                <div class="text-xs font-mono ui-text">{{ $selected->subscription->max_accounts ?? '∞' }}</div>
                            </div>
                            <div class="ui-bg-elevated rounded-lg p-2 col-span-2">
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Cap (USDT)</div>
                                <div class="text-xs font-mono ui-text">
                                    {{ $selected->subscription->max_balance ? number_format((float) $selected->subscription->max_balance, 0) : '∞' }}
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="ui-text-subtle text-xs">No subscription assigned.</div>
                    @endif

                    <form method="POST" action="{{ route('system.users.subscription', $selected) }}" class="mt-3 flex items-end gap-2">
                        @csrf
                        <div class="flex-1">
                            <x-hub-ui::select name="subscription_id" :value="$selected->subscription_id">
                                @foreach ($subscriptions as $sub)
                                    <option value="{{ $sub->id }}" @selected($selected->subscription_id === $sub->id)>
                                        {{ $sub->name }} · {{ number_format((float) $sub->monthly_rate_usdt, 2) }}/mo
                                    </option>
                                @endforeach
                            </x-hub-ui::select>
                        </div>
                        <x-hub-ui::button type="submit" variant="secondary" size="sm">Change</x-hub-ui::button>
                    </form>

                    @php
                        $tierIsCapped = $selected->subscription
                            && ! $selected->subscription->hasUnlimitedAccounts()
                            && (int) $selected->subscription->max_accounts === 1;
                    @endphp

                    @if ($tierIsCapped && $selected->accounts->count() > 1)
                        <div class="mt-3 pt-3 border-t ui-border">
                            <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">
                                Active account on capped tier
                            </label>
                            <form method="POST" action="{{ route('system.users.active-account', $selected) }}" class="flex items-end gap-2">
                                @csrf
                                <div class="flex-1">
                                    <x-hub-ui::select name="active_account_id">
                                        <option value="">— none assigned —</option>
                                        @foreach ($selected->accounts as $acc)
                                            <option value="{{ $acc->id }}" @selected($selected->active_account_id === $acc->id)>
                                                {{ $acc->name }}
                                            </option>
                                        @endforeach
                                    </x-hub-ui::select>
                                </div>
                                <x-hub-ui::button type="submit" variant="secondary" size="sm">Assign</x-hub-ui::button>
                            </form>
                        </div>
                    @elseif ($tierIsCapped && $selected->accounts->count() === 1)
                        <div class="mt-3 pt-3 border-t ui-border">
                            <div class="text-[10px] ui-text-subtle uppercase tracking-wider mb-1">
                                Active account
                            </div>
                            <div class="text-xs font-mono ui-text">
                                {{ $selected->accounts->first()->name }}
                                <span class="ui-text-subtle">(only one — auto-assigned)</span>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Trial --}}
                <div class="ui-card p-4 sm:p-5">
                    <div class="flex items-baseline justify-between mb-3">
                        <h2 class="text-sm font-semibold ui-text">Trial</h2>
                        @if ($selected->trial_started_at !== null)
                            @if ($selected->isTrialActive())
                                <x-hub-ui::badge type="info">active</x-hub-ui::badge>
                            @else
                                <x-hub-ui::badge type="default">expired</x-hub-ui::badge>
                            @endif
                        @endif
                    </div>

                    @if ($selected->trial_started_at === null)
                        <div class="text-xs ui-text-subtle mb-3">Not started.</div>
                        <form method="POST" action="{{ route('system.users.start-trial', $selected) }}">
                            @csrf
                            <x-hub-ui::button type="submit" variant="primary" size="sm">Start trial</x-hub-ui::button>
                        </form>
                    @else
                        @php
                            $effectiveDays = $selected->effectiveTrialDays();
                            $end = $selected->trial_started_at?->copy()->addDays($effectiveDays);
                        @endphp
                        <div class="ui-bg-elevated rounded-lg p-3 space-y-2">
                            <div>
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Started</div>
                                <div class="text-xs font-mono ui-text">{{ $selected->trial_started_at->toDateTimeString() }}</div>
                            </div>
                            <div>
                                <div class="text-[10px] ui-text-subtle uppercase tracking-wider">Expires</div>
                                <div class="text-xs font-mono ui-text">{{ $end?->toDateTimeString() }}</div>
                            </div>
                        </div>

                        @if ($selected->isTrialActive())
                            <form method="POST" action="{{ route('system.users.trial-days', $selected) }}" class="mt-3">
                                @csrf
                                <input type="hidden" name="trial_days_override" value="0" />
                                <x-hub-ui::button type="submit" variant="danger" size="sm">End trial now</x-hub-ui::button>
                            </form>
                        @endif
                    @endif

                    {{-- Trial duration override --}}
                    <form method="POST" action="{{ route('system.users.trial-days', $selected) }}" class="mt-3 flex items-end gap-2">
                        @csrf
                        <div class="flex-1">
                            <label class="block text-[10px] font-semibold uppercase tracking-[0.12em] ui-text-subtle mb-1">
                                Days
                                <span class="ui-text-muted normal-case tracking-normal font-normal">
                                    (tier default {{ $selected->subscription?->trial_days ?? 0 }})
                                </span>
                            </label>
                            <input
                                type="number"
                                name="trial_days_override"
                                min="0"
                                max="365"
                                value="{{ $selected->trial_days_override }}"
                                placeholder="empty = inherit tier"
                                class="w-full px-3 py-2 text-sm rounded-lg border ui-input"
                            />
                        </div>
                        <x-hub-ui::button type="submit" variant="secondary" size="sm">Save</x-hub-ui::button>
                    </form>
                </div>

            </div>

            {{-- Connected accounts --}}
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Connected accounts</h2>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">{{ $selected->accounts->count() }} attached</span>
                </div>

                @if ($selected->accounts->isEmpty())
                    <x-hub-ui::empty-state
                        title="No accounts attached"
                        description="This user hasn't linked an exchange API yet."
                    />
                @else
                    <x-hub-ui::data-table>
                        <x-slot:head>
                            <tr>
                                <th class="text-left">ID</th>
                                <th class="text-left">Name</th>
                                <th class="text-left">Exchange</th>
                                <th class="text-center">Trade?</th>
                                <th class="text-center">Active?</th>
                                <th class="text-center">Designated</th>
                            </tr>
                        </x-slot:head>

                        @foreach ($selected->accounts as $acc)
                            <tr>
                                <td class="font-mono ui-text-subtle">{{ $acc->id }}</td>
                                <td class="ui-text">{{ $acc->name }}</td>
                                <td class="ui-text-muted text-xs">{{ $acc->apiSystem?->canonical ?? '—' }}</td>
                                <td class="text-center">
                                    @if ($acc->can_trade)
                                        <x-hub-ui::badge type="success">yes</x-hub-ui::badge>
                                    @else
                                        <span class="ui-text-subtle text-xs">no</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($acc->is_active ?? true)
                                        <x-hub-ui::badge type="success">yes</x-hub-ui::badge>
                                    @else
                                        <span class="ui-text-subtle text-xs">no</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($selected->active_account_id === $acc->id)
                                        <x-hub-ui::badge type="primary">active</x-hub-ui::badge>
                                    @else
                                        <span class="ui-text-subtle text-xs">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-hub-ui::data-table>
                @endif
            </div>

            {{-- Override top-up --}}
            <div class="ui-card p-4 sm:p-5 mb-4">
                <div class="flex items-baseline justify-between mb-3">
                    <h2 class="text-sm font-semibold ui-text">Override top-up</h2>
                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">audit-logged</span>
                </div>

                <p class="text-xs ui-text-muted mb-3">
                    Manually credit or debit this user's wallet without going through the payment gateway.
                    Positive amount credits, negative amount debits. Every override writes a row to the
                    wallet ledger as <code class="font-mono">credit_admin</code> /
                    <code class="font-mono">debit_admin</code>, capturing the operator identity and the
                    description below.
                </p>

                <form method="POST" action="{{ route('system.users.credit', $selected) }}" class="space-y-3">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <x-hub-ui::input
                            name="amount_usdt"
                            label="Amount (USDT)"
                            type="number"
                            step="0.0001"
                            placeholder="e.g. 75 or -2.5"
                            required
                        />
                        <div class="md:col-span-2">
                            <x-hub-ui::input
                                name="description"
                                label="Description"
                                placeholder="e.g. Pre-launch test credit"
                                required
                            />
                        </div>
                    </div>
                    <x-hub-ui::button type="submit" variant="primary" size="sm">Apply override</x-hub-ui::button>
                </form>
            </div>

            {{-- Ledger --}}
            <div class="ui-card p-4 sm:p-5">
                <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Recent ledger</h2>
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
                                <x-hub-ui::empty-state title="No transactions yet" description="This wallet has no ledger entries." />
                            </td>
                        </tr>
                    @endforelse
                </x-hub-ui::data-table>
            </div>

        @endunless

    </div>
</x-app-layout>
