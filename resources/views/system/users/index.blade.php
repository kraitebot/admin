@php
    $input = 'w-full h-10 rounded-control border border-line bg-input px-3 text-[13px] text-fg-1 outline-none focus:border-accent';
    $label = 'font-mono text-[10px] font-semibold tracking-[0.09em] uppercase text-fg-mute';
    $button = 'inline-flex h-9 items-center justify-center gap-2 rounded-control bg-accent px-4 text-[12px] font-semibold text-accent-on hover:bg-accent-hover';
@endphp

<x-app-layout active="billing" :title="'Kraite — User billing'">
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-users class="w-[13px] h-[13px]" stroke-width="1.75"/>Sysadmin billing
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Users</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Wallet corrections, subscription assignment, trials, and active-account controls.</div>
        </div>
        @include('system.billing._tabs', ['active' => 'users'])
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-control border border-accent/40 bg-accent/10 px-4 py-3 text-[13px] text-fg-1">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-5 rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[13px] text-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-5 rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[13px] text-danger">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-[280px_1fr] gap-5 items-start max-[860px]:grid-cols-1">
        <aside class="card card--flat overflow-hidden max-[860px]:max-h-64 max-[860px]:overflow-y-auto">
            <div class="px-4 py-3 border-b border-line-soft font-mono text-[10px] font-semibold uppercase tracking-[0.1em] text-fg-mute">{{ $users->count() }} users</div>
            <div class="flex flex-col">
                @foreach($users as $billingUser)
                    <a href="{{ route('system.users', $billingUser) }}" class="px-4 py-3 border-b border-line-soft last:border-b-0 no-underline hover:bg-hover {{ $selected?->is($billingUser) ? 'bg-surface-3' : '' }}">
                        <div class="text-[12px] font-semibold text-fg-1 truncate">{{ $billingUser->email }}</div>
                        <div class="mt-1 flex items-center justify-between gap-2 font-mono text-[10px] text-fg-mute">
                            <span>{{ $billingUser->subscription?->name ?? 'No plan' }}</span>
                            <span>{{ number_format((float) $billingUser->wallet_balance_usdt, 2) }} USDT</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </aside>

        @if($selected)
            <main class="flex flex-col gap-5 min-w-0">
                <section class="card card--flat p-5">
                    <div class="flex items-start justify-between gap-4 max-[640px]:flex-col">
                        <div>
                            <div class="font-sans text-[18px] font-semibold text-fg-1">{{ $selected->name }}</div>
                            <div class="text-[12px] text-fg-3 mt-1">{{ $selected->email }} · user #{{ $selected->id }}</div>
                        </div>
                        <div class="text-right max-[640px]:text-left">
                            <div class="font-mono text-[22px] font-semibold text-fg-1">{{ number_format((float) $selected->wallet_balance_usdt, 4) }} USDT</div>
                            <div class="font-mono text-[10px] uppercase tracking-[0.08em] {{ $selected->isInClosingMode() ? 'text-danger' : 'text-pnlup' }}">{{ $selected->isInClosingMode() ? 'Read-only' : 'Trading enabled' }}</div>
                        </div>
                    </div>
                </section>

                <div class="grid grid-cols-2 gap-5 max-[760px]:grid-cols-1">
                    <section class="card card--flat p-5">
                        <div class="font-sans text-[15px] font-semibold text-fg-1 mb-4">Wallet adjustment</div>
                        <form method="POST" action="{{ route('system.users.credit', $selected) }}" class="flex flex-col gap-3">
                            @csrf
                            <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Signed amount</span><input class="{{ $input }}" type="number" step="0.0001" name="amount_usdt" placeholder="100 or -25" required></label>
                            <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Reason</span><input class="{{ $input }}" name="description" maxlength="255" required></label>
                            <button class="{{ $button }} self-start" type="submit">Apply adjustment</button>
                        </form>
                    </section>

                    <section class="card card--flat p-5">
                        <div class="font-sans text-[15px] font-semibold text-fg-1 mb-4">Subscription</div>
                        <form method="POST" action="{{ route('system.users.subscription', $selected) }}" class="flex flex-col gap-3">
                            @csrf
                            <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Plan</span><select class="{{ $input }}" name="subscription_id" required>@foreach($subscriptions as $subscription)<option value="{{ $subscription->id }}" @selected($selected->subscription_id === $subscription->id)>{{ $subscription->name }} · {{ $subscription->monthly_rate_usdt }} USDT</option>@endforeach</select></label>
                            <button class="{{ $button }} self-start" type="submit">Assign plan</button>
                        </form>
                    </section>

                    <section class="card card--flat p-5">
                        <div class="font-sans text-[15px] font-semibold text-fg-1 mb-4">Active account</div>
                        <form method="POST" action="{{ route('system.users.active-account', $selected) }}" class="flex flex-col gap-3">
                            @csrf
                            <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Trading account</span><select class="{{ $input }}" name="active_account_id"><option value="">None</option>@foreach($selected->accounts as $account)<option value="{{ $account->id }}" @selected($selected->active_account_id === $account->id)>{{ $account->apiSystem?->name }} · {{ $account->name }}</option>@endforeach</select></label>
                            <button class="{{ $button }} self-start" type="submit">Set active account</button>
                        </form>
                    </section>

                    <section class="card card--flat p-5">
                        <div class="font-sans text-[15px] font-semibold text-fg-1 mb-4">Trial</div>
                        @if($selected->subscription !== null && (float) $selected->subscription->monthly_rate_usdt <= 0)
                            <div class="text-[12px] leading-snug text-fg-3">Free forever — no trial or renewal.</div>
                        @else
                            <div class="text-[12px] text-fg-3 mb-3">Started: {{ $selected->trial_started_at?->format('M j, Y H:i') ?? 'Not started' }} · effective {{ $selected->effectiveTrialDays() }} days</div>
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('system.users.start-trial', $selected) }}">@csrf<button class="{{ $button }}" type="submit" @disabled($selected->trial_started_at !== null || $selected->subscription_id === null)>Start trial</button></form>
                                <form method="POST" action="{{ route('system.users.trial-days', $selected) }}" class="flex gap-2">@csrf<input class="{{ $input }} w-28" type="number" min="0" max="365" name="trial_days_override" value="{{ $selected->trial_days_override }}" placeholder="Default"><button class="{{ $button }} bg-transparent text-fg-2 border border-line" type="submit">Set days</button></form>
                            </div>
                        @endif
                    </section>
                </div>

                <section class="card card--flat p-5 flex items-center justify-between gap-4 max-[640px]:flex-col max-[640px]:items-start">
                    <div><div class="font-sans text-[14px] font-semibold text-fg-1">Password reset</div><div class="text-[12px] text-fg-3 mt-1">Send the user a fresh password setup link.</div></div>
                    <form method="POST" action="{{ route('system.users.password-reset', $selected) }}">@csrf<button class="{{ $button }} bg-transparent text-fg-2 border border-line" type="submit">Send reset link</button></form>
                </section>

                <section class="card card--flat overflow-hidden">
                    <div class="px-5 py-4 border-b border-line-soft font-sans text-[15px] font-semibold text-fg-1">Wallet ledger</div>
                    @if($transactions->isEmpty())
                        <div class="px-5 py-10 text-center text-[13px] text-fg-mute">No wallet movements.</div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[620px] border-collapse">
                                <thead><tr class="border-b border-line-soft">@foreach(['Date', 'Type', 'Description', 'Amount', 'Balance'] as $heading)<th class="px-4 py-2.5 text-left font-mono text-[9px] font-semibold uppercase tracking-[0.09em] text-fg-mute last:text-right">{{ $heading }}</th>@endforeach</tr></thead>
                                <tbody>@foreach($transactions as $transaction)<tr class="border-b border-line-soft last:border-b-0"><td class="px-4 py-3 font-mono text-[11px] text-fg-3">{{ $transaction->created_at?->format('M j, Y H:i') }}</td><td class="px-4 py-3 font-mono text-[10px] text-fg-2">{{ $transaction->type }}</td><td class="px-4 py-3 text-[12px] text-fg-2">{{ $transaction->description }}</td><td class="px-4 py-3 text-right font-mono text-[12px] {{ (float) $transaction->amount_usdt >= 0 ? 'text-pnlup' : 'text-pnldown' }}">{{ number_format((float) $transaction->amount_usdt, 4) }}</td><td class="px-4 py-3 text-right font-mono text-[12px] text-fg-3">{{ number_format((float) $transaction->balance_after, 4) }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </main>
        @else
            <div class="card card--flat flex min-h-64 items-center justify-center p-8 text-center text-[13px] text-fg-3">Select a user to inspect and manage billing.</div>
        @endif
    </div>
</x-app-layout>
