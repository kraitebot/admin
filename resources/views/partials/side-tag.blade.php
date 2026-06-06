{{-- Direction tag for table rows: green long / red short. Params: $side, $lev. --}}
<span class="inline-flex items-center gap-1 font-mono text-[10px] font-bold tracking-[0.07em] uppercase rounded-chip py-[3px] px-2 whitespace-nowrap {{ $side === 'long' ? 'bg-pnlup-bg text-pnlup' : 'bg-pnldown-bg text-pnldown' }}">
    @if($side === 'long')
        <x-feathericon-arrow-up class="w-[10px] h-[10px]" stroke-width="2"/>
    @else
        <x-feathericon-arrow-down class="w-[10px] h-[10px]" stroke-width="2"/>
    @endif
    {{ $side }} {{ $lev }}
</span>
