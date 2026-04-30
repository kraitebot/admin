<x-app-layout :activeSection="'system'" :activeHighlight="'system-users'">
    <div class="max-w-6xl">

        <x-hub-ui::page-header
            title="Billing"
            description="Manage the coins users can top up with, and the global top-up floor."
        />

        @include('system.billing._tabs', ['active' => 'coins'])

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

        {{-- Engine settings --}}
        <div class="ui-card p-4 sm:p-5 mb-6">
            <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                <div>
                    <h2 class="text-sm font-semibold ui-text">Engine settings</h2>
                    <span class="text-[10px] ui-text-subtle">Global floor applied when the user wallet already covers the next renewal.</span>
                </div>
            </div>

            <form method="POST" action="{{ route('system.billing.coins.engine') }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">
                        Top-up minimum when wallet covers (USDT)
                    </label>
                    <x-hub-ui::input
                        name="top_up_minimum_when_covered_usdt"
                        type="number"
                        step="0.01"
                        min="0"
                        :value="number_format((float) ($engine?->top_up_minimum_when_covered_usdt ?? 20), 4, '.', '')"
                        required
                    />
                </div>
                <x-hub-ui::button type="submit" variant="secondary" size="sm">Save</x-hub-ui::button>
            </form>
        </div>

        {{-- Existing coins --}}
        <div class="space-y-3 mb-6">
            @forelse ($coins as $coin)
                <div class="ui-card p-4 sm:p-5">
                    <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                        <div>
                            <h3 class="text-sm font-semibold ui-text">{{ $coin->display_name }}</h3>
                            <span class="text-[10px] ui-text-subtle font-mono">{{ $coin->canonical }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($coin->is_active)
                                <x-hub-ui::badge type="success">active</x-hub-ui::badge>
                            @else
                                <x-hub-ui::badge type="default">inactive</x-hub-ui::badge>
                            @endif
                            @php $live = $liveMinByCanonical[$coin->canonical] ?? null; @endphp
                            @if ($live)
                                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">
                                    live floor: {{ number_format((float) $live['min_amount'], 6) }} {{ $coin->canonical }}
                                </span>
                            @else
                                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">
                                    live floor: —
                                </span>
                            @endif
                        </div>
                    </div>

                    <form
                        id="update-coin-{{ $coin->id }}"
                        method="POST"
                        action="{{ route('system.billing.coins.update', $coin) }}"
                    >
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Canonical</label>
                                <x-hub-ui::input name="canonical" :value="$coin->canonical" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Display name</label>
                                <x-hub-ui::input name="display_name" :value="$coin->display_name" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Sort order</label>
                                <x-hub-ui::input name="sort_order" type="number" min="0" :value="(int) $coin->sort_order" required />
                            </div>
                            <div>
                                <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Min override (in coin units, blank = use live)</label>
                                <x-hub-ui::input name="min_amount_override" type="number" step="0.000001" min="0" :value="$coin->min_amount_override" />
                            </div>
                            <div class="md:col-span-2 flex items-center gap-2">
                                <x-hub-ui::checkbox name="is_active" :checked="(bool) $coin->is_active" value="1" id="active-{{ $coin->id }}" />
                                <label for="active-{{ $coin->id }}" class="text-xs ui-text-muted">Active (visible in user dropdown)</label>
                            </div>
                        </div>
                    </form>

                    <div class="flex items-center justify-between gap-2 mt-4 pt-3 border-t ui-border">
                        <x-hub-ui::button type="submit" form="update-coin-{{ $coin->id }}" variant="primary" size="sm">
                            Save changes
                        </x-hub-ui::button>

                        <form
                            method="POST"
                            action="{{ route('system.billing.coins.delete', $coin) }}"
                            onsubmit="return confirm('Delete coin {{ $coin->display_name }}? Existing payments retain their canonical reference.');"
                        >
                            @csrf
                            <x-hub-ui::button type="submit" variant="danger" size="sm">Delete</x-hub-ui::button>
                        </form>
                    </div>
                </div>
            @empty
                <x-hub-ui::empty-state
                    title="No coins configured"
                    description="Add the first coin below to make top-ups available to users."
                />
            @endforelse
        </div>

        {{-- Add coin --}}
        <form method="POST" action="{{ route('system.billing.coins.store') }}" class="ui-card p-4 sm:p-5">
            @csrf
            <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
                <h2 class="text-sm font-semibold ui-text">Add coin</h2>
                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">+ payment option</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Canonical (NOWPayments code)</label>
                    <x-hub-ui::input name="canonical" placeholder="e.g. usdtmatic" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Display name</label>
                    <x-hub-ui::input name="display_name" placeholder="e.g. Tether (Polygon)" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Sort order</label>
                    <x-hub-ui::input name="sort_order" type="number" min="0" :value="999" required />
                </div>
                <div>
                    <label class="text-[10px] ui-text-subtle uppercase tracking-wider block mb-1">Min override (blank = live)</label>
                    <x-hub-ui::input name="min_amount_override" type="number" step="0.000001" min="0" />
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <x-hub-ui::checkbox name="is_active" value="1" id="active-new" :checked="true" />
                    <label for="active-new" class="text-xs ui-text-muted">Active</label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 mt-4 pt-3 border-t ui-border">
                <x-hub-ui::button type="submit" variant="primary" size="sm">Add coin</x-hub-ui::button>
            </div>
        </form>

    </div>
</x-app-layout>
