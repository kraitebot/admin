{{-- Sortable header cell — inverted accent band. Expects the surrounding
     Alpine scope to expose sortKey / sortDir / setSort(). Params: $id, $label, $w. --}}
@php
    $w = $w ?? null;
@endphp
<th @click="setSort('{{ $id }}')"
    @if($w) style="width: {{ $w }};" @endif
    class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase bg-accent text-accent-on py-[11px] px-3 whitespace-nowrap select-none cursor-pointer text-center first:pl-5 last:pr-5 transition-colors duration-fast ease-out hover:bg-accent-hover">
    <span class="inline-flex items-center justify-center gap-1">
        {{ $label }}
        <span class="inline-flex transition-opacity duration-fast text-accent-on" :class="sortKey === '{{ $id }}' ? 'opacity-100' : 'opacity-0'">
            <span x-show="sortKey === '{{ $id }}' && sortDir === 'asc'"><x-feathericon-chevron-up class="w-3 h-3" stroke-width="2"/></span>
            <span x-show="!(sortKey === '{{ $id }}' && sortDir === 'asc')"><x-feathericon-chevron-down class="w-3 h-3" stroke-width="2"/></span>
        </span>
    </span>
</th>
