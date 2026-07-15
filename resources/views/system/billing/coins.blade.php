@php
    $input = 'w-full h-10 rounded-control border border-line bg-input px-3 text-[13px] text-fg-1 outline-none focus:border-accent';
    $label = 'font-mono text-[10px] font-semibold tracking-[0.09em] uppercase text-fg-mute';
    $button = 'inline-flex h-9 items-center justify-center gap-2 rounded-control bg-accent px-4 text-[12px] font-semibold text-accent-on hover:bg-accent-hover';
@endphp

<x-app-layout active="billing" :title="'Kraite — Billing coins'">
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-dollar-sign class="w-[13px] h-[13px]" stroke-width="1.75"/>Sysadmin billing
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Top-up coins</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Curated NOWPayments currencies and wallet funding floors.</div>
        </div>
        @include('system.billing._tabs', ['active' => 'coins'])
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

    <section class="card card--flat p-5 mb-5">
        <div class="flex items-start justify-between gap-4 max-[640px]:flex-col">
            <div>
                <div class="font-sans text-[15px] font-semibold text-fg-1">Covered-wallet minimum</div>
                <p class="text-[12px] text-fg-3 mt-1 max-w-[620px]">Applied when the wallet already covers the next renewal. Under-funded users instead pay at least their exact subscription shortfall.</p>
            </div>
            @if($engine)
                <form method="POST" action="{{ route('system.billing.coins.engine') }}" class="flex items-end gap-2">
                    @csrf
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Minimum USDT</span><input class="{{ $input }} w-36" type="number" step="0.0001" min="0" name="top_up_minimum_when_covered_usdt" value="{{ $engine->top_up_minimum_when_covered_usdt }}" required></label>
                    <button class="{{ $button }}" type="submit">Save</button>
                </form>
            @else
                <span class="text-[12px] text-danger">Kraite singleton missing.</span>
            @endif
        </div>
    </section>

    <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
        @foreach($coins as $coin)
            @php($live = $liveMinByCanonical[$coin->canonical] ?? null)
            <section class="card card--flat p-5">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div>
                        <div class="font-sans text-[16px] font-semibold text-fg-1">{{ $coin->display_name }}</div>
                        <div class="font-mono text-[10px] text-fg-mute">{{ $coin->canonical }} · live minimum {{ $live ? number_format($live['min_amount'], 8) . ' ' . $live['unit'] : 'unavailable' }}</div>
                    </div>
                    <span class="rounded-chip px-2 py-1 font-mono text-[9px] font-bold uppercase tracking-[0.08em] {{ $coin->is_active ? 'bg-pnlup-bg text-pnlup' : 'bg-surface-3 text-fg-mute' }}">{{ $coin->is_active ? 'Active' : 'Hidden' }}</span>
                </div>

                <form method="POST" action="{{ route('system.billing.coins.update', $coin) }}" class="flex flex-col gap-4">
                    @csrf
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Display name</span><input class="{{ $input }}" name="display_name" value="{{ $coin->display_name }}" required></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Canonical</span><input class="{{ $input }}" name="canonical" value="{{ $coin->canonical }}" required></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Sort order</span><input class="{{ $input }}" type="number" min="0" name="sort_order" value="{{ $coin->sort_order }}" required></label>
                        <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Minimum override</span><input class="{{ $input }}" type="number" step="0.000001" min="0" name="min_amount_override" value="{{ $coin->min_amount_override }}" placeholder="Use gateway"></label>
                    </div>
                    <label class="flex items-center gap-2 text-[12px] text-fg-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($coin->is_active)>Available for top-ups</label>
                    <button class="{{ $button }} self-start" type="submit"><x-feathericon-save class="w-3.5 h-3.5"/>Save coin</button>
                </form>

                <form method="POST" action="{{ route('system.billing.coins.delete', $coin) }}" class="mt-3" onsubmit="return confirm('Remove this coin?')">
                    @csrf
                    <button type="submit" class="text-[11px] font-semibold text-danger hover:underline">Remove coin</button>
                </form>
            </section>
        @endforeach

        <section class="card card--flat p-5 border-dashed">
            <div class="font-sans text-[16px] font-semibold text-fg-1 mb-4">Add coin</div>
            <form method="POST" action="{{ route('system.billing.coins.store') }}" class="flex flex-col gap-4">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Display name</span><input class="{{ $input }}" name="display_name" required></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Canonical</span><input class="{{ $input }}" name="canonical" required></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Sort order</span><input class="{{ $input }}" type="number" min="0" name="sort_order" value="100" required></label>
                    <label class="flex flex-col gap-1.5"><span class="{{ $label }}">Minimum override</span><input class="{{ $input }}" type="number" step="0.000001" min="0" name="min_amount_override" placeholder="Use gateway"></label>
                </div>
                <label class="flex items-center gap-2 text-[12px] text-fg-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked>Available for top-ups</label>
                <button class="{{ $button }} self-start" type="submit"><x-feathericon-plus class="w-3.5 h-3.5"/>Add coin</button>
            </form>
        </section>
    </div>
</x-app-layout>
