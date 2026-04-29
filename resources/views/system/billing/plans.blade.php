<x-app-layout :activeSection="'system'" :activeHighlight="'system-users'">
    <div class="max-w-6xl">

        <x-hub-ui::page-header
            title="Billing"
            description="Manage subscription tiers — rates, trial duration, caps, and activation."
        />

        @include('system.billing._tabs', ['active' => 'plans'])

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

        @if ($errors->any())
            <div class="mb-4">
                <x-hub-ui::alert type="error">
                    @foreach ($errors->all() as $err)
                        <div>{{ $err }}</div>
                    @endforeach
                </x-hub-ui::alert>
            </div>
        @endif

        {{-- Existing plans --}}
        <div class="space-y-3 mb-6">
            @forelse ($subscriptions as $sub)
                <div class="ui-card p-4 sm:p-5">
                    <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                        <div>
                            <h2 class="text-sm font-semibold ui-text">{{ $sub->name }}</h2>
                            <span class="text-[10px] ui-text-subtle font-mono">{{ $sub->canonical }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($sub->is_active)
                                <x-hub-ui::badge type="success">active</x-hub-ui::badge>
                            @else
                                <x-hub-ui::badge type="default">inactive</x-hub-ui::badge>
                            @endif
                            <span class="text-[10px] ui-text-subtle uppercase tracking-wider">
                                {{ $sub->users()->count() }} users
                            </span>
                        </div>
                    </div>

                    <form
                        id="update-plan-{{ $sub->id }}"
                        method="POST"
                        action="{{ route('system.billing.plans.update', $sub) }}"
                    >
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Name</label>
                                <x-hub-ui::input name="name" :value="$sub->name" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Canonical (slug)</label>
                                <x-hub-ui::input name="canonical" :value="$sub->canonical" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Monthly rate (USDT)</label>
                                <x-hub-ui::input name="monthly_rate_usdt" type="number" step="0.01" min="0" :value="number_format((float) $sub->monthly_rate_usdt, 4, '.', '')" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Trial days</label>
                                <x-hub-ui::input name="trial_days" type="number" min="0" :value="(int) $sub->trial_days" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Max accounts (blank = ∞)</label>
                                <x-hub-ui::input name="max_accounts" type="number" min="1" :value="$sub->max_accounts" />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Portfolio cap USDT (blank = ∞)</label>
                                <x-hub-ui::input name="max_balance" type="number" step="0.01" min="0" :value="$sub->max_balance" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Description</label>
                                <x-hub-ui::textarea name="description" rows="2">{{ $sub->description }}</x-hub-ui::textarea>
                            </div>
                            <div class="md:col-span-2 flex items-center gap-2">
                                <x-hub-ui::checkbox name="is_active" :checked="(bool) $sub->is_active" value="1" id="active-{{ $sub->id }}" />
                                <label for="active-{{ $sub->id }}" class="text-xs ui-text-muted">Active (visible to users for self-serve)</label>
                            </div>
                        </div>
                    </form>

                    <div class="flex items-center justify-between gap-2 mt-4 pt-3 border-t ui-border">
                        <x-hub-ui::button
                            type="submit"
                            form="update-plan-{{ $sub->id }}"
                            variant="primary"
                            size="sm"
                        >Save changes</x-hub-ui::button>

                        <form
                            method="POST"
                            action="{{ route('system.billing.plans.delete', $sub) }}"
                            onsubmit="return confirm('Delete plan {{ $sub->name }}? This is blocked if any user is still on it.');"
                        >
                            @csrf
                            <x-hub-ui::button type="submit" variant="danger" size="sm">Delete</x-hub-ui::button>
                        </form>
                    </div>
                </div>
            @empty
                <x-hub-ui::empty-state
                    title="No plans yet"
                    description="Create the first subscription tier below."
                />
            @endforelse
        </div>

        {{-- Create new plan --}}
        <form method="POST" action="{{ route('system.billing.plans.store') }}" class="ui-card p-4 sm:p-5">
            @csrf

            <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                <h2 class="text-sm font-semibold ui-text">Create new plan</h2>
                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">+ tier</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Name</label>
                    <x-hub-ui::input name="name" placeholder="e.g. Pro" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Canonical (slug)</label>
                    <x-hub-ui::input name="canonical" placeholder="e.g. pro" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Monthly rate (USDT)</label>
                    <x-hub-ui::input name="monthly_rate_usdt" type="number" step="0.01" min="0" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Trial days</label>
                    <x-hub-ui::input name="trial_days" type="number" min="0" :value="7" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Max accounts (blank = ∞)</label>
                    <x-hub-ui::input name="max_accounts" type="number" min="1" />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Portfolio cap USDT (blank = ∞)</label>
                    <x-hub-ui::input name="max_balance" type="number" step="0.01" min="0" />
                </div>
                <div class="md:col-span-2">
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Description</label>
                    <x-hub-ui::textarea name="description" rows="2"></x-hub-ui::textarea>
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <x-hub-ui::checkbox name="is_active" value="1" id="active-new" :checked="true" />
                    <label for="active-new" class="text-xs ui-text-muted">Active (visible to users for self-serve)</label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-4 pt-3 border-t ui-border">
                <x-hub-ui::button type="submit" variant="primary" size="sm">Create plan</x-hub-ui::button>
            </div>
        </form>

    </div>
</x-app-layout>
