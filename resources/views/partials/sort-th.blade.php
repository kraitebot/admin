{{-- Sortable header cell. Expects the surrounding Alpine scope to expose
     sortKey / sortDir / setSort(). Params: $id, $label, $align ('right'|'left'), $w. --}}
@php
    $align = $align ?? 'right';
    $w = $w ?? null;
@endphp
<th @click="setSort('{{ $id }}')"
    @if($w) style="width: {{ $w }};" @endif
    :class="sortKey === '{{ $id }}' ? 'text-fg-1' : 'text-fg-mute'"
    class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase py-[11px] px-3 border-b border-line whitespace-nowrap select-none cursor-pointer transition-colors duration-fast ease-out hover:text-fg-2 first:pl-5 last:pr-5 {{ $align === 'right' ? 'text-right' : 'text-left' }}">
    <span class="inline-flex items-center gap-1 {{ $align === 'right' ? 'flex-row-reverse' : '' }}">
        {{ $label }}
        <span class="inline-flex transition-opacity duration-fast" :class="sortKey === '{{ $id }}' ? 'opacity-100 text-accent' : 'opacity-0'">
            <span x-show="sortKey === '{{ $id }}' && sortDir === 'asc'"><x-feathericon-chevron-up class="w-3 h-3" stroke-width="2"/></span>
            <span x-show="!(sortKey === '{{ $id }}' && sortDir === 'asc')"><x-feathericon-chevron-down class="w-3 h-3" stroke-width="2"/></span>
        </span>
    </span>
</th>
