@php
    $input = 'w-full h-10 rounded-control border border-line bg-input px-3 text-[13px] text-fg-1 outline-none focus:border-accent';
    $label = 'font-mono text-[10px] font-semibold tracking-[0.09em] uppercase text-fg-mute';
    $button = 'inline-flex h-9 items-center justify-center gap-2 rounded-control bg-accent px-4 text-[12px] font-semibold text-accent-on hover:bg-accent-hover';
@endphp

<x-app-layout active="billing" :title="'Kraite — Billing plans'">
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-credit-card class="w-[13px] h-[13px]" stroke-width="1.75"/>Sysadmin billing
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Plans</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Rates, trials, trading caps, and public availability.</div>
        </div>
        @include('system.billing._tabs', ['active' => 'plans'])
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

    <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
        @foreach($subscriptions as $subscription)
            <section class="card card--flat p-5">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                        <div class="font-sans text-[16px] font-semibold text-fg-1">{{ $subscription->name }}</div>
                        <div class="font-mono text-[10px] text-fg-mute">{{ $subscription->canonical }} · {{ $subscription->users_count }} users</div>
                    </div>
                    <span class="rounded-chip px-2 py-1 font-mono text-[9px] font-bold uppercase tracking-[0.08em] {{ $subscription->is_active ? 'bg-pnlup-bg text-pnlup' : 'bg-surface-3 text-fg-mute' }}">
                        {{ $subscription->is_active ? 'Active' : 'Hidden' }}
                    </span>
                </div>

                <form method="POST" action="{{ route('system.billing.plans.update', $subscription) }}" class="flex flex-col gap-4">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Name</span><input class="{{ $input }}" name="name" value="{{ $subscription->name }}" required></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Canonical</span><input class="{{ $input }}" name="canonical" value="{{ $subscription->canonical }}" required></label>
                    </div>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Description</span><textarea class="{{ $input }} h-20 py-2" name="description">{{ $subscription->description }}</textarea></label>
                    <div class="grid grid-cols-2 gap-3 max-[520px]:grid-cols-1">
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Monthly USDT</span><input class="{{ $input }}" type="number" step="0.0001" min="0" name="monthly_rate_usdt" value="{{ $subscription->monthly_rate_usdt }}" required></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Trial days</span><input class="{{ $input }}" type="number" min="0" name="trial_days" value="{{ $subscription->trial_days }}" required></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Max accounts</span><input class="{{ $input }}" type="number" min="1" name="max_accounts" value="{{ $subscription->max_accounts }}" placeholder="Unlimited"></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Max exchanges</span><input class="{{ $input }}" type="number" min="1" name="max_exchanges" value="{{ $subscription->max_exchanges }}" placeholder="Unlimited"></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Max balance</span><input class="{{ $input }}" type="number" step="0.01" min="0" name="max_balance" value="{{ $subscription->max_balance }}" placeholder="Unlimited"></label>
                        <label class="flex items-center gap-2 self-end h-10 text-[12px] text-fg-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked($subscription->is_active)>
                            Available to users
                        </label>
                    </div>
                    <button class="{{ $button }} self-start" type="submit"><x-feathericon-save class="w-3.5 h-3.5"/>Save plan</button>
                </form>

                <form method="POST" action="{{ route('system.billing.plans.delete', $subscription) }}" class="mt-3" onsubmit="return confirm('Delete this plan?')">
                    @csrf
                    <button type="submit" class="text-[11px] font-semibold text-danger hover:underline">Delete plan</button>
                </form>
            </section>
        @endforeach

        <section class="card card--flat p-5 border-dashed">
            <div class="font-sans text-[16px] font-semibold text-fg-1 mb-4">Create plan</div>
            <form method="POST" action="{{ route('system.billing.plans.store') }}" class="flex flex-col gap-4">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Name</span><input class="{{ $input }}" name="name" required></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Canonical</span><input class="{{ $input }}" name="canonical" required></label>
                </div>
                <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Description</span><textarea class="{{ $input }} h-20 py-2" name="description"></textarea></label>
                <div class="grid grid-cols-2 gap-3 max-[520px]:grid-cols-1">
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Monthly USDT</span><input class="{{ $input }}" type="number" step="0.0001" min="0" name="monthly_rate_usdt" required></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Trial days</span><input class="{{ $input }}" type="number" min="0" name="trial_days" value="7" required></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Max accounts</span><input class="{{ $input }}" type="number" min="1" name="max_accounts" placeholder="Unlimited"></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Max exchanges</span><input class="{{ $input }}" type="number" min="1" name="max_exchanges" placeholder="Unlimited"></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Max balance</span><input class="{{ $input }}" type="number" step="0.01" min="0" name="max_balance" placeholder="Unlimited"></label>
                    <label class="flex items-center gap-2 self-end h-10 text-[12px] text-fg-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked>Available to users</label>
                </div>
                <button class="{{ $button }} self-start" type="submit"><x-feathericon-plus class="w-3.5 h-3.5"/>Create plan</button>
            </form>
        </section>
    </div>
</x-app-layout>
