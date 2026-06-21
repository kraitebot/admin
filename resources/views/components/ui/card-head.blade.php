@props([
    'icon' => null,
    'title',
    'hint' => null,
    'accent' => false,
    'collapsible' => false,
])
@php
    // `collapsible` turns the head into a clickable toggle button — the caller
    // wires the Alpine `x-on:click` + the open/collapsed `:class` (border-b when
    // open, rounded-b-surface when collapsed) through the forwarded attributes.
    // Non-collapsible heads render the original static <div> unchanged.
    $tag = $collapsible ? 'button' : 'div';
    $base = 'flex items-center justify-between gap-3 py-[13px] px-5 bg-surface-2 rounded-t-surface max-[640px]:px-4'
        .($collapsible
            ? ' w-full text-left appearance-none border-0 cursor-pointer transition-colors duration-fast hover:bg-hover'
            : ' border-b border-line-soft');
@endphp
{{-- Section-card header: icon + title on the left, optional `right` slot or
     `hint` text on the right. Mirrors the design's ACardHead. --}}
<{{ $tag }} @if($collapsible) type="button" @endif {{ $attributes->merge(['class' => $base]) }}>
    <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
        @if($icon)
            <x-dynamic-component :component="'feathericon-' . $icon" class="w-4 h-4 {{ $accent ? 'text-accent' : 'text-fg-3' }}" stroke-width="1.75"/>
        @endif
        {{ $title }}
    </h4>
    {{-- A named `right` slot is only defined when the caller passes one;
         isset() gates it, then isNotEmpty() guards an explicitly-empty slot.
         No slot → fall back to the `hint` text. --}}
    @if(isset($right) && $right->isNotEmpty())
        {{ $right }}
    @elseif($hint)
        <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">{{ $hint }}</span>
    @endif
</{{ $tag }}>
